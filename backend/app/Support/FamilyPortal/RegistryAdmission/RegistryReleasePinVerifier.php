<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * The lookup side of the release pin (ADD-WP-07B-2b; ADD-WP-07B-2R D-F3, DBA-3): from the
 * registry's own session, whether the admission transaction really holds the pin in this
 * database, and which release is active, read in one statement.
 *
 * Implemented for operation by CivilRegistryReader, the only user of the lookup
 * connection, over identity_ref_lookup.release_pin_held(), which does not exist until
 * DBA-3 is approved and applied: until then every verification fails and every pin fails
 * closed. Tests use a synthetic verifier under tests/.
 *
 * Throws on any failure; the pin treats every failure as unproven.
 */
interface RegistryReleasePinVerifier
{
    /**
     * @param  int  $holderPid  the admission session's backend pid
     * @param  int  $nonce  the transaction-scoped nonce lock the admission transaction holds (1 … 2^31 − 1)
     */
    public function releasePinHeld(int $holderPid, int $nonce): ReleasePinVerification;
}
