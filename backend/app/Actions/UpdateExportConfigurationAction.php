<?php

namespace App\Actions;

use App\Enums\AssistanceStatus;
use App\Models\Assistance;
use Illuminate\Support\Facades\DB;

/**
 * Saves the requested export fields of an EXTERNAL Assistance (permission
 * assistance.export; sensitive fields also need assistance.export-sensitive
 * — see UpdateExportConfigurationRequest). Completely separate from
 * targeting criteria. Issued lists keep their own configuration snapshot,
 * so changing this never affects them.
 */
class UpdateExportConfigurationAction
{
    /**
     * @param  list<array{field_key: string, column_label: string}>  $fields
     */
    public function handle(Assistance $assistance, array $fields, ?int $actingUserId): Assistance
    {
        return DB::transaction(function () use ($assistance, $fields, $actingUserId) {
            $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($assistance->isExternal(), 409, 'بيانات الكشف متاحة فقط للمساعدات ذات التنفيذ الخارجي.');
            abort_unless(
                in_array($assistance->status, [AssistanceStatus::DRAFT, AssistanceStatus::OPEN], true),
                409,
                'لا يمكن تعديل بيانات الكشف بعد اكتمال المساعدة.'
            );

            $assistance->export_fields = array_values(array_map(fn ($field, $index) => [
                'field_key' => $field['field_key'],
                'column_label' => trim($field['column_label']),
                'sort_order' => $index + 1,
            ], $fields, array_keys($fields)));
            $assistance->updated_by = $actingUserId;
            $assistance->save();

            return $assistance;
        });
    }
}
