<?php

namespace App\Support\Import\Apply;

use Closure;

/**
 * How much one Apply chunk may do (docs/03 §96b): at most maxRows rows or
 * about maxSeconds, whichever comes first — the approved default is 100 rows
 * / 10 seconds. The clock is injectable so tests never sleep. A row that has
 * started always finishes (its own transaction); the budget is checked
 * between rows.
 */
final class ApplyChunkBudget
{
    private float $startedAt = 0.0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    public function __construct(
        public readonly int $maxRows = 100,
        public readonly float $maxSeconds = 10.0,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    public function start(): void
    {
        $this->startedAt = ($this->clock)();
    }

    /** True when no further row may start in this chunk. */
    public function exhausted(int $rowsDone): bool
    {
        return $rowsDone >= $this->maxRows || (($this->clock)() - $this->startedAt) >= $this->maxSeconds;
    }
}
