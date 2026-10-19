<?php

use App\Jobs\NotificationHeartbeatJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Family Portal retention (docs/11 §30a): finished OTP challenges older than
// the retention period. Defining the schedule does not run it: the Laravel
// scheduler cron is a Production deployment prerequisite (docs/08 §16a).
Schedule::command('famboook:purge-otp-challenges')->daily();

// Abandoned mobile contact updates (docs/11 FP-ADR-079): closed once their
// verification window passed.
Schedule::command('famboook:close-expired-contact-updates')->everyFiveMinutes();

// Notification worker heartbeat (FB-NOTIFY-01B.2b): queued on the dedicated
// notifications queue; a worker running it proves scheduler and worker alike.
// Records a timestamp only — publishes nothing. Not active without the cron
// and a `queue:work --queue=notifications` worker (separate approval).
Schedule::job(new NotificationHeartbeatJob)->everyMinute();

// Notification publication recovery (FB-NOTIFY-01B.2b.5d): stale claims,
// automatic retries, orphaned QUEUED messages. Idempotent; never publishes
// itself. withoutOverlapping (lock expires after 10 minutes) and onOneServer
// rely on the shared cache store's atomic locks (the database store in
// Production). Not active without the cron (separate approval).
Schedule::command('famboook:notifications-reconcile')->everyMinute()->withoutOverlapping(10)->onOneServer();
