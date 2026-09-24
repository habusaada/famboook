<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\IssueBeneficiaryListAction;
use App\Actions\UpdateExportConfigurationAction;
use App\Enums\AssistanceStatus;
use App\Enums\BeneficiaryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueBeneficiaryListRequest;
use App\Http\Requests\Api\V1\UpdateExportConfigurationRequest;
use App\Http\Resources\AssistanceBeneficiaryListResource;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\AssistanceBeneficiaryList;
use App\Support\ExportFieldCatalog;
use App\Support\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * EXTERNAL Assistance beneficiary lists (docs/03-BUSINESS-RULES.md §47g-h):
 * requested-field configuration, dynamic preview, immutable issuance,
 * issued-list history and private XLSX download. Values of SENSITIVE
 * fields (e.g. National ID) are only resolved/returned to holders of
 * assistance.export-sensitive, and only inside these endpoints.
 */
class AssistanceExportController extends Controller
{
    /** The supported field catalog and the current configuration. */
    public function fields(Request $request, Assistance $assistance): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'catalog' => ExportFieldCatalog::catalog(),
                'configuration' => $assistance->export_fields ?? [],
                'contains_sensitive' => ExportFieldCatalog::containsSensitive($assistance->export_fields ?? []),
            ],
            'abilities' => [
                // UX hints only; every write is re-authorized by its request.
                'configure' => $assistance->isExternal()
                    && in_array($assistance->status, [AssistanceStatus::DRAFT, AssistanceStatus::OPEN], true)
                    && $user->can('assistance.export'),
                'sensitive' => $user->can('assistance.export-sensitive'),
            ],
        ]);
    }

    public function updateConfiguration(UpdateExportConfigurationRequest $request, Assistance $assistance, UpdateExportConfigurationAction $action): JsonResponse
    {
        $action->handle($assistance, $request->validated('fields'), $request->user()?->id);

        return $this->fields($request, $assistance->fresh());
    }

    /**
     * Current values for APPROVED beneficiaries with the current
     * configuration. Dynamic: writes nothing (no list, no snapshot, no
     * activity).
     */
    public function preview(Request $request, Assistance $assistance): JsonResponse
    {
        abort_unless($assistance->isExternal(), 409, 'الكشوف متاحة فقط للمساعدات ذات التنفيذ الخارجي.');
        $fields = $assistance->export_fields ?? [];
        $this->authorizeExport($request, ExportFieldCatalog::containsSensitive($fields));
        abort_if($fields === [], 422, 'حدد بيانات الكشف المطلوبة أولًا.');

        $beneficiaries = $assistance->beneficiaries()
            ->where('status', BeneficiaryStatus::APPROVED)
            ->with(['person', 'family', 'listEntries.list:id,list_number,issued_at'])
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        $rows = ExportFieldCatalog::resolve($beneficiaries, array_column($fields, 'field_key'), Carbon::today());

        return response()->json([
            'data' => [
                'columns' => $this->columns($fields),
                'contains_sensitive' => ExportFieldCatalog::containsSensitive($fields),
                'row_count' => count($rows),
                'rows' => $beneficiaries->values()->map(fn (AssistanceBeneficiary $b, int $i) => [
                    'beneficiary_id' => $b->uuid,
                    'target' => $b->person_id ? 'person' : 'family',
                    'family_code' => $b->family->family_code,
                    // Earlier issued lists containing this beneficiary.
                    'listed_in' => $b->listEntries->map(fn ($e) => $e->list->list_number)->values(),
                    'values' => $rows[$i],
                ]),
            ],
        ]);
    }

    public function issue(IssueBeneficiaryListRequest $request, Assistance $assistance, IssueBeneficiaryListAction $action): JsonResponse
    {
        $list = $action->handle(
            $assistance,
            $request->validated('beneficiary_ids'),
            $request->validated('recipient_organization'),
            $request->validated('notes'),
            $request->user()?->id,
        );

        return (new AssistanceBeneficiaryListResource($list->load('issuer:id,name')))->response()->setStatusCode(201);
    }

    /** Issued-list history (metadata only). */
    public function index(Assistance $assistance): AnonymousResourceCollection
    {
        return AssistanceBeneficiaryListResource::collection(
            $assistance->beneficiaryLists()->with('issuer:id,name')->get()
        );
    }

    /** The issued rows exactly as sent (from the immutable snapshot). */
    public function show(Request $request, AssistanceBeneficiaryList $list): JsonResponse
    {
        $this->authorizeExport($request, $list->contains_sensitive);
        $list->load(['issuer:id,name', 'entries', 'assistance:id,uuid,title']);

        return response()->json([
            'data' => [
                ...(new AssistanceBeneficiaryListResource($list))->toArray($request),
                'assistance' => ['id' => $list->assistance->uuid, 'title' => $list->assistance->title],
                'rows' => $list->entries->map(fn ($e) => [
                    'row_number' => $e->row_number,
                    'values' => $e->snapshot_data,
                ]),
            ],
        ]);
    }

    /**
     * Private XLSX of the snapshot: exactly the configured columns, labels
     * and order. Built on request from the immutable entries; never stored
     * publicly. The filename carries no personal data.
     */
    public function download(Request $request, AssistanceBeneficiaryList $list): Response
    {
        $this->authorizeExport($request, $list->contains_sensitive);
        $list->load('entries');

        $columns = $list->configuration_snapshot;
        $bytes = XlsxWriter::build(
            $list->list_number,
            array_column($columns, 'column_label'),
            $list->entries->map(fn ($e) => array_map(
                fn ($c) => $e->snapshot_data[$c['field_key']] ?? null,
                $columns
            ))->all(),
        );

        $filename = $list->list_number.'-'.$list->issued_at->format('Y-m-d').'.xlsx';

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeExport(Request $request, bool $sensitive): void
    {
        $user = $request->user();
        abort_unless($user->can('assistance.export'), 403);
        abort_if($sensitive && ! $user->can('assistance.export-sensitive'), 403, 'يتطلب هذا الكشف صلاحية تصدير البيانات الحساسة.');
    }

    /** @param  list<array{field_key: string, column_label: string}>  $fields */
    private function columns(array $fields): array
    {
        return array_map(fn ($f) => [
            'field_key' => $f['field_key'],
            'column_label' => $f['column_label'],
            'classification' => ExportFieldCatalog::classification($f['field_key']),
        ], $fields);
    }
}
