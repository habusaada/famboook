<?php

namespace Tests\Feature\Notifications;

use App\Exceptions\NotificationAudienceException;
use App\Jobs\PublishNotificationJob;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationAudienceReferences;
use App\Support\Notifications\NotificationPublisher;
use App\Support\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.8a (B1): announcement audiences name families by their
 * PUBLIC family_code. NotificationAudienceReferences maps them onto the
 * internal audience before the preview and the draft; internal ids are
 * refused at the HTTP boundary and never returned. Synthetic data only.
 */
class NotificationAudienceReferencesTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const STAFF_URI = '/api/v1/notifications';

    private const COORDINATOR_URI = '/api/v1/family/coordinator/notifications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
    }

    private function references(): NotificationAudienceReferences
    {
        return app(NotificationAudienceReferences::class);
    }

    private function staff(): NotificationSender
    {
        return NotificationActors::sender($this->staffSender(), send: false);
    }

    private function as(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, $uri, $body);
    }

    private function refusal(NotificationSender $sender, mixed $raw): ?string
    {
        try {
            $this->references()->toAudience($sender, $raw);

            return null;
        } catch (NotificationAudienceException $e) {
            return $e->reason;
        }
    }

    /** Runs every job the recording dispatcher received, as the worker would. */
    private function runWorker(): void
    {
        foreach ($this->dispatcher->ids() as $id) {
            (new PublishNotificationJob($id))->handle(app(NotificationPublisher::class));
        }
    }

    private function message(string $uuid): NotificationMessage
    {
        return NotificationMessage::query()->where('uuid', $uuid)->sole();
    }

    // -------------------------------------------------------------- mapper

    public function test_family_codes_map_to_internal_ids_trimmed_and_deduplicated_in_one_query(): void
    {
        [$a, $b] = [Family::factory()->create(), Family::factory()->create()];
        $staff = $this->staff();

        DB::enableQueryLog();
        $audience = $this->references()->toAudience($staff, ['type' => 'STAFF_FAMILIES', 'family_codes' => ["  {$b->family_code} ", $a->family_code, $b->family_code]]);
        $familyQueries = array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'families'));
        DB::disableQueryLog();

        $this->assertSame(['type' => 'STAFF_FAMILIES', 'family_ids' => [$a->id, $b->id]], $audience);
        $this->assertCount(1, $familyQueries, 'one batched lookup, no N+1');
    }

    public function test_types_without_a_selection_map_to_themselves(): void
    {
        $this->assertSame(['type' => 'STAFF_ALL_ACTIVE'], $this->references()->toAudience($this->staff(), ['type' => 'STAFF_ALL_ACTIVE']));

        $clan = $this->clan('REF_CLAN');
        $coordinator = NotificationActors::sender($this->coordinatorSender($this->branch($clan)), send: false);
        $this->assertSame(['type' => 'COORDINATOR_SCOPE'], $this->references()->toAudience($coordinator, ['type' => 'COORDINATOR_SCOPE']));
    }

    public function test_an_unknown_inactive_or_deleted_family_refuses_the_whole_audience_with_one_code(): void
    {
        $active = Family::factory()->create();
        $inactive = Family::factory()->create(['status' => 'INACTIVE']);
        $deleted = Family::factory()->create();
        $deleted->delete();
        $staff = $this->staff();

        $messages = [];
        foreach (['FAM-999999', $inactive->family_code, $deleted->family_code] as $code) {
            try {
                $this->references()->toAudience($staff, ['type' => 'STAFF_FAMILIES', 'family_codes' => [$active->family_code, $code]]);
                $this->fail("{$code} must be refused");
            } catch (NotificationAudienceException $e) {
                $this->assertSame(NotificationAudienceException::FAMILY_NOT_ELIGIBLE, $e->reason);
                $this->assertStringNotContainsString($code, $e->getMessage());
                $messages[] = $e->getMessage();
            }
        }
        $this->assertCount(1, array_unique($messages), 'the refusal never says which reference failed');
    }

    public function test_coordinator_codes_resolve_only_within_the_current_scope(): void
    {
        $clan = $this->clan('REF_CLAN');
        [$mine, $other] = [$this->branch($clan), $this->branch($clan)];
        $inScope = $this->familyIn($clan, $mine);
        $outside = $this->familyIn($clan, $other);
        $inactive = $this->familyIn($clan, $mine, attributes: ['status' => 'INACTIVE']);
        $coordinator = NotificationActors::sender($this->coordinatorSender($mine), send: false);

        $this->assertSame(['type' => 'COORDINATOR_SELECTION', 'family_ids' => [$inScope->id]],
            $this->references()->toAudience($coordinator, ['type' => 'COORDINATOR_SELECTION', 'family_codes' => [$inScope->family_code]]));
        foreach ([$outside, $inactive] as $family) {
            $this->assertSame(NotificationAudienceException::FAMILY_NOT_ELIGIBLE,
                $this->refusal($coordinator, ['type' => 'COORDINATOR_SELECTION', 'family_codes' => [$inScope->family_code, $family->family_code]]));
        }
        // An existing Staff-visible family outside the scope and a nonexistent code look the same.
        $this->assertSame(NotificationAudienceException::FAMILY_NOT_ELIGIBLE,
            $this->refusal($coordinator, ['type' => 'COORDINATOR_SELECTION', 'family_codes' => ['FAM-999999']]));
    }

    public function test_the_public_grammar_is_strict(): void
    {
        $family = Family::factory()->create();
        $code = $family->family_code;
        $staff = $this->staff();

        foreach ([
            'not an object' => 'STAFF_FAMILIES',
            'no type' => ['family_codes' => [$code]],
            'unknown type' => ['type' => 'EVERYONE'],
            'system origin' => ['type' => 'SYSTEM_ORIGIN'],
            'wrong side' => ['type' => 'COORDINATOR_SCOPE'],
            'wrong side selection' => ['type' => 'COORDINATOR_SELECTION', 'family_codes' => [$code]],
            'empty selection' => ['type' => 'STAFF_FAMILIES'],
            'empty list' => ['type' => 'STAFF_FAMILIES', 'family_codes' => []],
            'not a list' => ['type' => 'STAFF_FAMILIES', 'family_codes' => $code],
            'object' => ['type' => 'STAFF_FAMILIES', 'family_codes' => ['a' => $code]],
            'not a string' => ['type' => 'STAFF_FAMILIES', 'family_codes' => [$family->id]],
            'blank' => ['type' => 'STAFF_FAMILIES', 'family_codes' => ['   ']],
            'too long' => ['type' => 'STAFF_FAMILIES', 'family_codes' => [str_repeat('A', 51)]],
            'internal ids' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [$family->id]],
            'mixed keys' => ['type' => 'STAFF_FAMILIES', 'family_codes' => [$code], 'family_ids' => [$family->id]],
            'structure ids' => ['type' => 'STAFF_FAMILIES', 'family_codes' => [$code], 'clan_ids' => [1]],
            'unknown key' => ['type' => 'STAFF_FAMILIES', 'family_codes' => [$code], 'note' => 'x'],
            'selection on all' => ['type' => 'STAFF_ALL_ACTIVE', 'family_codes' => [$code]],
            '501 distinct codes' => ['type' => 'STAFF_FAMILIES', 'family_codes' => array_map(fn ($i) => "FAM-X{$i}", range(1, 501))],
            '1001 entries' => ['type' => 'STAFF_FAMILIES', 'family_codes' => array_fill(0, 1001, $code)],
        ] as $case => $raw) {
            $this->assertSame(NotificationAudienceException::INVALID, $this->refusal($staff, $raw), $case);
        }

        // The limit counts DISTINCT codes: 1000 entries naming 500 codes pass the grammar.
        $codes = array_map(fn ($i) => "FAM-X{$i}", range(1, 500));
        $this->assertSame(NotificationAudienceException::FAMILY_NOT_ELIGIBLE,
            $this->refusal($staff, ['type' => 'STAFF_FAMILIES', 'family_codes' => [...$codes, ...$codes]]));
    }

    // ----------------------------------------------------------------- API

    public function test_internal_keys_are_refused_at_the_http_boundary(): void
    {
        $sender = $this->staffSender(NotificationActors::STAFF_CREATE, NotificationActors::STAFF_SEND, 'announcement.view');
        $family = Family::factory()->create();

        foreach ([
            ['type' => 'STAFF_FAMILIES', 'family_ids' => [$family->id]],
            ['type' => 'STAFF_FAMILIES', 'family_codes' => [$family->family_code], 'family_ids' => [$family->id]],
            ['type' => 'STAFF_FAMILIES', 'family_codes' => [$family->family_code], 'filters' => ['status' => 'ACTIVE']],
        ] as $audience) {
            $this->as($sender, 'POST', self::STAFF_URI.'/audience-preview', ['audience' => $audience])
                ->assertUnprocessable()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_INVALID']);
            $this->as($sender, 'POST', self::STAFF_URI, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => $audience])
                ->assertUnprocessable()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_INVALID']);
        }
        $this->assertSame(0, NotificationMessage::query()->count());
    }

    public function test_a_draft_replays_with_the_same_public_references_and_conflicts_with_others(): void
    {
        $sender = $this->staffSender(NotificationActors::STAFF_CREATE, 'announcement.view');
        [$a, $b, $c] = [Family::factory()->create(), Family::factory()->create(), Family::factory()->create()];
        $reference = (string) Str::uuid();
        $body = fn (array $codes) => ['title' => 'إعلان', 'body' => 'نص.', 'client_reference' => $reference,
            'audience' => ['type' => 'STAFF_FAMILIES', 'family_codes' => $codes]];

        $uuid = $this->as($sender, 'POST', self::STAFF_URI, $body([$a->family_code, $b->family_code]))->assertCreated()->json('data.id');
        // Same families, another order, a duplicate and whitespace: the same draft.
        $this->as($sender, 'POST', self::STAFF_URI, $body([" {$b->family_code}", $a->family_code, $b->family_code]))->assertOk()
            ->assertJson(['meta' => ['replayed' => true], 'data' => ['id' => $uuid]]);
        $this->as($sender, 'POST', self::STAFF_URI, $body([$a->family_code, $c->family_code]))->assertStatus(409)
            ->assertJson(['code' => 'NOTIFICATION_IDEMPOTENCY_CONFLICT']);

        $this->assertSame(1, NotificationMessage::query()->count());
        $this->assertSame([$a->id, $b->id], $this->message($uuid)->audience['family_ids'], 'the stored audience keeps the internal form');
    }

    public function test_administrator_end_to_end_from_preview_to_the_family_inbox(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('ADMINISTRATOR', 'web'));
        $head = $this->activatedHead('123456789');
        $other = Family::factory()->create();
        $codes = [$head['family']->family_code, $other->family_code, " {$other->family_code} "];
        $audience = ['type' => 'STAFF_FAMILIES', 'family_codes' => $codes];

        $preview = $this->as($administrator, 'POST', self::STAFF_URI.'/audience-preview', ['audience' => $audience])->assertOk();
        $this->assertSame(['data' => ['recipient_count' => 2]], $preview->json());

        $draft = $this->as($administrator, 'POST', self::STAFF_URI, ['title' => 'إعلان للأسر', 'body' => 'نص الإعلان.', 'audience' => $audience])
            ->assertCreated()->assertJson(['data' => ['audience_type' => 'STAFF_FAMILIES', 'audience_selection' => ['family_ids' => 2]]]);
        $uuid = $draft->json('data.id');
        $published = $this->as($administrator, 'POST', self::STAFF_URI."/{$uuid}/publish")->assertStatus(202);

        $this->runWorker();

        $detail = $this->as($administrator, 'GET', self::STAFF_URI."/{$uuid}")->assertOk()
            ->assertJson(['data' => ['status' => 'PUBLISHED', 'recipient_count_at_publication' => 2, 'statistics' => ['recipients' => 2]]]);
        $this->assertSame($preview->json('data.recipient_count'), $detail->json('data.recipient_count_at_publication'), 'preview and publication agree');

        $inbox = $this->as($head['user'], 'GET', '/api/v1/family/notifications')->assertOk();
        $this->assertSame([$uuid], array_column($inbox->json('data'), 'id'));
        $this->assertSame(1, $inbox->json('meta.unread_count'));

        // No internal family id ever leaves the API: counts only, never the stored audience.
        foreach ([$draft, $published, $detail] as $response) {
            $this->assertArrayNotHasKey('audience', $response->json('data'));
            $this->assertIsInt($response->json('data.audience_selection.family_ids'));
            $this->assertStringNotContainsString('"family_ids":[', $response->getContent());
        }
    }

    public function test_coordinator_selected_family_publication_reaches_only_the_selection(): void
    {
        $clan = $this->clan('REF_CLAN');
        [$first, $second] = [$this->branch($clan), $this->branch($clan)];
        $selected = $this->familyIn($clan, $first);
        $notSelected = $this->familyIn($clan, $first);
        $coordinator = $this->coordinatorSender($first, '111111110',
            NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND, 'coordinator-announcement.view');
        $this->assign($coordinator, $second);
        $audience = ['type' => 'COORDINATOR_SELECTION', 'family_codes' => [$selected->family_code]];

        $this->as($coordinator, 'POST', self::COORDINATOR_URI.'/audience-preview', ['audience' => $audience])->assertOk()
            ->assertExactJson(['data' => ['recipient_count' => 1]]);
        $this->as($coordinator, 'POST', self::COORDINATOR_URI.'/audience-preview', ['audience' => ['type' => 'COORDINATOR_SCOPE']])->assertOk()
            ->assertExactJson(['data' => ['recipient_count' => 2]]);
        $uuid = $this->as($coordinator, 'POST', self::COORDINATOR_URI, ['title' => 'إعلان المنسق', 'body' => 'نص.', 'audience' => $audience])
            ->assertCreated()->json('data.id');
        $this->as($coordinator, 'POST', self::COORDINATOR_URI."/{$uuid}/publish")->assertStatus(202);

        $this->runWorker();

        $message = $this->message($uuid);
        $this->assertSame([1, 'COORDINATOR'], [$message->recipient_count, $message->sender_side->value]);
        $this->assertSame([$selected->id], DB::table('notification_recipients')->where('message_id', $message->id)->pluck('family_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains($notSelected->id, DB::table('notification_recipients')->pluck('family_id')->all());
        $this->as($coordinator, 'GET', self::COORDINATOR_URI."/{$uuid}")->assertOk()
            ->assertJson(['data' => ['status' => 'PUBLISHED', 'recipient_count_at_publication' => 1, 'statistics_basis' => 'CURRENT_COORDINATOR_SCOPE']]);
    }
}
