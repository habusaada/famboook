<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestAudience;
use App\Models\User;

/**
 * Who a Change Request presentation is for (PWA-6.1, docs/11 FP-ADR-073
 * prerequisite). Computed ONLY on the server from the authenticated actor
 * and the route side — never from client input — and handed to the
 * handler's present(), which shapes and masks its rows accordingly.
 *
 * - canViewSensitiveIdentity: full identity values (e.g. a National ID).
 *   No permission grants it yet, so it is false for every viewer: being
 *   Staff is NOT enough. A later approved permission sets it here, and
 *   only here.
 * - canViewInternalNotes: Staff holding change-request.view-internal-notes
 *   (the same rule as WorkflowEventResource); never a family.
 * - canViewMaskedIdentity: a masked National ID (`*****6789`): Staff with
 *   person.national-id.view-masked (docs/06 §39); the family for what it
 *   submitted itself (FP-ADR-062 masks its own members' IDs the same way).
 * - canViewPersonNames: names of OTHER registry Persons (e.g. an existing
 *   Person a proposal matches): Staff with person.view; never a family.
 */
final readonly class ChangeRequestPresentationContext
{
    private function __construct(
        public ChangeRequestAudience $audience,
        public bool $canViewSensitiveIdentity,
        public bool $canViewInternalNotes,
        public bool $canViewMaskedIdentity = false,
        public bool $canViewPersonNames = false,
    ) {}

    /** The Staff review view, for the authenticated Staff user. */
    public static function staff(?User $user): self
    {
        return new self(
            ChangeRequestAudience::STAFF,
            canViewSensitiveIdentity: false,
            canViewInternalNotes: $user?->can('change-request.view-internal-notes') === true,
            canViewMaskedIdentity: $user?->can('person.national-id.view-masked') === true,
            canViewPersonNames: $user?->can('person.view') === true,
        );
    }

    /** The Family Portal view: the family's own request, nothing Staff-only. */
    public static function family(): self
    {
        return new self(ChangeRequestAudience::FAMILY, canViewSensitiveIdentity: false, canViewInternalNotes: false, canViewMaskedIdentity: true);
    }

    public function isStaff(): bool
    {
        return $this->audience === ChangeRequestAudience::STAFF;
    }
}
