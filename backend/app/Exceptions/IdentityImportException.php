<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The identity reference staging import (FB-CIVIL-01A-P2) was refused or
 * interrupted. The message and `problems` hold fixed codes, safe file names,
 * line numbers and counts only — never a record value. The original database
 * exception is deliberately NOT chained (Laravel's handler would log its
 * SQL, bindings and PostgreSQL DETAIL); its redacted form is in the message.
 * `runId` is the import run concerned, when one exists.
 */
class IdentityImportException extends RuntimeException
{
    /** No usable import connection or target (configuration). */
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    /** The connection is not the named, disposable/approved target. */
    public const UNSAFE_TARGET = 'UNSAFE_TARGET';

    /** The session cannot act as famboook_identity_writer, or is a superuser role. */
    public const WRONG_ROLE = 'WRONG_ROLE';

    /** Another staging process holds the import lock. */
    public const CONCURRENT_IMPORT = 'CONCURRENT_IMPORT';

    /** A different import run is RUNNING (abort it first). */
    public const RUN_CONFLICT = 'RUN_CONFLICT';

    /** The manifest or the directory inventory is invalid (no run recorded). */
    public const MANIFEST_INVALID = 'MANIFEST_INVALID';

    /** A source file failed validation; the run is REJECTED and its staging purged. */
    public const SOURCE_INVALID = 'SOURCE_INVALID';

    /** A source file changed while it was being staged; the run is REJECTED and purged. */
    public const SOURCE_CHANGED = 'SOURCE_CHANGED';

    /** Unexpected failure while staging; the run stays RUNNING and can be resumed. */
    public const STAGING_INTERRUPTED = 'STAGING_INTERRUPTED';

    // ---- P3: validation, preparation, verification, publication

    /** Release-level (fatal) validation failed; nothing was written. */
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    /** The data needs a decision the approved policy does not contain (blank-field, quarantine); fails closed. */
    public const POLICY_BLOCKED = 'POLICY_BLOCKED';

    /** The policy is not approved here, or its comparison version is not supported. */
    public const POLICY_NOT_APPROVED = 'POLICY_NOT_APPROVED';

    /** The release or its run is not in a state allowing the operation. */
    public const RELEASE_STATE_INVALID = 'RELEASE_STATE_INVALID';

    /** The release has not been prepared and verified. */
    public const NOT_VERIFIED = 'NOT_VERIFIED';

    /** Preparation stopped; the BUILDING release can be resumed with the same policy. */
    public const PREPARATION_INTERRUPTED = 'PREPARATION_INTERRUPTED';

    /** The prepared release does not equal its recomputed classification. */
    public const VERIFICATION_FAILED = 'VERIFICATION_FAILED';

    /** Activation failed and was rolled back; the previously active release is unchanged. */
    public const PUBLICATION_FAILED = 'PUBLICATION_FAILED';

    /** Publication needs an explicit operator confirmation. */
    public const NOT_CONFIRMED = 'NOT_CONFIRMED';

    public ?string $runId = null;

    public ?int $releaseId = null;

    /** @param list<string> $problems */
    public function __construct(public readonly string $reason, string $message, public readonly array $problems = [])
    {
        parent::__construct("[{$reason}] {$message}");
    }
}
