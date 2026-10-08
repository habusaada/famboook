<?php

namespace App\Support\ChangeRequests;

use InvalidArgumentException;

/**
 * The approved V1 presentation of a proposal (docs/11 FP-ADR-073):
 *
 *     {rows: [{label, current, proposed}]}
 *
 * Ordered rows of display-ready Arabic text: a human label and the current
 * and proposed values as a string or NULL ("not recorded"). No database
 * keys, no nested objects, no raw submitted_data. Every handler builds its
 * presentation here, so a malformed row can never reach a resource.
 */
final class ChangeRequestPresentation
{
    /** @var list<array{label: string, current: ?string, proposed: ?string}> */
    private array $rows = [];

    public static function make(): self
    {
        return new self;
    }

    public function row(string $label, ?string $current, ?string $proposed): self
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A presentation row needs a label.');
        }
        $this->rows[] = ['label' => $label, 'current' => $current, 'proposed' => $proposed];

        return $this;
    }

    /** @return array{rows: list<array{label: string, current: ?string, proposed: ?string}>} */
    public function toArray(): array
    {
        return ['rows' => $this->rows];
    }
}
