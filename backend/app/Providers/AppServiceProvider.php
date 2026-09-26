<?php

namespace App\Providers;

use App\Models\Assessment;
use App\Models\AssistanceBeneficiary;
use App\Models\Family;
use App\Models\FamilyNeed;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
            'need' => FamilyNeed::class,
            'assistance_nominee' => AssistanceBeneficiary::class,
        ]);

        // Staff login (AUTH-ADR-057): a per-IP ceiling on every attempt, in
        // addition to LoginRequest's per email + IP limit on failures.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)
            ->by('login-ip|'.$request->ip())
            ->response(fn () => response()->json(['message' => 'محاولات تسجيل دخول كثيرة. حاول مجددًا بعد قليل.'], 429)));
    }
}
