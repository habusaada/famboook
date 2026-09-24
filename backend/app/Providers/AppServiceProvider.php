<?php

namespace App\Providers;

use App\Models\Assessment;
use App\Models\Family;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stable short names for family_activities.subject_type, so stored
        // rows don't depend on PHP class names. Not enforced globally:
        // other morphs (Spatie roles, Sanctum tokens) keep their class names.
        Relation::morphMap([
            'family' => Family::class,
            'person' => Person::class,
            'residence' => FamilyResidence::class,
            'health_record' => PersonHealthRecord::class,
            'assessment' => Assessment::class,
        ]);
    }
}
