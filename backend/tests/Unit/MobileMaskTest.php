<?php

namespace Tests\Unit;

use App\Support\FamilyAuth\ActivationConfirmations;
use App\Support\MobileMask;
use PHPUnit\Framework\TestCase;

/**
 * The single Family Portal mobile mask (docs/11 §23a). Synthetic values only.
 * The mask is screen privacy: it never shows more than the last three digits
 * and never invents a prefix for a value that is not a valid mobile.
 */
class MobileMaskTest extends TestCase
{
    public function test_a_valid_mobile_shows_05_and_the_last_three_digits(): void
    {
        $this->assertSame('05*****567', MobileMask::mask('0591234567'));
        // Spaces, dashes and Arabic-Indic digits are cleaned before the check.
        $this->assertSame('05*****567', MobileMask::mask('059-123 4567'));
        $this->assertSame('05*****567', MobileMask::mask('٠٥٩١٢٣٤٥٦٧'));
    }

    public function test_the_activation_confirmation_keeps_the_same_convention(): void
    {
        $this->assertSame('05*****567', ActivationConfirmations::mask('0591234567'));
        $this->assertSame(MobileMask::normalized('0591234567'), ActivationConfirmations::mask('0591234567'));
    }

    public function test_absent_values_stay_null(): void
    {
        $this->assertNull(MobileMask::mask(null));
        $this->assertNull(MobileMask::mask(''));
        $this->assertNull(MobileMask::mask('   '));
    }

    public function test_a_non_standard_value_gets_no_invented_prefix_and_never_shows_more_than_half(): void
    {
        $this->assertSame('*****567', MobileMask::mask('+970591234567'));
        $this->assertSame('*****45', MobileMask::mask('12345'));
        $this->assertSame('*****', MobileMask::mask('1'));
        foreach (['+970591234567', '12345', '1'] as $value) {
            $this->assertStringStartsNotWith('05', (string) MobileMask::mask($value));
        }
    }

    public function test_a_value_too_long_to_clean_is_still_masked_not_dropped(): void
    {
        $long = str_repeat('9', 300).'123';

        $this->assertSame('*****123', MobileMask::mask($long));
    }
}
