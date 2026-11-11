<?php

namespace App\Libraries;

/*******************
 * Cryptor Class
 *******************
 * Source: https://github.com/ioncube/php-openssl-cryptor/
 * Simple example of using the openssl encrypt/decrypt functions that
 * are inadequately documented in the PHP manual.
 *
 * Available under the MIT License
 *
 * The MIT License (MIT)
 * Copyright (c) 2016 ionCube Ltd.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of
 * the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO
 * THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS
 * OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

class Cryptor
{
    private $cipher_algo;
    private $hash_algo;
    private $format;

    /**
     * Lunghezza dell'IV del cifrario, calcolata nel costruttore. Va dichiarata:
     * senza, PHP 8.2+ la crea come proprietà dinamica, il deprecato finisce nel
     * log con la traccia degli argomenti — che qui contengono il testo cifrato,
     * byte non UTF-8 compresi — e la toolbar di debug va in errore.
     */
    private $iv_num_bytes;

    const FORMAT_RAW = 0;
    const FORMAT_B64 = 1;
    const FORMAT_HEX = 2;

    /**
     * Construct a Cryptor, using aes256 encryption, sha256 key hashing and base64 encoding.
     * @param string $cipher_algo The cipher algorithm.
     * @param string $hash_algo   Key hashing algorithm.
     * @param int    $fmt         Format of the encrypted data.
     */
    public function __construct($cipher_algo = 'aes-256-ctr', $hash_algo = 'sha256', $fmt = self::FORMAT_B64)
    {
        $this->cipher_algo = $cipher_algo;
        $this->hash_algo = $hash_algo;
        $this->format = $fmt;

        if (!in_array($cipher_algo, openssl_get_cipher_methods(true)))
        {
            throw new \Exception("Cryptor:: - unknown cipher algo {$cipher_algo}");
        }

        if (!in_array($hash_algo, openssl_get_md_methods(true)))
        {
            throw new \Exception("Cryptor:: - unknown hash algo {$hash_algo}");
        }

        $this->iv_num_bytes = openssl_cipher_iv_length($cipher_algo);
    }

    /** Byte del tag di autenticazione GCM (dimensione standard OpenSSL). */
    private const GCM_TAG_BYTES = 16;

    /**
     * Vero per una modalità AEAD (autenticata): il ciphertext porta anche
     * un tag di integrità, e va gestito con un parametro in più rispetto
     * alle modalità non autenticate (CTR/CBC) del resto della classe.
     */
    private function isAead(): bool
    {
        return str_contains($this->cipher_algo, '-gcm') || str_contains($this->cipher_algo, '-ccm');
    }

    /**
     * Encrypt a string.
     * @param  string $in  String to encrypt.
     * @param  string $key Encryption key.
     * @param  int $fmt Optional override for the output encoding. One of FORMAT_RAW, FORMAT_B64 or FORMAT_HEX.
     * @return string      The encrypted string.
     */
    public function encryptString($in, $key, $fmt = null)
    {
        if ($fmt === null)
        {
            $fmt = $this->format;
        }

        // Build an initialisation vector
        $iv = openssl_random_pseudo_bytes($this->iv_num_bytes, $isStrongCrypto);
        if (!$isStrongCrypto) {
            throw new \Exception("Cryptor::encryptString() - Not a strong key");
        }

        // Hash the key
        $keyhash = openssl_digest($key, $this->hash_algo, true);

        // and encrypt
        $opts =  OPENSSL_RAW_DATA;
        $tag = '';

        if ($this->isAead()) {
            // Il tag va catturato per riferimento: senza, GCM non autentica
            // nulla e la modalita' degrada silenziosamente a una cifratura
            // non protetta da manomissione (bit-flipping sul ciphertext).
            $encrypted = openssl_encrypt($in, $this->cipher_algo, $keyhash, $opts, $iv, $tag, '', self::GCM_TAG_BYTES);
        } else {
            $encrypted = openssl_encrypt($in, $this->cipher_algo, $keyhash, $opts, $iv);
        }

        if ($encrypted === false)
        {
            throw new \Exception('Cryptor::encryptString() - Encryption failed: ' . openssl_error_string());
        }

        // The result comprises the IV, the auth tag (solo AEAD) and encrypted data
        $res = $iv . $tag . $encrypted;

        // and format the result if required.
        if ($fmt == self::FORMAT_B64)
        {
            $res = base64_encode($res);
        }
        else if ($fmt == self::FORMAT_HEX)
        {
            $res = unpack('H*', $res)[1];
        }

        return $res;
    }

    /**
     * Decrypt a string.
     * @param  string $in  String to decrypt.
     * @param  string $key Decryption key.
     * @param  int $fmt Optional override for the input encoding. One of FORMAT_RAW, FORMAT_B64 or FORMAT_HEX.
     * @return string      The decrypted string.
     */
    public function decryptString($in, $key, $fmt = null)
    {
        if ($fmt === null)
        {
            $fmt = $this->format;
        }

        $raw = $in;

        // Restore the encrypted data if encoded
        if ($fmt == self::FORMAT_B64)
        {
            $raw = base64_decode($in);
        }
        else if ($fmt == self::FORMAT_HEX)
        {
            $raw = pack('H*', $in);
        }

        // and do an integrity check on the size.
        if (strlen($raw) < $this->iv_num_bytes)
        {
            throw new \Exception('Cryptor::decryptString() - ' .
                'data length ' . strlen($raw) . " is less than iv length {$this->iv_num_bytes}");
        }

        // Extract the initialisation vector and encrypted data
        $iv = substr($raw, 0, $this->iv_num_bytes);
        $raw = substr($raw, $this->iv_num_bytes);

        $tag = '';
        if ($this->isAead()) {
            if (strlen($raw) < self::GCM_TAG_BYTES) {
                throw new \Exception('Cryptor::decryptString() - data too short to contain an auth tag');
            }
            $tag = substr($raw, 0, self::GCM_TAG_BYTES);
            $raw = substr($raw, self::GCM_TAG_BYTES);
        }

        // Hash the key
        $keyhash = openssl_digest($key, $this->hash_algo, true);

        // and decrypt.
        $opts = OPENSSL_RAW_DATA;
        $res = $this->isAead()
            ? openssl_decrypt($raw, $this->cipher_algo, $keyhash, $opts, $iv, $tag)
            : openssl_decrypt($raw, $this->cipher_algo, $keyhash, $opts, $iv);

        if ($res === false)
        {
            // Con AEAD, false significa anche "tag non valido": il
            // ciphertext e' stato alterato, non solo "chiave sbagliata".
            throw new \Exception('Cryptor::decryptString - decryption failed: ' . openssl_error_string());
        }

        return $res;
    }

    /**
     * Static convenience method for encrypting.
     * @param  string $in  String to encrypt.
     * @param  string $key Encryption key.
     * @param  int $fmt Optional override for the output encoding. One of FORMAT_RAW, FORMAT_B64 or FORMAT_HEX.
     * @return string      The encrypted string.
     */
    public static function Encrypt($in, $key, $fmt = null)
    {
        $c = new Cryptor();
        return $c->encryptString($in, $key, $fmt);
    }

    /**
     * Static convenience method for decrypting.
     * @param  string $in  String to decrypt.
     * @param  string $key Decryption key.
     * @param  int $fmt Optional override for the input encoding. One of FORMAT_RAW, FORMAT_B64 or FORMAT_HEX.
     * @return string      The decrypted string.
     */
    public static function Decrypt($in, $key, $fmt = null)
    {
        $c = new Cryptor();
        return $c->decryptString($in, $key, $fmt);
    }
}
