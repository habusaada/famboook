<?php

namespace Tests\Support\FamilyPortal;

use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use App\Support\FamilyPortal\RegistryAdmission\ReleasePinVerification;
use Illuminate\Database\Connection;
use LogicException;

/**
 * The lookup side of the pin over SyntheticReleasePinFixture (ADD-WP-07B-2b): the same
 * query CivilRegistryReader::releasePinHeld() sends, on its own session, against the
 * synthetic schema. Tests only; bound nowhere.
 */
final class SyntheticReleasePinVerifier implements RegistryReleasePinVerifier
{
    public function __construct(private readonly Connection $lookup, private readonly string $schema = SyntheticReleasePinFixture::SCHEMA)
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The synthetic release pin verifier runs in tests only.');
        }
    }

    public function releasePinHeld(int $holderPid, int $nonce): ReleasePinVerification
    {
        return ReleasePinVerification::fromRow($this->lookup->selectOne(
            "SELECT held, active_release_id FROM {$this->schema}.release_pin_held(?, ?)", [$holderPid, $nonce]));
    }
}
