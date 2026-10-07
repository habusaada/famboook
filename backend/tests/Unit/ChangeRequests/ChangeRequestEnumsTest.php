<?php

namespace Tests\Unit\ChangeRequests;

use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\FingerprintContext;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use PHPUnit\Framework\TestCase;

/**
 * Stored values are part of the data: they never change (PWA-5a).
 */
class ChangeRequestEnumsTest extends TestCase
{
    /** @param class-string<\BackedEnum> $enum */
    private function values(string $enum): array
    {
        return array_map(fn (\BackedEnum $case) => $case->value, $enum::cases());
    }

    public function test_status_values_are_stable(): void
    {
        $this->assertSame(
            ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'RESUBMITTED', 'APPROVED', 'REJECTED', 'APPLIED', 'CANCELLED'],
            $this->values(ChangeRequestStatus::class),
        );
    }

    public function test_open_and_terminal_semantics(): void
    {
        $this->assertSame(
            [ChangeRequestStatus::SUBMITTED, ChangeRequestStatus::UNDER_REVIEW, ChangeRequestStatus::RETURNED_FOR_CLARIFICATION,
                ChangeRequestStatus::RESUBMITTED, ChangeRequestStatus::APPROVED],
            ChangeRequestStatus::open(),
        );
        $this->assertSame([ChangeRequestStatus::APPLIED, ChangeRequestStatus::REJECTED, ChangeRequestStatus::CANCELLED], ChangeRequestStatus::terminal());

        // APPROVED is still open: nothing changed until APPLIED. DRAFT is neither.
        $this->assertTrue(ChangeRequestStatus::APPROVED->isOpen());
        $this->assertFalse(ChangeRequestStatus::APPROVED->isTerminal());
        $this->assertFalse(ChangeRequestStatus::DRAFT->isOpen());
        $this->assertFalse(ChangeRequestStatus::DRAFT->isTerminal());
        foreach (ChangeRequestStatus::cases() as $status) {
            $this->assertFalse($status->isOpen() && $status->isTerminal(), $status->value);
        }
    }

    public function test_type_values_are_the_approved_base_list_only(): void
    {
        $this->assertSame(
            ['CONTACT_UPDATE', 'RESIDENCE_UPDATE', 'PERSON_CORRECTION', 'ADD_FAMILY_MEMBER', 'MEMBERSHIP_CHANGE', 'HOUSEHOLD_HEAD_CHANGE',
                'BIRTH_REPORT', 'DEATH_REPORT', 'MARRIAGE_UPDATE', 'DOCUMENT_UPDATE', 'OTHER'],
            $this->values(ChangeRequestType::class),
        );
        // PFP-008 proposals are not approved and must not exist as codes.
        foreach (['FAMILY_DATA_UPDATE', 'HOUSEHOLD_DECLARATION_UPDATE', 'HEALTH_RECORD_SUBMISSION', 'NEED_SUBMISSION'] as $proposed) {
            $this->assertNull(ChangeRequestType::tryFrom($proposed), $proposed);
        }
    }

    public function test_rejection_reason_event_and_actor_side_values_are_stable(): void
    {
        $this->assertSame(
            ['INSUFFICIENT_INFORMATION', 'CANNOT_VERIFY', 'DATA_ALREADY_CORRECT', 'DUPLICATE_REQUEST', 'DATA_CHANGED', 'NO_LONGER_APPLICABLE', 'OTHER'],
            $this->values(ChangeRequestRejectionReason::class),
        );
        $this->assertSame(
            ['SUBMITTED', 'REVIEW_STARTED', 'RETURNED', 'RESUBMITTED', 'APPROVED', 'REJECTED', 'APPLIED', 'APPLY_FAILED', 'CANCELLED'],
            $this->values(WorkflowEventType::class),
        );
        $this->assertSame(['FAMILY', 'STAFF', 'SYSTEM'], $this->values(WorkflowActorSide::class));
    }

    public function test_the_change_request_base_fingerprint_has_its_own_domain(): void
    {
        $this->assertSame('famboook.change-request.base.v1:', FingerprintContext::CHANGE_REQUEST_BASE->value);
        $prefixes = $this->values(FingerprintContext::class);
        $this->assertSame(count($prefixes), count(array_unique($prefixes)));
    }
}
