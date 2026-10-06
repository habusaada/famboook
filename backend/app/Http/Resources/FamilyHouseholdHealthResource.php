<?php

namespace App\Http\Resources;

use App\Models\FamilyMembership;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Household health for the Family Portal (GET
 * /api/v1/family/household/health, PWA-3B.6, docs/11 §23a): the registered
 * health facts of the household's members, grouped by the opaque member_ref
 * (FU-13) — an explicit allow-list built from HouseholdReadModel::health().
 *
 * Only members with at least one record appear; no record is never "healthy"
 * — it is only "nothing registered". Per record: type, disability type (code
 * and name), condition_name, started_at, ended_at and is_active (ended_at is
 * NULL — the domain rule). NULL stays NULL.
 *
 * Never details, a record id or uuid, person_id, person_code, a disability
 * type id / description / is_active / sort_order, created_by / updated_by,
 * timestamps, Staff summary or abilities, assessment, targeting, activity or
 * audit data. The Staff HealthRecordResource is deliberately not reused.
 *
 * @property Collection<int, FamilyMembership> $resource
 */
class FamilyHouseholdHealthResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $date = fn (?string $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString();

        $members = $this->resource
            ->groupBy('id')
            ->map(fn (Collection $rows) => [
                // The opaque membership reference (FU-13): computed, never an id.
                'member_ref' => HouseholdMemberReference::of((int) $rows->first()->family_id, (int) $rows->first()->id),
                'records' => $rows->map(fn (FamilyMembership $row) => [
                    'type' => $row->health_type,
                    'disability_type' => $row->disability_type_code === null ? null : [
                        'code' => $row->disability_type_code,
                        'name' => $row->disability_type_name,
                    ],
                    'condition_name' => $row->health_condition_name,
                    'started_at' => $date($row->health_started_at),
                    'ended_at' => $date($row->health_ended_at),
                    'is_active' => $row->health_ended_at === null,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return ['members' => $members];
    }
}
