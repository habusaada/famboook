<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestType;

/**
 * What a family asks for (PWA-5b): the request type and the client's input,
 * nothing that decides WHO or WHICH FAMILY — those come only from the trusted
 * FamilyAccessResult the caller passes beside it.
 *
 * - `input` is the type's own input (proposed values and, for a member, the
 *   opaque member_ref); validated and normalized by the type's handler.
 * - `reason` is the requester's optional explanation (plain text).
 * - `clientReference` is the client-generated idempotency key (a UUID).
 */
final readonly class ChangeRequestSubmission
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public ChangeRequestType $type,
        public array $input,
        public ?string $reason = null,
        public ?string $clientReference = null,
    ) {}
}
