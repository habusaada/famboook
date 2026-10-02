<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAccessDenial;
use App\Enums\FingerprintContext;
use App\Enums\UserPersonLinkStatus;
use App\Exceptions\FamilyIdentityException;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The authentication identity of family-side accounts (docs/11 §30a,
 * docs/04 §55b): a keyed fingerprint of the Person's nine normalized
 * National ID digits. Registry Identity and Authentication Identity stay
 * separate — persons.national_id is never the credential or the index.
 *
 * Every write runs inside the caller's transaction, so the identity changes
 * with the registry change or not at all. Rows are never deleted: a replaced
 * identity is SUPERSEDED with its reason.
 */
final class FamilyAuthIdentities
{
    private const CURRENT = [AuthIdentityStatus::ACTIVE->value, AuthIdentityStatus::SUSPENDED->value];

    /** The login key of nine normalized digits under a key version. */
    public function keyFor(string $nationalIdDigits, ?int $version = null): string
    {
        return KeyedFingerprint::of(FingerprintContext::LOGIN_ID, $nationalIdDigits, $version);
    }

    /** The user's ACTIVE or SUSPENDED identity, if any. */
    public function current(User $user, bool $lock = false): ?FamilyAuthIdentity
    {
        return FamilyAuthIdentity::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', self::CURRENT)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /** Creates the ACTIVE identity of $user from $person's stored National ID. */
    public function create(User $user, Person $person): FamilyAuthIdentity
    {
        $this->assertInTransaction();
        $digits = FamilyNationalId::normalize($person->national_id);
        if ($digits === null) {
            throw new FamilyIdentityException(FamilyIdentityException::NATIONAL_ID_INVALID);
        }
        if ($this->current($user) !== null) {
            throw new FamilyIdentityException(FamilyIdentityException::IDENTITY_EXISTS);
        }

        return $this->insertActive($user, $digits);
    }

    /** Whether $identity is the fingerprint of $person's CURRENT National ID. */
    public function isConsistent(FamilyAuthIdentity $identity, Person $person): bool
    {
        $digits = FamilyNationalId::normalize($person->national_id);

        return $digits !== null
            && KeyedFingerprint::matches(FingerprintContext::LOGIN_ID, $digits, $identity->login_key, $identity->key_version);
    }

    /**
     * The ACTIVE identity a typed National ID belongs to, or NULL. Tries the
     * current key version, then the previous one while a rotation is open.
     * Invalid input is simply "not found": the caller answers generically.
     */
    public function findByNationalIdInput(mixed $input): ?FamilyAuthIdentity
    {
        $digits = FamilyNationalId::normalize($input);
        if ($digits === null) {
            return null;
        }
        foreach (KeyedFingerprint::versions() as $version) {
            $identity = FamilyAuthIdentity::query()
                ->where('login_key', $this->keyFor($digits, $version))
                ->where('key_version', $version)
                ->where('status', AuthIdentityStatus::ACTIVE->value)
                ->first();
            if ($identity !== null) {
                return $identity;
            }
        }

        return null;
    }

    /** Retires an identity for good; the row stays as history. */
    public function supersede(FamilyAuthIdentity $identity, AuthIdentitySupersedeReason $reason): FamilyAuthIdentity
    {
        $this->assertInTransaction();
        $identity->forceFill([
            'status' => AuthIdentityStatus::SUPERSEDED,
            'superseded_at' => now(),
            'supersede_reason' => $reason,
        ])->save();

        return $identity;
    }

    /**
     * Keeps the identity true after CorrectNationalIdAction changed
     * $person's National ID — inside that action's transaction:
     *
     *   nine digits, another key  → old identity SUPERSEDED, new ACTIVE one;
     *                               sessions are kept (same person, same password)
     *   nine digits, the same key → nothing
     *   not nine digits           → identity SUSPENDED, sessions revoked
     *   key held by another ACTIVE identity → FamilyIdentityException: the
     *                               whole correction rolls back
     *
     * Only a Person with an ACTIVE or SUSPENDED link has an identity to sync.
     */
    public function syncAfterNationalIdCorrection(Person $person, User|int|null $actor = null): void
    {
        $this->assertInTransaction();
        $link = UserPersonLink::query()
            ->where('person_id', $person->getKey())
            ->whereIn('status', [UserPersonLinkStatus::ACTIVE->value, UserPersonLinkStatus::SUSPENDED->value])
            ->lockForUpdate()
            ->first();
        $user = $link?->user;
        $identity = $user ? $this->current($user, lock: true) : null;
        if ($link === null || $user === null || $identity === null) {
            return;
        }

        $digits = FamilyNationalId::normalize($person->national_id);
        if ($digits === null) {
            if ($identity->status === AuthIdentityStatus::ACTIVE) {
                $identity->forceFill(['status' => AuthIdentityStatus::SUSPENDED])->save();
                FamilySessions::revoke($user, $actor, FamilyAccessDenial::NATIONAL_ID_INVALID);
                AuthSecurityLog::record(
                    AuthSecurityEventType::LOGIN_IDENTIFIER_ROTATED,
                    AuthSecurityEventOutcome::FAILURE,
                    FamilyAccessDenial::NATIONAL_ID_INVALID,
                    person: $person, user: $user, actor: $actor, link: $link,
                    metadata: ['status_from' => AuthIdentityStatus::ACTIVE->value, 'status_to' => AuthIdentityStatus::SUSPENDED->value],
                );
            }

            return;
        }

        if ($identity->status === AuthIdentityStatus::ACTIVE && $this->isConsistent($identity, $person)) {
            return;
        }

        $from = $identity->status->value;
        $this->supersede($identity, AuthIdentitySupersedeReason::NATIONAL_ID_CORRECTED);
        $this->insertActive($user, $digits);
        AuthSecurityLog::record(
            AuthSecurityEventType::LOGIN_IDENTIFIER_ROTATED,
            AuthSecurityEventOutcome::SUCCESS,
            AuthIdentitySupersedeReason::NATIONAL_ID_CORRECTED,
            person: $person, user: $user, actor: $actor, link: $link,
            metadata: ['status_from' => $from, 'status_to' => AuthIdentityStatus::ACTIVE->value, 'key_version' => KeyedFingerprint::currentVersion()],
        );
    }

    private function insertActive(User $user, string $digits): FamilyAuthIdentity
    {
        $version = KeyedFingerprint::currentVersion();
        $key = $this->keyFor($digits, $version);

        if (FamilyAuthIdentity::query()->where('login_key', $key)->where('status', AuthIdentityStatus::ACTIVE->value)->exists()) {
            throw new FamilyIdentityException(FamilyIdentityException::LOGIN_KEY_TAKEN);
        }

        try {
            // A savepoint: a concurrent winner trips the unique index, and the
            // caller's transaction must still be able to roll back cleanly.
            return DB::transaction(fn () => FamilyAuthIdentity::create([
                'user_id' => $user->getKey(),
                'login_key' => $key,
                'key_version' => $version,
                'status' => AuthIdentityStatus::ACTIVE,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new FamilyIdentityException(FamilyIdentityException::LOGIN_KEY_TAKEN);
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A Family Auth identity changes only inside its Domain Action transaction.');
        }
    }
}
