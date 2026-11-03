<?php

namespace App\Exceptions;

use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit as Audit;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

/**
 * A refused direct admission (FB-FAMILY-ADD-01 ADD-WP-07B-2c; ADR §7 Failure behavior):
 * one public code and nothing else. Nothing was written, apart from the audit row.
 *
 * $auditCode is the INTERNAL outcome code recorded in family_registry_admission_events
 * (an eligibility reason or a procedural code). It is never rendered. NULL when the
 * refusal is recorded nowhere: no household context, the flag or the permission, or a
 * missing declaration.
 */
class RegistryAdmissionRefusedException extends RuntimeException
{
    public const FAMILY_CONTEXT_UNAVAILABLE = 'FAMILY_CONTEXT_UNAVAILABLE';

    public const ADMISSION_UNAVAILABLE = 'ADMISSION_UNAVAILABLE';

    public const DECLARATION_REQUIRED = 'DECLARATION_REQUIRED';

    /** One answer for every eligibility failure and every lost race (D-E1). */
    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const ADMISSION_IDEMPOTENCY_CONFLICT = 'ADMISSION_IDEMPOTENCY_CONFLICT';

    /** The household context changed under the lock: the reference no longer applies. */
    public const REFERENCE_INVALID = 'REFERENCE_INVALID';

    private const STATUS = [
        self::FAMILY_CONTEXT_UNAVAILABLE => 403,
        self::ADMISSION_UNAVAILABLE => 404,
        self::DECLARATION_REQUIRED => 422,
        self::NOT_ELIGIBLE => 422,
        self::ADMISSION_IDEMPOTENCY_CONFLICT => 409,
        self::REFERENCE_INVALID => 422,
    ];

    private const MESSAGES = [
        self::FAMILY_CONTEXT_UNAVAILABLE => 'هذه الخدمة متاحة لرب الأسرة فقط.',
        self::ADMISSION_UNAVAILABLE => 'الخدمة غير متاحة.',
        self::DECLARATION_REQUIRED => 'يلزم الإقرار بأن الابن أو الابنة من أفراد الأسرة.',
        self::NOT_ELIGIBLE => 'لا يمكن إضافة هذا الشخص مباشرة. يمكنك تقديم طلب إضافة فرد.',
        self::ADMISSION_IDEMPOTENCY_CONFLICT => 'استُخدم مرجع العملية نفسه لطلب مختلف.',
        self::REFERENCE_INVALID => 'انتهت صلاحية الاختيار. أعد عرض الأبناء والبنات.',
    ];

    public function __construct(public readonly string $reason, public readonly ?string $auditCode = null)
    {
        if (! isset(self::STATUS[$reason])) {
            throw new InvalidArgumentException('Unknown admission refusal.');
        }
        if ($auditCode !== null && ! in_array($auditCode, Audit::refusalCodes(), true)) {
            throw new InvalidArgumentException('Unknown admission refusal code.');
        }
        parent::__construct($reason);
    }

    public static function notEligible(string $reason): self
    {
        return new self(self::NOT_ELIGIBLE, $reason);
    }

    public function status(): int
    {
        return self::STATUS[$this->reason];
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGES[$this->reason], 'code' => $this->reason], $this->status())
            ->header('Cache-Control', 'no-store, private');
    }
}
