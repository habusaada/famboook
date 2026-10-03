<?php

namespace Tests\Unit\FamilyAuth;

use App\Support\FamilyAuth\FamilyMobile;
use App\Support\FamilyAuth\FamilyNationalId;
use App\Support\NationalId;
use PHPUnit\Framework\TestCase;

/**
 * The strict Family Portal normalizers (docs/11 §30a). Synthetic values
 * only. The point of every rejection below: arbitrary input must never be
 * stripped into a valid identifier.
 */
class FamilyNormalizersTest extends TestCase
{
    public function test_nine_ascii_digits_are_a_valid_national_id(): void
    {
        $this->assertSame('123456789', FamilyNationalId::normalize('123456789'));
        $this->assertSame('012345678', FamilyNationalId::normalize('012345678'));
    }

    public function test_arabic_indic_and_persian_digits_become_ascii(): void
    {
        $this->assertSame('123456789', FamilyNationalId::normalize('١٢٣٤٥٦٧٨٩'));
        $this->assertSame('123456789', FamilyNationalId::normalize('۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('123456789', FamilyNationalId::normalize('١٢٣45۶۷۸9'));
    }

    public function test_only_approved_whitespace_marks_and_separators_are_removed(): void
    {
        foreach ([
            ' 123456789 ', "123 456\t789", "123456789\n", "123\u{00A0}456\u{00A0}789", "\u{200F}123456789\u{200E}",
            "\u{061C}123456789", '123-456-789', '123.456.789', '123/456/789', '123_456_789', "\u{2009}1234 56789",
        ] as $input) {
            $this->assertSame('123456789', FamilyNationalId::normalize($input), json_encode($input));
        }
    }

    public function test_each_approved_invisible_formatting_character_is_removed(): void
    {
        $approved = [
            0x200B, 0x200C, 0x200D,                 // ZWSP, ZWNJ, ZWJ
            0x202A, 0x202B, 0x202C, 0x202D, 0x202E, // LRE, RLE, PDF, LRO, RLO
            0x2066, 0x2067, 0x2068, 0x2069,         // LRI, RLI, FSI, PDI
            0xFEFF,                                 // BOM / ZWNBSP
        ];
        foreach ($approved as $codepoint) {
            $char = mb_chr($codepoint, 'UTF-8');
            foreach (["{$char}123456789", "1234{$char}56789", "123456789{$char}"] as $input) {
                $this->assertSame('123456789', FamilyNationalId::normalize($input), sprintf('U+%04X', $codepoint));
            }
        }
        // A pasted, RTL-isolated and joined number with Arabic-Indic digits.
        $this->assertSame('123456789', FamilyNationalId::normalize("\u{FEFF}\u{2067}١٢٣\u{200D}٤٥٦\u{200B}٧٨٩\u{2069}"));
    }

    public function test_characters_outside_the_approved_ranges_are_still_rejected(): void
    {
        // Neighbours of the approved ranges and other invisible format
        // characters: not approved as harmless, so never stripped.
        $rejected = [
            0x2060, // WORD JOINER
            0x2065, // unassigned
            0x206A, // INHIBIT SYMMETRIC SWAPPING
            0x00AD, // SOFT HYPHEN
            0x180E, // MONGOLIAN VOWEL SEPARATOR
            0x201F, // a quotation mark
            0xFEFE, // an Arabic presentation form
        ];
        foreach ($rejected as $codepoint) {
            $this->assertNull(
                FamilyNationalId::normalize('1234'.mb_chr($codepoint, 'UTF-8').'56789'),
                sprintf('U+%04X must not be stripped', $codepoint),
            );
        }
        // Stripping never yields anything but exactly nine ASCII digits.
        $this->assertNull(FamilyNationalId::normalize("12345678\u{200B}"));
        $this->assertNull(FamilyNationalId::normalize("1234567890\u{FEFF}"));
    }

    public function test_a_national_id_must_be_exactly_nine_digits(): void
    {
        foreach (['', ' ', '12345678', '1234567890', '00000000', '12345678901234567890'] as $input) {
            $this->assertNull(FamilyNationalId::normalize($input), json_encode($input));
        }
    }

    public function test_garbage_is_rejected_never_stripped_into_a_valid_national_id(): void
    {
        foreach ([
            '12a345678-9x', 'A123456789', '123456789a', '123456789!', '+123456789', '(123)456789', '123,456,789',
            '123456789;', '12345678９', "123456789\0", '1234567O9', '#123456789', '123456789=', '١٢٣٤٥٦٧٨٩x',
        ] as $input) {
            $this->assertNull(FamilyNationalId::normalize($input), json_encode($input));
        }
    }

    public function test_non_string_overlong_and_malformed_input_is_rejected(): void
    {
        foreach ([null, 123456789, 123456789.0, true, ['123456789'], new \stdClass] as $input) {
            $this->assertNull(FamilyNationalId::normalize($input));
        }
        // Valid once cleaned, but longer than any real input: refused outright.
        $this->assertNull(FamilyNationalId::normalize(str_repeat(' ', 30).'123456789'));
        // Malformed UTF-8.
        $this->assertNull(FamilyNationalId::normalize("12345678\xC3"));
    }

    public function test_valid_mobiles_are_ten_digits_beginning_05(): void
    {
        $this->assertSame('0591234567', FamilyMobile::normalize('0591234567'));
        $this->assertSame('0561234567', FamilyMobile::normalize('٠٥٦١٢٣٤٥٦٧'));
        $this->assertSame('0591234567', FamilyMobile::normalize(' 059-123 4567 '));
        $this->assertSame('0591234567', FamilyMobile::normalize("059\u{00A0}123.4567"));
    }

    public function test_other_mobile_shapes_are_not_valid(): void
    {
        foreach ([
            '', '591234567', '059123456', '05912345678', '0691234567', '1591234567', '+970591234567', '00970591234567',
            '970591234567', '0591234567x', '0591234567 / 0561234567', '(059)1234567', '059123456a', null, 591234567,
        ] as $input) {
            $this->assertNull(FamilyMobile::normalize($input), json_encode($input));
        }
    }

    public function test_the_existing_comparison_normalizer_is_unchanged(): void
    {
        // It keeps and upper-cases letters and is NOT the login contract.
        $this->assertSame('12A3456789X', NationalId::normalize('12a345678-9x'));
        $this->assertSame('123456789', NationalId::normalize(' ١٢٣-٤٥٦.٧٨٩ '));
        $this->assertNull(FamilyNationalId::normalize('12a345678-9x'));
    }
}
