<?php

namespace App\Libraries\Sdi;

use function db_bool;

/**
 * Controlli sul contenuto di una fattura prima di generare il tracciato.
 *
 * La XSD respinge una <Sede> senza indirizzo con il messaggio "Element
 * 'Nazione': This element is not expected. Expected is ( Indirizzo )": parla
 * dell'elemento che c'e' invece di quello che manca, e non dice a chi manca.
 * Chi legge si trova davanti a un errore di schema su un campo che nel
 * gestionale non ha mai compilato.
 *
 * Qui si guardano i campi che finiscono in <Sede> e si dice quale parte li ha
 * incompleti, cosi' la pagina di verifica e il blocco dell'invio possono
 * mandare l'utente sulla scheda giusta invece di lasciarlo a decifrare il
 * messaggio di libxml.
 *
 * Solo decisioni, nessun I/O e nessun database: si verifica nei test unitari.
 */
final class SdiInvoiceCheck
{
    /**
     * Campi che IndirizzoType rende obbligatori, nell'ordine dello schema:
     * Indirizzo, CAP e Comune. Provincia e Nazione non sono qui perche'
     * la prima e' facoltativa e la seconda ha IT come default.
     *
     * @var array<string, string>
     */
    public const SEDE_REQUIRED = [
        // Il suffisso e' quello delle colonne di ip_clients/ip_companies
        // ('address_1', non 'address'): la fattura le porta gia' unite come
        // client_* ed emitter_*.
        'address_1' => 'Indirizzo',
        'zip'       => 'CAP',
        'city'      => 'Comune',
    ];

    /**
     * Parti del tracciato con la <Sede> incompleta.
     *
     * @param array<string, mixed> $invoice riga di InvoiceModel::getByIdScoped()
     *
     * @return list<array{party: string, name: string, fields: list<string>}>
     */
    public static function missingSede(array $invoice): array
    {
        $problems = [];

        $azienda = self::missing($invoice, 'emitter');

        if ($azienda !== []) {
            $problems[] = [
                'party'  => 'company',
                'name'   => trim((string) ($invoice['emitter_name'] ?? '')),
                'fields' => $azienda,
            ];
        }

        // Carta del Docente: il destinatario del tracciato non e' il cliente
        // ma l'ufficio del Ministero, con indirizzo fisso (lo imposta
        // FatturaPaXmlService). La scheda del docente puo' restare senza
        // indirizzo senza che il file ne soffra.
        if (! db_bool($invoice['invoice_carta_docente'] ?? false)) {
            $cliente = self::missing($invoice, 'client');

            if ($cliente !== []) {
                $problems[] = [
                    'party'  => 'client',
                    'name'   => self::clientLabel($invoice),
                    'fields' => $cliente,
                ];
            }
        }

        return $problems;
    }

    /**
     * Nome leggibile del cliente: per una persona nome e cognome, per una
     * societa' la denominazione. Serve per dire "al cliente «...» manca"
     * invece del solo identificativo.
     *
     * @param array<string, mixed> $invoice
     */
    public static function clientLabel(array $invoice): string
    {
        $name    = trim((string) ($invoice['client_name'] ?? ''));
        $surname = trim((string) ($invoice['client_surname'] ?? ''));

        if (($invoice['client_type'] ?? '') === 'person' && $surname !== '') {
            return trim($name . ' ' . $surname);
        }

        return $name;
    }

    /**
     * Campi obbligatori vuoti della sede di una parte.
     *
     * @param array<string, mixed> $invoice
     *
     * @return list<string>
     */
    private static function missing(array $invoice, string $prefix): array
    {
        $missing = [];

        foreach (self::SEDE_REQUIRED as $key => $label) {
            if (trim((string) ($invoice[$prefix . '_' . $key] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
