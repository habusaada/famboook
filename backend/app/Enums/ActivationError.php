<?php

namespace App\Enums;

// docs/11 §30a — the PUBLIC error contract of the Family activation
// endpoints: a stable code, an HTTP status and fixed Arabic text. Nothing
// here says whether a National ID exists, is eligible or has an account;
// internal reasons (FamilyAccessDenial, OtpFailure, ActivationDenial) are
// mapped onto these and never leave the server.
enum ActivationError: string
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

    public function status(): int
    {
        return match ($this) {
            self::OTP_INVALID => 422,
            self::OTP_EXPIRED, self::GRANT_EXPIRED => 410,
            self::OTP_LOCKED => 423,
            self::OTP_COOLDOWN, self::OTP_SEND_LIMIT, self::TOO_MANY_REQUESTS => 429,
            self::ACTIVATION_FAILED => 409,
            self::ACTIVATION_UNAVAILABLE => 503,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::OTP_INVALID => 'رمز التحقق غير صحيح.',
            self::OTP_EXPIRED => 'انتهت صلاحية رمز التحقق.',
            self::OTP_LOCKED => 'تم إيقاف هذا الرمز. ابدأ من جديد.',
            self::OTP_COOLDOWN => 'يمكن طلب رمز جديد بعد قليل.',
            self::OTP_SEND_LIMIT => 'لا يمكن إرسال رمز آخر الآن. ابدأ من جديد لاحقًا.',
            self::GRANT_EXPIRED => 'انتهت مهلة إنشاء كلمة المرور. ابدأ من جديد.',
            self::ACTIVATION_FAILED => 'تعذّر إكمال التفعيل. ابدأ من جديد أو راجع الإدارة.',
            self::TOO_MANY_REQUESTS => 'محاولات كثيرة. حاول مجددًا بعد قليل.',
            self::ACTIVATION_UNAVAILABLE => 'الخدمة غير متاحة حاليًا.',
        };
    }
}
