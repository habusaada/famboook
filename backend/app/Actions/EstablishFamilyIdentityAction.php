<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\UserPersonLinkStatus;
use App\Enums\UserPersonLinkType;
use App\Enums\UserPersonLinkVerificationMethod;
use App\Exceptions\FamilyIdentityException;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\AccountSide;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use App\Support\FamilyAuth\FamilyNationalId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Establishes the Family Portal identity of an account (docs/11 §30a,
 * docs/05 §53b): the ACTIVE User-Person Link and the ACTIVE Family Auth
 * Identity, together. This is the building block the activation flow
 * (PWA-1F) calls after the OTP and password steps — it is a SYSTEM process:
 * no Staff permission, no endpoint, and it never creates the User itself.
 *
 * It must run inside the caller's transaction, so the account, its role, the
 * link and the identity commit or roll back as one. Refused, with nothing
 * written, when:
 *
 * - the account is inactive or not a valid family-side account;
 * - the Person is not an eligible household head (ALIVE, active head of an
 *   ACTIVE Family) — the same rule the access resolver applies;
 * - the stored National ID is not nine digits;
 * - the User or the Person already has an ACTIVE or SUSPENDED link, or the
 *   login key belongs to another ACTIVE identity.
 */
class EstablishFamilyIdentityAction
{
    public function __construct(
        private readonly FamilyAccessResolver $resolver,
        private readonly FamilyAuthIdentities $identities,
    ) {}

    public function handle(User $user, Person $person): UserPersonLink
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A Family identity is established inside the activation transaction.');
        }

        // One activation per Person at a time.
        /** @var Person $person */
        $person = Person::withTrashed()->whereKey($person->getKey())->lockForUpdate()->firstOrFail();

        if (! $user->is_active) {
            throw new FamilyIdentityException(FamilyIdentityException::USER_INACTIVE);
        }
        if (! AccountSide::isFamily($user)) {
            throw new FamilyIdentityException(FamilyIdentityException::NOT_FAMILY_SIDE);
        }
        if ($denial = $this->resolver->headEligibility($person)) {
            throw new FamilyIdentityException(FamilyIdentityException::NOT_ELIGIBLE, $denial->value);
        }
        if (FamilyNationalId::normalize($person->national_id) === null) {
            throw new FamilyIdentityException(FamilyIdentityException::NATIONAL_ID_INVALID);
        }
        $taken = UserPersonLink::query()->current()
            ->where(fn ($q) => $q->where('user_id', $user->getKey())->orWhere('person_id', $person->getKey()))
            ->exists();
        if ($taken) {
            throw new FamilyIdentityException(FamilyIdentityException::LINK_EXISTS);
        }

        try {
            // A savepoint: the partial unique indexes are the backstop.
            $link = DB::transaction(fn () => UserPersonLink::create([
                'user_id' => $user->getKey(),
                'person_id' => $person->getKey(),
                'link_type' => UserPersonLinkType::SELF,
                'status' => UserPersonLinkStatus::ACTIVE,
                'verification_method' => UserPersonLinkVerificationMethod::SYSTEM_OTP_ACTIVATION,
                'verified_by' => null,
                'verified_at' => now(),
                'activated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new FamilyIdentityException(FamilyIdentityException::LINK_EXISTS);
        }

        $this->identities->create($user, $person);

        AuthSecurityLog::record(
            AuthSecurityEventType::LINK_ACTIVATED,
            AuthSecurityEventOutcome::SUCCESS,
            UserPersonLinkVerificationMethod::SYSTEM_OTP_ACTIVATION,
            person: $person, user: $user, link: $link,
        );

        return $link;
    }
}
