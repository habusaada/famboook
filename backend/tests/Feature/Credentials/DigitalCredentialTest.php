<?php

namespace Tests\Feature\Credentials;

use App\Actions\IssueFamilyCredentialAction;
use App\Actions\ReissueFamilyCredentialAction;
use App\Actions\RevokeFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Enums\CredentialRevokeReason;
use App\Enums\FamilyActivityType;
use App\Exceptions\CredentialException;
use App\Models\DigitalCredential;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\Credentials\CredentialNumbers;
use App\Support\Credentials\CredentialResolver;
use App\Support\Credentials\CredentialTokens;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * PWA-8.2: the Digital Family Card credential — schema, model guard, token and
 * number services, and the issue / revoke / reissue Domain Actions (docs/11
 * FP-ADR-070). Synthetic data only.
 */
class DigitalCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function issue(Family $family, ?User $by = null, bool $existing = false): DigitalCredential
    {
        return app(IssueFamilyCredentialAction::class)->handle(
            $family, $by ? CredentialIssueChannel::STAFF : CredentialIssueChannel::FAMILY_PORTAL, $by?->id, $existing,
        );
    }

    // --------------------------------------------------------------- services

    public function test_a_token_is_43_url_safe_characters_never_repeated_and_hashed_with_sha256(): void
    {
        $tokens = array_map(fn () => CredentialTokens::generate(), range(1, 50));

        $this->assertCount(50, array_unique($tokens));
        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
            $this->assertTrue(CredentialTokens::isWellFormed($token));
            $this->assertSame(hash('sha256', $token), CredentialTokens::hash($token));
        }
        foreach ([null, '', 'short', str_repeat('a', 44), str_repeat('a', 42).'=', ['x'], 123] as $bad) {
            $this->assertFalse(CredentialTokens::isWellFormed($bad));
        }
    }

    public function test_seal_and_reveal_round_trip_and_an_unreadable_copy_reveals_nothing_without_logging_it(): void
    {
        $family = Family::factory()->create();
        $credential = $this->issue($family);
        $this->assertTrue(CredentialTokens::isWellFormed(CredentialTokens::reveal($credential)));
        $this->assertSame(CredentialTokens::hash(CredentialTokens::reveal($credential)), $credential->token_hash);

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.json_encode($message->context);
        });
        $credential->setRawAttributes([...$credential->getAttributes(), 'token_encrypted' => 'not-a-valid-payload']);

        $this->assertNull(CredentialTokens::reveal($credential));
        $this->assertStringNotContainsString('not-a-valid-payload', implode("\n", $logged));
    }

    public function test_card_numbers_follow_the_public_format(): void
    {
        foreach (range(1, 200) as $_) {
            $this->assertMatchesRegularExpression(CredentialNumbers::FORMAT, CredentialNumbers::candidate());
        }
        $this->assertMatchesRegularExpression('/\AFC-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{2}\z/', CredentialNumbers::generate());
    }

    // ------------------------------------------------------------------ issue

    public function test_issue_creates_one_active_credential_with_a_sealed_token_and_a_card_number(): void
    {
        $family = Family::factory()->create();
        $credential = $this->issue($family);

        $this->assertTrue($credential->isActive());
        $this->assertSame('FAMILY', $credential->subject_type->value);
        $this->assertSame($family->id, $credential->family_id);
        $this->assertSame(1, $credential->token_version);
        $this->assertNull($credential->issued_by);
        $this->assertMatchesRegularExpression(CredentialNumbers::FORMAT, $credential->credential_number);
        // The token is stored only as a hash and a sealed copy.
        $token = CredentialTokens::reveal($credential);
        $this->assertStringNotContainsString($token, json_encode($credential->getAttributes()));
        $this->assertNotSame($family->family_code, $credential->credential_number);
    }

    public function test_lazy_issuance_returns_the_existing_card_and_staff_issuance_refuses_a_second(): void
    {
        $family = Family::factory()->create();
        $first = $this->issue($family);

        $this->assertTrue($first->is($this->issue($family, existing: true)));
        try {
            $this->issue($family, User::factory()->create());
            $this->fail('A second ACTIVE card was issued.');
        } catch (CredentialException $e) {
            $this->assertSame(CredentialException::CARD_ALREADY_ACTIVE, $e->reason);
        }
        $this->assertSame(1, DigitalCredential::count());
    }

    public function test_the_database_allows_one_active_credential_per_family(): void
    {
        $family = Family::factory()->create();
        $this->issue($family);

        $this->expectException(QueryException::class);
        DigitalCredential::create([
            'subject_type' => 'FAMILY', 'family_id' => $family->id, 'credential_number' => CredentialNumbers::generate(),
            'token_hash' => CredentialTokens::hash(CredentialTokens::generate()), 'token_encrypted' => 'x',
            'status' => 'ACTIVE', 'issued_at' => now(),
        ]);
    }

    public function test_issuance_requires_an_active_family(): void
    {
        foreach (['INACTIVE', 'ARCHIVED'] as $status) {
            $family = Family::factory()->create(['status' => $status]);
            $this->assertRefused(CredentialException::FAMILY_NOT_ACTIVE, fn () => $this->issue($family, existing: true));
        }
        $deleted = Family::factory()->create();
        $deleted->delete();
        $this->assertRefused(CredentialException::FAMILY_NOT_ACTIVE, fn () => $this->issue($deleted, existing: true));
        $this->assertSame(0, DigitalCredential::count());
    }

    public function test_the_issuance_switch_blocks_new_cards_but_not_an_existing_one(): void
    {
        $withCard = Family::factory()->create();
        $card = $this->issue($withCard);
        config(['credentials.family_card_issuance_enabled' => false]);

        $this->assertTrue($card->is($this->issue($withCard, existing: true)));
        $this->assertRefused(CredentialException::ISSUANCE_DISABLED, fn () => $this->issue(Family::factory()->create(), existing: true));
        $this->assertRefused(CredentialException::ISSUANCE_DISABLED, fn () => $this->issue(Family::factory()->create(), User::factory()->create()));
        $this->assertRefused(CredentialException::ISSUANCE_DISABLED, fn () => app(ReissueFamilyCredentialAction::class)->handle($withCard, User::factory()->create()->id));
        // Revocation stays available.
        app(RevokeFamilyCredentialAction::class)->handle($withCard, CredentialRevokeReason::ADMINISTRATIVE, User::factory()->create()->id);
        $this->assertFalse($card->fresh()->isActive());
        $this->assertSame(1, DigitalCredential::count());
    }

    // ----------------------------------------------------- revoke / reissue

    public function test_revoke_records_the_reason_and_keeps_the_row(): void
    {
        $family = Family::factory()->create();
        $card = $this->issue($family);
        $staff = User::factory()->create();

        app(RevokeFamilyCredentialAction::class)->handle($family, CredentialRevokeReason::COMPROMISED, $staff->id);

        $card->refresh();
        $this->assertSame(['REVOKED', 'COMPROMISED', $staff->id], [$card->status->value, $card->revoke_reason->value, $card->revoked_by]);
        $this->assertNotNull($card->revoked_at);
        $this->assertRefused(CredentialException::NO_ACTIVE_CARD,
            fn () => app(RevokeFamilyCredentialAction::class)->handle($family, CredentialRevokeReason::ADMINISTRATIVE, $staff->id));
    }

    public function test_reissued_is_never_a_staff_revoke_reason(): void
    {
        $family = Family::factory()->create();
        $this->issue($family);

        $this->expectException(InvalidArgumentException::class);
        app(RevokeFamilyCredentialAction::class)->handle($family, CredentialRevokeReason::REISSUED, User::factory()->create()->id);
    }

    public function test_reissue_revokes_the_old_card_and_issues_a_new_number_and_token(): void
    {
        $family = Family::factory()->create();
        $old = $this->issue($family);
        $oldToken = CredentialTokens::reveal($old);

        $new = app(ReissueFamilyCredentialAction::class)->handle($family, User::factory()->create()->id);

        $this->assertSame(['REVOKED', 'REISSUED'], [$old->fresh()->status->value, $old->fresh()->revoke_reason->value]);
        $this->assertTrue($new->isActive());
        $this->assertNotSame($old->credential_number, $new->credential_number);
        $this->assertNotSame($old->token_hash, $new->token_hash);
        $this->assertNull(CredentialResolver::resolveFamily($oldToken));
        $this->assertTrue($new->is(CredentialResolver::resolveFamily(CredentialTokens::reveal($new))));
        $this->assertRefused(CredentialException::NO_ACTIVE_CARD,
            fn () => app(ReissueFamilyCredentialAction::class)->handle(Family::factory()->create(), User::factory()->create()->id));
    }

    // ------------------------------------------------------------- immutable

    public function test_a_credential_is_immutable_apart_from_one_revocation_and_never_deleted(): void
    {
        $family = Family::factory()->create();
        $card = $this->issue($family);

        foreach (['credential_number' => 'FC-0000-0000-00', 'token_hash' => str_repeat('a', 64), 'family_id' => Family::factory()->create()->id] as $column => $value) {
            try {
                $card->fresh()->forceFill([$column => $value])->save();
                $this->fail("{$column} changed.");
            } catch (LogicException) {
            }
        }
        app(RevokeFamilyCredentialAction::class)->handle($family, CredentialRevokeReason::ADMINISTRATIVE, User::factory()->create()->id);
        try {
            $card->fresh()->forceFill(['status' => 'ACTIVE', 'revoked_at' => null, 'revoke_reason' => null])->save();
            $this->fail('A revoked credential was revived.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $card->fresh()->delete();
    }

    public function test_tokens_are_hidden_from_serialization(): void
    {
        $card = $this->issue(Family::factory()->create());

        $this->assertArrayNotHasKey('token_hash', $card->toArray());
        $this->assertArrayNotHasKey('token_encrypted', $card->toArray());
    }

    // --------------------------------------------------------------- activity

    public function test_activity_records_controlled_codes_only_and_never_the_token_or_number(): void
    {
        $family = Family::factory()->create();
        $staff = User::factory()->create();
        $card = $this->issue($family);
        app(RevokeFamilyCredentialAction::class)->handle($family, CredentialRevokeReason::ADMINISTRATIVE, $staff->id);
        $this->issue($family, $staff);
        $reissued = app(ReissueFamilyCredentialAction::class)->handle($family, $staff->id);

        $activities = FamilyActivity::where('family_id', $family->id)->orderBy('id')->get();
        $this->assertSame(
            [FamilyActivityType::FAMILY_CARD_ISSUED, FamilyActivityType::FAMILY_CARD_REVOKED, FamilyActivityType::FAMILY_CARD_ISSUED, FamilyActivityType::FAMILY_CARD_REISSUED],
            $activities->pluck('event_type')->all(),
        );
        $this->assertSame([['issue_channel' => 'FAMILY_PORTAL'], ['revoke_reason' => 'ADMINISTRATIVE'], ['issue_channel' => 'STAFF'], null], $activities->pluck('metadata')->all());
        $this->assertSame([null, $staff->id, $staff->id, $staff->id], $activities->pluck('actor_user_id')->all());
        $raw = $activities->toJson();
        foreach (DigitalCredential::all() as $credential) {
            $this->assertStringNotContainsString(CredentialTokens::reveal($credential), $raw);
            $this->assertStringNotContainsString($credential->token_hash, $raw);
            $this->assertStringNotContainsString($credential->credential_number, $raw);
        }
        $this->assertSame('digital_credential', $activities->last()->subject_type);
        $this->assertSame($reissued->id, $activities->last()->subject_id);
        $this->assertNotNull($card);
    }

    public function test_a_household_head_change_touches_nothing(): void
    {
        $family = Family::factory()->create();
        $head = FamilyMembership::factory()->create(['family_id' => $family->id, 'is_household_head' => true]);
        $card = $this->issue($family);
        $before = $card->fresh()->getAttributes();

        $head->forceFill(['is_household_head' => false])->save();
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => Person::factory()->create()->id, 'is_household_head' => true]);

        $this->assertSame($before, $card->fresh()->getAttributes());
        $this->assertSame(1, DigitalCredential::count());
    }

    private function assertRefused(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected {$reason}.");
        } catch (CredentialException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
