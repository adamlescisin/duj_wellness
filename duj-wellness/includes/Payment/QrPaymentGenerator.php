<?php

declare(strict_types=1);

namespace Duj\Wellness\Payment;

/**
 * Generátor QR platebního kódu ve formátu SPD (Short Payment Descriptor).
 *
 * Specifikace: https://qr-platba.cz/pro-vyvojare/specifikace-formatu/
 * Formát: SPD*1.0*ACC:<IBAN>*AM:<amount>*CC:CZK*MSG:<message>*VS:<vs>
 *
 * IBAN se zadává bez mezer.
 */
final class QrPaymentGenerator
{
    /**
     * Sestaví SPD řetězec pro QR platbu.
     *
     * @param  string $iban         IBAN bez mezer (např. CZ6508000000192000145399)
     * @param  int    $amountMinor  Částka v haléřích (Kč = amountMinor / 100)
     * @param  string $reference    Variabilní symbol (max 10 číslic)
     * @param  string $message      Zpráva pro příjemce (max 60 znaků)
     * @return string               SPD řetězec pro zakódování do QR
     */
    public function generate(
        string $iban,
        int    $amountMinor,
        string $reference,
        string $message = '',
    ): string {
        $iban    = strtoupper(preg_replace('/\s+/', '', $iban));
        $amount  = number_format($amountMinor / 100, 2, '.', '');
        $vs      = preg_replace('/[^0-9]/', '', $reference);
        $vs      = substr($vs, 0, 10);
        $message = substr(preg_replace('/[*]/', '', $message), 0, 60);

        $parts = [
            'SPD',
            '1.0',
            'ACC:' . $iban,
            'AM:'  . $amount,
            'CC:CZK',
        ];

        if ($vs !== '') {
            $parts[] = 'VS:' . $vs;
        }

        if ($message !== '') {
            $parts[] = 'MSG:' . $message;
        }

        return implode('*', $parts);
    }

    /**
     * Vrátí data-URI SVG QR kódu vhodnou pro vložení do e-mailu (<img src="…">).
     * Používá endroid/qr-code přes BaconQrCode backend (vendorovaný bez GD/Imagick).
     */
    public function toDataUri(string $spdString): string
    {
        if ($spdString === '') {
            return '';
        }

        try {
            $qrCode = new \Endroid\QrCode\QrCode(
                data:                 $spdString,
                errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
                size:                 250,
                margin:               10,
            );

            $result = (new \Endroid\QrCode\Writer\SvgWriter())->write($qrCode, options: [
                \Endroid\QrCode\Writer\SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
                \Endroid\QrCode\Writer\SvgWriter::WRITER_OPTION_COMPACT               => true,
            ]);

            $svg = $result->getString();
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable) {
            return '';
        }
    }
}
