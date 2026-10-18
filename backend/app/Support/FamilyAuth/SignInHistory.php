<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Models\AuthSecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The previous successful sign-in of a Family Portal session
 * (FB-FAMILY-DASHBOARD-01), read from auth_security_events — no new column.
 *
 * - A successful sign-in is LOGIN_SUCCEEDED, ACTIVATION_COMPLETED or
 *   PASSWORD_RESET_COMPLETED with outcome SUCCESS (each signs the user in).
 *   PASSWORD_CHANGED is a session refresh, never a sign-in; failures never
 *   count.
 * - Each of those paths records its event inside its Domain Action BEFORE
 *   FamilySessionController::signIn() runs. AuthSecurityLog hands the event
 *   to this request-scoped instance (remember()), so the CURRENT sign-in is
 *   that exact row — not "the newest row", which a concurrent sign-in on
 *   another device could be.
 * - The previous sign-in is the user's newest successful sign-in row with a
 *   smaller id than the current one, resolved ONCE when the session starts
 *   and kept in the session (start()). /family/me only reads it.
 * - first_sign_in is claimed only when it is certain: the current event is
 *   ACTIVATION_COMPLETED, and activation always creates a NEW account
 *   (ActivateFamilyAccountAction). Otherwise "no earlier row" means unknown:
 *   the log may not reach back to the account's real first sign-in, and a
 *   session started before this feature carries nothing.
 * - Only a timestamp of the user's OWN event is kept; never an IP, a device
 *   or anyone else's event.
 */
final class SignInHistory
{
    /** @var list<AuthSecurityEventType> */
    public const SIGN_IN_TYPES = [
        AuthSecurityEventType::LOGIN_SUCCEEDED,
        AuthSecurityEventType::ACTIVATION_COMPLETED,
        AuthSecurityEventType::PASSWORD_RESET_COMPLETED,
    ];

    private const SESSION_KEY = 'family_portal.sign_in';

    private ?AuthSecurityEvent $current = null;

    /** Called by AuthSecurityLog for every event it writes; keeps the request's successful sign-in. */
    public function remember(AuthSecurityEvent $event): void
    {
        if ($event->outcome === AuthSecurityEventOutcome::SUCCESS
            && $event->user_id !== null
            && in_array($event->event_type, self::SIGN_IN_TYPES, true)) {
            $this->current = $event;
        }
    }

    /**
     * Resolves this new session's previous sign-in and stores it. Call after
     * the session is (re)generated. Without a current sign-in event of this
     * user in this request, the history is unknown.
     */
    public function start(Request $request, User $user): void
    {
        $current = $this->current !== null && $this->current->user_id === $user->getKey() ? $this->current : null;
        $previous = $current === null ? null : AuthSecurityEvent::query()
            ->where('user_id', $user->getKey())
            ->whereIn('event_type', array_map(fn (AuthSecurityEventType $t) => $t->value, self::SIGN_IN_TYPES))
            ->where('outcome', AuthSecurityEventOutcome::SUCCESS->value)
            ->where('id', '<', $current->getKey())
            ->orderByDesc('id')
            ->first(['id', 'created_at']);

        $this->store($request, [
            'previous_sign_in_at' => $previous?->created_at?->utc()->toIso8601String(),
            'first_sign_in' => $previous === null && $current?->event_type === AuthSecurityEventType::ACTIVATION_COMPLETED,
        ]);
        // Used once: a later session in the same worker never inherits it.
        $this->current = null;
    }

    /** What the session knows, carried across a session refresh (password change). */
    public static function snapshot(Request $request): ?array
    {
        $value = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_array($value) ? $value : null;
    }

    /** Puts a snapshot taken before a session refresh back into the new session. */
    public function store(Request $request, ?array $value): void
    {
        if ($value !== null) {
            $request->session()->put(self::SESSION_KEY, [
                'previous_sign_in_at' => is_string($value['previous_sign_in_at'] ?? null) ? $value['previous_sign_in_at'] : null,
                'first_sign_in' => ($value['first_sign_in'] ?? false) === true,
            ]);
        }
    }

    /** @return array{previous_sign_in_at: ?string, first_sign_in: bool} unknown = null / false */
    public static function forSession(Request $request): array
    {
        $value = self::snapshot($request);

        return [
            'previous_sign_in_at' => $value['previous_sign_in_at'] ?? null,
            'first_sign_in' => ($value['first_sign_in'] ?? false) === true,
        ];
    }
}
