<?php

namespace App\Console\Commands;

use App\Actions\ContactUpdate\CloseContactUpdateAction;
use Illuminate\Console\Command;

/**
 * Closes every mobile CONTACT_UPDATE whose verification window passed
 * (docs/11 FP-ADR-079): SUBMITTED → CANCELLED by SYSTEM, its codes
 * superseded. Requests are also closed when they are next used; this
 * sweep closes the abandoned ones. Prints a count only.
 */
class CloseExpiredContactUpdates extends Command
{
    protected $signature = 'famboook:close-expired-contact-updates';

    protected $description = 'Close mobile contact-update requests whose verification window passed.';

    public function handle(CloseContactUpdateAction $close): int
    {
        $this->info('Closed contact-update requests: '.$close->closeExpired());

        return self::SUCCESS;
    }
}
