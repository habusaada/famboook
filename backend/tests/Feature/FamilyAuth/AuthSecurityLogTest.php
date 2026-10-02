<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\MobileVerificationMethod;
use App\Enums\OtpPurpose;
use App\Models\AuthSecurityEvent;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilySessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * PWA-1D: the security event recorder and session revocation (docs/11 §30a).
 * Synthetic data only.
 */
class AuthSecurityLogTest extends TestCase
{
    use RefreshDatabase;

    private function sessionRow(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => bin2hex(random_bytes(20)), 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
    }

    public function test_it_records_a_typed_event_with_references_only(): void
    {
        $link = UserPersonLink::factory()->create();
        $staff = User::factory()->create();
        $trust = PersonMobileTrust::factory()->create(['person_id' => $link->person_id]);

        $event = AuthSecurityLog::record(
            AuthSecurityEventType::MOBILE_TRUST_OPENED,
            AuthSecurityEventOutcome::SUCCESS,
            MobileVerificationMethod::IN_PERSON,
            person: $link->person,
            user: $link->user,
            actor: $staff,
            link: $link,
            trust: $trust,
            loginKey: hash('sha256', 'synthetic'),
            metadata: ['purpose' => OtpPurpose::ACTIVATION->value, 'attempts' => 2],
        )->fresh();

        $this->assertSame(AuthSecurityEventType::MOBILE_TRUST_OPENED, $event->event_type);
        $this->assertSame(AuthSecurityEventOutcome::SUCCESS, $event->outcome);
        $this->assertSame('IN_PERSON', $event->reason_code);
        $this->assertSame($link->person_id, $event->person_id);
        $this->assertSame($link->user_id, $event->user_id);
        $this->assertSame($staff->id, $event->actor_user_id);
        $this->assertSame($link->id, $event->user_person_link_id);
        $this->assertSame($trust->id, $event->mobile_trust_id);
        $this->assertSame(['purpose' => 'ACTIVATION', 'attempts' => 2], $event->metadata);
        $this->assertNotNull($event->created_at);
    }

    public function test_an_actor_may_be_given_as_an_id(): void
    {
        $staff = User::factory()->create();

        $event = AuthSecurityLog::record(AuthSecurityEventType::LINK_ENDED, AuthSecurityEventOutcome::SUCCESS, actor: $staff->id);

        $this->assertSame($staff->id, $event->fresh()->actor_user_id);
        $this->assertNull($event->fresh()->reason_code);
    }

    public function test_it_refuses_a_raw_identifier_as_login_key(): void
    {
        foreach (['123456789', '0591234567', 'not-a-fingerprint', strtoupper(hash('sha256', 'x'))] as $value) {
            try {
                AuthSecurityLog::record(AuthSecurityEventType::LOGIN_FAILED, AuthSecurityEventOutcome::FAILURE, loginKey: $value);
                $this->fail('A raw identifier was accepted as a login key.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_it_refuses_unsafe_metadata(): void
    {
        foreach ([
            ['national_id' => 'ANY'], ['mobile' => 'ANY'], ['otp' => 123456], ['password' => 'ANY'],
            ['purpose' => '123456789'], ['attempts' => 123456], ['purpose' => 'free text here'],
        ] as $metadata) {
            try {
                AuthSecurityLog::record(AuthSecurityEventType::LOGIN_FAILED, AuthSecurityEventOutcome::FAILURE, metadata: $metadata);
                $this->fail('Unsafe metadata was recorded: '.json_encode($metadata));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_the_user_agent_is_stored_only_as_a_digest(): void
    {
        $this->app['request']->headers->set('User-Agent', 'Synthetic Browser 1.0 (id 123456789)');

        $event = AuthSecurityLog::record(AuthSecurityEventType::LOGIN_FAILED, AuthSecurityEventOutcome::FAILURE)->fresh();

        $this->assertSame(hash('sha256', 'Synthetic Browser 1.0 (id 123456789)'), $event->user_agent_hash);
        $this->assertStringNotContainsString('123456789', json_encode($event->getAttributes()));
    }

    public function test_recorded_events_stay_append_only(): void
    {
        $event = AuthSecurityLog::record(AuthSecurityEventType::LINK_SUSPENDED, AuthSecurityEventOutcome::SUCCESS);

        foreach ([fn () => $event->update(['outcome' => 'FAILURE']), fn () => $event->delete()] as $write) {
            try {
                $write();
                $this->fail('A recorded security event was changed.');
            } catch (LogicException) {
            }
        }
        $this->assertSame(1, AuthSecurityEvent::count());
    }

    public function test_revoking_sessions_deletes_the_rows_and_clears_the_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->familySide()->create(['remember_token' => 'remember-me']);
        $other = User::factory()->familySide()->create();
        $staff = User::factory()->create();
        $this->sessionRow($user);
        $this->sessionRow($user);
        $this->sessionRow($other);

        $this->assertSame(2, FamilySessions::revoke($user, $staff));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertNull($user->fresh()->remember_token);
        // The account itself is untouched.
        $this->assertTrue($user->fresh()->is_active);

        $event = AuthSecurityEvent::sole();
        $this->assertSame(AuthSecurityEventType::SESSIONS_REVOKED, $event->event_type);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame($staff->id, $event->actor_user_id);
    }

    public function test_a_revocation_rolls_back_with_its_transaction(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->familySide()->create(['remember_token' => 'remember-me']);
        $this->sessionRow($user);

        try {
            DB::transaction(function () use ($user) {
                FamilySessions::revoke($user);
                throw new RuntimeException('the domain action failed');
            });
        } catch (RuntimeException) {
        }

        // Nothing is left half-done: session, token and event are all back.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame('remember-me', $user->fresh()->remember_token);
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_without_database_sessions_only_the_token_and_the_event_change(): void
    {
        config(['session.driver' => 'array']);
        $user = User::factory()->familySide()->create(['remember_token' => 'remember-me']);
        $this->sessionRow($user);

        $this->assertSame(0, FamilySessions::revoke($user));

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNull($user->fresh()->remember_token);
        $this->assertSame(1, AuthSecurityEvent::count());
    }

    public function test_reading_records_nothing(): void
    {
        Person::factory()->create();
        UserPersonLink::factory()->create()->fresh();

        $this->assertSame(0, AuthSecurityEvent::count());
    }
}
