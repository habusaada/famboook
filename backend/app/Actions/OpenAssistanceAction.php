<?php

namespace App\Actions;

use App\Enums\AssistanceStatus;
use App\Models\Assistance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DRAFT → OPEN (permission assistance.open). From now on nominees can be
 * managed and the definition is locked except for description, target
 * count and dates. Records opened_at/opened_by on the Assistance itself.
 */
class OpenAssistanceAction
{
    public function handle(Assistance $assistance, ?int $actingUserId): Assistance
    {
        return DB::transaction(function () use ($assistance, $actingUserId) {
            $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($assistance->isDraft(), 409, 'هذه المساعدة مفتوحة أو مغلقة مسبقًا.');

            if (! $assistance->items()->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'أضف عنصرًا واحدًا على الأقل قبل فتح المساعدة.',
                ]);
            }

            $assistance->status = AssistanceStatus::OPEN;
            $assistance->opened_at = Carbon::now();
            $assistance->opened_by = $actingUserId;
            $assistance->updated_by = $actingUserId;
            $assistance->save();

            return $assistance;
        });
    }
}
