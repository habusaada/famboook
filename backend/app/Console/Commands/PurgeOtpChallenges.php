<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention of OTP challenges (docs/11 §30a): operational rows that are
 * FINISHED — consumed, superseded, locked or expired — may be purged once
 * that moment is older than the retention period (90 days by default,
 * config family_auth.retention.otp_challenge_days).
 *
 * An open, unexpired challenge is never touched, whatever its age. Only
 * auth_otp_challenges is purged: mobile trust history, User-Person Link
 * history, authentication identities and auth_security_events are not
 * (security events reference a challenge by a plain uuid, so nothing blocks
 * or follows the delete; their own 24-month purge is not implemented yet).
 *
 * Scheduled daily (routes/console.php). The schedule only runs where the
 * Laravel scheduler cron exists — a Production deployment prerequisite.
 * Prints a count only.
 */
class PurgeOtpChallenges extends Command
{
    protected $signature = 'famboook:purge-otp-challenges {--days= : Retention in days (default: family_auth.retention.otp_challenge_days)}';

    protected $description = 'Delete finished OTP challenges older than the retention period';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('family_auth.retention.otp_challenge_days');
        if (! is_numeric($days) || (int) $days < 1) {
            $this->error('The retention period must be at least 1 day.');

            return self::FAILURE;
        }
        $cutoff = now()->subDays((int) $days);

        // The moment each row finished must itself be older than the cutoff.
        $deleted = DB::table('auth_otp_challenges')
            ->where(function ($finished) use ($cutoff) {
                $finished->where('consumed_at', '<', $cutoff)
                    ->orWhere('superseded_at', '<', $cutoff)
                    ->orWhere('locked_at', '<', $cutoff)
                    // Expired without another outcome: after the code's expiry
                    // and, when it was verified, after its grant too.
                    ->orWhere(fn ($expired) => $expired
                        ->whereNull('consumed_at')->whereNull('superseded_at')->whereNull('locked_at')
                        ->where('expires_at', '<', $cutoff)
                        ->where(fn ($grant) => $grant->whereNull('grant_expires_at')->orWhere('grant_expires_at', '<', $cutoff)));
            })
            ->delete();

        $this->info("Purged {$deleted} finished OTP challenge(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
