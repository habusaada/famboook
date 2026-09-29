<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConfirmImportMappingAction;
use App\Actions\CreateImportBatchAction;
use App\Actions\ReconcileImportBatchAction;
use App\Actions\ReplaceImportWorkbookAction;
use App\Actions\ResolveFamilyKeyAction;
use App\Actions\SelectImportWorksheetAction;
use App\Enums\FamilyKeyDecision;
use App\Enums\ImportMode;
use App\Enums\ImportReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\Clan;
use App\Models\ImportBatch;
use App\Support\Import\ImportBatchWorkbook;
use App\Support\Import\InitialFamilyImportSummary;
use App\Support\Import\InitialFamilyWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Import Wizard (docs/03 §96a, docs/06 §61/§84):
 *   upload / replace workbook / select worksheet   import.upload
 *   columns (+ masked samples) / confirm mapping    import.validate (staging)
 *   list / show / family keys / problem rows        import.review
 * No row payloads are returned and there is NO Apply endpoint.
 */
class InitialFamilyImportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ImportBatchResource::collection(
            ImportBatch::query()->with(['clan', 'uploader'])->latest('id')->limit(50)->get()
        );
    }

    public function store(CreateImportBatchRequest $request, CreateImportBatchAction $action): JsonResponse
    {
        $clan = Clan::where('code', $request->validated('clan_code'))->firstOrFail();
        $batch = $action->handle($clan, ImportMode::from($request->validated('import_mode')), $request->file('file'), $request->user()->id);

        return $this->detail($batch, 201);
    }

    public function replaceFile(CreateImportBatchRequest $request, ImportBatch $importBatch, ReplaceImportWorkbookAction $action): JsonResponse
    {
        return $this->detail($action->handle($importBatch, $request->file('file')));
    }

    public function selectWorksheet(Request $request, ImportBatch $importBatch, SelectImportWorksheetAction $action): JsonResponse
    {
        $data = $request->validate(['worksheet' => ['required', 'string', 'max:255']], ['worksheet.required' => 'اختر ورقة العمل.']);

        return $this->detail($action->handle($importBatch, $data['worksheet']));
    }

    /**
     * The selected sheet's columns for the mapping step: stable identity
     * (letter + position + header), suggestion, masked samples. Excluded
     * columns are listed separately by header only — no samples, not mappable.
     */
    public function columns(ImportBatch $importBatch): JsonResponse
    {
        $sheet = ImportBatchWorkbook::sheet($importBatch);
        if ($sheet === null) {
            throw ValidationException::withMessages(['worksheet' => 'اختر ورقة العمل أولًا.']);
        }
        $samples = InitialFamilyWorkbook::samples(ImportBatchWorkbook::path($importBatch), $importBatch->worksheet_name);

        $columns = [];
        $excluded = [];
        foreach ($sheet['columns'] as $column) {
            if ($column['excluded']) {
                $excluded[] = ['letter' => $column['letter'], 'header' => $column['header']];

                continue;
            }
            $columns[] = [
                'letter' => $column['letter'],
                'position' => $column['position'],
                'header' => $column['header'],
                'suggested_field' => $column['suggested_field'],
                'samples' => $samples[$column['letter']] ?? [],
            ];
        }

        return response()->json(['data' => [
            'worksheet' => $importBatch->worksheet_name,
            'fields' => array_map(fn (string $field) => [
                'field' => $field,
                'required' => in_array($field, InitialFamilyWorkbook::REQUIRED_FIELDS, true),
            ], InitialFamilyWorkbook::fields()),
            'columns' => $columns,
            'excluded_columns' => $excluded,
            'confirmed_mapping' => $importBatch->column_mapping,
        ]]);
    }

    public function confirmMapping(Request $request, ImportBatch $importBatch, ConfirmImportMappingAction $action): JsonResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:3'],
            'ignored' => ['present', 'array'],
            'ignored.*' => ['string', 'max:3'],
        ]);

        return $this->detail($action->handle($importBatch, $data['mapping'], $data['ignored']));
    }

    /** Step 5: compare staged rows with the registry — never a registry write (import.validate). */
    public function reconcile(Request $request, ImportBatch $importBatch, ReconcileImportBatchAction $action): JsonResponse
    {
        return $this->detail($action->handle($importBatch, $request->user()));
    }

    /** Reconciliation rows (masked IDs, codes, differences) — import.review. */
    public function reconciliationRows(Request $request, ImportBatch $importBatch): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(ImportReconciliationStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json(InitialFamilyImportSummary::reconciliationRows($importBatch, $data['status'] ?? null, (int) ($data['page'] ?? 1)));
    }

    public function show(ImportBatch $importBatch): JsonResponse
    {
        return $this->detail($importBatch);
    }

    /**
     * Step 4: every distinct source key with its discovery facts, exact-name
     * SUGGESTION and saved decision; meta carries the authoritative progress,
     * the target Clan and its selectable Branches.
     */
    public function familyKeys(Request $request, ImportBatch $importBatch): JsonResponse
    {
        $importBatch->loadMissing('clan');

        return response()->json([
            'data' => InitialFamilyImportSummary::familyKeys($importBatch),
            'meta' => [
                'resolution' => InitialFamilyImportSummary::keyResolution($importBatch),
                'clan' => ['code' => $importBatch->clan->code, 'name' => $importBatch->clan->name, 'is_active' => $importBatch->clan->is_active],
                'branches' => InitialFamilyImportSummary::selectableBranches($importBatch),
                // UX hint only; the action enforces clan.manage itself.
                'can_create_branch' => $request->user()->can('clan.manage'),
            ],
        ]);
    }

    /** One decision for one exact source key (import.review; creating a Branch also needs clan.manage). */
    public function resolveFamilyKey(Request $request, ImportBatch $importBatch, ResolveFamilyKeyAction $action): JsonResponse
    {
        $data = $request->validate([
            'source_family_key' => ['required', 'string', 'max:150'],
            'decision' => ['required', Rule::enum(FamilyKeyDecision::class)],
            'branch_id' => ['nullable', 'uuid', 'required_if:decision,MATCH_EXISTING_BRANCH'],
            'branch' => ['nullable', 'array', 'required_if:decision,CREATE_NEW_BRANCH'],
            'branch.name' => ['nullable', 'string', 'max:150'],
            'branch.code' => ['nullable', 'string', 'max:50'],
            'same_as_key' => ['nullable', 'string', 'max:150', 'required_if:decision,SAME_BRANCH_AS_KEY'],
        ], ['decision.required' => 'اختر القرار.']);

        $action->handle($importBatch, $data['source_family_key'], FamilyKeyDecision::from($data['decision']), $data, $request->user());

        return $this->familyKeys($request, $importBatch->fresh());
    }

    /** Back to UNRESOLVED before Apply (a Branch it created is kept). */
    public function clearFamilyKey(Request $request, ImportBatch $importBatch, ResolveFamilyKeyAction $action): JsonResponse
    {
        $data = $request->validate(['source_family_key' => ['required', 'string', 'max:150']]);
        $action->clear($importBatch, $data['source_family_key']);

        return $this->familyKeys($request, $importBatch->fresh());
    }

    /**
     * Explicitly selected keys only: CREATE_NEW_BRANCH (name + code per key,
     * reviewed in a preview; needs clan.manage) or NO_BRANCH. All or nothing.
     */
    public function bulkResolveFamilyKeys(Request $request, ImportBatch $importBatch, ResolveFamilyKeyAction $action): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([FamilyKeyDecision::CREATE_NEW_BRANCH->value, FamilyKeyDecision::NO_BRANCH->value])],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.source_family_key' => ['required', 'string', 'max:150', 'distinct'],
            'items.*.name' => ['nullable', 'string', 'max:150'],
            'items.*.code' => ['nullable', 'string', 'max:50'],
        ]);

        $data['decision'] === FamilyKeyDecision::CREATE_NEW_BRANCH->value
            ? $action->bulkCreate($importBatch, $data['items'], $request->user())
            : $action->bulkNoBranch($importBatch, array_column($data['items'], 'source_family_key'), $request->user());

        return $this->familyKeys($request, $importBatch->fresh());
    }

    public function problemRows(Request $request, ImportBatch $importBatch): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['sometimes', 'in:all,needs_review,rejected'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json(InitialFamilyImportSummary::problemRows($importBatch, $data['filter'] ?? 'all', (int) ($data['page'] ?? 1)));
    }

    private function detail(ImportBatch $batch, int $status = 200): JsonResponse
    {
        $batch->load(['clan', 'uploader']);

        return response()->json(['data' => [
            ...(new ImportBatchResource($batch))->resolve(request()),
            'summary' => InitialFamilyImportSummary::summary($batch),
        ]], $status);
    }
}
