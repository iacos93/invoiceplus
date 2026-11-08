<?php

namespace App\Validation;

/**
 * Regole di validazione dei dati fiscali/bancari.
 *
 * Stanno in un ruleset invece che dentro il singolo model perche' lo stesso
 * dato compare in piu' anagrafiche: l'IBAN e' sul conto di saldo (creditore)
 * e sul cliente (debitore RiBa/SDD).
 */
class FiscalRules
{
    /**
     * IBAN formalmente valido secondo lo standard ISO 13616: lunghezza per
     * paese e resto 1 nella divisione per 97.
     *
     * Vale la pena controllarlo qui perche' l'IBAN salvato finisce cosi'
     * com'e' nel nodo <IBAN> della fattura elettronica: una cifra sbagliata
     * non si vede leggendo, e torna indietro come scarto dello SdI giorni
     * dopo l'invio, quando la fattura e' gia' stata numerata.
     *
     * Il valore vuoto passa: la presenza la decide 'required'/'permit_empty'.
     */
    public function valid_iban(?string $str, ?string &$error = null): bool
    {
        $iban = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $str));

        if ($iban === '') {
            return true;
        }

        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            $error = 'L\'IBAN non ha un formato valido (due lettere del paese, due cifre di controllo, poi il numero di conto).';

            return false;
        }

        // Lunghezze ufficiali dei paesi piu' frequenti in fattura. Gli altri
        // passano con il solo controllo del resto: meglio accettare un IBAN
        // estero raro che rifiutarne uno corretto.
        $lengths = ['IT' => 27, 'SM' => 27, 'DE' => 22, 'FR' => 27, 'ES' => 24, 'CH' => 21, 'AT' => 20, 'NL' => 18, 'BE' => 16, 'GB' => 22];
        $country = substr($iban, 0, 2);

        if (isset($lengths[$country]) && strlen($iban) !== $lengths[$country]) {
            $error = 'Un IBAN ' . $country . ' e\' di ' . $lengths[$country] . ' caratteri, questo ne ha ' . strlen($iban) . '.';

            return false;
        }

        // Mod-97: si sposta in coda il prefisso paese+controllo e si sostituisce
        // ogni lettera con il suo numero (A=10 … Z=35); il resto deve fare 1.
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric    = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        // bcmod non e' garantito: il resto si calcola a blocchi sul numero,
        // che per un IBAN arriva a una quarantina di cifre.
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        if ($remainder !== 1) {
            $error = 'L\'IBAN non supera il controllo di validita\': ricontrolla le cifre.';

            return false;
        }

        return true;
    }
}
