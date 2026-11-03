<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use stdClass;
use UnexpectedValueException;

/** One answer of the lookup-side verification (ADD-WP-07B-2b). */
final class ReleasePinVerification
{
    public function __construct(
        /** The holder session holds both the shared pin and its nonce, in one transaction, in the verifier's database. */
        public readonly bool $held,
        /** The active release in the same statement; NULL when none is published. */
        public readonly ?int $activeReleaseId,
    ) {}

    /** From the row `SELECT held, active_release_id FROM …release_pin_held(…)`; anything else is refused. */
    public static function fromRow(mixed $row): self
    {
        if (! $row instanceof stdClass || ! is_bool($row->held ?? null)) {
            throw new UnexpectedValueException('The release pin verification answered unexpectedly.');
        }
        $active = $row->active_release_id ?? null;
        if (is_string($active) && preg_match('/\A[1-9][0-9]{0,18}\z/', $active) === 1) {
            $active = (int) $active;
        }
        if ($active !== null && (! is_int($active) || $active < 1)) {
            throw new UnexpectedValueException('The release pin verification answered unexpectedly.');
        }

        return new self($row->held, $active);
    }
}
