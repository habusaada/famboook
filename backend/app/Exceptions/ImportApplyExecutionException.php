<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A structured Apply execution failure (docs/03 §96b): a stable code and the
 * source row number — never exception text or row data. The original cause is
 * kept only as `previous` for server-side logs. The future runner turns it
 * into import_batches.apply_error_code / apply_error_row_number.
 */
class ImportApplyExecutionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $rowNumber = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
