<?php

namespace App\Models;

use App\Libraries\CsvReader;
use RuntimeException;

/**
 * ImportModel
 *
 * Importazione CSV in ip_clients, ip_invoices, ip_invoice_items e
 * ip_payments, e storico delle importazioni (ip_imports + ip_import_details).
 *
 * Rispetto al porting diretto di Mdl_import (CI3) cambiano quattro cose, tutte
 * per difetti che si vedevano solo a dati importati:
 *
 *  - la lettura passa da CsvReader: delimitatore riconosciuto, BOM tolto,
 *    codifica normalizzata, righe non troncate. Prima un CSV uscito da questa
 *    stessa applicazione (";" + BOM) non rientrava;
 *  - date e importi vengono convertiti prima di toccare il database: "31/12/2025"
 *    e "1.234,56" finivano grezzi in colonne DATE e DECIMAL, e diventavano
 *    rispettivamente 0000-00-00 e 1.23;
 *  - ogni file viene importato in transazione, quindi un errore a metà non
 *    lascia mezza fattura;
 *  - ogni riga inserita viene annotata in ip_import_details, così "Elimina"
 *    torna a essere l'annullamento dell'importazione che era in CI3 e non la
 *    sola cancellazione della voce di storico.
 */
class ImportModel extends IpBaseModel
{
    protected $table            = 'ip_imports';
    protected $primaryKey       = 'import_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'import_date',
        'import_file',
        'import_rows',
        'import_error',
        'company_id',
    ];

    /** @var array<string, string> file CSV ammessi => tabella di destinazione */
    private const IMPORT_TABLES = [
        'clients.csv'       => 'ip_clients',
        'invoices.csv'      => 'ip_invoices',
        'invoice_items.csv' => 'ip_invoice_items',
        'payments.csv'      => 'ip_payments',
    ];

    /** Chiave primaria per tabella: serve all'annullamento di un'importazione. */
    private const PRIMARY_KEYS = [
        'ip_clients'       => 'client_id',
        'ip_invoices'      => 'invoice_id',
        'ip_invoice_items' => 'item_id',
        'ip_payments'      => 'payment_id',
    ];

    /** @var array<string, list<string>> intestazioni attese per ogni file CSV */
    private const EXPECTED_HEADERS = [
        'clients.csv' => [
            'client_name',
            'client_address_1',
            'client_address_2',
            'client_city',
            'client_state',
            'client_zip',
            'client_country',
            'client_phone',
            'client_fax',
            'client_mobile',
            'client_email',
            'client_web',
            'client_vat_id',
            'client_tax_code',
            'client_active',
        ],
        'invoices.csv' => [
            'user_email',
            'client_name',
            'invoice_date_created',
            'invoice_date_due',
            'invoice_number',
            'invoice_terms',
        ],
        'invoice_items.csv' => [
            'invoice_number',
            'item_tax_rate',
            'item_date_added',
            'item_name',
            'item_description',
            'item_quantity',
            'item_price',
        ],
        'payments.csv' => [
            'invoice_number',
            'payment_method',
            'payment_date',
            'payment_amount',
            'payment_note',
        ],
    ];

    /**
     * Colonne che il database vuole in un formato preciso. Il CSV le porta
     * come le scrive il foglio di calcolo, quindi vanno convertite: senza,
     * MySQL le accetta silenziosamente sbagliate invece di rifiutarle.
     */
    private const DATE_FIELDS = [
        'invoice_date_created',
        'invoice_date_due',
        'item_date_added',
        'payment_date',
    ];

    private const DECIMAL_FIELDS = [
        'item_quantity',
        'item_price',
        'payment_amount',
    ];

    private const INT_FIELDS = [
        'client_active',
    ];

    public function validationRulesLegacy(): array
    {
        return [
            'import_date' => 'required',
        ];
    }

    /** @return list<string> */
    public static function allowedFiles(): array
    {
        return array_keys(self::IMPORT_TABLES);
    }

    /** @return list<string> intestazioni attese, nell'ordine del template */
    public static function headersFor(string $file): array
    {
        return self::EXPECTED_HEADERS[$file] ?? [];
    }

    /**
     * Nome del campo di upload per un file dell'import.
     *
     * Il punto non puo' comparirci: PHP sostituisce '.' con '_' nelle chiavi di
     * $_FILES e $_POST, quindi il campo dichiarato "file_clients.csv" arriva al
     * server come "file_clients_csv" e getFile('file_clients.csv') non trovava
     * mai niente. L'upload del form era inerte da sempre — funzionava solo la
     * casella "usa file gia' presente", cioe' solo per chi i CSV li aveva
     * messi nella cartella a mano.
     */
    public static function fieldFor(string $file): string
    {
        return 'file_' . str_replace('.', '_', $file);
    }

    /**
     * Tabella di destinazione per un file CSV ammesso.
     */
    public function tableForFile(string $file): string
    {
        if (!isset(self::IMPORT_TABLES[$file])) {
            throw new RuntimeException('File di importazione non ammesso: ' . $file);
        }

        return self::IMPORT_TABLES[$file];
    }

    /**
     * Importazioni passate dell'azienda attiva, dalla più recente.
     */
    public function getAllOrdered(): array
    {
        return $this->scopeToCompany()
            ->orderBy('import_date', 'DESC')
            ->orderBy('import_id', 'DESC')
            ->findAll();
    }

    /* ------------------------------------------------------ ciclo di vita */

    /**
     * Apre una nuova importazione e ne ritorna l'id: una riga di storico per
     * ogni esecuzione, non per ogni file. È l'unità che l'utente riconosce
     * ("l'import di stamattina") ed è quella che si annulla.
     */
    public function startRun(): int
    {
        return (int) $this->insert([
            'import_date' => date('Y-m-d H:i:s'),
            'import_file' => '',
            'import_rows' => 0,
            'company_id'  => active_company_id(),
        ], true);
    }

    /**
     * Chiude l'importazione scrivendone l'esito. Se non è entrata nessuna riga
     * e non c'è nulla da annullare la voce viene rimossa: uno storico pieno di
     * tentativi a vuoto nasconde quelli veri.
     *
     * @param list<string> $files file effettivamente elaborati
     */
    public function completeRun(int $importId, array $files, int $rows, ?string $error = null): void
    {
        if ($rows === 0 && $error === null) {
            $this->delete($importId);

            return;
        }

        $this->update($importId, [
            'import_file'  => implode(', ', $files),
            'import_rows'  => $rows,
            'import_error' => $error,
        ]);
    }

    /**
     * Annulla un'importazione: cancella le righe che aveva inserito, poi il
     * dettaglio e la voce di storico. Ritorna quante righe sono state rimosse.
     *
     * L'ordine non è casuale — pagamenti e righe prima delle fatture, fatture
     * prima dei clienti — perché cancellare prima la testata lascerebbe righe
     * orfane appese a un invoice_id inesistente.
     *
     * Restano fuori di proposito le aliquote e i metodi di pagamento che
     * l'import può aver creato strada facendo: sono anagrafiche condivise, e
     * un'aliquota nata qui può nel frattempo essere finita su un documento
     * scritto a mano. Cancellarla per annullare un import romperebbe quel
     * documento; una riga in più in una tabella di appoggio no.
     */
    public function rollback(int $importId): int
    {
        $order = ['ip_payments', 'ip_invoice_items', 'ip_invoices', 'ip_clients'];

        $details = $this->db->table('ip_import_details')
            ->where('import_id', $importId)
            ->get()
            ->getResultArray();

        $byTable = [];
        foreach ($details as $detail) {
            $table = (string) $detail['import_table_name'];

            if (!isset(self::PRIMARY_KEYS[$table])) {
                continue;
            }

            $byTable[$table][] = (int) $detail['import_record_id'];
        }

        $removed = 0;
        $touchedInvoices = [];

        $this->db->transStart();

        foreach ($order as $table) {
            if (empty($byTable[$table])) {
                continue;
            }

            $ids = array_values(array_unique($byTable[$table]));

            // Le fatture toccate vanno raccolte prima della cancellazione:
            // dopo, l'invoice_id delle righe/pagamenti non è più risolvibile.
            if ($table === 'ip_invoice_items' || $table === 'ip_payments') {
                $rows = $this->db->table($table)
                    ->select('invoice_id')
                    ->whereIn(self::PRIMARY_KEYS[$table], $ids)
                    ->get()
                    ->getResultArray();

                foreach ($rows as $row) {
                    $touchedInvoices[(int) $row['invoice_id']] = true;
                }
            }

            $this->db->table($table)->whereIn(self::PRIMARY_KEYS[$table], $ids)->delete();
            $removed += $this->db->affectedRows();

            if ($table === 'ip_invoices') {
                // I totali derivati non hanno vincolo di integrità verso la
                // fattura: senza questa riga restavano in ip_invoice_amounts a
                // riferire documenti che non esistono più, e i report li
                // continuavano a sommare.
                $this->db->table('ip_invoice_amounts')->whereIn('invoice_id', $ids)->delete();

                foreach ($ids as $id) {
                    unset($touchedInvoices[$id]);
                }
            }
        }

        $this->db->table('ip_import_details')->where('import_id', $importId)->delete();
        $this->delete($importId);

        $this->db->transComplete();

        // Le fatture sopravvissute a cui sono state tolte righe o pagamenti
        // hanno totali da rifare.
        $amountModel = new InvoiceAmountModel();
        foreach (array_keys($touchedInvoices) as $invoiceId) {
            $amountModel->calculate($invoiceId);
        }

        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        return $removed;
    }

    /* --------------------------------------------------------- importazione */

    /**
     * Importa un CSV nella tabella corrispondente.
     *
     * @return array{inserted: int, skipped: int, warnings: list<string>}
     *
     * @throws RuntimeException se il file manca o le intestazioni non coincidono
     */
    public function importCsv(string $file, string $path, int $importId): array
    {
        $expected = self::EXPECTED_HEADERS[$file] ?? null;

        if ($expected === null) {
            throw new RuntimeException('File di importazione non ammesso: ' . $file);
        }

        $table = $this->tableForFile($file);
        $rows  = CsvReader::rows($path);

        if ($rows === []) {
            throw new RuntimeException('Il file ' . $file . ' è vuoto o illeggibile');
        }

        $map = $this->mapHeaders(array_shift($rows), $expected);

        if ($map === null) {
            throw new RuntimeException(
                'Intestazioni non valide nel file ' . $file
                . '. Attese: ' . implode(', ', $expected)
            );
        }

        $prepared    = [];
        $recordIds   = [];
        $skipped     = 0;
        $warnings    = [];
        $clientCache = [];
        $seenNumbers = [];

        foreach ($rows as $index => $row) {
            $lineNum = $index + 2;
            $dbArray = [];
            $skip    = false;

            foreach ($map as $header => $colIdx) {
                $value = trim((string) ($row[$colIdx] ?? ''));
                $value = (strtolower($value) === 'null') ? '' : $value;

                switch (true) {
                    case $file === 'invoices.csv' && $header === 'user_email':
                        $dbArray['user_id'] = $this->lookupUserId($value);
                        break;

                    case $file === 'invoices.csv' && $header === 'client_name':
                        $dbArray['client_id'] = $this->lookupOrCreateClient($value, $clientCache, $importId);
                        break;

                    case $file === 'invoice_items.csv' && $header === 'item_tax_rate':
                        $dbArray['item_tax_rate_id'] = $this->lookupOrCreateTaxRate($value);
                        break;

                    case $file === 'payments.csv' && $header === 'payment_method':
                        $dbArray['payment_method_id'] = $this->lookupOrCreatePaymentMethod($value);
                        break;

                    case in_array($file, ['invoice_items.csv', 'payments.csv'], true) && $header === 'invoice_number':
                        $invoiceId = $this->lookupInvoiceId($value);

                        if ($invoiceId === null) {
                            $warnings[] = 'riga ' . $lineNum . ': fattura "' . $value . '" non trovata';
                            $skip       = true;
                            break;
                        }

                        $dbArray['invoice_id'] = $invoiceId;
                        break;

                    default:
                        $normalized = $this->normalizeValue($header, $value, $lineNum, $warnings);

                        // null significa "questa cella non ha un valore
                        // utilizzabile": la chiave si omette del tutto invece
                        // di scrivere NULL, cosi' vale il default della
                        // colonna. Su client_active — NOT NULL con default 1 —
                        // un NULL esplicito viola il vincolo e fa fallire
                        // l'intero file per una cella lasciata in bianco.
                        if ($normalized !== null) {
                            $dbArray[$header] = $normalized;
                        }
                        break;
                }

                if ($skip) {
                    break;
                }
            }

            if ($skip) {
                $skipped++;
                continue;
            }

            // Una testata di fattura è identificata dal numero: reimportare lo
            // stesso file creava un secondo documento con lo stesso numero, che
            // per l'Agenzia delle Entrate è un errore e a valle rendeva
            // ambiguo il collegamento di righe e pagamenti.
            if ($file === 'invoices.csv') {
                $number = trim((string) ($dbArray['invoice_number'] ?? ''));

                if ($number === '') {
                    $warnings[] = 'riga ' . $lineNum . ': numero fattura mancante';
                    $skipped++;
                    continue;
                }

                if (isset($seenNumbers[$number]) || $this->lookupInvoiceId($number) !== null) {
                    $warnings[] = 'riga ' . $lineNum . ': fattura "' . $number . '" già presente';
                    $skipped++;
                    continue;
                }

                $seenNumbers[$number] = true;
            }

            if ($file === 'clients.csv' && trim((string) ($dbArray['client_name'] ?? '')) === '') {
                $skipped++;
                continue;
            }

            $prepared[] = $this->withDefaults($file, $dbArray);
        }

        if ($prepared === []) {
            return ['inserted' => 0, 'skipped' => $skipped, 'warnings' => $warnings];
        }

        $primaryKey = self::PRIMARY_KEYS[$table];

        $this->db->transStart();

        // Inserimento riga per riga invece di insertBatch: serve l'id di ogni
        // record per poter annullare l'importazione. Il costo è accettabile —
        // un import è un'operazione rara — e in cambio la transazione rende
        // l'operazione tutto-o-niente.
        foreach ($prepared as $row) {
            $this->db->table($table)->insert($row);
            $recordIds[] = (int) $this->db->insertID();
        }

        $this->recordDetails($importId, $table, $recordIds, $primaryKey);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new RuntimeException(
                'scrittura non riuscita, nessuna riga importata'
                . ' (dettaglio in writable/logs)'
            );
        }

        // ip_invoice_amounts/ip_item_amounts sono derivati e non si aggiornano
        // da soli: queste righe non passano da InvoicesAjax/PaymentModel::saveRecord(),
        // che sono i punti che normalmente richiamano calculate(). Senza,
        // le fatture importate mostravano saldo e totale a zero ovunque si
        // legge ip_invoice_amounts (vista cliente, dashboard, scadenzario,
        // report Finance), finché qualcuno non le riapriva e risalvava a mano.
        $this->recalculateAffectedInvoices($file, $prepared, $recordIds);

        return [
            'inserted' => count($recordIds),
            'skipped'  => $skipped,
            'warnings' => $warnings,
        ];
    }

    /* --------------------------------------------------------------- CSV */

    /**
     * Posizione di ogni intestazione attesa dentro la riga di testata.
     *
     * Il confronto è normalizzato (maiuscole, spazi e underscore non contano),
     * perché un foglio salvato a mano scrive "Client Name" dove il tracciato
     * dice "client_name" e prima l'import si limitava a rifiutare il file.
     * Ritorna null se manca anche solo una colonna attesa.
     *
     * @param list<string> $expected
     *
     * @return array<string, int>|null
     */
    private function mapHeaders(array $headerRow, array $expected): ?array
    {
        $positions = [];

        foreach ($headerRow as $colIdx => $header) {
            $key = CsvReader::normalizeHeader((string) $header);

            if ($key !== '' && !isset($positions[$key])) {
                $positions[$key] = $colIdx;
            }
        }

        $map = [];

        foreach ($expected as $header) {
            $key = CsvReader::normalizeHeader($header);

            if (!isset($positions[$key])) {
                return null;
            }

            $map[$header] = $positions[$key];
        }

        return $map;
    }

    /**
     * Template CSV con le intestazioni attese e una riga di esempio.
     *
     * Senza, l'unico modo di scoprire il tracciato era leggere il codice: le
     * esportazioni usano intestazioni in italiano ("Nome / Ragione Sociale"),
     * mentre l'import vuole i nomi delle colonne, quindi il file esportato non
     * è un modello valido per rientrare.
     */
    public function templateFor(string $file): string
    {
        $headers = self::EXPECTED_HEADERS[$file] ?? null;

        if ($headers === null) {
            throw new RuntimeException('File di importazione non ammesso: ' . $file);
        }

        $samples = [
            'clients.csv' => [
                'Acme S.r.l.', 'Via Roma 10', '', 'Milano', 'MI', '20121', 'IT',
                '021234567', '', '3401234567', 'amministrazione@acme.it', 'www.acme.it',
                'IT12345678901', '12345678901', '1',
            ],
            'invoices.csv' => [
                'admin@example.com', 'Acme S.r.l.', '01/01/2026', '31/01/2026', '2026/0001', 'Pagamento a 30 giorni',
            ],
            'invoice_items.csv' => [
                '2026/0001', '22', '01/01/2026', 'Consulenza', 'Analisi e sviluppo', '10', '80,00',
            ],
            'payments.csv' => [
                '2026/0001', 'Bonifico', '15/01/2026', '976,00', 'Saldo fattura',
            ],
        ];

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, CsvReader::DELIMITER, '"', '\\');
        fputcsv($handle, $samples[$file], CsvReader::DELIMITER, '"', '\\');
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /* ----------------------------------------------------------- supporto */

    /**
     * Converte il valore nel formato che la colonna si aspetta e segnala
     * quando il dato è illeggibile invece di scrivere silenziosamente un
     * valore sbagliato.
     *
     * Le colonne non testuali vuote tornano null, non stringa vuota: sono
     * tutte nullable o con default, e PostgreSQL — a differenza di MySQL —
     * rifiuta '' su una DATE, una DECIMAL o una INT invece di convertirlo per
     * cortesia. Con una cella "importo" lasciata in bianco l'intero file
     * sarebbe morto sull'insert.
     *
     * Ritorna null quando non c'e' un valore utilizzabile: il chiamante omette
     * la chiave, cosi' vale il default della colonna.
     *
     * @param list<string> $warnings
     */
    private function normalizeValue(string $header, string $value, int $lineNum, array &$warnings): ?string
    {
        $isDate    = in_array($header, self::DATE_FIELDS, true);
        $isDecimal = in_array($header, self::DECIMAL_FIELDS, true);
        $isInt     = in_array($header, self::INT_FIELDS, true);

        if ($value === '') {
            return ($isDate || $isDecimal || $isInt) ? null : '';
        }

        if ($isDate) {
            $date = CsvReader::toDate($value);

            if ($date === null) {
                $warnings[] = 'riga ' . $lineNum . ': data "' . $value . '" non riconosciuta in ' . $header;
            }

            return $date;
        }

        if ($isDecimal) {
            $number = CsvReader::toDecimal($value);

            if ($number === null) {
                $warnings[] = 'riga ' . $lineNum . ': importo "' . $value . '" non riconosciuto in ' . $header;

                return '0';
            }

            return $number;
        }

        if ($isInt) {
            return is_numeric($value) ? (string) (int) $value : null;
        }

        return $value;
    }

    /**
     * Annota le righe inserite, così l'importazione resta annullabile.
     *
     * @param list<int> $recordIds
     */
    private function recordDetails(int $importId, string $table, array $recordIds, string $primaryKey): void
    {
        if ($importId <= 0 || $recordIds === []) {
            return;
        }

        $details = [];

        foreach ($recordIds as $recordId) {
            $details[] = [
                'import_id'         => $importId,
                'import_lang_key'   => $primaryKey,
                'import_table_name' => $table,
                'import_record_id'  => $recordId,
            ];
        }

        $this->db->table('ip_import_details')->insertBatch($details);
    }

    /**
     * Ricalcola i totali derivati delle fatture toccate da un import.
     *
     * La catena ha due anelli e prima ne veniva percorso solo il secondo:
     * ip_invoice_amounts somma ip_invoice_item_amounts, che a sua volta e'
     * derivata e la scrive ItemAmountModel::calculate() riga per riga. Senza il
     * primo anello le righe importate esistevano ma non avevano importi, quindi
     * la fattura restava a imponibile e totale zero — con il pagamento
     * regolarmente registrato, cioe' con un saldo negativo — ovunque si legge
     * ip_invoice_amounts: vista cliente, bacheca, scadenzario, report Finance.
     *
     * @param list<array<string, mixed>> $rows      righe appena inserite
     * @param list<int>                  $recordIds id delle righe appena inserite
     */
    private function recalculateAffectedInvoices(string $file, array $rows, array $recordIds = []): void
    {
        if ($file === 'invoice_items.csv' && $recordIds !== []) {
            $itemAmountModel = new ItemAmountModel();

            foreach ($recordIds as $itemId) {
                $itemAmountModel->calculate($itemId);
            }
        }

        $invoiceIds = [];

        if (in_array($file, ['invoice_items.csv', 'payments.csv'], true)) {
            foreach ($rows as $row) {
                if (!empty($row['invoice_id'])) {
                    $invoiceIds[(int) $row['invoice_id']] = true;
                }
            }
        } elseif ($file === 'invoices.csv') {
            $numbers = array_values(array_filter(array_column($rows, 'invoice_number')));

            if ($numbers !== []) {
                $found = scope_builder_to_company($this->db->table('ip_invoices'), 'ip_invoices')
                    ->whereIn('invoice_number', $numbers)
                    ->get()
                    ->getResultArray();

                foreach ($found as $invoice) {
                    $invoiceIds[(int) $invoice['invoice_id']] = true;
                }
            }
        }

        if ($invoiceIds === []) {
            return;
        }

        $amountModel = new InvoiceAmountModel();

        foreach (array_keys($invoiceIds) as $invoiceId) {
            $amountModel->calculate($invoiceId);
        }

        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }
    }

    /**
     * Valori di default per le colonne NOT NULL senza default.
     *
     * company_id va scritto qui perché queste righe entrano su un builder
     * grezzo, non da saveRecord(): senza, nascevano con company_id NULL, e per
     * la regola dell'archivio pre-multi-azienda una riga NULL è visibile a
     * TUTTE le aziende. Un import fatto dall'azienda A finiva così
     * nell'anagrafica e nei report anche dell'azienda B.
     */
    private function withDefaults(string $file, array $dbArray): array
    {
        switch ($file) {
            case 'clients.csv':
                $dbArray['client_date_created']  = date('Y-m-d');
                $dbArray['client_date_modified'] = date('Y-m-d');
                $dbArray['company_id']           = active_company_id();
                break;

            case 'invoices.csv':
                $dbArray['invoice_url_key']       = generate_key(32);
                $dbArray['invoice_group_id']      = 0;
                $dbArray['invoice_date_modified'] = date('Y-m-d H:i:s');
                $dbArray['company_id']            = active_company_id();
                break;
        }

        return $dbArray;
    }

    private function lookupUserId(string $email): int
    {
        if ($email === '') {
            return 0;
        }

        $row = $this->db->table('ip_users')->where('user_email', $email)->get()->getRowArray();

        return $row ? (int) $row['user_id'] : 0;
    }

    /**
     * Cliente per nome: se esiste ne riusa l'id, altrimenti lo crea.
     * La cache interna evita duplicati tra righe dello stesso file.
     */
    private function lookupOrCreateClient(string $name, array &$cache, int $importId): int
    {
        $name = trim($name);

        if ($name === '') {
            return 0;
        }

        if (isset($cache[$name])) {
            return $cache[$name];
        }

        // Cliente cercato e creato dentro l'azienda attiva: un omonimo di
        // un'altra azienda non è lo stesso cliente.
        $row = scope_builder_to_company($this->db->table('ip_clients'), 'ip_clients')
            ->where('client_name', $name)->get()->getRowArray();

        if ($row) {
            return $cache[$name] = (int) $row['client_id'];
        }

        $this->db->table('ip_clients')->insert([
            'client_name'          => $name,
            'client_date_created'  => date('Y-m-d'),
            'client_date_modified' => date('Y-m-d'),
            'company_id'           => active_company_id(),
        ]);

        $clientId = (int) $this->db->insertID();

        // Anche i clienti nati di rimbalzo da invoices.csv appartengono
        // all'importazione: se non fossero annotati, annullarla lascerebbe
        // in anagrafica dei clienti che non c'erano prima.
        $this->recordDetails($importId, 'ip_clients', [$clientId], 'client_id');

        return $cache[$name] = $clientId;
    }

    private function lookupInvoiceId(string $number): ?int
    {
        if (trim($number) === '') {
            return null;
        }

        $row = scope_builder_to_company($this->db->table('ip_invoices'), 'ip_invoices')
            ->where('invoice_number', $number)->get()->getRowArray();

        return $row ? (int) $row['invoice_id'] : null;
    }

    /**
     * Aliquota per percentuale: se esiste ne riusa l'id, altrimenti la crea;
     * con valore vuoto o zero ritorna 0 (nessuna aliquota).
     */
    private function lookupOrCreateTaxRate($percent): int
    {
        $percent = CsvReader::toDecimal((string) $percent);

        if ($percent === null || (float) $percent <= 0) {
            return 0;
        }

        $row = $this->db->table('ip_tax_rates')->where('tax_rate_percent', $percent)->get()->getRowArray();

        if ($row) {
            return (int) $row['tax_rate_id'];
        }

        $this->db->table('ip_tax_rates')->insert([
            'tax_rate_name'    => rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.') . '%',
            'tax_rate_percent' => $percent,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Metodo di pagamento per nome: se esiste ne riusa l'id, altrimenti lo
     * crea; con valore vuoto ritorna 0 (nessun metodo).
     */
    private function lookupOrCreatePaymentMethod($name): int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 0;
        }

        $row = $this->db->table('ip_payment_methods')->where('payment_method_name', $name)->get()->getRowArray();

        if ($row) {
            return (int) $row['payment_method_id'];
        }

        $this->db->table('ip_payment_methods')->insert([
            'payment_method_name' => $name,
        ]);

        return (int) $this->db->insertID();
    }
}
