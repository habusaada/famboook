<?php

namespace App\Http\Middleware;

use App\Support\Sms\SmsDispatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the public routes that send an OTP (activation and password reset):
 * any SMS of this request is handed to the provider AFTER the response
 * (docs/11 §30a, A′) — same process, no queue — so the provider's latency
 * or failure never changes what, or when, the client is answered.
 */
class SendSmsAfterResponse
{
    public function __construct(private readonly SmsDispatcher $dispatcher) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->dispatcher->deferUntilResponseSent();

        return $next($request);
    }
}
