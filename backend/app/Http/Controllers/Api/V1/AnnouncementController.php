<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\CancelPublicationAction;
use App\Actions\Notifications\CreateAnnouncementDraftAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Actions\Notifications\RetryPublicationAction;
use App\Enums\NotificationSenderSide;
use App\Exceptions\NotificationAudienceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notifications\AnnouncementIndexRequest;
use App\Http\Requests\Api\V1\Notifications\AudiencePreviewRequest;
use App\Http\Requests\Api\V1\Notifications\CancelAnnouncementRequest;
use App\Http\Requests\Api\V1\Notifications\StoreAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\NotificationMessage;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\NotificationAudienceResolver;
use App\Support\Notifications\NotificationOutcome;
use App\Support\Notifications\SenderAnnouncements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Announcement management (FB-NOTIFY-01B.2b.6c), shared by the Staff and the
 * Coordinator API: the account's OWN announcements only, through the
 * lifecycle Domain Actions — which re-check the account side, the
 * permission, a coordinator's CURRENT context, ownership, readiness and the
 * audience; routes only ask earlier. Nothing here publishes: /publish and
 * /retry queue the message (202) for the worker.
 *
 * A message is looked up among the account's own announcements by uuid —
 * never route-model bound — so another sender's message is 404, exactly
 * like a nonexistent one. Every response is no-store, private.
 */
abstract class AnnouncementController extends Controller
{
    public function __construct(protected readonly SenderAnnouncements $announcements) {}

    /** The sender side of this API. */
    abstract protected function side(): NotificationSenderSide;

    /**
     * The recipient statistics this account may see for its message and
     * their basis — both NULL before publication. Staff: every recipient of
     * their own announcement (ALL_RECIPIENTS).
     *
     * @return array{0: string|null, 1: array<string, int|null>|null}
     */
    protected function statistics(Request $request, NotificationMessage $message): array
    {
        $statistics = $this->announcements->statistics($message);

        return [$statistics === null ? null : 'ALL_RECIPIENTS', $statistics];
    }

    public function index(AnnouncementIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $page = $this->announcements->own($request->user(), $this->side())
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->paginate((int) ($filters['per_page'] ?? 20));

        return AnnouncementResource::collection($page)->response()->header('Cache-Control', 'no-store, private');
    }

    /**
     * How many families this audience reaches NOW (docs/11 §22: shown before
     * sending). The same side, scope, send-all and eligibility rules as a
     * draft; an empty audience is a count of 0, not an error. Counts only.
     */
    public function preview(AudiencePreviewRequest $request, NotificationAudienceResolver $resolver): JsonResponse
    {
        $sender = NotificationActors::sender($request->user(), send: false);
        $audience = NotificationAudience::fromArray($request->validated('audience'));
        if ($audience->type->senderSide() !== $sender->side) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }
        try {
            $resolver->validateRequest($sender->user, $audience);
        } catch (NotificationAudienceException $e) {
            if ($e->reason !== NotificationAudienceException::EMPTY) {
                throw $e;
            }
        }

        return response()->json(['data' => ['recipient_count' => $resolver->families($sender->user, $audience)->count()]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreAnnouncementRequest $request, CreateAnnouncementDraftAction $action): JsonResponse
    {
        $outcome = $action->handle($request->user(), $request->draftInput());

        return $this->outcome($request, $outcome, $outcome->replayed ? 200 : 201);
    }

    public function show(Request $request, string $notification): JsonResponse
    {
        return $this->detail($request, $this->own($request, $notification));
    }

    public function publish(Request $request, string $notification, RequestPublicationAction $action): JsonResponse
    {
        $outcome = $action->handle($request->user(), $this->own($request, $notification));

        return $this->outcome($request, $outcome, $outcome->replayed ? 200 : 202);
    }

    public function cancel(CancelAnnouncementRequest $request, string $notification, CancelPublicationAction $action): JsonResponse
    {
        $outcome = $action->handle($request->user(), $this->own($request, $notification), $request->validated('reason_code'));

        return $this->outcome($request, $outcome, 200);
    }

    public function retry(Request $request, string $notification, RetryPublicationAction $action): JsonResponse
    {
        $outcome = $action->handle($request->user(), $this->own($request, $notification));

        return $this->outcome($request, $outcome, $outcome->replayed ? 200 : 202);
    }

    private function own(Request $request, string $uuid): NotificationMessage
    {
        return $this->announcements->find($request->user(), $this->side(), $uuid);
    }

    private function outcome(Request $request, NotificationOutcome $outcome, int $status): JsonResponse
    {
        return $this->detail($request, $outcome->message->fresh(), ['replayed' => $outcome->replayed])->setStatusCode($status);
    }

    /** @param  array<string, bool>  $meta */
    private function detail(Request $request, NotificationMessage $message, array $meta = []): JsonResponse
    {
        [$basis, $statistics] = $this->statistics($request, $message);

        return (new AnnouncementResource($message))->detail($basis, $statistics)
            ->additional($meta === [] ? [] : ['meta' => $meta])
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
