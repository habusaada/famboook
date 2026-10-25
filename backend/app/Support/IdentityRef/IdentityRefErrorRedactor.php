<?php

namespace App\Support\IdentityRef;

use PDOException;
use Throwable;

/**
 * Turns a database error from the identity reference schema (FB-CIVIL-01A)
 * into a loggable string WITHOUT any record value.
 *
 * PostgreSQL puts values in error texts — "Failing row contains (…)" and
 * "Key (mainid)=(…)" in DETAIL, input values in some primary messages — and
 * Laravel's QueryException appends the SQL and its bindings. None of that may
 * reach a log. Only fixed identifiers are kept: the SQLSTATE, an
 * IDENTITY_REF_* code raised by the schema, and the constraint / relation
 * name (schema-defined identifiers, never data).
 */
final class IdentityRefErrorRedactor
{
    public static function redact(Throwable $e): string
    {
        $sqlState = self::sqlState($e);
        $text = $e->getMessage();

        $code = preg_match('/\b(IDENTITY_REF_[A-Z0-9_]{1,64})\b/', $text, $m) ? $m[1] : null;
        $constraint = preg_match('/constraint "([a-z0-9_]{1,63})"/', $text, $m) ? $m[1] : null;
        $relation = preg_match('/relation "([a-z0-9_]{1,63})"/', $text, $m) ? $m[1] : null;

        return 'identity_ref database error'
            .' sqlstate='.($sqlState ?? 'unknown')
            .($code !== null ? ' code='.$code : '')
            .($constraint !== null ? ' constraint='.$constraint : '')
            .($relation !== null ? ' relation='.$relation : '');
    }

    private static function sqlState(Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && is_array($current->errorInfo) && is_string($current->errorInfo[0] ?? null)) {
                return preg_match('/^[0-9A-Z]{5}$/', $current->errorInfo[0]) === 1 ? $current->errorInfo[0] : null;
            }
            $code = (string) $current->getCode();
            if (preg_match('/^[0-9A-Z]{5}$/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }
}
