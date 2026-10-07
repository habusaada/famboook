<?php

namespace App\Support\Credentials;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The printable Digital Family Card (docs/11 FP-ADR-071, PWA-8.3): a
 * presentation of the EXISTING ACTIVE Family credential, never a new one.
 *
 * mPDF (GPL-2.0-only, server-side use) renders the Blade view
 * pdf.family-card in memory and returns the bytes — no PDF, QR or token is
 * written to disk. mPDF's own temp / font-metric cache lives in
 * credentials.pdf.temp_dir (storage/framework/cache/mpdf), created on first
 * use.
 *
 * Arabic: UTF-8, RTL, IBM Plex Sans Arabic (bundled, OFL) as the default font
 * with OpenType layout and kashida; mPDF's bundled DejaVu Sans Condensed
 * substitutes any missing glyph. Only local files and data URIs: stream
 * wrappers are limited to `file`, so nothing is ever fetched remotely.
 */
final class FamilyCardPdf
{
    public const FONT = 'ibmplexsansarabic';

    public const LOGO = 'pdf/famboook-logo.svg';

    /** The HTML handed to mPDF — every value escaped by Blade. */
    public static function html(FamilyCardView $card): string
    {
        return view('pdf.family-card', [
            'card' => $card,
            'qr' => QrCodes::pngDataUri($card->verificationUrl ?? ''),
            'logo' => resource_path(self::LOGO),
        ])->render();
    }

    /** The PDF bytes. The caller has checked that the card has a QR. */
    public static function render(FamilyCardView $card): string
    {
        $tempDir = (string) config('credentials.pdf.temp_dir');
        File::ensureDirectoryExists($tempDir);

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_header' => 0,
            'margin_footer' => 0,
            'tempDir' => $tempDir,
            'fontDir' => [...$fontDirs, resource_path('fonts/ibm-plex-sans-arabic')],
            'fontdata' => $fontData + [
                self::FONT => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-SemiBold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => self::FONT,
            'directionality' => 'rtl',
            // Keep the bundled font for Arabic (no automatic font switch);
            // substitute only glyphs it lacks.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'useSubstitutions' => true,
            'whitelistStreamWrappers' => ['file'],
            'debug' => false,
            'showImageErrors' => false,
        ]);

        $mpdf->SetTitle('بطاقة الأسرة الرقمية');
        $mpdf->SetAuthor('Famboook');
        $mpdf->SetCreator('Famboook');
        $mpdf->WriteHTML(self::html($card));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** famboook-family-card-{credential_number}.pdf — the public card number only. */
    public static function filename(FamilyCardView $card): string
    {
        return 'famboook-family-card-'.$card->credentialNumber.'.pdf';
    }
}
