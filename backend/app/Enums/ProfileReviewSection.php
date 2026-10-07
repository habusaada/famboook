<?php

namespace App\Enums;

// The Family Profile Review sections (docs/11 §9, FP-ADR-057). PWA-5b uses
// them only as the handler hook "which sections does this request type
// touch" — the one mapping PWA-4 will read to derive PENDING. Nothing of
// PWA-4 itself is implemented.
enum ProfileReviewSection: string
{
    case FAMILY = 'FAMILY';
    case HEAD = 'HEAD';
    case MEMBERS = 'MEMBERS';
    case RESIDENCE = 'RESIDENCE';
}
