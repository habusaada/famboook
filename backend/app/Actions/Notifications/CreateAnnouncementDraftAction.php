<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationLevel;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\NotificationAudienceResolver;
use App\Support\Notifications\NotificationOutcome;
use App\Support\Notifications\NotificationSender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Staff or a coordinator prepares an ANNOUNCEMENT as a DRAFT
 * (FB-NOTIFY-01B.2b.4; ADR FB-NOTIFY-01 ADR-01, ADR-04, ADR-08 rule 1).
 * Nothing is queued, dispatched or visible to a family; no recipient and no
 * event is written (the history starts at QUEUED).
 *
 * WHO first (NotificationActors: the account's side and the create
 * permission; a coordinator's CURRENT context), then the input — title
 * 1..150 and body 1..2000 characters after trimming (R3), level, optional
 * expiry in the future (the one-hour lead R2 is enforced when publication is
 * requested), the audience (its side must be the sender's; eligibility and
 * non-emptiness via NotificationAudienceResolver) and an optional UUID
 * client_reference.
 *
 * The kind, sender side, sender and coordinator scope snapshot are set
 * here, never read from the input. The notice code comes from the model's
 * NotificationCodes generator.
 *
 * Idempotency (client_reference, unique per sender — the change-request
 * convention): the same key with the same material returns the existing
 * message untouched (replayed); with different material it is refused
 * (NOTIFICATION_IDEMPOTENCY_CONFLICT). The sender's user row lock and the
 * unique index serialize concurrent duplicates.
 */
class CreateAnnouncementDraftAction
{
    public function __construct(private readonly NotificationAudienceResolver $audiences) {}

    /** @param  array<string, mixed>  $input */
    public function handle(User $user, array $input): NotificationOutcome
    {
        $sender = NotificationActors::sender($user, send: false);

        foreach (['title', 'body'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        $validated = Validator::make($input, [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'level' => ['sometimes', Rule::enum(NotificationLevel::class)],
            'audience' => ['required', 'array'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'client_reference' => ['nullable', 'uuid'],
        ])->validate();

        $audience = NotificationAudience::fromArray($validated['audience']);
        if ($audience->type->senderSide() !== $sender->side) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }
        $this->audiences->validateRequest($user, $audience);

        $material = [
            'level' => NotificationLevel::from($validated['level'] ?? NotificationLevel::NORMAL->value),
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => $audience->toArray(),
            'expires_at' => isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
        ];
        $reference = $validated['client_reference'] ?? null;

        try {
            return DB::transaction(function () use ($sender, $material, $reference) {
                // Serializes this sender's drafts: the replay check and the insert.
                User::query()->whereKey($sender->user->getKey())->lockForUpdate()->first();
                if ($reference !== null && ($existing = $this->byReference($sender, $reference)) !== null) {
                    return $this->replay($existing, $sender, $material);
                }

                $message = NotificationMessage::create([
                    'kind' => NotificationKind::ANNOUNCEMENT,
                    'sender_side' => $sender->side,
                    'sender_user_id' => $sender->user->getKey(),
                    'sender_scope' => $sender->scopeSnapshot(),
                    'client_reference' => $reference,
                    ...$material,
                ]);

                return new NotificationOutcome($message);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The unique (sender_user_id, client_reference) index is the final guard.
            $existing = $reference !== null ? $this->byReference($sender, $reference) : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $sender, $material);
        }
    }

    private function byReference(NotificationSender $sender, string $reference): ?NotificationMessage
    {
        return NotificationMessage::query()
            ->where('sender_user_id', $sender->user->getKey())
            ->where('client_reference', $reference)
            ->first();
    }

    /** @param  array<string, mixed>  $material */
    private function replay(NotificationMessage $existing, NotificationSender $sender, array $material): NotificationOutcome
    {
        $same = $existing->kind === NotificationKind::ANNOUNCEMENT
            && $existing->sender_side === $sender->side
            && $existing->level === $material['level']
            && $existing->title === $material['title']
            && $existing->body === $material['body']
            && self::canonical($existing->audience) === self::canonical($material['audience'])
            && $existing->expires_at?->getTimestamp() === $material['expires_at']?->getTimestamp();
        if (! $same) {
            throw new NotificationPublicationException(NotificationPublicationException::IDEMPOTENCY_CONFLICT);
        }

        return new NotificationOutcome($existing, replayed: true);
    }

    /** The audience in one form whatever the storage's key order (jsonb reorders keys). */
    private static function canonical(?array $audience): string
    {
        $audience = NotificationAudience::fromArray($audience ?? [])->toArray();
        ksort($audience);

        return json_encode($audience, JSON_THROW_ON_ERROR);
    }
}
