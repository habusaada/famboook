<?php

namespace App\Http\Resources;

use App\Models\FamilyNeed;
use App\Support\FamilyPortal\HistoricalPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Household needs for the Family Portal (GET /api/v1/family/household/needs,
 * PWA-3B.7, docs/11 §23a, docs/06 §124): an explicit allow-list built from
 * HouseholdReadModel::needs(). Per need: category (code, name), title,
 * quantity (trimmed decimal), unit, status (OPEN | FULFILLED | CLOSED),
 * created_at, resolved_at and person (null = the whole family). NULL stays
 * NULL. A FULFILLED need is a registry status — never proof that assistance
 * was delivered.
 *
 * Never an id or uuid, family_id, person_id, person_code, priority,
 * description, closure_reason, resolved_by, created_by / updated_by, the
 * source assessment, or a category's id / description / is_active /
 * sort_order. The Staff NeedResource is deliberately not reused.
 *
 * @property array{family_id: int, needs: Collection<int, FamilyNeed>} $resource
 */
class FamilyHouseholdNeedsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $familyId = $this->resource['family_id'];
        $date = fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString();

        return [
            'needs' => $this->resource['needs']->map(fn (FamilyNeed $need) => [
                'category' => ['code' => $need->category_code, 'name' => $need->category_name],
                'title' => $need->title,
                'quantity' => HistoricalPerson::decimal($need->getRawOriginal('quantity')),
                'unit' => $need->unit,
                'status' => $need->getRawOriginal('status'),
                'created_at' => $date($need->getRawOriginal('created_at')),
                'resolved_at' => $date($need->getRawOriginal('resolved_at')),
                'person' => $need->person_id === null ? null : HistoricalPerson::member(
                    $familyId,
                    $need->person_full_name,
                    $need->person_deleted_at,
                    $need->current_membership_id === null ? null : (int) $need->current_membership_id,
                ),
            ])->values()->all(),
        ];
    }
}
