<?php

namespace App\Actions\ContactUpdate;

use RuntimeException;

/**
 * @internal A refusal inside the CONTACT_UPDATE completion transaction: the
 * work is rolled back, then the request is closed by the system.
 */
final class ContactUpdateFailure extends RuntimeException {}
