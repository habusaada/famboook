<?php

namespace App\Support\Notifications;

use App\Enums\FamilyStatus;
use App\Enums\NotificationAudienceType;
use App\Enums\NotificationSenderSide;
use App\Exceptions\NotificationAudienceException;
use App\Models\Family;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public announcement audience of the HTTP API (FB-NOTIFY-01B.2b.8a, B1):
 * families are named by their public family_code, never by an internal id.
 * This maps such an audience onto the INTERNAL audience grammar
 * (NotificationAudience) before the audience preview and the draft action;
 * the stored audience, the resolver, the publication request and the worker
 * are unchanged.
 *
 *   {"type": "STAFF_FAMILIES", "family_codes": ["FAM-…", …]}
 *   {"type": "STAFF_ALL_ACTIVE"}
 *   {"type": "COORDINATOR_SCOPE"}
 *   {"type": "COORDINATOR_SELECTION", "family_codes": ["FAM-…", …]}
 *
 * Grammar first, strictly — NOTIFICATION_AUDIENCE_INVALID for an unknown or
 * internal key (family_ids, branch_ids, branch_group_ids, clan_ids), a type
 * of the other side or SYSTEM_ORIGIN, a code that is not a non-empty string
 * of at most MAX_CODE_LENGTH characters, an empty selection, more than
 * MAX_RAW_CODES entries or more than NotificationAudience::MAX_SELECTED
 * distinct codes. Codes are matched exactly after trimming; duplicates are
 * removed.
 *
 * Then ONE query resolves the codes among the families the sender may
 * address now: ACTIVE and not deleted for Staff, CoordinatorScopes::
 * families() of the CURRENT context for a coordinator. An unknown, inactive,
 * deleted or out-of-scope code refuses the WHOLE audience with the same
 * NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE, never naming which one — so the
 * mapping is no existence or scope oracle. It authorizes nothing: the draft
 * action, the publication request and the worker re-validate eligibility,
 * scope and send-all exactly as before. family_code is immutable, so the
 * mapping needs no transaction of its own.
 */
final class NotificationAudienceReferences
{
    /** At most this many entries are read before de-duplication. */
    public const MAX_RAW_CODES = 1000;

    public const MAX_CODE_LENGTH = 50;

    /** The public keys of each type, besides `type`. */
    private const PUBLIC_KEYS = [
        'STAFF_FAMILIES' => ['family_codes'],
        'STAFF_ALL_ACTIVE' => [],
        'COORDINATOR_SCOPE' => [],
        'COORDINATOR_SELECTION' => ['family_codes'],
    ];

    public function __construct(private readonly CoordinatorScopes $scopes) {}

    /**
     * The internal audience array for $raw, addressed by $sender.
     *
     * @return array<string, mixed>
     */
    public function toAudience(NotificationSender $sender, mixed $raw): array
    {
        if (! is_array($raw) || ! is_string($raw['type'] ?? null)) {
            throw self::invalid();
        }
        $type = NotificationAudienceType::tryFrom($raw['type']);
        if ($type === null || ! array_key_exists($type->value, self::PUBLIC_KEYS) || $type->senderSide() !== $sender->side) {
            throw self::invalid();
        }
        $keys = self::PUBLIC_KEYS[$type->value];
        if (array_diff(array_keys($raw), ['type', ...$keys]) !== []) {
            throw self::invalid();
        }
        if ($keys === []) {
            return ['type' => $type->value];
        }

        $codes = self::codes($raw['family_codes'] ?? null);
        $ids = $this->addressable($sender)
            ->whereIn('families.family_code', $codes)
            ->pluck('families.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (count($ids) !== count($codes)) {
            throw new NotificationAudienceException(NotificationAudienceException::FAMILY_NOT_ELIGIBLE);
        }

        return ['type' => $type->value, 'family_ids' => $ids];
    }

    /**
     * The families $sender may name now — the same sources as
     * NotificationAudienceResolver.
     *
     * @return Builder<Family>
     */
    private function addressable(NotificationSender $sender): Builder
    {
        if ($sender->side === NotificationSenderSide::COORDINATOR) {
            if ($sender->coordinator === null || ! $sender->coordinator->allowed()) {
                throw new NotificationAudienceException(NotificationAudienceException::NOT_AUTHORIZED);
            }

            return $this->scopes->families($sender->coordinator);
        }

        return Family::query()->where('families.status', FamilyStatus::ACTIVE->value);
    }

    /** @return list<string> trimmed, distinct, non-empty codes */
    private static function codes(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === [] || count($raw) > self::MAX_RAW_CODES) {
            throw self::invalid();
        }
        $codes = [];
        foreach ($raw as $code) {
            if (! is_string($code)) {
                throw self::invalid();
            }
            $code = trim($code);
            if ($code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
                throw self::invalid();
            }
            $codes[$code] = true;
        }
        if (count($codes) > NotificationAudience::MAX_SELECTED) {
            throw self::invalid();
        }

        return array_map('strval', array_keys($codes));
    }

    private static function invalid(): NotificationAudienceException
    {
        return new NotificationAudienceException(NotificationAudienceException::INVALID);
    }
}
