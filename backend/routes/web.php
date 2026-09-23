<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

// Local-only development session bootstrap. Establishes a real Sanctum
// stateful session (via Auth::login + the existing 'web' session/cookie
// stack) for a fixed dev user holding SUPER_ADMIN — no auth UI, no new
// auth mechanism, no weakening of auth:sanctum/can: middleware on the
// API routes. Not registered in non-local environments.
if (app()->environment('local')) {
    Route::get('/dev-login', function () {
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'dev@famboook.test'],
            [
                'name' => 'Famboook Dev',
                'password' => bcrypt(Str::random(40)),
            ]
        );

        if (! $user->hasRole('SUPER_ADMIN')) {
            $user->assignRole('SUPER_ADMIN');
        }

        Auth::login($user);

        return response()->json([
            'message' => 'Development session established.',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    });

    Route::get('/dev-logout', function () {
        Auth::logout();

        return response()->json(['message' => 'Development session ended.']);
    });
}
