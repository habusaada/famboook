<?php

namespace Tests\Unit\FamilyPortal;

use App\Models\Family;
use App\Models\User;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit as Audit;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-1: an admission success is never recorded outside a
 * transaction. No RefreshDatabase here: it would wrap the test in one.
 */
class FamilyRegistryAdmissionAuditGuardTest extends TestCase
{
    use FamilyIdentityFixtures;

    public function test_a_success_outside_a_transaction_is_refused_before_any_write(): void
    {
        $this->useFamilyAuthKey();
        $this->assertSame(0, DB::transactionLevel());
        DB::enableQueryLog();

        try {
            Audit::admissionSucceeded((new User)->forceFill(['id' => 1]), (new Family)->forceFill(['id' => 1]), 3, Audit::PERSON_CREATED, '401234567', null);
            $this->fail('A success was recorded outside a transaction.');
        } catch (LogicException $e) {
            $this->assertStringNotContainsString('401234567', $e->getMessage());
        }
        $this->assertSame([], DB::getQueryLog());
    }
}
