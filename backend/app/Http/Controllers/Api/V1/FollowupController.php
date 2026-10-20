<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\CloseFollowupManuallyAction;
use App\Actions\Notifications\RecordFollowupContactAction;
use App\Exceptions\NotificationFollowupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notifications\FollowupCloseRequest;
use App\Http\Requests\Api\V1\Notifications\FollowupContactRequest;
use App\Http\Resources\FollowupDetailResource;
use App\Http\Resources\FollowupEventResource;
use App\Http\Resources\FollowupResource;
use App\Models\NotificationFollowup;
use App\Models\NotificationRecipientEvent;
use App\Support\Notifications\FollowupInbox;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The follow-up API, shared by the Staff and the coordinator side
 * (FB-NOTIFY-01E.3-F3.2; ADR FB-NOTIFY-01 §2f). Thin: reads go through
 * FollowupInbox, writes through the audited Domain Actions, which re-check
 * everything under their locks. The side defines visibility (Staff: every
 * follow-up; coordinator: the CURRENT scope — anything else is the same 404
 * as a nonexistent uuid), the list and summary queries, and what the detail
 * and the history disclose.
 *
 * Addressed by the follow-up uuid only. Every response is no-store, private.
 * A lock timeout or a resolved deadlock (SQLSTATE 55P03 / 40P01) is 503
 * FOLLOWUP_BUSY with Retry-After: 1 — the transaction rolled back, nothing was
 * written; any other database error is NOT swallowed.
 */
abstract class FollowupController extends Controller
{
    private const RETRYABLE_SQLSTATES = ['55P03', '40P01'];

    public function __construct(
        protected readonly FollowupInbox $inbox,
    ) {}

    /** The visible follow-up (with the inbox's read columns), or NULL — nonexistent and invisible alike. */
    abstract protected function visible(Request $request, string $uuid): ?NotificationFollowup;

    /** @param  array<string, mixed>  $filters */
    abstract protected function listQuery(Request $request, array $filters): Builder;

    /** @return array<string, mixed> */
    abstract protected function summaryCounts(Request $request): array;

    /** @return array<string, mixed> the side's management information for the detail */
    abstract protected function detailExtra(Request $request, NotificationFollowup $followup): array;

    abstract protected function eventResource(Request $request, NotificationFollowup $followup, NotificationRecipientEvent $event): FollowupEventResource;

    protected function list(Request $request, array $filters, int $default = 20): JsonResponse
    {
        $page = $this->listQuery($request, $filters)->paginate((int) ($filters['per_page'] ?? $default));

        return FollowupResource::collection($page)->response()->header('Cache-Control', 'no-store, private');
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summaryCounts($request)])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $followup): JsonResponse
    {
        return $this->detail($request, $this->found($request, $followup));
    }

    public function history(Request $request, string $followup): JsonResponse
    {
        $found = $this->found($request, $followup);
        $events = $this->inbox->history($found)->map(fn (NotificationRecipientEvent $event) => $this->eventResource($request, $found, $event));

        return response()->json(['data' => $events->map->toArray($request)->values()])->header('Cache-Control', 'no-store, private');
    }

    public function contact(FollowupContactRequest $request, RecordFollowupContactAction $action, string $followup): JsonResponse
    {
        $found = $this->found($request, $followup);
        [$event, $replayed] = $this->retryable(fn () => $action->record(
            $request->user(), $found->uuid, $request->validated('outcome'), $request->validated('note'), $request->validated('client_reference'),
        ));

        return response()->json([
            'data' => $this->eventResource($request, $found, $this->inbox->history($found)->firstWhere('id', $event->getKey()) ?? $event)->toArray($request),
            'meta' => ['replayed' => $replayed],
        ], $replayed ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    public function close(FollowupCloseRequest $request, CloseFollowupManuallyAction $action, string $followup): JsonResponse
    {
        $found = $this->found($request, $followup);
        $this->retryable(fn () => $action->handle($request->user(), $found->uuid, $request->validated('reason'), $request->validated('note')));

        return $this->detail($request, $this->found($request, $followup));
    }

    private function detail(Request $request, NotificationFollowup $followup): JsonResponse
    {
        return (new FollowupDetailResource($followup, [
            'contacts' => NotificationRecipientEvent::query()->where('followup_id', $followup->getKey())->where('event_type', 'CONTACT_RECORDED')->count(),
            ...$this->detailExtra($request, $followup),
        ]))->response()->header('Cache-Control', 'no-store, private');
    }

    private function found(Request $request, string $uuid): NotificationFollowup
    {
        return $this->visible($request, $uuid) ?? throw new NotificationFollowupException(NotificationFollowupException::NOT_FOUND);
    }

    /** Runs a write; a lock timeout or deadlock becomes FOLLOWUP_BUSY (nothing was written). */
    private function retryable(Closure $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? (string) $e->getCode();
            if (in_array($state, self::RETRYABLE_SQLSTATES, true)) {
                throw new NotificationFollowupException(NotificationFollowupException::BUSY);
            }
            throw $e;
        }
    }
}
