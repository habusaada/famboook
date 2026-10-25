<?php

namespace App\Support\IdentityRef;

use InvalidArgumentException;
use LogicException;

/**
 * A versioned identity import policy (FB-CIVIL-01A-P3): the comparison
 * policy, the blank-field policy (OD-04) and the quarantine policy (OD-06)
 * a release is prepared under. The policy is recorded on the release
 * preparation (id + fingerprint) and re-enforced by the database at
 * verification.
 *
 * Only APPROVED policies are in the registry
 * (docs/audits/FB-CIVIL-01A-P3-DECISION-REGISTER.md):
 *   IDREF-POLICY-1 (default, P3.1): blank-field PRESERVE_EXISTING_ON_BLANK
 *     (D-01) and ZERO_UNRESOLVED_QUARANTINE (D-02) — a release with any
 *     quarantined row is never published.
 *   IDREF-POLICY-0: the earlier no-decision baseline (both UNRESOLVED; fails
 *     closed), kept so releases prepared under it remain explainable.
 *
 * synthetic() builds other combinations for the automated tests only; it
 * refuses outside the testing environment.
 */
final class IdentityImportPolicy
{
    /** Comparison policies this code implements (identity_ref_staging.assess_run). */
    public const SUPPORTED_COMPARISON = ['IDREF-CMP-1'];

    public const BLANK_UNRESOLVED = 'UNRESOLVED';

    public const BLANK_ACCEPT_SOURCE = 'ACCEPT_SOURCE';

    public const BLANK_QUARANTINE_ROW = 'QUARANTINE_ROW';

    /** D-01 (approved): an incoming blank (NULL or "") keeps the existing value; the raw row is preserved. */
    public const BLANK_PRESERVE_EXISTING = 'PRESERVE_EXISTING_ON_BLANK';

    public const DEFAULT_ID = 'IDREF-POLICY-1';

    /** @var array<string, array{comparison: string, blank: string, max_rows: ?int, max_ratio: ?string}> approved policies only */
    private const REGISTRY = [
        // Baseline, no decision recorded for OD-04 / OD-06: fail closed.
        'IDREF-POLICY-0' => ['comparison' => 'IDREF-CMP-1', 'blank' => self::BLANK_UNRESOLVED, 'max_rows' => null, 'max_ratio' => null],
        // D-01 PRESERVE_EXISTING_ON_BLANK + D-02 ZERO_UNRESOLVED_QUARANTINE (approved for P3.1).
        'IDREF-POLICY-1' => ['comparison' => 'IDREF-CMP-1', 'blank' => self::BLANK_PRESERVE_EXISTING, 'max_rows' => 0, 'max_ratio' => '0'],
    ];

    private function __construct(
        public readonly string $id,
        public readonly string $comparison,
        public readonly string $blankField,
        public readonly ?int $quarantineMaxRows,
        public readonly ?string $quarantineMaxRatio,
    ) {
        if (! in_array($blankField, [self::BLANK_UNRESOLVED, self::BLANK_ACCEPT_SOURCE, self::BLANK_QUARANTINE_ROW, self::BLANK_PRESERVE_EXISTING], true)
            || (($quarantineMaxRows === null) !== ($quarantineMaxRatio === null))
            || preg_match('/^[A-Z][A-Z0-9_-]{0,63}$/', $id) !== 1) {
            throw new InvalidArgumentException('Invalid identity import policy definition.');
        }
    }

    public static function approved(string $id = self::DEFAULT_ID): self
    {
        $p = self::REGISTRY[$id] ?? throw new InvalidArgumentException('Unknown or unapproved identity import policy.');

        return new self($id, $p['comparison'], $p['blank'], $p['max_rows'], $p['max_ratio']);
    }

    /** @return list<string> */
    public static function approvedIds(): array
    {
        return array_keys(self::REGISTRY);
    }

    /** Test-only policy combinations (synthetic data). Refused outside the testing environment. */
    public static function synthetic(string $blankField = self::BLANK_UNRESOLVED, ?int $maxRows = null, ?string $maxRatio = null,
        string $comparison = 'IDREF-CMP-1'): self
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Synthetic identity import policies exist for automated tests only.');
        }

        return new self('TEST-SYNTHETIC', $comparison, $blankField, $maxRows, $maxRatio);
    }

    public function comparisonSupported(): bool
    {
        return in_array($this->comparison, self::SUPPORTED_COMPARISON, true);
    }

    public function quarantineResolved(): bool
    {
        return $this->quarantineMaxRows !== null;
    }

    /** SHA-256 of the canonical policy definition, recorded with the preparation. */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            'id' => $this->id, 'comparison' => $this->comparison, 'blank_field' => $this->blankField,
            'quarantine_max_rows' => $this->quarantineMaxRows, 'quarantine_max_ratio' => $this->quarantineMaxRatio,
        ]));
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'policy_id' => $this->id,
            'comparison' => $this->comparison,
            'blank_field' => $this->blankField,
            'quarantine' => match (true) {
                ! $this->quarantineResolved() => 'UNRESOLVED',
                $this->quarantineMaxRows === 0 => 'ZERO_UNRESOLVED_QUARANTINE',
                default => ['max_rows' => $this->quarantineMaxRows, 'max_ratio' => $this->quarantineMaxRatio],
            },
        ];
    }
}
