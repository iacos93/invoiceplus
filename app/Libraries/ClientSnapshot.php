<?php

namespace App\Libraries;

/**
 * Copia dei dati del cliente dentro il documento.
 *
 * Una fattura, un preventivo e un DDT nascono con un intestatario preciso:
 * quello che c'era in anagrafica il giorno in cui sono stati emessi. Finché
 * l'intestazione si rileggeva dall'anagrafica a ogni apertura, correggere un
 * refuso nel nome del cliente riscriveva anche il passato — la fattura di
 * marzo mostrava il nome nuovo, e lo stesso numero dava due PDF diversi a
 * seconda di quando lo si apriva. Su un documento fiscale non è un dettaglio:
 * ciò che è stato consegnato al cliente (e allo SdI) deve restare quello.
 *
 * La copia sta in una colonna JSON per documento (`client_snapshot`) e non in
 * dodici colonne per tabella: i campi sono letti ovunque con lo stesso nome che
 * arriva dal join su ip_clients, quindi la copia si applica sopra la riga e
 * viste, PDF, XML FatturaPA, email e portale guest continuano a leggere
 * `client_name`, `client_address_1` e così via senza sapere che esiste.
 *
 * Cosa si ferma e cosa no: nome, indirizzo e dati fiscali sì; email, telefono
 * e lingua no. Un'email scritta a un cliente rinominato deve arrivare
 * all'indirizzo di oggi, un PDF deve mostrare l'intestazione di allora.
 */
final class ClientSnapshot
{
    /**
     * Campi congelati, con il nome che hanno nel join: sono esattamente quelli
     * che finiscono nel PDF e nel tracciato FatturaPA.
     */
    public const FIELDS = [
        'client_name',
        'client_surname',
        'client_address_1',
        'client_address_2',
        'client_city',
        'client_state',
        'client_zip',
        'client_country',
        'client_vat_id',
        'client_tax_code',
        'client_sdi_code',
        'client_sdi_pec',
    ];

    /**
     * Copia dei dati attuali del cliente, pronta da scrivere sul documento.
     * Null se il cliente non esiste (documento orfano): meglio nessuna copia
     * che una copia vuota, che nasconderebbe il nome anche nel join.
     */
    public static function capture(?int $clientId): ?string
    {
        if (!$clientId) {
            return null;
        }

        $client = \Config\Database::connect()
            ->table('ip_clients')
            ->where('client_id', $clientId)
            ->get()
            ->getRowArray();

        return $client ? self::encode($client) : null;
    }

    /** @param array<string, mixed> $client riga di ip_clients */
    public static function encode(array $client): string
    {
        $snapshot = ['client_id' => (int) ($client['client_id'] ?? 0)];

        foreach (self::FIELDS as $field) {
            $snapshot[$field] = (string) ($client[$field] ?? '');
        }

        return (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> vuoto se la colonna è vuota o illeggibile */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Applica la copia alla riga del documento: accetta array o oggetto e
     * restituisce lo stesso tipo, perché i template usano entrambi.
     *
     * Se la copia appartiene a un altro cliente (client_id diverso) non si
     * tocca niente: significa che il documento è stato spostato senza passare
     * dal model, e mostrare i dati del cliente sbagliato sarebbe peggio che
     * mostrare quelli attuali.
     *
     * @param array<string, mixed>|object $row
     *
     * @return array<string, mixed>|object
     */
    public static function apply($row)
    {
        $data = is_array($row) ? $row : (array) $row;
        $snapshot = self::decode($data['client_snapshot'] ?? null);

        if ($snapshot === []) {
            return $row;
        }

        if (isset($data['client_id'], $snapshot['client_id'])
            && (int) $snapshot['client_id'] !== (int) $data['client_id']) {
            return $row;
        }

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $snapshot)) {
                continue;
            }

            if (is_array($row)) {
                $row[$field] = $snapshot[$field];
            } else {
                $row->{$field} = $snapshot[$field];
            }
        }

        return $row;
    }
}
