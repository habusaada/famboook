<?php

namespace App\Enums;

// Who originated a notification message (FB-NOTIFY-01B.1 design §6): the
// system (SYSTEM kind only), administration Staff, or a coordinator. The
// family-visible source label is derived from it and never stored; it never
// reveals the sending user.
enum NotificationSenderSide: string
{
    case SYSTEM = 'SYSTEM';
    case STAFF = 'STAFF';
    case COORDINATOR = 'COORDINATOR';

    /** The family-visible source (ADR-01, docs/11 §22). */
    public function familyLabel(): string
    {
        return match ($this) {
            self::SYSTEM, self::STAFF => 'إدارة Famboook',
            self::COORDINATOR => 'منسق العائلة',
        };
    }
}
