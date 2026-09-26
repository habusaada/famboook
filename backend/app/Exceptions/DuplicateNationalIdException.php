<?php

namespace App\Exceptions;

use App\Models\Person;
use App\Support\NationalIdGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A Person creation was refused because the National ID already belongs
 * to another Person (docs/03 §21, AUTH-ADR-058). Rendered as a 422 in the
 * usual validation shape plus safe references to the existing record(s) —
 * never the National ID itself. Nothing was created or changed.
 */
class DuplicateNationalIdException extends RuntimeException
{
    public const MESSAGE = 'يوجد شخص مسجل مسبقًا بهذه الهوية.';

    /** @param Collection<int, Person> $matches */
    public function __construct(public readonly string $field, public readonly Collection $matches)
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGE,
            'errors' => [$this->field => [self::MESSAGE]],
            'duplicate' => ['matches' => NationalIdGuard::describe($this->matches, $request)],
        ], 422);
    }

    /** Expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
