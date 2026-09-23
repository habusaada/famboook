<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §19 "displacement_status" — V1 values for
// "هل الأسرة نازحة حاليًا؟". The column stays nullable: NULL means the
// status was never collected (e.g. legacy records) and must not be read
// as NOT_DISPLACED.
enum DisplacementStatus: string
{
    case DISPLACED = 'DISPLACED';
    case NOT_DISPLACED = 'NOT_DISPLACED';
}
