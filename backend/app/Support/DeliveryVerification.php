<?php

namespace App\Support;

use App\Enums\BeneficiaryStatus;
use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Enums\ReceiptMode;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\FamilyMembership;
use App\Models\Person;
use Illuminate\Validation\ValidationException;

/**
 * Identity verification for an INTERNAL delivery (docs/03-BUSINESS-RULES.md
 * §47e). Purpose-limited: typed National IDs are only compared with the
 * specific persons this beneficiary context allows — never searched
 * globally, never stored, never returned.
 *
 * Original beneficiary: the nominated Person (person-level) or the CURRENT
 * household head (family-level).
 *
 * DELEGATE: only an unmarried (SINGLE) son or daughter of the original
 * beneficiary. SON/DAUGHTER in Famboook are recorded relative to the
 * current household head, so the relationship is provable only when the
 * original beneficiary IS the current household head of the family. For a
 * person-level beneficiary who is not the head it is not provable and
 * delegation is refused (PERSONAL remains available).
 */
class DeliveryVerification
{
    /**
     * @return array{original: Person, recipient: Person, relationship: ?string}
     */
    public static function verify(
        Assistance $assistance,
        AssistanceBeneficiary $beneficiary,
        ReceiptMode $mode,
        ?string $beneficiaryNationalId,
        ?string $delegateNationalId,
    ): array {
        abort_unless($assistance->isInternal(), 409, 'التسليم داخل Famboook غير متاح لمساعدة ذات تنفيذ خارجي.');
        abort_unless($assistance->isOpen(), 409, 'يمكن تسجيل التسليم فقط عندما تكون المساعدة مفتوحة.');
        abort_unless($beneficiary->status === BeneficiaryStatus::APPROVED, 409, 'يمكن التسليم للمستفيدين المعتمدين فقط.');
        abort_if($beneficiary->activeDelivery()->exists(), 409, 'تم تسجيل تسليم فعّال لهذا المستفيد مسبقًا.');

        $headMembership = FamilyMembership::query()
            ->where('family_id', $beneficiary->family_id)
            ->where('is_active', true)
            ->where('is_household_head', true)
            ->with('person')
            ->first();

        $original = $beneficiary->person_id !== null
            ? Person::find($beneficiary->person_id)
            : $headMembership?->person;

        if ($original === null) {
            throw ValidationException::withMessages([
                'beneficiary_national_id' => 'لا يوجد رب أسرة حالي لهذه الأسرة؛ لا يمكن تسجيل التسليم.',
            ]);
        }
        if ($original->life_status === LifeStatus::DECEASED) {
            throw ValidationException::withMessages([
                'beneficiary_national_id' => 'المستفيد مسجّل متوفى؛ لا يمكن تسجيل التسليم.',
            ]);
        }
        if (NationalId::normalize($original->national_id) === '') {
            throw ValidationException::withMessages([
                'beneficiary_national_id' => 'لا يوجد رقم هوية مسجّل للمستفيد؛ لا يمكن التحقق من هويته.',
            ]);
        }
        if (! NationalId::matches($original->national_id, $beneficiaryNationalId)) {
            throw ValidationException::withMessages([
                'beneficiary_national_id' => 'رقم الهوية لا يطابق المستفيد المتوقع.',
            ]);
        }

        if ($mode === ReceiptMode::PERSONAL) {
            return ['original' => $original, 'recipient' => $original, 'relationship' => null];
        }

        if (NationalId::normalize($delegateNationalId) === '') {
            throw ValidationException::withMessages([
                'delegate_national_id' => 'رقم هوية المستلم بالنيابة مطلوب مع رقم هوية المستفيد.',
            ]);
        }
        if (NationalId::matches($original->national_id, $delegateNationalId)) {
            throw ValidationException::withMessages([
                'delegate_national_id' => 'المستلم بالنيابة لا يمكن أن يكون المستفيد نفسه.',
            ]);
        }

        // Relationship to the original beneficiary is only provable when
        // they are the current household head of this family.
        if ($headMembership === null || $headMembership->person_id !== $original->id) {
            throw ValidationException::withMessages([
                'delegate_national_id' => 'لا يمكن إثبات صلة البنوة لهذا المستفيد في السجل؛ يُسمح بالاستلام الشخصي فقط.',
            ]);
        }

        // Only this family's active members are compared — no global search.
        $membership = FamilyMembership::query()
            ->where('family_id', $beneficiary->family_id)
            ->where('is_active', true)
            ->where('person_id', '!=', $original->id)
            ->with(['person', 'relationshipType'])
            ->get()
            ->first(fn (FamilyMembership $m) => NationalId::matches($m->person?->national_id, $delegateNationalId));

        if ($membership === null) {
            throw ValidationException::withMessages([
                'delegate_national_id' => 'رقم هوية المستلم لا يطابق أي فرد من أسرة المستفيد.',
            ]);
        }

        $delegate = $membership->person;
        $relationship = $membership->relationshipType?->code;

        if (! in_array($relationship, ['SON', 'DAUGHTER'], true)) {
            throw ValidationException::withMessages([
                'delegate_national_id' => 'يشترط أن يكون المستلم بالنيابة ابنًا أو ابنة للمستفيد.',
            ]);
        }
        if ($delegate->life_status === LifeStatus::DECEASED) {
            throw ValidationException::withMessages([
                'delegate_national_id' => 'المستلم بالنيابة مسجّل متوفى.',
            ]);
        }
        if ($delegate->marital_status !== MaritalStatus::SINGLE) {
            throw ValidationException::withMessages([
                'delegate_national_id' => $delegate->marital_status === MaritalStatus::UNKNOWN
                    ? 'الحالة الاجتماعية للمستلم غير معروفة؛ يشترط أن يكون غير متزوج/ة ومسجّلًا بذلك.'
                    : 'يشترط أن يكون المستلم بالنيابة غير متزوج/ة.',
            ]);
        }

        return ['original' => $original, 'recipient' => $delegate, 'relationship' => $relationship];
    }
}
