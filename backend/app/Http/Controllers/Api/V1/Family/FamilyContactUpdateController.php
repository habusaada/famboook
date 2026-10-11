<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\ContactUpdate\CompleteContactUpdateAction;
use App\Actions\ContactUpdate\ResendContactUpdateCodeAction;
use App\Actions\ContactUpdate\StartContactUpdateAction;
use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Requests\Api\V1\Family\CompleteContactUpdateRequest;
use App\Http\Requests\Api\V1\Family\ResendContactUpdateCodeRequest;
use App\Http\Requests\Api\V1\Family\StartContactUpdateRequest;
use App\Models\ChangeRequest;
use App\Support\ContactUpdate\ContactUpdateVerification;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The household head's mobile CONTACT_UPDATE (docs/11 FP-ADR-079). The
 * Family is ONLY the family.context Family; a request is looked up inside it
 * (another Family's uuid is not found). The flow's Domain Actions do the
 * work; responses carry the request's identity and status and the MASKED
 * code destinations with their timers — never a number, a code or a
 * challenge reference. Every response is no-store, private.
 */
class FamilyContactUpdateController extends Controller
{
    public function store(StartContactUpdateRequest $request, StartContactUpdateAction $start): JsonResponse
    {
        $result = $start->handle(
            EnsureFamilyContext::context($request),
            $request->contactInput(),
            $request->validated('current_password'),
            $request->validated('client_reference'),
        );

        return self::json([
            ...self::identity($result['request'], $result['outcome']->replayed),
            'verification' => ContactUpdateVerification::state($result['request']->refresh()),
        ], $result['outcome']->replayed ? 200 : 201);
    }

    public function show(Request $request, string $contactUpdate): JsonResponse
    {
        $found = $this->find(EnsureFamilyContext::context($request), $contactUpdate);

        return self::json([...self::identity($found, false), 'verification' => ContactUpdateVerification::state($found)]);
    }

    public function complete(CompleteContactUpdateRequest $request, string $contactUpdate, CompleteContactUpdateAction $complete): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        $result = $complete->handle($context, $this->find($context, $contactUpdate), (string) $request->validated('code'), $request->validated('old_code'));

        return self::json([
            ...self::identity($result['outcome']->request, $result['outcome']->replayed),
            'trust_transferred' => $result['trust_transferred'],
        ]);
    }

    public function resend(ResendContactUpdateCodeRequest $request, string $contactUpdate, ResendContactUpdateCodeAction $resend): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        $found = $resend->handle($context, $this->find($context, $contactUpdate), (string) $request->validated('target'));

        return self::json([...self::identity($found->refresh(), false), 'verification' => ContactUpdateVerification::state($found)]);
    }

    /** One of the context Family's CONTACT_UPDATE requests by uuid; anything else is not found. */
    private function find(FamilyAccessResult $context, string $uuid): ChangeRequest
    {
        $found = Str::isUuid($uuid)
            ? ChangeRequest::query()->where('family_id', $context->family->getKey())->where('type', ChangeRequestType::CONTACT_UPDATE->value)->where('uuid', $uuid)->first()
            : null;

        return $found ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
    }

    /** @return array<string, mixed> */
    private static function identity(ChangeRequest $request, bool $replayed): array
    {
        return ['id' => $request->uuid, 'request_code' => $request->request_code, 'status' => $request->status, 'replayed' => $replayed];
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'no-store, private');
    }
}
