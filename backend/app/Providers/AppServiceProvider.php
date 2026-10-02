<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Enums\FamilyAuthError;
use App\Exceptions\FamilyAuthException;
use App\Models\Assessment;
use App\Models\AssistanceBeneficiary;
use App\Models\Family;
use App\Models\FamilyNeed;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Support\Import\Apply\ApplyRunnerLock;
use App\Support\Import\Apply\PostgresApplyRunnerLock;
use App\Support\Import\Apply\ProcessApplyRunnerLock;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\UnconfiguredSmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One Apply runner per batch (docs/03 §96b): a real advisory lock on
        // PostgreSQL; an in-process equivalent for the SQLite test suite.
        $this->app->singleton(ApplyRunnerLock::class, fn () => DB::getDriverName() === 'pgsql'
            ? new PostgresApplyRunnerLock
            : new ProcessApplyRunnerLock);

        // SMS delivery (docs/11 §30a): fails closed. Only an explicitly
        // configured driver delivers; anything else — unset or unknown — is
        // the sender that always refuses. No real provider exists yet.
        $this->app->bind(SmsSender::class, fn () => match (config('family_auth.sms.driver')) {
            'log' => new LogSmsSender((string) config('family_auth.sms.log_path')),
            default => new UnconfiguredSmsSender,
        });
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

        // Exact National ID pre-check (AUTH-ADR-058): enough for data entry,
        // too few for enumerating National IDs.
        RateLimiter::for('national-id-check', fn (Request $request) => Limit::perMinute(30)
            ->by('nid-check|'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn () => response()->json(['message' => 'عدد كبير من عمليات التحقق. حاول مجددًا بعد قليل.'], 429)));

        // Family activation (docs/11 §30a): request ceilings per IP on the
        // public endpoints, real and decoy flows alike. The key is a digest
        // of the address; the per-identifier ceiling of start lives in
        // FamilyActivation (keyed fingerprint). OtpThrottle remains the only
        // SMS ceiling.
        foreach (['start' => ['minute', 'hour'], 'verify' => ['minute'], 'resend' => ['minute'], 'complete' => ['minute']] as $step => $windows) {
            RateLimiter::for("family-activation-{$step}", fn (Request $request) => array_map(
                function (string $window) use ($step, $request) {
                    $max = (int) config("family_auth.activation.limits.{$step}_ip_{$window}");

                    return ($window === 'hour' ? Limit::perHour($max) : Limit::perMinute($max))
                        ->by("family-activation|{$step}|ip|".hash('sha256', (string) $request->ip())."|{$window}")
                        ->response(fn () => FamilyAuthException::response(FamilyAuthError::TOO_MANY_REQUESTS));
                },
                $windows,
            ));
        }
    }
}
