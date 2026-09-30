<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportApplyEffect as E;
use App\Models\Branch;

/**
 * Presents an ImportBatchApplyPlan for the Dry Run (docs/03 §96b, docs/06
 * §61): aggregate counts and paginated row plans with intents, reason codes,
 * masked National IDs and public person / branch references only — never a
 * full National ID, a name or a source payload. Read only.
 */
final class DryRunReport
{
    public const FILTERS = ['all', 'executable', 'blocked', 'warnings'];

    /** @return array<string, mixed> */
    public static function summary(ImportBatchApplyPlan $plan): array
    {
        return [
            'state' => ! $plan->preconditionsMet() ? 'PRECONDITIONS_FAILED' : ($plan->executable() ? 'READY' : 'ROWS_BLOCKED'),
            'preconditions' => $plan->preconditionFailures,
            'plan_fingerprint' => $plan->fingerprint(),
            // Final execution is not available in this phase.
            'execution_enabled' => false,
            'counts' => $plan->preconditionsMet() ? $plan->summary() : null,
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int}}
     */
    public static function rows(ImportBatchApplyPlan $plan, string $filter, ?string $reason, ?string $search, int $page, int $perPage = 25): array
    {
        $rows = array_values(array_filter($plan->rows, function (ImportRowApplyPlan $r) use ($filter, $reason, $search) {
            $ok = match ($filter) {
                'executable' => $r->executable(),
                'blocked' => ! $r->executable(),
                'warnings' => $r->warnings !== [],
                default => true,
            };
            if ($ok && $reason !== null) {
                $ok = in_array($reason, [...$r->warnings, ...$r->blockReasons(), ...array_map(fn ($e) => $e->reason, $r->effects)], true);
            }
            if ($ok && $search !== null && $search !== '') {
                $ok = (string) $r->rowNumber === $search || ($r->sourceFamilyKey !== null && mb_stripos($r->sourceFamilyKey, $search) !== false);
            }

            return $ok;
        }));
        $total = count($rows);
        $last = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $last);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $branchIds = array_filter(array_map(fn (ImportRowApplyPlan $r) => $r->effect(E::FAMILY)?->values['branch_id'] ?? null, $slice));
        $branches = Branch::whereIn('id', $branchIds ?: [0])->pluck('name', 'id')->all();

        return [
            'data' => array_map(fn (ImportRowApplyPlan $r) => self::row($r, $branches), $slice),
            'meta' => ['current_page' => $page, 'last_page' => $last, 'total' => $total],
        ];
    }

    /** @param array<int, string> $branches */
    private static function row(ImportRowApplyPlan $r, array $branches): array
    {
        $family = $r->effect(E::FAMILY);
        $spouses = [];
        foreach ($r->spouseNationalIdsMasked as $slot => $masked) {
            $person = $r->effect(E::spousePerson($slot));
            $spouses[] = [
                'slot' => $slot,
                'national_id_masked' => $masked,
                'person' => self::effect($person),
                'membership' => self::effect($r->effect(E::spouseMembership($slot))),
            ];
        }
        $branchId = $family?->values['branch_id'] ?? null;

        return [
            'row_number' => $r->rowNumber,
            'source_family_key' => $r->sourceFamilyKey,
            'national_id_masked' => $r->headNationalIdMasked,
            'executable' => $r->executable(),
            'block_reasons' => $r->blockReasons(),
            'warnings' => $r->warnings,
            'head_person' => self::effect($r->effect(E::HEAD_PERSON)),
            'family' => [...self::effect($family), 'branch' => $branchId !== null ? ($branches[$branchId] ?? null) : null],
            'head_membership' => self::effect($r->effect(E::HEAD_MEMBERSHIP)),
            'declaration' => self::effect($r->effect(E::HOUSEHOLD_DECLARATION)),
            'residence' => self::effect($r->effect(E::RESIDENCE)),
            'spouses' => $spouses,
        ];
    }

    /** Intent + reason + safe references; CREATE shows only non-identifying planned facts. */
    private static function effect(?ImportApplyEffectPlan $e): array
    {
        if ($e === null) {
            return ['intent' => null, 'reason' => null];
        }

        return array_filter([
            'intent' => $e->intent->value,
            'reason' => $e->reason,
            'person_code' => $e->existingCode,
            'owner' => $e->ownerRow !== null ? ['row_number' => $e->ownerRow, 'effect' => $e->ownerEffect->value] : null,
            // Safe planned facts: status codes, never names or IDs.
            'gender' => $e->values['gender'] ?? null,
            'life_status' => $e->values['life_status'] ?? null,
        ], fn ($v) => $v !== null) + ['intent' => $e->intent->value, 'reason' => $e->reason];
    }
}
