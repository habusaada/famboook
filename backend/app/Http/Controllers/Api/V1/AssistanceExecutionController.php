<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ApproveBeneficiariesAction;
use App\Actions\MarkNotDeliveredAction;
use App\Actions\RecordDeliveryAction;
use App\Actions\RejectBeneficiaryAction;
use App\Actions\ReverseDeliveryAction;
use App\Enums\ReceiptMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApproveBeneficiariesRequest;
use App\Http\Requests\Api\V1\DeliveryRequest;
use App\Http\Requests\Api\V1\NotDeliveredRequest;
use App\Http\Requests\Api\V1\RejectBeneficiaryRequest;
use App\Http\Requests\Api\V1\ReverseDeliveryRequest;
use App\Http\Resources\AssistanceNomineeResource;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\AssistanceDelivery;
use App\Models\AssistanceItem;
use App\Support\DeliveryVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assistance V1-B execution: approval/rejection (both modes) and INTERNAL
 * identity-verified delivery, non-delivery and reversal. National IDs are
 * only accepted in request bodies, compared, and never stored or returned.
 */
class AssistanceExecutionController extends Controller
{
    public function approve(Request $request, Assistance $assistance, AssistanceBeneficiary $nominee, ApproveBeneficiariesAction $action): AssistanceNomineeResource
    {
        $this->belongs($assistance, $nominee);
        $action->handle($assistance, [$nominee->uuid], $request->user()?->id);

        return $this->nominee($nominee);
    }

    public function bulkApprove(ApproveBeneficiariesRequest $request, Assistance $assistance, ApproveBeneficiariesAction $action): JsonResponse
    {
        return response()->json([
            'data' => ['approved' => $action->handle($assistance, $request->validated('nominee_ids'), $request->user()?->id)],
        ]);
    }

    public function reject(RejectBeneficiaryRequest $request, Assistance $assistance, AssistanceBeneficiary $nominee, RejectBeneficiaryAction $action): AssistanceNomineeResource
    {
        $this->belongs($assistance, $nominee);

        return $this->nominee($action->handle($assistance, $nominee, $request->validated('rejection_reason'), $request->user()?->id));
    }

    /**
     * Checks identity for the given receipt mode without writing anything,
     * and returns a minimal summary for the staff member to confirm. No
     * National ID is echoed back.
     */
    public function verifyDelivery(DeliveryRequest $request, Assistance $assistance, AssistanceBeneficiary $nominee): JsonResponse
    {
        $this->belongs($assistance, $nominee);
        $mode = ReceiptMode::from($request->validated('receipt_mode'));

        $verified = DeliveryVerification::verify(
            $assistance,
            $nominee,
            $mode,
            $request->validated('beneficiary_national_id'),
            $request->validated('delegate_national_id'),
        );

        return response()->json(['data' => [
            'receipt_mode' => $mode,
            'beneficiary' => ['person_code' => $verified['original']->person_code, 'full_name' => $verified['original']->full_name],
            'recipient' => ['person_code' => $verified['recipient']->person_code, 'full_name' => $verified['recipient']->full_name],
            'relationship' => $verified['relationship'],
            'recipient_marital_status' => $mode === ReceiptMode::DELEGATE ? $verified['recipient']->marital_status : null,
            // The full planned package, received as a whole.
            'package' => AssistanceItem::where('assistance_id', $assistance->id)->orderBy('sort_order')->get()
                ->map(fn ($i) => [
                    'item_name' => $i->item_name,
                    'quantity_per_beneficiary' => $i->quantity_per_beneficiary === null ? null : rtrim(rtrim($i->quantity_per_beneficiary, '0'), '.'),
                    'unit' => $i->unit,
                ]),
        ]]);
    }

    public function deliver(DeliveryRequest $request, Assistance $assistance, AssistanceBeneficiary $nominee, RecordDeliveryAction $action): JsonResponse
    {
        $this->belongs($assistance, $nominee);

        $action->handle(
            $assistance,
            $nominee,
            ReceiptMode::from($request->validated('receipt_mode')),
            $request->validated('beneficiary_national_id'),
            $request->validated('delegate_national_id'),
            $request->validated('notes'),
            $request->user()?->id,
        );

        return $this->nominee($nominee)->response()->setStatusCode(201);
    }

    public function notDelivered(NotDeliveredRequest $request, Assistance $assistance, AssistanceBeneficiary $nominee, MarkNotDeliveredAction $action): AssistanceNomineeResource
    {
        $this->belongs($assistance, $nominee);

        return $this->nominee($action->handle($assistance, $nominee, $request->validated('not_delivered_reason'), $request->user()?->id));
    }

    public function reverse(ReverseDeliveryRequest $request, AssistanceDelivery $delivery, ReverseDeliveryAction $action): AssistanceNomineeResource
    {
        $delivery = $action->handle($delivery, $request->validated('reversal_reason'), $request->user()?->id);

        return $this->nominee($delivery->beneficiary);
    }

    private function belongs(Assistance $assistance, AssistanceBeneficiary $nominee): void
    {
        abort_unless($nominee->assistance_id === $assistance->id, 404);
    }

    private function nominee(AssistanceBeneficiary $nominee): AssistanceNomineeResource
    {
        return new AssistanceNomineeResource($nominee->fresh()->load(AssistanceNomineeResource::RELATIONS));
    }
}
