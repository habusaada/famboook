<?php

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
