<?php

namespace App\Libraries\Sdi;

/**
 * Verifica best-effort della firma CMS/CAdES delle buste `.p7m`.
 *
 * Lo SdI consegna le fatture dentro una busta CMS SignedData (CAdES-BES),
 * in DER oppure in BER a lunghezza indefinita con il contenuto spezzato in
 * blocchi. FatturaXml::unwrapP7m() sa estrarre l'XML da entrambe; questa
 * classe prova anche a dire se il contenuto è quello firmato.
 *
 * Cosa viene verificato:
 *   - l'integrità: il digest del contenuto e la firma del firmatario sono
 *     ricalcolati sui certificati inclusi nella busta
 *     (`PKCS7_NOVERIFY | PKCS7_BINARY`). NON si verifica che il certificato
 *     sia attendibile, né la catena, né la revoca: per quelle serve la CA
 *     SOGEI e restano demandate al canale (mTLS) e allo SdI.
 *   - la scadenza dei certificati trovati nella busta, riportata a parte.
 *
 * `PKCS7_NOVERIFY` è la scelta giusta qui: senza una CA attendibile non si
 * potrebbe verificare nessuna busta reale, ma il flag salta solo il controllo
 * della catena, non quello della firma. Una manomissione del contenuto resta
 * quindi rilevabile.
 *
 * Perché è best-effort: le buste reali sono BER e OpenSSL può non digerirle
 * (versioni diverse, costrutti non supportati). In quel caso `checked` è
 * false e `valid` è null: chi chiama deve accettare il documento e segnalare
 * che non è stato possibile verificarlo, mai scartarlo. Si rifiuta soltanto
 * quando OpenSSL ha letto la busta e ha rilevato un digest o una firma non
 * validi (`valid === false`).
 *
 * Non usa estensioni oltre a openssl e non lancia processi esterni: la CLI
 * `openssl` serve solo ai test, per generare le buste di prova.
 */
final class P7mSignature
{
    /** OID 1.2.840.113549.1.7.2 (signedData), codificato in DER. */
    private const SIGNED_DATA_OID = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02";

    /**
     * Ispeziona una busta CMS.
     *
     * @param string $content contenuto del `.p7m`: DER binario, base64 (con o
     *                        senza righe), oppure PEM `PKCS7`/`CMS`
     *
     * @return array{
     *     checked: bool,
     *     valid: ?bool,
     *     signers: list<array{cn: string, issuer: string, valid_from: string, valid_to: string, expired: bool}>,
     *     problems: list<string>
     * }
     *     checked: OpenSSL ha saputo leggere la busta e ha potuto esprimere un
     *              giudizio (vero anche quando la firma risulta NON valida);
     *     valid:   true firma verificata, false contenuto/firma non validi,
     *              null non verificabile (busta non interpretabile o assente);
     *     signers: certificati inclusi nella busta, con il CN e la validità.
     *              In una busta tipica il primo è il firmatario, gli altri
     *              (se ci sono) sono la catena. Su una busta manomessa OpenSSL
     *              non li esporta, quindi la lista può essere vuota anche con
     *              `checked` true;
     *     problems: motivi leggibili, per il log e per gli avvisi all'utente.
     */
    public static function inspect(string $content): array
    {
        $result = [
            'checked'  => false,
            'valid'    => null,
            'signers'  => [],
            'problems' => [],
        ];

        if (!function_exists('openssl_pkcs7_verify')) {
            $result['problems'][] = 'estensione openssl di PHP non disponibile';

            return $result;
        }

        $der = self::der($content);

        if ($der === null) {
            $result['problems'][] = 'il contenuto non è una busta CMS (PKCS#7 SignedData)';

            return $result;
        }

        self::clearErrors();

        $input   = tempnam(sys_get_temp_dir(), 'p7m-sign-');
        $signers = tempnam(sys_get_temp_dir(), 'p7m-certs-');

        if ($input === false || $signers === false) {
            if ($input !== false) {
                @unlink($input);
            }

            if ($signers !== false) {
                @unlink($signers);
            }

            $result['problems'][] = 'impossibile creare i file temporanei per la verifica';

            return $result;
        }

        try {
            // openssl_pkcs7_verify() vuole un messaggio S/MIME, non un DER
            // nudo: la busta si incapsula in base64 con gli header MIME.
            file_put_contents($input, self::smime($der));

            // Il terzo parametro è il file in cui OpenSSL scrive i
            // certificati dei firmatari (in PEM) quando la verifica riesce.
            $verified = @openssl_pkcs7_verify(
                $input,
                PKCS7_NOVERIFY | PKCS7_BINARY,
                $signers,
                [],
                null,
                null,
                null
            );

            $errors = self::drainErrors();

            if ($verified === true) {
                $result['checked'] = true;
                $result['valid']   = true;
                $result['signers'] = self::readSigners((string) @file_get_contents($signers));

                return $result;
            }

            // In PHP 8.5 il valore può essere anche -1 ("errore") oltre a
            // false: solo un digest/signature failure però prova che la busta
            // è stata letta e che il contenuto non torna. Qualunque altro
            // errore (struttura BER non digerita, nessuna firma presente) è
            // "non verificabile", non "manomessa".
            if (self::integrityFailure($errors)) {
                $result['checked']    = true;
                $result['valid']      = false;
                $result['problems'][] = 'la firma non corrisponde al contenuto (digest o firma non validi)';
            } else {
                foreach ($errors as $error) {
                    $result['problems'][] = 'OpenSSL: ' . $error;
                }

                if ($errors === []) {
                    $result['problems'][] = 'OpenSSL non ha potuto verificare la busta';
                }
            }

            return $result;
        } finally {
            @unlink($input);
            @unlink($signers);
            self::clearErrors();
        }
    }

