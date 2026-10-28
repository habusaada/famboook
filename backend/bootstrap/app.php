<?php

use App\Http\Middleware\EnsureActivationEnabled;
use App\Http\Middleware\EnsureCivilLookupEnabled;
use App\Http\Middleware\EnsureContactUpdateEnabled;
use App\Http\Middleware\EnsureCoordinatorSpace;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilyLoginEnabled;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Http\Middleware\EnsureNotificationPublicationReady;
use App\Http\Middleware\EnsurePasswordChangeEnabled;
use App\Http\Middleware\EnsurePasswordResetEnabled;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SendSmsAfterResponse;
use App\Http\Middleware\SetCivilLookupContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Laravel's default guest redirect is route('login'), evaluated by the
        // auth middleware for every request that does not ask for JSON. This
        // backend has no web login route: for /api there is no redirect at
        // all — the exception renders as a 401 JSON (withExceptions below).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));

        // The Staff API boundary (AUTH-ADR-065): family-side accounts never
        // enter Staff routes, whatever permission they hold.
        // Its mirror for /api/v1/family (docs/06 §22b): only family-side accounts.
        $middleware->alias([
            'staff.side' => EnsureStaffSideAccount::class,
            'family.side' => EnsureFamilySideAccount::class,
            // The Family activation gate (docs/08 §16a): off by default.
            'family.activation' => EnsureActivationEnabled::class,
            // Independent gates, each off by default.
            'family.login' => EnsureFamilyLoginEnabled::class,
            'family.password-reset' => EnsurePasswordResetEnabled::class,
            'family.password-change' => EnsurePasswordChangeEnabled::class,
            'family.contact-update' => EnsureContactUpdateEnabled::class,
            // Coordinator Space (PWA-1H): after family.side, never instead of it.
            'coordinator.space' => EnsureCoordinatorSpace::class,
            // Family data (PWA-3A): the server-resolved Family context.
            'family.context' => EnsureFamilyContext::class,
            // OTP SMS after the response, same process, no queue (A′).
            'sms.after-response' => SendSmsAfterResponse::class,
            // Notification publication readiness (FB-NOTIFY-01B.2b): after
            // auth, never instead of the route's permission or policy.
            'notifications.publication-ready' => EnsureNotificationPublicationReady::class,
            // FB-CIVIL-02A: the Civil Registry Lookup gate (off by default).
            'civil-lookup.enabled' => EnsureCivilLookupEnabled::class,
            // FB-CIVIL-02B: fixes the lookup context of a mount (STAFF, FAMILY, COORDINATOR).
            'civil-lookup.context' => SetCivilLookupContext::class,
        ]);
        // FU-16: the password-change gate runs BEFORE the route limiter (which
        // the priority list would otherwise move ahead of `can:`), so a closed
        // gate counts nothing — like the other Family Auth gates.
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsurePasswordChangeEnabled::class);
        // FP-ADR-079: the same for the contact-update gate.
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureContactUpdateEnabled::class);
        // FB-CIVIL-02A: a closed lookup gate counts nothing either.
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureCivilLookupEnabled::class);
        // One enforcement point for deactivated accounts (AUTH-ADR-057):
        // every Staff API and Filament request.
        $middleware->appendToGroup('web', EnsureUserIsActive::class);
        $middleware->appendToGroup('api', EnsureUserIsActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is JSON-only, whatever the client sends as Accept: an
        // unauthenticated /api request answers 401 {"message":"Unauthenticated."}
        // and never attempts a redirect to a web login route (which does not
        // exist here); validation and other errors render as JSON too.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );

        // Typed National IDs (identity verification) must never be flashed
        // back into a session or error context.
        $exceptions->dontFlash([
            'national_id',
            'national_id_confirmation',
            'beneficiary_national_id',
            'delegate_national_id',
            'code',
            // Digital Family Card verification token (PWA-8.2).
            'token',
        ]);
    })->create();
