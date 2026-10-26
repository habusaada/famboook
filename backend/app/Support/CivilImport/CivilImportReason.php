<?php

namespace App\Support\CivilImport;

/**
 * Stable reason codes of civil import execution (FB-CIVIL-01A-UI U2) and
 * their FIXED Arabic text. A request's `message` is always one of these
 * texts — never an exception message, a file name, a path or SQL.
 */
final class CivilImportReason
{
    // Worker outcomes beyond the FB-CIVIL-01A reasons (IdentityImportException).
    public const WORKER_LOST = 'WORKER_LOST';

    public const CANCELLED_BY_OPERATOR = 'CANCELLED_BY_OPERATOR';

    public const SOURCE_UNAVAILABLE = 'SOURCE_UNAVAILABLE';

    public const SOURCE_CHANGED_SINCE_CATALOGUE = 'SOURCE_CHANGED_SINCE_CATALOGUE';

    public const NOT_APPROVED = 'NOT_APPROVED';

    public const TARGET_NOT_CONFIGURED = 'TARGET_NOT_CONFIGURED';

    public const PHASE_NOT_ALLOWED = 'PHASE_NOT_ALLOWED';

    public const UNSUPPORTED_REQUEST = 'UNSUPPORTED_REQUEST';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    // API refusals.
    public const REQUEST_ALREADY_ACTIVE = 'REQUEST_ALREADY_ACTIVE';

    public const SOURCE_NOT_READY = 'SOURCE_NOT_READY';

    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    public const REQUEST_NOT_CANCELLABLE = 'REQUEST_NOT_CANCELLABLE';

    public const REQUEST_NOT_FOUND = 'REQUEST_NOT_FOUND';

    public const NOT_ALLOWED = 'NOT_ALLOWED';

    // U3: publication (API refusals and worker outcomes).
    public const PUBLICATION_ALREADY_OPEN = 'PUBLICATION_ALREADY_OPEN';

    public const PUBLICATION_NOT_PENDING = 'PUBLICATION_NOT_PENDING';

    public const SAME_USER_APPROVAL = 'SAME_USER_APPROVAL';

    public const PUBLICATION_GATE_CLOSED = 'PUBLICATION_GATE_CLOSED';

    public const PUBLICATION_EXPIRED = 'PUBLICATION_EXPIRED';

    public const BASE_CHANGED = 'BASE_CHANGED';

    public const RELEASE_CHANGED = 'RELEASE_CHANGED';

    public const CONFIRMATION_MISMATCH = 'CONFIRMATION_MISMATCH';

    public const AUTHORIZATION_MISSING = 'AUTHORIZATION_MISSING';

    public const NOT_APPROVED_FOR_PUBLICATION = 'NOT_APPROVED_FOR_PUBLICATION';

