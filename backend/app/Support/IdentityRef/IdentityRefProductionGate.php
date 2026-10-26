<?php

namespace App\Support\IdentityRef;

/**
 * The FB-CIVIL-01A-P4 Production gate, shared by the operator command and the
 * import worker (FB-CIVIL-01A-UI X-7, S-3). In production every identity
 * import operation needs an explicit approval reference — the recorded change
 * ticket, issued only once the real-data gates are approved — in the PROCESS
 * environment: the operator's shell, or the worker's root-owned environment
 * file. It is never read from .env or the cached configuration.
 */
final class IdentityRefProductionGate
{
    public const VARIABLE = 'IDENTITY_REF_PRODUCTION_APPROVAL';

    private const REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,63}$/';

    /** True outside production; in production, only with a well-formed approval reference. */
    public static function allows(): bool
    {
        return ! app()->environment('production') || self::reference() !== null;
    }

    /** The approval reference from the process environment, when well-formed. */
    public static function reference(): ?string
    {
        $value = (string) getenv(self::VARIABLE);

        return preg_match(self::REFERENCE, $value) === 1 ? $value : null;
    }
}
