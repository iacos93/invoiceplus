<?php

namespace App\Libraries;

/**
 * Password condivisa di un documento (fattura o preventivo).
 *
 * Fino a oggi la password del portale guest si cifrava con encrypt_secret():
 * reversibile per costruzione, quindi chiunque leggesse la colonna -- un dump,
 * un backup, una query di troppo -- aveva la password in chiaro. Qui si usa un
 * hash bcrypt: password_verify() basta a dire sì o no, e dal database non si
 * torna indietro.
 *
 * Perché bcrypt e non PASSWORD_DEFAULT/Argon2: la colonna è VARCHAR(90) e un
 * hash bcrypt ne occupa 60. Argon2 supera i 90 e verrebbe troncato, cioè
 * rifiutato per sempre.
 *
 * La migrazione dei valori vecchi è trasparente: verify() riconosce un valore
 * non-hash, lo decifra come faceva il codice precedente e, se la password è
 * giusta, restituisce l'hash nuovo da scrivere al posto del vecchio.
 */
final class GuestPassword
{
    /**
     * Lunghezza della colonna invoice_password / quote_password.
     */
    public const COLUMN_LENGTH = 90;

    /**
     * bcrypt: 60 caratteri, dentro i 90 della colonna.
     */
    public const ALGORITHM = PASSWORD_BCRYPT;

    /**
     * Il valore è già un hash gestito da password_hash()?
     */
    public static function isHash(string $value): bool
    {
        return $value !== '' && password_get_info($value)['algo'] !== null;
    }

    /**
     * Il valore è nel formato vecchio (cifrato con encrypt_secret, o addirittura
     * in chiaro per i dati anteriori alla cifratura)?
     */
    public static function isLegacy(string $value): bool
    {
        return $value !== '' && !self::isHash($value);
    }

    /**
     * Hash da salvare per una password in chiaro.
     *
     * Idempotente: un valore che è già un hash viene restituito tal quale.
     * Serve ai percorsi che ricopiano la colonna da un documento all'altro
     * (preventivo → fattura) e non devono ri-hashare l'hash.
     */
    public static function hash(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        if (self::isHash($plain)) {
            return $plain;
        }

        return password_hash($plain, self::ALGORITHM);
    }

    /**
     * Verifica una password inserita nel form pubblico.
     *
     * Restituisce:
     *  - ok:      la password è corretta;
     *  - upgrade: l'hash nuovo da scrivere in colonna, oppure null.
     *
     * upgrade è valorizzato quando il valore salvato non è un hash (formato
     * vecchio) o quando l'hash è di un algoritmo/costo superato. In entrambi i
     * casi la verifica è già riuscita: chi chiama deve solo sostituire il
     * valore, senza far ripetere l'inserimento al cliente.
     *
     * @return array{ok: bool, upgrade: string|null}
     */
    public static function verify(string $stored, string $submitted): array
    {
        if ($stored === '') {
            return ['ok' => false, 'upgrade' => null];
        }

        if (self::isHash($stored)) {
            if (!password_verify($submitted, $stored)) {
                return ['ok' => false, 'upgrade' => null];
            }

            $upgrade = password_needs_rehash($stored, self::ALGORITHM)
                ? self::hash($submitted)
                : null;

            return ['ok' => true, 'upgrade' => $upgrade];
        }

        // Formato vecchio: encrypt_secret() (prefisso enc2:/enc:) oppure testo
        // in chiaro per i dati più antichi della cifratura. decrypt_secret()
        // copre entrambi e restituisce '' se il ciphertext è illeggibile.
        $plain = decrypt_secret($stored);

        // hash_equals e non ===: stesso confronto a tempo costante di prima.
        // Il controllo su '' evita che un ciphertext rotto (decifrato a '')
        // faccia passare un invio vuoto.
        $ok = $plain !== '' && hash_equals($plain, $submitted);

        return ['ok' => $ok, 'upgrade' => $ok ? self::hash($submitted) : null];
    }
}
