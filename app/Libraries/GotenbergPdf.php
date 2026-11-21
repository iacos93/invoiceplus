<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Client HTTP per Gotenberg (Chromium) sul VPS.
 *
 * Gotenberg stampa l'HTML con lo stesso motore del browser, quindi i documenti
 * escono con regole CSS che dompdf non conosce (flex, grid, sfondi). L'HTML del
 * documento è autosufficiente — logo e immagini sono già data URI — quindi
 * viaggia come index.html nella richiesta multipart, senza esporre
 * l'applicazione a Gotenberg.
 *
 * I margini sono quelli del foglio dell'app (16mm in alto, 22mm in basso,
 * 15mm ai lati): con Chromium i margini sono parametri della chiamata e il CSS
 * @page non li governa, quindi vanno ripetuti qui o il documento esce a filo
 * carta. La numerazione "n / totale", che Chromium non sa comporre con
 * counter(page), arriva dal footer.html nativo, che riceve pageNumber e
 * totalPages già calcolati.
 *
 * Qualsiasi errore diventa un'eccezione: chi chiama (pdf_render()) ripiega su
 * dompdf, perché un PDF deve uscire comunque.
 */
class GotenbergPdf
{
    public const DEFAULT_URL     = 'http://127.0.0.1:3000';
    public const DEFAULT_TIMEOUT = 30;

    /** Misure del foglio, le stesse del CSS @page dei documenti. */
    private const MARGIN_TOP    = '16mm';
    private const MARGIN_BOTTOM = '22mm';
    private const MARGIN_SIDE   = '15mm';

    private string $url;
    private int $timeout;

    public function __construct(?string $url = null, ?int $timeout = null)
    {
        $this->url = rtrim($url ?? self::configuredUrl(), '/');

        $timeout      = $timeout ?? (int) get_setting('pdf_gotenberg_timeout', env('pdf.gotenbergTimeout') ?: self::DEFAULT_TIMEOUT);
        $this->timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * URL del servizio: ambiente, impostazione, poi il default di Gotenberg.
     */
    public static function configuredUrl(): string
    {
        $url = trim((string) (env('pdf.gotenbergURL') ?: get_setting('pdf_gotenberg_url', self::DEFAULT_URL)));

        return $url !== '' ? $url : self::DEFAULT_URL;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * HTML → byte del PDF. Lancia RuntimeException se il servizio non risponde
     * o se la risposta non è un PDF.
     */
    public function render(string $html): string
    {
        if (trim($html) === '') {
            throw new RuntimeException('HTML vuoto');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('estensione cURL non disponibile');
        }

        // Due file sotto lo stesso nome di campo: Gotenberg riconosce
        // footer.html dal nome del file, non dalla chiave. Un array PHP con
        // due CURLFile verrebbe inviato come files[0]/files[1], che il
        // servizio non legge: il corpo multipart si compone qui.
        $body = self::multipartBody(
            [
                'index.html'  => $html,
                'footer.html' => self::footerHtml(),
            ],
            [
                // I margini del foglio: li governa la chiamata, non il CSS.
                'paperSize'    => 'A4',
                'marginTop'    => self::MARGIN_TOP,
                'marginBottom' => self::MARGIN_BOTTOM,
                'marginLeft'   => self::MARGIN_SIDE,
                'marginRight'  => self::MARGIN_SIDE,
                // Gli sfondi dei badge e della riga "saldo" devono restare.
                'printBackground' => 'true',
            ]
        );

        $ch = curl_init($this->url . '/forms/chromium/convert/html');

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body['body'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: multipart/form-data; boundary=' . $body['boundary'],
                'Accept: application/pdf',
            ],
        ]);

        $pdf    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = (string) curl_error($ch);

        if (!is_string($pdf) || $status !== 200) {
            throw new RuntimeException(sprintf(
                'Gotenberg ha risposto %d%s',
                $status,
                $error !== '' ? ' (' . $error . ')' : ''
            ));
        }

        if (!str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('la risposta di Gotenberg non è un PDF');
        }

        return $pdf;
    }

    /**
     * Corpo multipart con più file sotto lo stesso nome di campo.
     *
     * @param array<string, string> $files  nome file => contenuto
     * @param array<string, string> $fields campi semplici
     *
     * @return array{body: string, boundary: string}
     */
    private static function multipartBody(array $files, array $fields): array
    {
        $boundary = '----ip-gotenberg-' . bin2hex(random_bytes(12));
        $body     = '';

        foreach ($files as $name => $content) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="files"; filename="' . $name . "\"\r\n"
                . "Content-Type: text/html; charset=utf-8\r\n\r\n"
                . $content . "\r\n";
        }

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n"
                . $value . "\r\n";
        }

        return ['body' => $body . '--' . $boundary . "--\r\n", 'boundary' => $boundary];
    }

    /**
     * Piè di pagina stampato da Chromium in fondo a ogni foglio.
     *
     * Sostituisce il "n / totale" che in dompdf scrive counter(page): Chromium
     * riempie pageNumber e totalPages, quindi il conteggio è esatto anche
     * quando il documento cambia numero di pagine. Lo stile è quello del piede
     * del PDF (7,5pt, grigio, a destra).
     */
    private static function footerHtml(): string
    {
        $page = htmlspecialchars(function_exists('trans') ? trans('page', '', 'Pagina') : 'Pagina', ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{margin:0;font-family:Arial,Helvetica,sans-serif;font-size:7.5pt;color:#6b6b6b;}'
            . '.pages{margin:0 ' . self::MARGIN_SIDE . ' 0 0;text-align:right;}'
            . '</style></head><body><div class="pages">'
            . $page . ' <span class="pageNumber"></span> / <span class="totalPages"></span>'
            . '</div></body></html>';
    }
}
