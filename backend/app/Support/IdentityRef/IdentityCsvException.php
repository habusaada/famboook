<?php

namespace App\Support\IdentityRef;

use RuntimeException;

/**
 * A structural problem in an identity source file (FB-CIVIL-01A-P2). Carries
 * only a fixed code, the safe file name and a physical line number — never a
 * value from the file.
 */
final class IdentityCsvException extends RuntimeException
{
    public const BOM_MISSING = 'BOM_MISSING';

    public const EMPTY_FILE = 'EMPTY_FILE';

    public const HEADER_MISMATCH = 'HEADER_MISMATCH';

    public const FIELD_COUNT = 'FIELD_COUNT';

    public const UNTERMINATED_QUOTE = 'UNTERMINATED_QUOTE';

    public const QUOTE_IN_UNQUOTED_FIELD = 'QUOTE_IN_UNQUOTED_FIELD';

    public const TEXT_AFTER_CLOSING_QUOTE = 'TEXT_AFTER_CLOSING_QUOTE';

    public const INVALID_UTF8 = 'INVALID_UTF8';

    public const NUL_BYTE = 'NUL_BYTE';

    public const RECORD_TOO_LONG = 'RECORD_TOO_LONG';

    public const UNREADABLE = 'UNREADABLE';

    public function __construct(public readonly string $reason, public readonly string $fileName, public readonly ?int $lineNo = null)
    {
        parent::__construct($reason.' in '.$fileName.($lineNo !== null ? ' at line '.$lineNo : ''));
    }
}
