<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestApplyFailure;
use App\Enums\ChangeRequestStatus;
use App\Enums\FamilyActivityType;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staff apply an APPROVED request to the canonical registry (docs/04 §40–§43,
 * docs/05 §64–§69): the ONLY way a Change Request changes registry data.
 *
 * One transaction, in the lock order request → Family → Person:
 * status APPROVED → actor → handler → fresh canonical rows → data rules and
 * preconditions → base fingerprint → handler.apply() (existing canonical
 * Domain Actions only, each recording its own FamilyActivity) → APPLIED with
 * applied_by / applied_at → APPLIED event → CHANGE_REQUEST_APPLIED activity.
 * The registry mutation and APPLIED commit together or not at all.
 *
 * Already APPLIED: a replay — nothing runs again, nothing is written.
 *
 * Failure: the whole transaction rolls back (registry unchanged, request
 * still APPROVED, no APPLIED event or activity). Then, in a separate small
 * transaction, the still-APPROVED request gets apply_failure_count + 1,
 * last_apply_failed_at and an APPLY_FAILED event (APPROVED → APPROVED) with a
 * safe ChangeRequestApplyFailure code only, and a typed exception is thrown:
 * a refusal (BASE_CHANGED / PRECONDITION_FAILED / NOT_APPLICABLE) keeps its
 * code; anything unexpected becomes CHANGE_REQUEST_APPLY_FAILED (logged with
 * the request uuid and the exception class only — never its message, which
 * may carry SQL or values). A retry runs the whole path again from scratch.
 * Refusals before the actor is authorized (transition, actor, type) record
 * nothing.
 *
 * Call it outside any surrounding transaction: an enclosing rollback would
 * also undo the failure record.
 */
class ApplyChangeRequestAction
{
    public function __construct(private readonly ChangeRequestTypes $types) {}

    public function handle(ChangeRequest $request, User $staff): ChangeRequestOutcome
    {
        ChangeRequestActors::staff($staff, ChangeRequestTransitions::assertAllowed(ChangeRequestStatus::APPROVED, ChangeRequestStatus::APPLIED));
        $attempted = false;

        try {
            return DB::transaction(function () use ($request, $staff, &$attempted) {
                $locked = ChangeRequestWorkflow::lock($request);
                if ($locked->status === ChangeRequestStatus::APPLIED) {
                    return new ChangeRequestOutcome($locked, replayed: true);
                }
                if ($locked->status !== ChangeRequestStatus::APPROVED) {
                    throw ChangeRequestWorkflow::invalidTransition();
                }
                $handler = $this->types->handler($locked->type);
                $attempted = true;

                $target = ChangeRequestWorkflow::lockCanonical($locked);
                ChangeRequestWorkflow::verify($handler, $locked, $target);
                ChangeRequestWorkflow::assertBaseUnchanged($handler, $locked, $target);

                $handler->apply($locked, $target, $staff->getKey());

                $locked->forceFill(['status' => ChangeRequestStatus::APPLIED, 'applied_by' => $staff->getKey(), 'applied_at' => now()])->save();
                WorkflowEventRecorder::record($locked, ChangeRequestStatus::APPROVED, WorkflowEventType::APPLIED, WorkflowActorSide::STAFF, $staff->getKey());
                FamilyActivityLog::record($locked->family_id, FamilyActivityType::CHANGE_REQUEST_APPLIED, $locked, $staff->getKey(), [
                    'request_type' => $locked->type->value,
                ]);

                return new ChangeRequestOutcome($locked);
            });
        } catch (ChangeRequestException $e) {
            $failure = match ($e->reason) {
                ChangeRequestException::BASE_CHANGED => ChangeRequestApplyFailure::BASE_CHANGED,
                ChangeRequestException::PRECONDITION_FAILED => ChangeRequestApplyFailure::PRECONDITION_FAILED,
                ChangeRequestException::NOT_APPLICABLE => ChangeRequestApplyFailure::NOT_APPLICABLE,
                default => null,
            };
            if ($attempted && $failure !== null) {
                $this->recordFailure($request, $staff, $failure);
            } elseif ($attempted) {
                // Any other refusal raised by a handler or a Domain Action is unexpected here.
                $this->recordFailure($request, $staff, ChangeRequestApplyFailure::APPLY_FAILED);
                throw new ChangeRequestException(ChangeRequestException::APPLY_FAILED);
            }
            throw $e;
        } catch (ValidationException) {
            // A canonical Domain Action refused the stored proposal.
            if ($attempted) {
                $this->recordFailure($request, $staff, ChangeRequestApplyFailure::PRECONDITION_FAILED);
            }
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        } catch (Throwable $e) {
            Log::error('Change request apply failed.', ['change_request' => $request->uuid, 'exception' => $e::class]);
            if ($attempted) {
                $this->recordFailure($request, $staff, ChangeRequestApplyFailure::APPLY_FAILED);
            }
            throw new ChangeRequestException(ChangeRequestException::APPLY_FAILED);
        }
    }

    /** After the rollback: count the failed attempt on the still-APPROVED request. */
    private function recordFailure(ChangeRequest $request, User $staff, ChangeRequestApplyFailure $failure): void
    {
        DB::transaction(function () use ($request, $staff, $failure) {
            $locked = ChangeRequestWorkflow::lock($request);
            if ($locked->status !== ChangeRequestStatus::APPROVED) {
                return;
            }
            $locked->forceFill([
                'apply_failure_count' => $locked->apply_failure_count + 1,
                'last_apply_failed_at' => now(),
            ])->save();
            WorkflowEventRecorder::record($locked, ChangeRequestStatus::APPROVED, WorkflowEventType::APPLY_FAILED, WorkflowActorSide::STAFF,
                $staff->getKey(), reason: $failure);
        });
    }
}
