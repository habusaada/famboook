<?php

namespace App\Support\ChangeRequests;

use App\Models\ChangeRequest;

/**
 * The result of a Change Request Domain Action (PWA-5b). `replayed` is true
 * when the same operation had already happened — a retried submission with
 * the same client_reference, or a transition the same actor already made —
 * and nothing new was written (no event, no activity, no registry change).
 */
final readonly class ChangeRequestOutcome
{
    public function __construct(
        public ChangeRequest $request,
        public bool $replayed = false,
    ) {}
}
