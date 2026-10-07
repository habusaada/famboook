{{--
    The printable Digital Family Card (docs/11 FP-ADR-071, PWA-8.3 / 8.3a polish),
    rendered by mPDF: A4 portrait, 15 mm margins, one compact credential in the
    upper part of the page, Arabic RTL. Tables and simple CSS only (mPDF does not
    support flex / grid); no gradients, shadows, textures or emoji; dark
    print-safe text on white with the Famboook purple as a restrained accent.

    Hierarchy: product title and brand → the current household head → the card
    identifiers and family affiliation as a compact grid → the QR with the
    approved instruction → the fixed disclaimer, inside the card.

    Only the approved card-level fields. The verification URL exists ONLY inside
    the QR image — never as text. Latin identifiers are isolated left-to-right.
--}}
@php
    // The metadata grid: label → value, two per row; an absent branch is
    // simply not a cell (no "not available" placeholder).
    $facts = [
        ['label' => 'رقم البطاقة', 'value' => $card->credentialNumber, 'ltr' => true, 'field' => 'credential_number'],
        ['label' => 'رمز الأسرة', 'value' => $card->familyCode, 'ltr' => true, 'field' => 'family_code'],
    ];
    if ($card->clan !== null) {
        $facts[] = ['label' => 'العشيرة', 'value' => $card->clan, 'ltr' => false, 'field' => 'clan'];
    }
    if ($card->branch !== null) {
        $facts[] = ['label' => 'الفرع', 'value' => $card->branch, 'ltr' => false, 'field' => 'branch'];
    }
    $facts[] = ['label' => 'تاريخ الإصدار', 'value' => $card->issuedAtLabel(), 'ltr' => false, 'field' => 'issued_at'];
    $rows = array_chunk($facts, 2);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>بطاقة الأسرة الرقمية</title>
<style>
    body { font-family: ibmplexsansarabic; color: #18181b; font-size: 10pt; direction: rtl; }
    .card { border: 0.5mm solid #d9d2e6; border-radius: 3mm; background-color: #ffffff; padding: 6mm 8mm 5mm 8mm; }
    table { border-collapse: collapse; }
    .header { width: 100%; }
    .header td { vertical-align: middle; padding: 0 0 3.5mm 0; border-bottom: 0.4mm solid #751bd5; }
    .title { font-size: 19pt; font-weight: bold; color: #751bd5; text-align: right; }
    .brand { text-align: left; }
    .body { width: 100%; }
    .main { width: 100%; }
    .eyebrow { font-size: 9pt; color: #71717a; padding: 6mm 0 0.5mm 0; }
    .holder { font-size: 18pt; font-weight: bold; color: #18181b; line-height: 1.3; padding: 0 0 5mm 0; }
    .facts { width: 100%; }
    .facts td { padding: 0 0 4mm 4mm; vertical-align: top; width: 50%; }
    .label { font-size: 9pt; color: #71717a; }
    .value { font-size: 11.5pt; font-weight: bold; color: #18181b; }
    .side { width: 100%; }
    .scan { border: 0.3mm solid #d9d2e6; background-color: #ffffff; }
    .scan td { text-align: center; }
    .qr { padding: 4mm 0 1.5mm 0; }
    .instruction { font-size: 8.5pt; color: #3f3f46; line-height: 1.5; padding: 0 8mm 3.5mm 8mm; }
    .footer td { border-top: 0.3mm solid #e2dcee; padding: 3.5mm 0 0 0; font-size: 9pt; color: #52525b; line-height: 1.65; text-align: right; }
</style>
</head>
<body>
<div class="card">
    <table class="header">
        <tr>
            <td class="title">بطاقة الأسرة الرقمية</td>
            <td class="brand"><img src="{{ $logo }}" style="width: 34mm;" alt="Famboook"></td>
        </tr>
    </table>

    <table class="body">
        <tr>
            <td style="width: 64%; padding: 0 0 0 6mm; vertical-align: top;">
                <table class="main">
                    <tr><td class="eyebrow">رب الأسرة الحالي</td></tr>
                    <tr><td class="holder" data-field="head_name">{{ $card->headName }}</td></tr>
                    <tr>
                        <td style="padding: 0;">
                            <table class="facts">
                                @foreach ($rows as $row)
                                    <tr>
                                        @foreach ($row as $fact)
                                            <td>
                                                <div class="label">{{ $fact['label'] }}</div>
                                                <div class="value" data-field="{{ $fact['field'] }}">@if ($fact['ltr'])<bdo dir="ltr">{{ $fact['value'] }}</bdo>@else{{ $fact['value'] }}@endif</div>
                                            </td>
                                        @endforeach
                                        @if (count($row) === 1)
                                            <td></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 36%; padding: 6mm 0 4mm 0; vertical-align: top;">
                <table class="side scan">
                    <tr><td class="qr"><img src="{{ $qr }}" style="width: 39mm; height: 39mm;" alt="رمز QR"></td></tr>
                    <tr><td class="instruction">امسح رمز QR للتحقق من صلاحية البطاقة عبر Famboook.</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="footer" style="width: 100%;">
        <tr><td>وسيلة تحقق رقمية ضمن نظام Famboook، وليست وثيقة هوية رسمية. يُثبت رمز QR صلاحية البطاقة فقط، ولا يُثبت هوية الشخص الذي يحملها.</td></tr>
    </table>
</div>
</body>
</html>
