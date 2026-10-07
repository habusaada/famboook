<?php

namespace Tests\Unit\ChangeRequests;

use App\Enums\ChangeRequestRejectionReason as R;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType as E;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The Change Request state machine (PWA-5a, WF-ADR-049): exactly the approved
 * transitions, nothing else.
 */
class ChangeRequestTransitionsTest extends TestCase
{
    /** from|to => [event, side, permission] — the complete approved table. */
    private const APPROVED = [
        'NEW|SUBMITTED' => [E::SUBMITTED, WorkflowActorSide::FAMILY, 'change-request.submit'],
        'DRAFT|SUBMITTED' => [E::SUBMITTED, WorkflowActorSide::FAMILY, 'change-request.submit'],
        'SUBMITTED|UNDER_REVIEW' => [E::REVIEW_STARTED, WorkflowActorSide::STAFF, 'change-request.review'],
        'SUBMITTED|CANCELLED' => [E::CANCELLED, WorkflowActorSide::FAMILY, 'change-request.cancel'],
        'UNDER_REVIEW|RETURNED_FOR_CLARIFICATION' => [E::RETURNED, WorkflowActorSide::STAFF, 'change-request.return'],
        'UNDER_REVIEW|APPROVED' => [E::APPROVED, WorkflowActorSide::STAFF, 'change-request.approve'],
        'UNDER_REVIEW|REJECTED' => [E::REJECTED, WorkflowActorSide::STAFF, 'change-request.reject'],
        'UNDER_REVIEW|CANCELLED' => [E::CANCELLED, WorkflowActorSide::FAMILY, 'change-request.cancel'],
        'RETURNED_FOR_CLARIFICATION|RESUBMITTED' => [E::RESUBMITTED, WorkflowActorSide::FAMILY, 'change-request.resubmit'],
        'RETURNED_FOR_CLARIFICATION|CANCELLED' => [E::CANCELLED, WorkflowActorSide::FAMILY, 'change-request.cancel'],
        'RESUBMITTED|UNDER_REVIEW' => [E::REVIEW_STARTED, WorkflowActorSide::STAFF, 'change-request.review'],
        'RESUBMITTED|CANCELLED' => [E::CANCELLED, WorkflowActorSide::FAMILY, 'change-request.cancel'],
        'APPROVED|APPLIED' => [E::APPLIED, WorkflowActorSide::STAFF, 'change-request.apply'],
        'APPROVED|REJECTED' => [E::REJECTED, WorkflowActorSide::STAFF, 'change-request.reject'],
    ];

    private static function key(?S $from, S $to): string
    {
        return ($from->value ?? 'NEW').'|'.$to->value;
    }

    public function test_the_table_is_exactly_the_approved_transitions(): void
    {
        $actual = [];
        foreach (ChangeRequestTransitions::all() as $t) {
            $actual[self::key($t->from, $t->to)] = [$t->event, $t->actorSide, $t->permission];
        }

        $this->assertEquals(self::APPROVED, $actual);
        $this->assertCount(count(self::APPROVED), ChangeRequestTransitions::all(), 'no duplicate pair');
    }

    public function test_every_pair_outside_the_table_is_refused(): void
    {
        foreach ([null, ...S::cases()] as $from) {
            foreach (S::cases() as $to) {
                $expected = array_key_exists(self::key($from, $to), self::APPROVED);
                $this->assertSame($expected, ChangeRequestTransitions::allows($from, $to), self::key($from, $to));
                if (! $expected) {
                    try {
                        ChangeRequestTransitions::assertAllowed($from, $to);
                        $this->fail('Accepted '.self::key($from, $to));
                    } catch (LogicException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
        }
    }

    public function test_terminal_states_have_no_outgoing_transition(): void
    {
        foreach (S::terminal() as $terminal) {
            $this->assertSame([], ChangeRequestTransitions::from($terminal), $terminal->value);
        }
        $this->assertEqualsCanonicalizing([S::APPLIED, S::REJECTED, S::CANCELLED], S::terminal());
    }

    public function test_the_family_may_cancel_until_approval_but_never_after(): void
    {
        foreach ([S::SUBMITTED, S::UNDER_REVIEW, S::RETURNED_FOR_CLARIFICATION, S::RESUBMITTED] as $from) {
            $this->assertSame(WorkflowActorSide::FAMILY, ChangeRequestTransitions::assertAllowed($from, S::CANCELLED)->actorSide);
        }
        foreach ([S::DRAFT, S::APPROVED, S::APPLIED, S::REJECTED, S::CANCELLED] as $from) {
            $this->assertFalse(ChangeRequestTransitions::allows($from, S::CANCELLED), $from->value);
        }
    }

    public function test_approved_to_rejected_accepts_only_no_longer_applicable(): void
    {
        $this->assertSame([R::NO_LONGER_APPLICABLE], ChangeRequestTransitions::find(S::APPROVED, S::REJECTED)->reasons);
        ChangeRequestTransitions::assertAllowed(S::APPROVED, S::REJECTED, R::NO_LONGER_APPLICABLE);

        foreach ([null, ...array_filter(R::cases(), fn (R $r) => $r !== R::NO_LONGER_APPLICABLE)] as $reason) {
            try {
                ChangeRequestTransitions::assertAllowed(S::APPROVED, S::REJECTED, $reason);
                $this->fail('Accepted APPROVED → REJECTED with '.($reason->value ?? 'no reason'));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_rejection_under_review_requires_a_reason_and_other_transitions_take_none(): void
    {
        foreach (R::cases() as $reason) {
            ChangeRequestTransitions::assertAllowed(S::UNDER_REVIEW, S::REJECTED, $reason);
        }
        $this->expectException(LogicException::class);
        ChangeRequestTransitions::assertAllowed(S::UNDER_REVIEW, S::REJECTED);
    }

    public function test_non_rejection_transitions_refuse_a_reason(): void
    {
        foreach (ChangeRequestTransitions::all() as $t) {
            if ($t->to === S::REJECTED) {
                continue;
            }
            $this->assertFalse($t->accepts(R::OTHER), self::key($t->from, $t->to));
            $this->assertTrue($t->accepts(null), self::key($t->from, $t->to));
        }
    }

    public function test_draft_to_submitted_is_kept_but_v1_creation_starts_at_submitted(): void
    {
        $this->assertTrue(ChangeRequestTransitions::allows(S::DRAFT, S::SUBMITTED));
        // Creation (from nothing) can only produce SUBMITTED — never a DRAFT.
        $this->assertSame([S::SUBMITTED], array_map(fn ($t) => $t->to, ChangeRequestTransitions::from(null)));
        $this->assertFalse(ChangeRequestTransitions::allows(null, S::DRAFT));
    }

    public function test_family_transitions_use_family_permissions_and_staff_transitions_staff_permissions(): void
    {
        $family = ['change-request.submit', 'change-request.resubmit', 'change-request.cancel'];
        foreach (ChangeRequestTransitions::all() as $t) {
            $this->assertSame($t->actorSide === WorkflowActorSide::FAMILY, in_array($t->permission, $family, true), self::key($t->from, $t->to));
        }
    }
}
