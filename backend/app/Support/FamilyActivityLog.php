<?php

namespace App\Support;

use App\Enums\FamilyActivityType;
use App\Models\FamilyActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of Family Activity Log entries (docs/03-BUSINESS-RULES.md
 * §97a). Called at the end of a Domain Action, inside its transaction, so
 * the entry commits or rolls back together with the business change.
 *
 * Metadata is allow-listed: any other key is rejected rather than stored.
 * Never pass request payloads, model arrays, previous/new values, National
 * IDs, phone numbers or health details.
 */
class FamilyActivityLog
{
    /** Keys that may appear in metadata, and nothing else. */
    public const ALLOWED_METADATA = [
        // Broad health record category only (DISABILITY, CHRONIC_DISEASE,
        // PREGNANCY, BREASTFEEDING). Never the disease, disability or notes.
        'health_record_type',
    ];

    /**
     * Events with their OWN metadata contract: only these keys, each a
     * positive integer identifier (never a National ID, name or source
     * value). These keys are rejected on every other event.
     */
    public const EVENT_METADATA = [
        'FAMILY_IMPORTED' => ['import_batch_id', 'source_row_number'],
    ];

    /**
     * @param  array<string, string|int>  $metadata
     */
    public static function record(
        int $familyId,
        FamilyActivityType $type,
        ?Model $subject,
        ?int $actorUserId,
        array $metadata = [],
    ): FamilyActivity {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Family activity must be recorded inside the Domain Action transaction.');
        }

        $eventKeys = self::EVENT_METADATA[$type->value] ?? null;
        $unexpected = array_diff(array_keys($metadata), $eventKeys ?? self::ALLOWED_METADATA);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Metadata key not allowed: '.implode(', ', $unexpected));
        }
        if ($eventKeys !== null) {
            foreach ($metadata as $key => $value) {
                if (! is_int($value) || $value < 1) {
                    throw new InvalidArgumentException("Metadata {$key} must be a positive integer identifier.");
                }
            }
        }

        return FamilyActivity::create([
            'family_id' => $familyId,
            'actor_user_id' => $actorUserId,
            'event_type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
