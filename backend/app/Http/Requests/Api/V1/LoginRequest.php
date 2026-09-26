<?php

namespace App\Http\Requests\Api\V1;

use App\Support\StaffRoles;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff login (docs/06 §59c, AUTH-ADR-057) on the Sanctum session guard.
 * Wrong password, unknown email, inactive account and non-Staff account
 * (e.g. FAMILY_USER) all fail with the same generic message, so the
 * response never reveals whether an account exists. Failed attempts are
 * limited per normalized email + IP; the route adds a per-IP limit.
 */
class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const FAILED = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'password.required' => 'كلمة المرور مطلوبة.',
        ];
    }

    public function email(): string
    {
        return mb_strtolower(trim((string) $this->input('email')));
    }

    /** Authenticates on the web (session) guard or throws a generic failure. */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('web');
        $valid = $guard->validate(['email' => $this->email(), 'password' => (string) $this->input('password')]);
        $user = $valid ? $guard->getLastAttempted() : null;

        if ($user === null || ! $user->is_active || ! StaffRoles::isStaff($user->getRoleNames()->first())) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => self::FAILED]);
        }

        $guard->login($user);
        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "محاولات تسجيل دخول كثيرة. حاول مجددًا بعد {$seconds} ثانية.",
        ])->status(429);
    }

    public function throttleKey(): string
    {
        return 'login|'.Str::transliterate($this->email()).'|'.$this->ip();
    }
}
