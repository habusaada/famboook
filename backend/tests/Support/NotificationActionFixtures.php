<?php

namespace Tests\Support;

use App\Actions\Notifications\CreateAnnouncementDraftAction;
use App\Contracts\NotificationPublicationDispatcher;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationStatus;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * FB-NOTIFY-01B.2b.4 action fixtures. The announcement permissions are
 * PROPOSED and not seeded (AUTH-ADR-097): they are created here, inside the
 * test database only, and granted directly to synthetic users — no role
 * mapping changes. Publication is never run: the dispatcher records.
 */
trait NotificationActionFixtures
{
    use CoordinatorFixtures, FamilyIdentityFixtures, NotificationReadinessFixtures;

    protected RecordingPublicationDispatcher $dispatcher;

    protected function setUpNotificationActions(): void
    {
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->notificationDefaults();
        $this->infrastructureReady();
        config(['notifications.mode' => 'ON']);
        $this->dispatcher = new RecordingPublicationDispatcher;
        $this->app->instance(NotificationPublicationDispatcher::class, $this->dispatcher);
    }

    protected function grant(User $user, string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    /** A Staff-side ADMINISTRATOR holding the given announcement permissions. */
    protected function staffSender(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('ADMINISTRATOR', 'web'));

        return $this->grant($user, ...($permissions ?: [NotificationActors::STAFF_CREATE, NotificationActors::STAFF_SEND]));
    }

    /** A Coordinator assigned to $target, holding the given coordinator-announcement permissions. */
    protected function coordinatorSender(Clan|BranchGroup|Branch $target, string $nationalId = '111111110', string ...$permissions): User
    {
        $user = $this->coordinator($nationalId);
        $this->assign($user, $target);

        return $this->grant($user, ...($permissions ?: [NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND]));
    }

    /** @param  array<string, mixed>  $input */
    protected function draft(User $sender, array $audience, array $input = []): NotificationMessage
    {
        return app(CreateAnnouncementDraftAction::class)->handle($sender, [
            'title' => 'إعلان تجريبي', 'body' => 'نص إعلان تجريبي.', 'audience' => $audience, ...$input,
        ])->message;
    }

    /** The worker's failure, simulated row by row (the worker is FB-NOTIFY-01B.2b.5). */
    protected function failPublication(NotificationMessage $message, NotificationFailureCode|string $code, int $attempts = 1): NotificationMessage
    {
        $message->forceFill([
            'status' => NotificationStatus::PUBLISH_FAILED, 'queued_at' => $message->queued_at ?? now(),
            'publishing_started_at' => now(), 'publishing_token' => (string) Str::uuid(), 'publish_attempts' => $attempts,
            'publish_failed_at' => now(), 'publish_failure_code' => $code instanceof NotificationFailureCode ? $code->value : $code,
        ])->save();

        return $message->fresh();
    }
}
