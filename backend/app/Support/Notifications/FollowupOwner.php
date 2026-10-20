<?php

namespace App\Support\Notifications;

use App\Models\User;

/**
 * Who owns a family's notification follow-ups RIGHT NOW (FollowupOwnership;
 * ADR FB-NOTIFY-01 ADR-06 §2c): its primary Coordinator, or CENTRAL (SUPER_ADMIN
 * and ADMINISTRATOR) when no Coordinator is eligible.
 *
 * Server-side only and never persisted: the user is for authorization
 * decisions, never for an API resource (no internal id leaves the server).
 */
final readonly class FollowupOwner
{
    private function __construct(public ?User $coordinator) {}

    /** @internal FollowupOwnership only. */
    public static function coordinator(User $user): self
    {
        return new self($user);
    }

    /** @internal FollowupOwnership only. */
    public static function central(): self
    {
        return new self(null);
    }

    public function isCentral(): bool
    {
        return $this->coordinator === null;
    }

    public function isCoordinator(User $user): bool
    {
        return $this->coordinator !== null && (int) $this->coordinator->getKey() === (int) $user->getKey();
    }
}
