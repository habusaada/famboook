{{--
    The printable Digital Family Card (docs/11 FP-ADR-071, PWA-8.3), rendered by
    mPDF: A4 portrait, 15 mm margins, one card panel, Arabic RTL. Tables and
    simple CSS only (mPDF does not support flex / grid); no gradients, shadows or
    emoji; dark print-safe text on white with the Famboook purple as an accent.

    Only the approved card-level fields. The verification URL exists ONLY inside
    the QR image — never as text. Latin identifiers are isolated left-to-right.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>بطاقة الأسرة الرقمية</title>
<style>
    body { font-family: ibmplexsansarabic; color: #1f1530; font-size: 11pt; direction: rtl; }
    .cut { border: 0.3mm dashed #9b93ad; padding: 4mm; }
    .card { width: 100%; border: 0.5mm solid #751bd5; border-collapse: collapse; background-color: #ffffff; }
    .header td { border-bottom: 1.2mm solid #751bd5; padding: 4mm 5mm 3mm 5mm; vertical-align: middle; }
    .title { font-size: 15pt; font-weight: bold; color: #751bd5; text-align: right; }
    .logo { text-align: left; }
    .body td { padding: 5mm; vertical-align: top; }
    .holder-label { font-size: 9pt; color: #5b5470; }
    .holder { font-size: 15pt; font-weight: bold; color: #1f1530; padding-bottom: 3mm; }
    .facts { width: 100%; border-collapse: collapse; }
    .facts td { padding: 1.6mm 0; border-bottom: 0.2mm solid #e4e0ec; font-size: 10.5pt; }
    .facts .label { color: #5b5470; width: 32mm; }
    .facts .value { font-weight: bold; color: #1f1530; }
    .qr { text-align: center; width: 52mm; }
    .instruction { font-size: 9.5pt; color: #1f1530; text-align: center; padding: 3mm 5mm 4mm 5mm; border-top: 0.2mm solid #e4e0ec; }
    .disclaimer { font-size: 9pt; color: #5b5470; margin-top: 5mm; line-height: 1.6; }
</style>
</head>
<body>
<div class="cut">
    <table class="card">
        <tr class="header">
            <td class="title">بطاقة الأسرة الرقمية</td>
            <td class="logo"><img src="{{ $logo }}" style="height: 7mm;" alt="Famboook"></td>
        </tr>
        <tr class="body">
            <td>
                <div class="holder-label">رب الأسرة الحالي</div>
                <div class="holder" data-field="head_name">{{ $card->headName }}</div>
                <table class="facts">
                    <tr>
                        <td class="label">رقم البطاقة</td>
                        <td class="value" data-field="credential_number"><bdo dir="ltr">{{ $card->credentialNumber }}</bdo></td>
                    </tr>
                    <tr>
                        <td class="label">رمز الأسرة</td>
                        <td class="value" data-field="family_code"><bdo dir="ltr">{{ $card->familyCode }}</bdo></td>
                    </tr>
                    @if ($card->clan !== null)
                        <tr>
                            <td class="label">العشيرة</td>
                            <td class="value" data-field="clan">{{ $card->clan }}</td>
                        </tr>
                    @endif
                    @if ($card->branch !== null)
                        <tr>
                            <td class="label">الفرع</td>
                            <td class="value" data-field="branch">{{ $card->branch }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="label">تاريخ الإصدار</td>
                        <td class="value" data-field="issued_at">{{ $card->issuedAtLabel() }}</td>
                    </tr>
                </table>
            </td>
            <td class="qr"><img src="{{ $qr }}" style="width: 45mm; height: 45mm;" alt="رمز QR"></td>
        </tr>
        <tr>
            <td colspan="2" class="instruction">امسح رمز QR للتحقق من صلاحية البطاقة عبر Famboook.</td>
        </tr>
    </table>
</div>
<p class="disclaimer">وسيلة تحقق رقمية ضمن نظام Famboook، وليست وثيقة هوية رسمية. يُثبت رمز QR صلاحية البطاقة فقط، ولا يُثبت هوية الشخص الذي يحملها.</p>
</body>
</html>
