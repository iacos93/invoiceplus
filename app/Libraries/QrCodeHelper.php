<?php

namespace App\Libraries;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR code SVG in data URI per la schermata di attivazione MFA.
 *
 * Un PNG richiederebbe un upload o un endpoint dedicato: l'SVG data URI è
 * una stringa da incollare in un <img> (nessuna richiesta extra, scala col
 * tema). chillerlan php-qrcode è puro PHP e non ha dipendenze oltre le
 * estensioni di base.
 */
class QrCodeHelper
{
    public static function svg(string $uri, int $scale = 6): string
    {
        $options = new QROptions([
            'scale' => max(1, min($scale, 20)),
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->render($uri);
    }
}