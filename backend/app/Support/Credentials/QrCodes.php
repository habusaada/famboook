<?php

namespace App\Support\Credentials;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Server-generated QR codes for Digital Family Cards (docs/11 FP-ADR-070):
 * an SVG as a base64 data URI (chillerlan/php-qrcode, pure PHP — no GD), so
 * the Family Portal renders it with <img> and the PDF (PWA-8.3) can reuse
 * it. ECC level M, the standard quiet zone. The input is the verification
 * URL; it is never logged.
 */
final class QrCodes
{
    public static function svgDataUri(#[\SensitiveParameter] string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'drawLightModules' => false,
            'svgUseFillAttributes' => true,
        ]);

        return (new QRCode($options))->render($data);
    }

    /**
     * The same QR as a PNG data URI for the printable card PDF (PWA-8.3): GD
     * (no SVG renderer dependence in mPDF), black modules on an opaque white
     * background, ECC level M, the standard quiet zone, 10 px per module for
     * print. Rendered in memory, never written to disk.
     */
    public static function pngDataUri(#[\SensitiveParameter] string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'scale' => 10,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($data);
    }
}
