<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\Notifications\AcknowledgeNotificationAction;
use App\Actions\Notifications\RecordNotificationViewAction;
use App\Exceptions\NotificationInboxException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Requests\Api\V1\Family\FamilyNotificationIndexRequest;
use App\Http\Resources\FamilyNotificationResource;
use App\Models\NotificationRecipient;
use App\Support\Notifications\FamilyNotificationInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The household head's notifications (FB-NOTIFY-01B.2b.6b; ADR FB-NOTIFY-01
 * ADR-03, ADR-05, ADR-07; docs/11 §21). The Family is ONLY the
 * family.context Family — never a route, query or body value. A notification
 * is found through that Family's own recipient row by the message uuid
 * (never route-model bound), so another family's notification is simply not
 * found (404). Reading never records a view: /view and /acknowledge are
 * explicit POSTs. Every response is no-store, private.
 */
class FamilyNotificationController extends Controller
{
    public function __construct(private readonly FamilyNotificationInbox $inbox) {}

    /** The active inbox: PUBLISHED and not yet expired, newest first, with the unread count. */
    public function index(FamilyNotificationIndexRequest $request): JsonResponse
    {
        $familyId = $this->familyId($request);

        return $this->page($this->inbox->active($familyId), $request, ['unread_count' => $this->inbox->unreadCount($familyId)]);
    }

    /** The archive: ARCHIVED, or expired before the sweep archived it. */
    public function archive(FamilyNotificationIndexRequest $request): JsonResponse
    {
        return $this->page($this->inbox->archive($this->familyId($request)), $request);
    }

    /** One notification's details — reading only; it records no view. */
    public function show(Request $request, string $notification): JsonResponse
    {
        $found = Str::isUuid($notification) ? $this->inbox->find($this->familyId($request), $notification) : null;
        if ($found === null) {
            throw new NotificationInboxException(NotificationInboxException::NOT_FOUND);
        }

        return $this->one($found);
    }

    public function view(Request $request, string $notification, RecordNotificationViewAction $action): JsonResponse
    {
        [$recipient, $recorded] = $action->handle(EnsureFamilyContext::context($request), $notification);

        return $this->one($recipient, replayed: ! $recorded);
    }

    public function acknowledge(Request $request, string $notification, AcknowledgeNotificationAction $action): JsonResponse
    {
        [$recipient, $recorded] = $action->handle(EnsureFamilyContext::context($request), $notification);

        return $this->one($recipient, replayed: ! $recorded);
    }

    private function familyId(Request $request): int
    {
        return (int) EnsureFamilyContext::context($request)->family->getKey();
    }

    /**
     * @param  Builder<NotificationRecipient>  $query
     * @param  array<string, int>  $meta
     */
    private function page(Builder $query, FamilyNotificationIndexRequest $request, array $meta = []): JsonResponse
    {
        $page = $query->paginate((int) ($request->validated('per_page') ?? 20));

        return FamilyNotificationResource::collection($page)
            ->additional(['meta' => $meta])
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    private function one(NotificationRecipient $recipient, ?bool $replayed = null): JsonResponse
    {
        return (new FamilyNotificationResource($recipient))->withBody()
            ->additional($replayed === null ? [] : ['meta' => ['replayed' => $replayed]])
            ->response()
            ->setStatusCode(200)
            ->header('Cache-Control', 'no-store, private');
    }
}
