<?php

namespace Tests\Feature\FamilyAuth;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PWA-1C CHECK constraints (docs/04 §55b). They exist on PostgreSQL only —
 * the repository convention — so this class is skipped on the default SQLite
 * suite and is run against a dedicated local `_test` PostgreSQL database.
 * Rows are written with the query builder so the database, not an enum
 * cast, is what refuses them. Synthetic data only.
 */
class IdentitySchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private const HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are PostgreSQL-only (docs/04 §55b).');
        }
    }

    /** @param array<string, mixed> $row */
    private function accepts(string $table, array $row): void
    {
        DB::transaction(fn () => DB::table($table)->insert($row));
        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $row */
    private function refuses(string $table, array $row, string $constraint): void
    {
        try {
            DB::transaction(fn () => DB::table($table)->insert($row));
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        $this->fail("{$table} accepted a row that {$constraint} must refuse.");
    }

    /** @return array<string, mixed> */
    private function stamps(): array
    {
        return ['uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_users_email_is_nullable_in_postgresql(): void
    {
        $nullable = DB::selectOne(
            "select is_nullable from information_schema.columns where table_schema = current_schema() and table_name = 'users' and column_name = 'email'"
        )->is_nullable;

        $this->assertSame('YES', $nullable);
        $this->assertNull(User::factory()->familySide()->create()->fresh()->email);
        $this->assertNull(User::factory()->familySide()->create()->fresh()->email);
    }

    public function test_user_person_link_checks(): void
    {
        $base = fn (array $o = []) => [
            ...$this->stamps(), 'user_id' => User::factory()->familySide()->create()->id, 'person_id' => Person::factory()->create()->id,
            'link_type' => 'SELF', 'status' => 'ACTIVE', 'verification_method' => 'SYSTEM_OTP_ACTIVATION',
            'verified_at' => now(), 'activated_at' => now(), ...$o,
        ];

        $this->accepts('user_person_links', $base());
        $this->accepts('user_person_links', $base(['status' => 'PENDING_VERIFICATION', 'verified_at' => null, 'activated_at' => null]));
        $this->accepts('user_person_links', $base(['status' => 'ENDED', 'ended_at' => now(), 'end_reason' => 'PERSON_DECEASED']));

        $this->refuses('user_person_links', $base(['link_type' => 'GUARDIAN']), 'chk_user_person_link_type');
        $this->refuses('user_person_links', $base(['status' => 'ENABLED']), 'chk_user_person_link_status');
        $this->refuses('user_person_links', $base(['verification_method' => 'SELF_DECLARED']), 'chk_user_person_link_method');
        $this->refuses('user_person_links', $base(['verified_at' => null]), 'chk_user_person_link_verified');
        $this->refuses('user_person_links', $base(['status' => 'SUSPENDED', 'verified_at' => null]), 'chk_user_person_link_verified');
        $this->refuses('user_person_links', $base(['status' => 'ENDED']), 'chk_user_person_link_ended');
        $this->refuses('user_person_links', $base(['status' => 'ENDED', 'ended_at' => now()]), 'chk_user_person_link_ended');
        $this->refuses('user_person_links', $base(['status' => 'ENDED', 'ended_at' => now(), 'end_reason' => 'free text reason']), 'chk_user_person_link_reasons');
    }

    public function test_family_auth_identity_checks(): void
    {
        $base = fn (array $o = []) => [
            ...$this->stamps(), 'user_id' => User::factory()->familySide()->create()->id,
            'login_key' => hash('sha256', (string) Str::uuid()), 'key_version' => 1, 'status' => 'ACTIVE', ...$o,
        ];

        $this->accepts('family_auth_identities', $base());
        $this->accepts('family_auth_identities', $base(['status' => 'SUPERSEDED', 'superseded_at' => now(), 'supersede_reason' => 'KEY_ROTATION']));

        $this->refuses('family_auth_identities', $base(['status' => 'DISABLED']), 'chk_family_auth_identity_status');
        // A raw National ID (or anything that is not a 64-hex fingerprint) can never be stored.
        $this->refuses('family_auth_identities', $base(['login_key' => '123456789']), 'chk_family_auth_identity_key');
        $this->refuses('family_auth_identities', $base(['login_key' => strtoupper(self::HEX)]), 'chk_family_auth_identity_key');
        $this->refuses('family_auth_identities', $base(['key_version' => 0]), 'chk_family_auth_identity_key');
        $this->refuses('family_auth_identities', $base(['status' => 'SUPERSEDED']), 'chk_family_auth_identity_superseded');
        $this->refuses('family_auth_identities', $base(['superseded_at' => now()]), 'chk_family_auth_identity_superseded');
        $this->refuses(
            'family_auth_identities',
            $base(['status' => 'SUPERSEDED', 'superseded_at' => now(), 'supersede_reason' => 'OTHER']),
            'chk_family_auth_identity_superseded',
        );
    }

    public function test_person_mobile_trust_checks(): void
    {
        $staff = User::factory()->create()->id;
        $base = fn (array $o = []) => [
            ...$this->stamps(), 'person_id' => Person::factory()->create()->id, 'mobile_fingerprint' => self::HEX,
            'mobile_last2' => '67', 'key_version' => 1, 'status' => 'PENDING_VERIFICATION', ...$o,
        ];
        $trusted = ['status' => 'TRUSTED', 'verification_method' => 'IN_PERSON', 'verified_by' => $staff, 'verified_at' => now()];

        $this->accepts('person_mobile_trusts', $base());
        // The same fingerprint for another Person: shared numbers are valid.
        $this->accepts('person_mobile_trusts', $base($trusted));
        $this->accepts('person_mobile_trusts', $base([...$trusted, 'verification_method' => 'AUTHORIZED_RECORD_REVIEW']));

        $this->refuses('person_mobile_trusts', $base(['status' => 'VERIFIED']), 'chk_person_mobile_trust_status');
        $this->refuses('person_mobile_trusts', $base(['verification_method' => 'SMS_ONLY']), 'chk_person_mobile_trust_method');
        // A raw mobile number can never be stored as the fingerprint.
        $this->refuses('person_mobile_trusts', $base(['mobile_fingerprint' => '0591234567']), 'chk_person_mobile_trust_value');
        $this->refuses('person_mobile_trusts', $base(['mobile_last2' => 'ab']), 'chk_person_mobile_trust_value');
        $this->refuses('person_mobile_trusts', $base(['status' => 'TRUSTED']), 'chk_person_mobile_trust_granted');
        $this->refuses('person_mobile_trusts', $base([...$trusted, 'verified_by' => null]), 'chk_person_mobile_trust_granted');
        $this->refuses('person_mobile_trusts', $base([...$trusted, 'verification_method' => null]), 'chk_person_mobile_trust_granted');
        $this->refuses('person_mobile_trusts', $base(['status' => 'REVOKED']), 'chk_person_mobile_trust_revoked');
        $this->refuses('person_mobile_trusts', $base(['status' => 'STALE']), 'chk_person_mobile_trust_stale');
        $this->refuses('person_mobile_trusts', $base(['assisted_by' => $staff]), 'chk_person_mobile_trust_assisted');
    }

    public function test_auth_otp_challenge_checks(): void
    {
        $base = function (array $o = []) {
            $trust = PersonMobileTrust::factory()->trusted()->create();

            return [
                ...$this->stamps(), 'purpose' => 'ACTIVATION', 'person_id' => $trust->person_id, 'mobile_trust_id' => $trust->id,
                'code_hash' => self::HEX, 'expires_at' => now()->addMinutes(5), 'attempts' => 0, 'send_count' => 1,
                'last_sent_at' => now(), ...$o,
            ];
        };

        $this->accepts('auth_otp_challenges', $base());
        $this->accepts('auth_otp_challenges', $base(['purpose' => 'PASSWORD_RESET', 'user_id' => User::factory()->familySide()->create()->id]));

        $this->refuses('auth_otp_challenges', $base(['purpose' => 'LOGIN']), 'chk_auth_otp_challenge_purpose');
        // A plaintext code can never be stored.
        $this->refuses('auth_otp_challenges', $base(['code_hash' => '123456']), 'chk_auth_otp_challenge_hash');
        $this->refuses('auth_otp_challenges', $base(['send_count' => 0]), 'chk_auth_otp_challenge_counters');
        $this->refuses('auth_otp_challenges', $base(['purpose' => 'PASSWORD_RESET']), 'chk_auth_otp_challenge_reset_user');
        $this->refuses('auth_otp_challenges', $base(['grant_expires_at' => now()]), 'chk_auth_otp_challenge_grant');
    }

    public function test_auth_security_event_checks(): void
    {
        $base = fn (array $o = []) => [
            'uuid' => (string) Str::uuid(), 'event_type' => 'LOGIN_FAILED', 'outcome' => 'FAILURE', 'created_at' => now(), ...$o,
        ];

        $this->accepts('auth_security_events', $base());
        $this->accepts('auth_security_events', $base(['reason_code' => 'PERSON_NOT_ALIVE', 'login_key' => self::HEX, 'user_agent_hash' => self::HEX]));

        $this->refuses('auth_security_events', $base(['outcome' => 'MAYBE']), 'chk_auth_security_event_codes');
        $this->refuses('auth_security_events', $base(['event_type' => 'login failed']), 'chk_auth_security_event_codes');
        $this->refuses('auth_security_events', $base(['reason_code' => 'the id was 123456789']), 'chk_auth_security_event_codes');
        // A raw National ID can never be stored as the login key.
        $this->refuses('auth_security_events', $base(['login_key' => '123456789']), 'chk_auth_security_event_hashes');
        $this->refuses('auth_security_events', $base(['user_agent_hash' => 'Mozilla/5.0']), 'chk_auth_security_event_hashes');
    }

    public function test_coordinator_scope_assignment_checks(): void
    {
        $clan = Clan::query()->firstOrCreate(['code' => 'TEST_CLAN'], ['name' => 'عشيرة تجريبية']);
        $group = BranchGroup::create(['clan_id' => $clan->id, 'code' => 'G01', 'name' => 'مجموعة تجريبية', 'sort_order' => 1]);
        $branch = Branch::create(['branch_group_id' => $group->id, 'clan_id' => $clan->id, 'code' => 'BR_A', 'name' => 'فرع أ', 'sort_order' => 1]);
        $admin = User::factory()->create()->id;
        $base = fn (array $o = []) => [
            ...$this->stamps(), 'user_id' => User::factory()->familySide()->create()->id, 'scope_type' => 'CLAN',
            'clan_id' => $clan->id, 'assigned_by' => $admin, 'assigned_at' => now(), ...$o,
        ];

        $this->accepts('coordinator_scope_assignments', $base());
        $this->accepts('coordinator_scope_assignments', $base(['scope_type' => 'BRANCH_GROUP', 'branch_group_id' => $group->id]));
        $this->accepts('coordinator_scope_assignments', $base(['scope_type' => 'BRANCH', 'branch_id' => $branch->id]));
        $this->accepts('coordinator_scope_assignments', $base(['revoked_at' => now(), 'revoked_by' => $admin, 'revoke_reason' => 'ADMINISTRATIVE']));

        $shape = 'chk_coordinator_scope_shape';
        $this->refuses('coordinator_scope_assignments', $base(['scope_type' => 'REGION']), $shape);
        $this->refuses('coordinator_scope_assignments', $base(['branch_id' => $branch->id]), $shape);
        $this->refuses('coordinator_scope_assignments', $base(['branch_group_id' => $group->id]), $shape);
        $this->refuses('coordinator_scope_assignments', $base(['scope_type' => 'BRANCH_GROUP']), $shape);
        $this->refuses('coordinator_scope_assignments', $base(['scope_type' => 'BRANCH_GROUP', 'branch_group_id' => $group->id, 'branch_id' => $branch->id]), $shape);
        $this->refuses('coordinator_scope_assignments', $base(['scope_type' => 'BRANCH']), $shape);
        $this->refuses('coordinator_scope_assignments', $base(['scope_type' => 'BRANCH', 'branch_id' => $branch->id, 'branch_group_id' => $group->id]), $shape);
        $this->refuses('coordinator_scope_assignments', $base(['revoked_at' => now()]), 'chk_coordinator_scope_revoked');
        $this->refuses('coordinator_scope_assignments', $base(['revoked_at' => now(), 'revoked_by' => $admin]), 'chk_coordinator_scope_revoked');
    }

    public function test_persons_national_id_has_no_unique_index(): void
    {
        $unique = DB::select(
            "select indexname from pg_indexes where schemaname = current_schema() and tablename = 'persons' "
            ."and indexdef ilike '%unique%' and indexdef ilike '%national_id%'"
        );

        $this->assertSame([], $unique);
    }
}
