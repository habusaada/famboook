<?php

namespace Tests\Support\FamilyPortal;

use App\Support\FamilyPortal\RegistryAdmission\LifeVerification;
use App\Support\FamilyPortal\RegistryAdmission\LifeVerificationStatus;
use App\Support\FamilyPortal\RegistryAdmission\RegistryLifeVerifier;
use DateTimeImmutable;
use LogicException;
use RuntimeException;

/**
 * TESTS ONLY (FB-FAMILY-ADD-01 RR-02): a synthetic authoritative life source. It is NOT
 * evidence of anything: it answers what a test tells it, per synthetic National ID, and
 * throws for an ID it was told to fail. Refused outside the test runner; bound by tests only.
 */
final class SyntheticLifeVerifier implements RegistryLifeVerifier
{
    /** @var array<string, LifeVerification|'FAIL'> */
    private array $answers = [];

    /** @var list<string> every National ID asked, in order */
    public array $asked = [];

    public function __construct()
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The synthetic life source exists in tests only.');
        }
    }

    public function answer(string $nationalId, LifeVerificationStatus $status, ?DateTimeImmutable $at): self
    {
        $this->answers[$nationalId] = new LifeVerification($status, $at);

        return $this;
    }

    public function fail(string $nationalId): self
    {
        $this->answers[$nationalId] = 'FAIL';

        return $this;
    }

    public function verify(#[\SensitiveParameter] string $nationalId): LifeVerification
    {
        $this->asked[] = $nationalId;
        $answer = $this->answers[$nationalId] ?? new LifeVerification(LifeVerificationStatus::UNKNOWN, null);
        if ($answer === 'FAIL') {
            throw new RuntimeException('The synthetic life source is unavailable for '.$nationalId);
        }

        return $answer;
    }
}
