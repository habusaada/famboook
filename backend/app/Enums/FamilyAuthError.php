<?php

namespace App\Enums;

// docs/11 §30a — the PUBLIC error contract of the Family authentication
// endpoints (activation, login, password reset): a stable code, an HTTP
// status and fixed Arabic text. Nothing here says whether a National ID
// exists, is eligible or has an account; internal reasons
// (FamilyAccessDenial, OtpFailure, ActivationDenial, …) are mapped onto
// these and never leave the server.
enum FamilyAuthError: string
{
    case OTP_INVALID = 'OTP_INVALID';
    case OTP_EXPIRED = 'OTP_EXPIRED';
    case OTP_LOCKED = 'OTP_LOCKED';
    case OTP_COOLDOWN = 'OTP_COOLDOWN';
    case OTP_SEND_LIMIT = 'OTP_SEND_LIMIT';
    case GRANT_EXPIRED = 'GRANT_EXPIRED';
    case ACTIVATION_FAILED = 'ACTIVATION_FAILED';
    case TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';
    case ACTIVATION_UNAVAILABLE = 'ACTIVATION_UNAVAILABLE';
    // First self-activation cannot start for this input (FP-ADR-054): ONE
    // answer for every eligibility reason, which stays server-side.
    case ACTIVATION_REFUSED = 'ACTIVATION_REFUSED';
    // Login: ONE answer for every credential, account and context failure.
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case FAMILY_AUTH_UNAVAILABLE = 'FAMILY_AUTH_UNAVAILABLE';
    // Password reset.
    case RESET_FAILED = 'RESET_FAILED';
    case PASSWORD_RESET_UNAVAILABLE = 'PASSWORD_RESET_UNAVAILABLE';

    public function status(): int
    {
        return match ($this) {
            self::OTP_INVALID, self::ACTIVATION_REFUSED => 422,
            self::OTP_EXPIRED, self::GRANT_EXPIRED => 410,
            self::OTP_LOCKED => 423,
            self::OTP_COOLDOWN, self::OTP_SEND_LIMIT, self::TOO_MANY_REQUESTS => 429,
            self::ACTIVATION_FAILED, self::RESET_FAILED => 409,
            self::ACTIVATION_UNAVAILABLE, self::FAMILY_AUTH_UNAVAILABLE, self::PASSWORD_RESET_UNAVAILABLE => 503,
            self::INVALID_CREDENTIALS => 401,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::OTP_INVALID => 'رمز التحقق غير صحيح.',
            self::ACTIVATION_REFUSED => 'تعذّر متابعة التفعيل بهذه البيانات. تأكد من إدخال رقم هوية رب الأسرة المسجل في فامبوك، ثم حاول مرة أخرى.',
            self::OTP_EXPIRED => 'انتهت صلاحية رمز التحقق.',
            self::OTP_LOCKED => 'تم إيقاف هذا الرمز. ابدأ من جديد.',
            self::OTP_COOLDOWN => 'يمكن طلب رمز جديد بعد قليل.',
            self::OTP_SEND_LIMIT => 'لا يمكن إرسال رمز آخر الآن. ابدأ من جديد لاحقًا.',
            self::GRANT_EXPIRED => 'انتهت مهلة إنشاء كلمة المرور. ابدأ من جديد.',
            self::ACTIVATION_FAILED => 'تعذّر إكمال التفعيل. ابدأ من جديد أو راجع الإدارة.',
            self::TOO_MANY_REQUESTS => 'محاولات كثيرة. حاول مجددًا بعد قليل.',
            self::ACTIVATION_UNAVAILABLE, self::PASSWORD_RESET_UNAVAILABLE => 'الخدمة غير متاحة حاليًا.',
            self::INVALID_CREDENTIALS => 'رقم الهوية أو كلمة المرور غير صحيحة.',
            self::FAMILY_AUTH_UNAVAILABLE => 'تسجيل الدخول غير متاح حاليًا.',
            self::RESET_FAILED => 'تعذّر تغيير كلمة المرور. ابدأ من جديد أو راجع الإدارة.',
        };
    }
}
