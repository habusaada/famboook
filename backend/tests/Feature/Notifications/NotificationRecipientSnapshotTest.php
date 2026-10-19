<?php

namespace Tests\Feature\Notifications;

use App\Enums\AuthIdentityStatus;
use App\Enums\FamilyStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationSenderSide;
use App\Enums\UserPersonLinkStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationRecipientSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.3: NotificationRecipientSnapshot projects, in ONE query,
 * the publication-time facts of every resolved family — status, the ACTIVE
 * household head, and whether that head has an activated account (ACTIVE
 * link, active user, ACTIVE Family Auth Identity). The production code
 * inserts no recipient; only the PostgreSQL compatibility test feeds the
 * projection to INSERT … SELECT, inside its rolled-back test transaction.
 * Synthetic data only.
 */
class NotificationRecipientSnapshotTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->travelTo('2026-10-19 10:00:00');
    }

    /** @return array<int, object> the snapshot rows keyed by family id */
    private function snapshot(Family ...$families): array
    {
        $query = Family::query()->whereIn('families.id', collect($families)->pluck('id'));

        return NotificationRecipientSnapshot::query($query, 77, now())->get()->keyBy('family_id')->all();
    }

    public function test_an_activated_head_is_snapshotted_with_an_account(): void
    {
        $head = $this->activatedHead();
        $row = $this->snapshot($head['family'])[$head['family']->id];

        $this->assertSame(77, (int) $row->message_id);
        $this->assertSame('ACTIVE', $row->family_status_at_publication);
        $this->assertSame((int) $head['person']->id, (int) $row->head_person_id);
        $this->assertTrue((bool) $row->had_family_account);
        $this->assertSame('2026-10-19 10:00:00', (string) $row->created_at);
        $this->assertSame(NotificationRecipientSnapshot::COLUMNS, array_keys((array) $row));
    }

    public function test_a_head_without_an_account_is_snapshotted_without_one(): void
    {
        [$person, $family] = $this->eligibleHead();
        $row = $this->snapshot($family)[$family->id];

        $this->assertSame((int) $person->id, (int) $row->head_person_id);
        $this->assertFalse((bool) $row->had_family_account);
    }

    public function test_a_suspended_link_a_suspended_identity_or_an_inactive_user_is_no_account(): void
    {
        $suspendedLink = $this->activatedHead('111111110');
        $suspendedLink['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();
        $suspendedIdentity = $this->activatedHead('222222220');
        $suspendedIdentity['identity']->forceFill(['status' => AuthIdentityStatus::SUSPENDED->value])->save();
        $inactiveUser = $this->activatedHead('333333330');
        $inactiveUser['user']->forceFill(['is_active' => false])->save();

        $rows = $this->snapshot($suspendedLink['family'], $suspendedIdentity['family'], $inactiveUser['family']);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertNotNull($row->head_person_id);
            $this->assertFalse((bool) $row->had_family_account);
        }
    }

    public function test_a_former_head_and_a_family_without_head_have_no_head_and_no_account(): void
    {
        $former = $this->activatedHead();
        $former['membership']->forceFill(['is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'OTHER'])->save();
        $headless = Family::factory()->create();
        FamilyMembership::factory()->create(['family_id' => $headless->id, 'is_household_head' => false]);

        foreach ($this->snapshot($former['family'], $headless) as $row) {
            $this->assertNull($row->head_person_id);
            $this->assertFalse((bool) $row->had_family_account);
        }
    }

    public function test_the_head_is_the_current_active_head(): void
    {
        $head = $this->activatedHead();
        $head['membership']->forceFill(['is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'OTHER'])->save();
        $successor = FamilyMembership::factory()->create(['family_id' => $head['family']->id, 'is_household_head' => true]);

        $row = $this->snapshot($head['family'])[$head['family']->id];

        // The new head has no account; the former head's account does not count.
        $this->assertSame((int) $successor->person_id, (int) $row->head_person_id);
        $this->assertFalse((bool) $row->had_family_account);
    }

    public function test_the_family_status_is_snapshotted_as_it_is(): void
    {
        $family = Family::factory()->create(['status' => FamilyStatus::INACTIVE->value]);

        $this->assertSame('INACTIVE', $this->snapshot($family)[$family->id]->family_status_at_publication);
    }

    public function test_one_query_for_any_number_of_families(): void
    {
        $families = [];
        foreach (['111111110', '222222220', '333333330', '444444440'] as $nationalId) {
            $families[] = $this->activatedHead($nationalId)['family'];
        }
        $families[] = Family::factory()->create();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $rows = $this->snapshot(...$families);

        $this->assertSame(1, $queries);
        $this->assertCount(5, $rows);
        $this->assertSame(4, collect($rows)->filter(fn ($row) => (bool) $row->had_family_account)->count());
    }

    public function test_the_projection_feeds_insert_select_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Typed INSERT … SELECT compatibility is checked against PostgreSQL.');
        }
        $head = $this->activatedHead();
        $headless = Family::factory()->create();
        $message = NotificationMessage::create([
            'kind' => NotificationKind::ANNOUNCEMENT, 'sender_side' => NotificationSenderSide::STAFF, 'title' => 'إعلان تجريبي',
            'body' => 'نص تجريبي.', 'sender_user_id' => User::factory()->create()->id,
        ]);

        $inserted = DB::table('notification_recipients')->insertUsing(
            NotificationRecipientSnapshot::COLUMNS,
            NotificationRecipientSnapshot::query(Family::query()->whereIn('families.id', [$head['family']->id, $headless->id]), $message->id, now()),
        );

        $this->assertSame(2, $inserted);
        $rows = DB::table('notification_recipients')->where('message_id', $message->id)->orderBy('family_id')->get()->keyBy('family_id');
        $this->assertTrue($rows[$head['family']->id]->had_family_account);
        $this->assertFalse($rows[$headless->id]->had_family_account);
        $this->assertNull($rows[$headless->id]->head_person_id);
    }
}