    private const MESSAGES = [
        // FB-CIVIL-01A (IdentityImportException reasons)
        'NOT_CONFIGURED' => 'اتصال الاستيراد غير مضبوط على الخادم.',
        'UNSAFE_TARGET' => 'قاعدة البيانات المتصلة ليست القاعدة المحددة للاستيراد.',
        'WRONG_ROLE' => 'حساب الاستيراد لا يعمل بالدور المعتمد.',
        'CONCURRENT_IMPORT' => 'عملية استيراد أخرى قيد التشغيل؛ أعد المحاولة لاحقًا.',
        'RUN_CONFLICT' => 'توجد عملية استيراد أخرى مفتوحة؛ ألغِها أولًا.',
        'MANIFEST_INVALID' => 'ملف البيان أو محتوى مجلد المصدر غير صالح؛ لم يُسجَّل شيء.',
        'SOURCE_INVALID' => 'فشل التحقق من ملفات المصدر؛ رُفضت العملية وحُذف تحميلها.',
        'SOURCE_CHANGED' => 'تغيّر أحد ملفات المصدر أثناء التحميل؛ رُفضت العملية وحُذف تحميلها.',
        'STAGING_INTERRUPTED' => 'توقف التحميل؛ العملية ما زالت مفتوحة ويمكن استئنافها.',
        'VALIDATION_FAILED' => 'فشل التحقق على مستوى الإصدار؛ لم يُكتب شيء.',
        'POLICY_BLOCKED' => 'السياسة المعتمدة لا تغطي هذه البيانات؛ لم يُكتب شيء.',
        'POLICY_NOT_APPROVED' => 'السياسة غير معتمدة.',
        'RELEASE_STATE_INVALID' => 'حالة الإصدار أو العملية لا تسمح بهذا الإجراء.',
        'NOT_VERIFIED' => 'الإصدار غير مُحضَّر أو غير متحقق منه.',
        'PREPARATION_INTERRUPTED' => 'توقف التحضير؛ الإصدار النشط لم يتغير ويمكن الاستئناف.',
        'VERIFICATION_FAILED' => 'فشل التحقق من الإصدار؛ لا يمكن نشره.',
        'PUBLICATION_FAILED' => 'تعذّر النشر.',
        'NOT_CONFIRMED' => 'لم يُؤكَّد الإجراء.',
        // Worker
        self::WORKER_LOST => 'توقف عامل الاستيراد أثناء التنفيذ؛ العملية قابلة للاستئناف بطلب جديد.',
        self::CANCELLED_BY_OPERATOR => 'أُوقف التنفيذ بطلب من المشغّل عند آخر نقطة محفوظة؛ يمكن الاستئناف.',
        self::SOURCE_UNAVAILABLE => 'المصدر غير متاح على الخادم.',
        self::SOURCE_CHANGED_SINCE_CATALOGUE => 'تغيّر المصدر منذ فهرسته؛ أعد التحقق منه.',
        self::NOT_APPROVED => 'التشغيل على بيئة الإنتاج يتطلب مرجع موافقة مسجلًا لدى عامل الاستيراد.',
        self::TARGET_NOT_CONFIGURED => 'قاعدة البيانات المستهدفة غير مضبوطة لدى عامل الاستيراد.',
        self::PHASE_NOT_ALLOWED => 'مرحلة الاستيراد الحالية لا تسمح بهذا الإجراء.',
        self::UNSUPPORTED_REQUEST => 'نوع الطلب غير مدعوم.',
        self::INTERNAL_ERROR => 'حدث خطأ غير متوقع لدى عامل الاستيراد؛ العملية قابلة للاستئناف.',
        // API
        self::REQUEST_ALREADY_ACTIVE => 'يوجد طلب تنفيذ آخر في الانتظار أو قيد التشغيل.',
        self::SOURCE_NOT_READY => 'المصدر غير متاح أو ملف البيان غير صالح.',
        self::IDEMPOTENCY_KEY_REUSED => 'مفتاح عدم التكرار مستخدم لطلب مختلف.',
        self::REQUEST_NOT_CANCELLABLE => 'لا يمكن إلغاء هذا الطلب في حالته الحالية.',
        self::REQUEST_NOT_FOUND => 'الطلب غير موجود.',
        self::NOT_ALLOWED => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
        // U3
        self::PUBLICATION_ALREADY_OPEN => 'يوجد طلب نشر آخر مفتوح.',
        self::PUBLICATION_NOT_PENDING => 'طلب النشر لم يعد بانتظار القرار.',
        self::SAME_USER_APPROVAL => 'لا يمكن لمقدّم طلب النشر أن يقرر فيه؛ يلزم مستخدم آخر.',
        self::PUBLICATION_GATE_CLOSED => 'بوابة النشر مغلقة.',
        self::PUBLICATION_EXPIRED => 'انتهت صلاحية طلب النشر.',
        self::BASE_CHANGED => 'تغيّر الإصدار النشط منذ تقديم الطلب؛ يلزم طلب جديد.',
        self::RELEASE_CHANGED => 'لم يعد الإصدار متحققًا منه؛ يلزم طلب جديد.',
        self::CONFIRMATION_MISMATCH => 'رقم الإصدار أو الحضور المتوقع المُدخل لا يطابق الطلب.',
        self::AUTHORIZATION_MISSING => 'لا يوجد مرجع تفويض بالبيانات الحقيقية لدى عامل الاستيراد؛ رُفض النشر.',
        self::NOT_APPROVED_FOR_PUBLICATION => 'لا يوجد اعتماد صالح لهذا النشر؛ رُفض.',
        'PUBLICATION_FAILED' => 'تعذّر النشر وأُلغي؛ الإصدار النشط السابق لم يتغير.',
    ];

    public static function message(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::MESSAGES[$code] ?? 'تعذّر إكمال الطلب.';
    }
}
