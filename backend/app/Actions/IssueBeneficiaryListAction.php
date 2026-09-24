<?php

namespace App\Actions;

use App\Enums\BeneficiaryStatus;
use App\Enums\FamilyActivityType;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\AssistanceBeneficiaryList;
use App\Models\AssistanceBeneficiaryListEntry;
use App\Support\BusinessIdentifier;
use App\Support\ExportFieldCatalog;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues an immutable beneficiary list to the external organization of an
 * EXTERNAL Assistance (permission assistance.export, plus
 * assistance.export-sensitive when sensitive fields are configured).
 *
 * The configuration and, per row, the exact values sent are frozen at
 * issuance; later registry changes never alter them. Issuing a list is NOT
 * a delivery: no AssistanceDelivery is created and nobody is marked
 * delivered. A beneficiary may intentionally appear in several lists (e.g.
 * a corrected list).
 */
class IssueBeneficiaryListAction
{
    /**
     * @param  list<string>  $beneficiaryUuids
     */
    public function handle(
        Assistance $assistance,
        array $beneficiaryUuids,
        ?string $recipientOrganization,
        ?string $notes,
        ?int $actingUserId,
    ): AssistanceBeneficiaryList {
        return DB::transaction(function () use ($assistance, $beneficiaryUuids, $recipientOrganization, $notes, $actingUserId) {
            $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($assistance->isExternal(), 409, 'إصدار الكشوف متاح فقط للمساعدات ذات التنفيذ الخارجي.');
            abort_unless($assistance->isOpen(), 409, 'يمكن إصدار الكشوف فقط عندما تكون المساعدة مفتوحة.');

            $fields = $assistance->export_fields ?? [];
            if ($fields === []) {
                throw ValidationException::withMessages(['fields' => 'حدد بيانات الكشف المطلوبة قبل الإصدار.']);
            }

            $beneficiaries = AssistanceBeneficiary::query()
                ->where('assistance_id', $assistance->id)
                ->whereIn('uuid', $beneficiaryUuids)
                ->get()
                ->keyBy('uuid');

            // Only current APPROVED beneficiaries; all-or-nothing.
            foreach (array_values($beneficiaryUuids) as $index => $uuid) {
                if ($beneficiaries->get($uuid)?->status !== BeneficiaryStatus::APPROVED) {
                    throw ValidationException::withMessages([
                        "beneficiary_ids.{$index}" => 'يمكن إدراج المستفيدين المعتمدين فقط في الكشف.',
                    ]);
                }
            }

            $ordered = collect($beneficiaryUuids)->map(fn ($uuid) => $beneficiaries[$uuid])->values();
            $rows = ExportFieldCatalog::resolve($ordered, array_column($fields, 'field_key'), Carbon::today());

            $id = BusinessIdentifier::nextId('assistance_beneficiary_lists');
            // forceCreate: the id reserved above must be kept so the list
            // number matches it.
            $list = AssistanceBeneficiaryList::forceCreate([
                'id' => $id,
                'assistance_id' => $assistance->id,
                'list_number' => BusinessIdentifier::format('ABL', $id),
                'recipient_organization' => $recipientOrganization ?: $assistance->provider_name,
                'issued_at' => Carbon::now(),
                'issued_by' => $actingUserId,
                'notes' => $notes,
                'configuration_snapshot' => array_map(fn ($f) => [
                    'field_key' => $f['field_key'],
                    'column_label' => $f['column_label'],
                    'classification' => ExportFieldCatalog::classification($f['field_key']),
                ], $fields),
                'contains_sensitive' => ExportFieldCatalog::containsSensitive($fields),
                'row_count' => count($rows),
            ]);

            foreach ($ordered as $index => $beneficiary) {
                AssistanceBeneficiaryListEntry::create([
                    'assistance_beneficiary_list_id' => $list->id,
                    'assistance_beneficiary_id' => $beneficiary->id,
                    'row_number' => $index + 1,
                    'snapshot_data' => $rows[$index],
                ]);

                FamilyActivityLog::record($beneficiary->family_id, FamilyActivityType::ASSISTANCE_BENEFICIARY_LISTED, $beneficiary, $actingUserId);
            }

            return $list;
        });
    }
}
