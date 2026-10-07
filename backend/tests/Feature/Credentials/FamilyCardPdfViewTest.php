<?php

namespace Tests\Feature\Credentials;

use App\Actions\IssueFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\DigitalCredential;
use App\Support\Credentials\CredentialTokens;
use App\Support\Credentials\FamilyCardPdf;
use App\Support\Credentials\FamilyCardView;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-8.3: the HTML handed to mPDF for the printable Digital Family Card
 * (docs/11 FP-ADR-071) — exactly the approved fields, the QR as a PNG data
 * URI, Latin identifiers isolated left-to-right, and nothing sensitive or
 * internal; the verification URL / token never as text. Synthetic data only.
 */
class FamilyCardPdfViewTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const DISCLAIMER = 'وسيلة تحقق رقمية ضمن نظام Famboook، وليست وثيقة هوية رسمية. يُثبت رمز QR صلاحية البطاقة فقط، ولا يُثبت هوية الشخص الذي يحملها.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->useFamilyAuthKey();
        config(['credentials.verify_base_url' => 'https://famboook.test/verify/']);
    }

    /** @return array{0: string, 1: FamilyCardView, 2: DigitalCredential, 3: array<string, mixed>} */
    private function render(bool $withBranch = true): array
    {
        $head = $this->activatedHead();
        $head['person']->forceFill([
            'full_name' => 'عبد الرحمن محمد عبد الله أحمد الاختبار', 'mobile' => '0591234567',
            'alternate_mobile' => '0567654321', 'birth_date' => '1980-01-15',
        ])->save();
        // A Family always has a Clan (NOT NULL); the Branch is optional.
        $clan = Clan::firstOrCreate(['code' => 'TEST_CLAN'], ['name' => 'عشيرة الاختبار']);
        $branch = $withBranch ? Branch::create(['clan_id' => $clan->id, 'code' => 'BR_TEST', 'name' => 'فرع الاختبار', 'is_active' => true]) : null;
        $head['family']->forceFill(['clan_id' => $clan->id, 'branch_id' => $branch?->id])->save();
        Carbon::setTestNow('2026-10-16 10:00:00');
        $credential = app(IssueFamilyCredentialAction::class)->handle($head['family'], CredentialIssueChannel::FAMILY_PORTAL, null);
        Carbon::setTestNow();
        $context = app(FamilyAccessResolver::class)->familyContext($head['user']->fresh());
        $view = FamilyCardView::forOwner($context, $credential);

        return [FamilyCardPdf::html($view), $view, $credential, $head];
    }

    public function test_the_view_contains_exactly_the_approved_card_fields(): void
    {
        [$html, , $credential, $head] = $this->render();

        $this->assertStringContainsString('بطاقة الأسرة الرقمية', $html);
        $this->assertStringContainsString('عبد الرحمن محمد عبد الله أحمد الاختبار', $html);
        $this->assertStringContainsString('<bdo dir="ltr">'.$credential->credential_number.'</bdo>', $html);
        $this->assertStringContainsString('<bdo dir="ltr">'.$head['family']->family_code.'</bdo>', $html);
        $this->assertStringContainsString('عشيرة الاختبار', $html);
        $this->assertStringContainsString('فرع الاختبار', $html);
        $this->assertStringContainsString('16 أكتوبر 2026', $html);
        $this->assertStringContainsString('امسح رمز QR للتحقق من صلاحية البطاقة عبر Famboook.', $html);
        $this->assertStringContainsString(self::DISCLAIMER, $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertMatchesRegularExpression('/<img src="data:image\/png;base64,[A-Za-z0-9+\/=]+"/', $html);
        $this->assertStringContainsString(resource_path('pdf/famboook-logo.svg'), $html);
    }

    public function test_the_branch_row_is_omitted_when_the_family_has_no_branch(): void
    {
        [$html] = $this->render(withBranch: false);

        $this->assertStringContainsString('عشيرة الاختبار', $html);
        $this->assertStringNotContainsString('الفرع', $html);
    }

    public function test_the_verification_url_and_token_never_appear_as_text(): void
    {
        [$html, $view, $credential] = $this->render();
        $token = CredentialTokens::reveal($credential);
        $text = preg_replace('/src="[^"]*"/', 'src=""', $html);

        $this->assertNotNull($view->verificationUrl);
        $this->assertStringNotContainsString($token, $text);
        $this->assertStringNotContainsString('verify', $text);
        $this->assertStringNotContainsString($credential->token_hash, $html);
        $this->assertStringNotContainsString($credential->token_encrypted, $html);
    }

    public function test_nothing_sensitive_or_internal_is_in_the_view(): void
    {
        [$html, , $credential, $head] = $this->render();

        foreach ([$head['person']->national_id, '0591234567', '0567654321', '1980-01-15', $head['person']->person_code,
            '"id"', 'family_id', 'person_id', 'uuid', 'issued_by', 'السكن', 'العنوان', 'عدد أفراد', 'الصحة', 'إعاقة',
            'الاحتياج', 'المساعد', 'التقييم'] as $value) {
            $this->assertStringNotContainsString((string) $value, $html, (string) $value);
        }
        $this->assertNotNull($credential);
    }

    public function test_the_issue_date_uses_arabic_month_names_with_latin_digits(): void
    {
        [, $view] = $this->render();

        $this->assertSame('16 أكتوبر 2026', $view->issuedAtLabel());
        $this->assertMatchesRegularExpression('/\A[0-9]{1,2} \p{Arabic}+ [0-9]{4}\z/u', $view->issuedAtLabel());
    }
}