    /**
     * Avviso da mostrare/registrare per una busta ispezionata, o null se non
     * c'è nulla da segnalare. Non distingue il chiamante: la stessa frase va
     * bene per l'import delle spese e per quello delle fatture emesse.
     *
     * Non restituisce nulla per `valid === false`: quel documento va rifiutato
     * prima dell'import (vedi FatturaXml::unwrapP7m()), quindi non arriva qui.
     *
     * @param array<string, mixed> $inspection esito di inspect()
     */
    public static function warning(array $inspection): ?string
    {
        if (($inspection['checked'] ?? false) !== true) {
            $reason = (string) (($inspection['problems'] ?? [])[0] ?? 'busta non interpretabile');

            return 'firma .p7m non verificabile (' . $reason . '): documento accettato senza verifica crittografica';
        }

        if (($inspection['valid'] ?? null) === false) {
            return 'firma digitale non valida: il contenuto è stato modificato';
        }

        foreach ($inspection['signers'] ?? [] as $signer) {
            if (!empty($signer['expired'])) {
                return 'certificato del firmatario ' . ($signer['cn'] ?: 'senza CN')
                    . ' scaduto il ' . substr((string) ($signer['valid_to'] ?? ''), 0, 10)
                    . ': firma formalmente valida ma certificato non più in corso';
            }
        }

        return null;
    }

    /**
     * Porta il contenuto a DER binario. Accetta DER, base64 (anche su più
     * righe) e PEM PKCS7/CMS; qualunque altra cosa torna null.
     */
    private static function der(string $content): ?string
    {
        $trimmed = ltrim($content, "\xEF\xBB\xBF \t\r\n");

        // PEM: intestazione e chiusura con il base64 in mezzo.
        if (preg_match('/-----BEGIN (?:PKCS7|CMS)-----(.*?)-----END (?:PKCS7|CMS)-----/s', $trimmed, $match) === 1) {
            $body   = preg_replace('/\s+/', '', $match[1]) ?? '';
            $binary = base64_decode($body, true);

            return $binary !== false && self::looksLikeCms($binary) ? $binary : null;
        }

        if (self::looksLikeCms($trimmed)) {
            return $trimmed;
        }

        // Base64: i .p7m scaricati dal portale sono spesso solo testo.
        $compact = preg_replace('/\s+/', '', $content) ?? '';

        if ($compact === '') {
            return null;
        }

        $decoded = base64_decode($compact, true);

        return $decoded !== false && self::looksLikeCms($decoded) ? $decoded : null;
    }

    /** Una busta CMS comincia con una SEQUENCE e contiene l'OID signedData. */
    private static function looksLikeCms(string $content): bool
    {
        return strlen($content) > 16
            && $content[0] === "\x30"
            && str_contains(substr($content, 0, 64), self::SIGNED_DATA_OID);
    }

    /** Incapsula il DER nel messaggio S/MIME che openssl_pkcs7_verify() legge. */
    private static function smime(string $der): string
    {
        return "MIME-Version: 1.0\r\n"
            . "Content-Disposition: attachment; filename=\"smime.p7m\"\r\n"
            . "Content-Type: application/x-pkcs7-mime; smime-type=signed-data; name=\"smime.p7m\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($der), 64, "\r\n");
    }

    /**
     * Certificati PEM esportati da OpenSSL, letti con openssl_x509_parse().
     *
     * @return list<array{cn: string, issuer: string, valid_from: string, valid_to: string, expired: bool}>
     */
    private static function readSigners(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches);

        $signers = [];
        $seen    = [];

        foreach (array_unique($matches[0]) as $certificate) {
            $info = @openssl_x509_parse($certificate);

            if (!is_array($info)) {
                continue;
            }

            $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');

            if (is_string($fingerprint) && isset($seen[$fingerprint])) {
                continue;
            }

            if (is_string($fingerprint)) {
                $seen[$fingerprint] = true;
            }

            $from = (int) ($info['validFrom_time_t'] ?? 0);
            $to   = (int) ($info['validTo_time_t'] ?? 0);

            $signers[] = [
                'cn'         => self::commonName($info['subject'] ?? []),
                'issuer'     => self::commonName($info['issuer'] ?? []),
                'valid_from' => $from > 0 ? gmdate('Y-m-d H:i:s', $from) : '',
                'valid_to'   => $to > 0 ? gmdate('Y-m-d H:i:s', $to) : '',
                'expired'    => $to > 0 && $to < time(),
            ];
        }

        return $signers;
    }

    /**
     * CN del soggetto/emittente, o la denominazione se il CN manca (alcuni
     * certificati di firma non lo portano).
     *
     * @param array<string, mixed> $name
     */
    private static function commonName(array $name): string
    {
        foreach (['CN', 'O'] as $field) {
            $value = $name[$field] ?? null;

            if (is_array($value)) {
                $value = reset($value);
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return 'n/d';
    }

    /**
     * Gli errori OpenSSL che provano una manomissione: la busta è stata letta
     * e il confronto fra contenuto e firma è stato eseguito.
     *
     * @param list<string> $errors
     */
    private static function integrityFailure(array $errors): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error, 'digest failure') || str_contains($error, 'signature failure')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Svuota la coda degli errori OpenSSL e restituisce quelli accumulati
     * durante la verifica, senza duplicati.
     *
     * @return list<string>
     */
    private static function drainErrors(): array
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return array_values(array_unique($errors));
    }

    private static function clearErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
