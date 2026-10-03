<?php

use App\Http\Middleware\EnsureActivationEnabled;
use App\Http\Middleware\EnsureCoordinatorSpace;
use App\Http\Middleware\EnsureFamilyLoginEnabled;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Http\Middleware\EnsurePasswordResetEnabled;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SendSmsAfterResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            // Coordinator Space (PWA-1H): after family.side, never instead of it.
            'coordinator.space' => EnsureCoordinatorSpace::class,
            // OTP SMS after the response, same process, no queue (A′).
            'sms.after-response' => SendSmsAfterResponse::class,
        ]);
        // One enforcement point for deactivated accounts (AUTH-ADR-057):
        // every Staff API and Filament request.
        $middleware->appendToGroup('web', EnsureUserIsActive::class);
        $middleware->appendToGroup('api', EnsureUserIsActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Typed National IDs (identity verification) must never be flashed
        // back into a session or error context.
        $exceptions->dontFlash([
            'national_id',
            'national_id_confirmation',
            'beneficiary_national_id',
            'delegate_national_id',
            'code',
        ]);
    })->create();
