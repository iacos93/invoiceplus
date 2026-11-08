<?php

namespace App\Libraries;

/**
 * TOTP (RFC 6238) e codici di recupero, senza dipendenze.
 *
 * Scritta a mano invece di tirare dentro otphp: l'algoritmo è una funzione
 * HMAC-SHA1 sul contatore, e una libreria intera per questo sarebbe stato un
 * pacchetto in più da mantenere. La verifica accetta uno scarto di ±1 passo
 * (i ritardi di orologio degli authenticator sono normali) e, se si passa
 * l'ultimo contatore accettato, rifiuta la ri-uso dello stesso codice
 * (anti-replay: un codice intercettato scade con il passo, ma finché è valido
 * non deve poter essere usato due volte).
 *
 * I codici di recupero sono generati con un alfabeto senza caratteri
 * ambigui (niente I/O/0/1) e salvati solo come hash sha256: sono password
 * usa-e-getta, equivalenti in valore a una password in chiaro.
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    /** Alfabeto base32 senza caratteri ambigui. */
    private const BACKUP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Lunghezza dei codici di recupero (10 caratteri ~= 46 bit di entropia). */
    public const BACKUP_CODE_LENGTH = 10;

    /** Numero di codici di recupero generati all'attivazione. */
    public const BACKUP_CODE_COUNT = 10;

    public static function generateSecret(int $length = 20): string
    {
        return self::base32Encode(random_bytes($length));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return self::hotp($secret, intdiv($timestamp, self::PERIOD), self::DIGITS);
    }

    /**
     * Verifica un codice con tolleranza di ±$window passi.
     *
     * @param int|null $lastCounter ultimo contatore accettato (anti-replay)
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $lastCounter = null): bool
    {
        $code = trim($code);
        if ($code === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $current = intdiv(time(), self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            $counter = $current + $i;
            if (hash_equals(self::hotp($secret, $counter, self::DIGITS), $code)) {
                if ($lastCounter !== null && $counter <= (int) $lastCounter) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    /** Restituisce il contatore corrispondente al codice, o null se non valido. */
    public static function counterFor(string $secret, string $code, int $window = 1, ?int $lastCounter = null): ?int
    {
        $code = trim($code);
        if ($code === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }

        $current = intdiv(time(), self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            $counter = $current + $i;
            if (hash_equals(self::hotp($secret, $counter, self::DIGITS), $code)) {
                if ($lastCounter !== null && $counter <= (int) $lastCounter) {
                    return null;
                }

                return $counter;
            }
        }

        return null;
    }

    public static function hotp(string $secret, int $counter, int $digits = 6): string
    {
        $key         = self::base32Decode($secret);
        $binCounter  = pack('N*', 0) . pack('N*', $counter); // 8 byte big-endian
        $hash        = hash_hmac('sha1', $binCounter, $key, true);
        $offset      = ord($hash[19]) & 0x0f;
        $value       = ((ord($hash[$offset]) & 0x7f) << 24)
                     | ((ord($hash[$offset + 1]) & 0xff) << 16)
                     | ((ord($hash[$offset + 2]) & 0xff) << 8)
                     | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** URI otpauth:// per la scansione con l'app authenticator. */
    public static function uri(string $secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function generateBackupCodes(int $count = self::BACKUP_CODE_COUNT, int $length = self::BACKUP_CODE_LENGTH): array
    {
        $codes  = [];
        $alphabetLength = strlen(self::BACKUP_ALPHABET);

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < $length; $j++) {
                $code .= self::BACKUP_ALPHABET[random_int(0, $alphabetLength - 1)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    public static function hashBackupCodes(array $codes): array
    {
        return array_map(static fn (string $code): string => hash('sha256', $code), $codes);
    }

    /** Verifica un codice di recupero contro gli hash salvati. */
    public static function verifyBackupCode(string $code, ?string $storedJson): bool
    {
        $hashes = self::decodeBackupHashes($storedJson);
        if (!$hashes) {
            return false;
        }

        $code = strtoupper(trim($code));
        foreach ($hashes as $hash) {
            if (hash_equals($hash, hash('sha256', $code))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Consuma un codice di recupero: restituisce il nuovo JSON degli hash
     * (senza il codice usato), o null se il codice non è valido.
     */
    public static function consumeBackupCode(string $code, ?string $storedJson): ?string
    {
        $hashes = self::decodeBackupHashes($storedJson);
        if (!$hashes) {
            return null;
        }

        $code = strtoupper(trim($code));
        foreach ($hashes as $i => $hash) {
            if (hash_equals($hash, hash('sha256', $code))) {
                unset($hashes[$i]);

                return json_encode(array_values($hashes));
            }
        }

        return null;
    }

    private static function decodeBackupHashes(?string $storedJson): array
    {
        if (!$storedJson) {
            return [];
        }

        $hashes = json_decode($storedJson, true);

        return is_array($hashes) ? $hashes : [];
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result   = '';
        $buffer   = 0;
        $bits     = 0;

        foreach (str_split($data) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits  += 8;
            while ($bits >= 5) {
                $bits  -= 5;
                $result .= $alphabet[($buffer >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $result .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $result;
    }

    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $map      = [];
        foreach (str_split($alphabet) as $i => $char) {
            $map[$char] = $i;
        }

        $data   = strtoupper(str_replace('=', '', $data));
        $result = '';
        $buffer = 0;
        $bits   = 0;

        foreach (str_split($data) as $char) {
            if (!isset($map[$char])) {
                continue;
            }
            $buffer = ($buffer << 5) | $map[$char];
            $bits  += 5;
            if ($bits >= 8) {
                $bits  -= 8;
                $result .= chr(($buffer >> $bits) & 0xff);
            }
        }

        return $result;
    }
}