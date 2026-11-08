<?php

namespace App\Services;

use App\Libraries\CsvReader;
use App\Libraries\CsvWriter;
use App\Models\ClientModel;
use App\Models\SupplierModel;

/**
 * ContactImporter — Importazione e template CSV per Clienti e Fornitori.
 *
 * Supporta:
 * - Separatori automatici (punto e virgola, virgola, tabulatore)
 * - Riconoscimento intelligente delle intestazioni (italiano/inglese e nomi colonne DB)
 * - Gestione codifica UTF-8 con e senza BOM
 * - Controllo e aggiornamento o inserimento dei duplicati (P. IVA / Denominazione)
 * - Assegnazione automatica all'azienda attiva (multi-company)
 */
class ContactImporter
{
    private const CSV_DELIMITER = CsvReader::DELIMITER;

    /**
     * Importa clienti da file CSV.
     *
     * @return array{imported: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importClients(string $filePath, bool $updateExisting = true): array
    {
        $rows = $this->parseCsv($filePath);
        if (empty($rows)) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['File CSV vuoto o non valido']];
        }

        $headers = array_shift($rows);
        $map     = $this->mapClientHeaders($headers);

        if (!isset($map['client_name'])) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Colonna Nome / Ragione Sociale mancante nel file CSV']];
        }

        $clientModel = new ClientModel();
        $companyId   = active_company_id();
        $imported    = 0;
        $updated     = 0;
        $skipped     = 0;
        $errors      = [];

        $db = \Config\Database::connect();

        foreach ($rows as $index => $row) {
            $lineNum = $index + 2;
            $data    = $this->extractRowData($row, $map);

            $name = trim($data['client_name'] ?? '');
            if ($name === '') {
                $skipped++;
                continue;
            }

            $vat     = trim($data['client_vat_id'] ?? '');
            $taxCode = trim($data['client_tax_code'] ?? '');
            $surname = trim($data['client_surname'] ?? '');

            // Ricerca duplicato per P. IVA o Nome+Cognome nell'azienda
            $existing = null;
            if ($vat !== '') {
                $existing = $db->table('ip_clients')
                    ->where('client_vat_id', $vat)
                    ->where('company_id', $companyId)
                    ->get()
                    ->getRowArray();
            }

            if (!$existing && $taxCode !== '') {
                $existing = $db->table('ip_clients')
                    ->where('client_tax_code', $taxCode)
                    ->where('company_id', $companyId)
                    ->get()
                    ->getRowArray();
            }

            if (!$existing) {
                $existing = $clientModel->findDuplicate($name, $surname);
            }

            $now = date('Y-m-d H:i:s');
            $data['client_active']        = isset($data['client_active']) ? (int) $data['client_active'] : 1;
            $data['company_id']           = $companyId;
            $data['client_date_modified'] = $now;

            if ($existing) {
                if ($updateExisting) {
                    $clientId = (int) $existing['client_id'];
                    $db->table('ip_clients')->where('client_id', $clientId)->update($data);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $data['client_date_created'] = $now;

                // Niente client_url_key: quella colonna non esiste in
                // ip_clients (il link pubblico e' una cosa dei documenti, non
                // dell'anagrafica). Scriverla faceva fallire l'INSERT di ogni
                // cliente nuovo, quindi l'import dall'anagrafica poteva solo
                // aggiornare quelli gia' presenti.
                $db->table('ip_clients')->insert($data);
                $clientId = (int) $db->insertID();

                if ($clientId) {
                    $clientModel->assignToAllUsers($clientId);
                    $imported++;
                } else {
                    $errors[] = "Riga {$lineNum}: Errore durante l'inserimento di '{$name}'";
                }
            }
        }

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * Importa fornitori da file CSV.
     *
     * @return array{imported: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importSuppliers(string $filePath, bool $updateExisting = true): array
    {
        $rows = $this->parseCsv($filePath);
        if (empty($rows)) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['File CSV vuoto o non valido']];
        }

        $headers = array_shift($rows);
        $map     = $this->mapSupplierHeaders($headers);

        if (!isset($map['supplier_name'])) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Colonna Nome / Ragione Sociale mancante nel file CSV']];
        }

        $supplierModel = new SupplierModel();
        $companyId     = active_company_id();
        $imported      = 0;
        $updated       = 0;
        $skipped       = 0;
        $errors        = [];

        $db = \Config\Database::connect();

        foreach ($rows as $index => $row) {
            $lineNum = $index + 2;
            $data    = $this->extractRowData($row, $map);

            $name = trim($data['supplier_name'] ?? '');
            if ($name === '') {
                $skipped++;
                continue;
            }

            $vat     = trim($data['supplier_vat_id'] ?? '');
            $taxCode = trim($data['supplier_tax_code'] ?? '');

            // Ricerca fornitore esistente
            $existing = null;
            if ($vat !== '') {
                $existing = $supplierModel->findExisting($vat, null);
            }

            if (!$existing && $taxCode !== '') {
                $existing = $db->table('ip_suppliers')
                    ->where('supplier_tax_code', $taxCode)
                    ->where('company_id', $companyId)
                    ->get()
                    ->getRowArray();
            }

            if (!$existing) {
                $existing = $supplierModel->findExisting(null, $name);
            }

            $now = date('Y-m-d H:i:s');
            $data['supplier_active']        = isset($data['supplier_active']) ? db_bool($data['supplier_active']) : true;
            $data['company_id']             = $companyId;
            $data['supplier_date_modified'] = $now;

            if ($existing) {
                if ($updateExisting) {
                    $supplierId = (int) $existing['supplier_id'];
                    $db->table('ip_suppliers')->where('supplier_id', $supplierId)->update($data);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $data['supplier_date_created'] = $now;
                $db->table('ip_suppliers')->insert($data);
                $supplierId = (int) $db->insertID();

                if ($supplierId) {
                    $imported++;
                } else {
                    $errors[] = "Riga {$lineNum}: Errore durante l'inserimento del fornitore '{$name}'";
                }
            }
        }

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * Genera template CSV di esempio per Clienti.
     */
    public function generateClientTemplate(): string
    {
        $export = new ContactExport();

        return CsvWriter::toString($export->clientHeaders(), [$export->clientSample()]);
    }

    /**
     * Genera template CSV di esempio per Fornitori.
     */
    public function generateSupplierTemplate(): string
    {
        $export = new ContactExport();

        return CsvWriter::toString($export->supplierHeaders(), [$export->supplierSample()]);
    }

    /**
     * Righe del CSV. La lettura vera sta in CsvReader, condivisa con
     * l'importazione storica (ImportModel): finche' ognuna aveva la sua, le
     * due strade leggevano lo stesso file in modo diverso — questa riconosceva
     * delimitatore, BOM e codifica, l'altra no.
     */
    private function parseCsv(string $filePath): array
    {
        return CsvReader::rows($filePath);
    }

    private function normalizeString(string $str): string
    {
        return CsvReader::normalizeHeader($str);
    }

    private function mapClientHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $colIdx => $header) {
            $n = $this->normalizeString($header);

            if (in_array($n, ['clientname', 'nome', 'client', 'nomeragionesociale', 'ragionesociale', 'denominazione', 'cliente', 'azienda', 'company', 'name', 'intestazione'], true)) {
                $map['client_name'] = $colIdx;
            } elseif (in_array($n, ['clientsurname', 'cognome', 'surname', 'lastname'], true)) {
                $map['client_surname'] = $colIdx;
            } elseif (in_array($n, ['clientvatid', 'vatid', 'piva', 'partitaiva', 'vat'], true)) {
                $map['client_vat_id'] = $colIdx;
            } elseif (in_array($n, ['clienttaxcode', 'taxcode', 'codicefiscale', 'cf', 'fiscalcode'], true)) {
                $map['client_tax_code'] = $colIdx;
            } elseif (in_array($n, ['clientaddress1', 'indirizzo', 'address', 'address1', 'via'], true)) {
                $map['client_address_1'] = $colIdx;
            } elseif (in_array($n, ['clientaddress2', 'indirizzo2', 'address2', 'civico'], true)) {
                $map['client_address_2'] = $colIdx;
            } elseif (in_array($n, ['clientcity', 'citta', 'città', 'city', 'comune'], true)) {
                $map['client_city'] = $colIdx;
            } elseif (in_array($n, ['clientzip', 'cap', 'zip', 'postalcode'], true)) {
                $map['client_zip'] = $colIdx;
            } elseif (in_array($n, ['clientstate', 'provincia', 'prov', 'state'], true)) {
                $map['client_state'] = $colIdx;
            } elseif (in_array($n, ['clientcountry', 'paese', 'nazione', 'country'], true)) {
                $map['client_country'] = $colIdx;
            } elseif (in_array($n, ['clientemail', 'email', 'mail', 'e-mail'], true)) {
                $map['client_email'] = $colIdx;
            } elseif (in_array($n, ['clientphone', 'telefono', 'tel', 'phone'], true)) {
                $map['client_phone'] = $colIdx;
            } elseif (in_array($n, ['clientmobile', 'cellulare', 'cell', 'mobile'], true)) {
                $map['client_mobile'] = $colIdx;
            } elseif (in_array($n, ['clientiban', 'iban', 'conto'], true)) {
                $map['client_iban'] = $colIdx;
            } elseif (in_array($n, ['clientsdicode', 'sdicode', 'sdi', 'codicesdi', 'codicedestinatario'], true)) {
                $map['client_sdi_code'] = $colIdx;
            } elseif (in_array($n, ['clientsdipec', 'sdipec', 'pec', 'postaelettronicacertificata'], true)) {
                $map['client_sdi_pec'] = $colIdx;
            } elseif (in_array($n, ['clientactive', 'attivo', 'active'], true)) {
                $map['client_active'] = $colIdx;
            }
        }

        return $map;
    }

    private function mapSupplierHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $colIdx => $header) {
            $n = $this->normalizeString($header);

            if (in_array($n, ['suppliername', 'fornitore', 'supplier', 'fornitoreragionesociale', 'ragionesociale', 'denominazione', 'azienda', 'company', 'name', 'intestazione'], true)) {
                $map['supplier_name'] = $colIdx;
            } elseif (in_array($n, ['suppliervatid', 'vatid', 'piva', 'partitaiva', 'vat'], true)) {
                $map['supplier_vat_id'] = $colIdx;
            } elseif (in_array($n, ['suppliertaxcode', 'taxcode', 'codicefiscale', 'cf', 'fiscalcode'], true)) {
                $map['supplier_tax_code'] = $colIdx;
            } elseif (in_array($n, ['supplieraddress1', 'indirizzo', 'address', 'address1', 'via'], true)) {
                $map['supplier_address_1'] = $colIdx;
            } elseif (in_array($n, ['supplieraddress2', 'indirizzo2', 'address2', 'civico'], true)) {
                $map['supplier_address_2'] = $colIdx;
            } elseif (in_array($n, ['suppliercity', 'citta', 'città', 'city', 'comune'], true)) {
                $map['supplier_city'] = $colIdx;
            } elseif (in_array($n, ['supplierzip', 'cap', 'zip', 'postalcode'], true)) {
                $map['supplier_zip'] = $colIdx;
            } elseif (in_array($n, ['supplierstate', 'provincia', 'prov', 'state'], true)) {
                $map['supplier_state'] = $colIdx;
            } elseif (in_array($n, ['suppliercountry', 'paese', 'nazione', 'country'], true)) {
                $map['supplier_country'] = $colIdx;
            } elseif (in_array($n, ['supplieremail', 'email', 'mail', 'e-mail'], true)) {
                $map['supplier_email'] = $colIdx;
            } elseif (in_array($n, ['supplierphone', 'telefono', 'tel', 'phone'], true)) {
                $map['supplier_phone'] = $colIdx;
            } elseif (in_array($n, ['supplieriban', 'iban', 'conto'], true)) {
                $map['supplier_iban'] = $colIdx;
            } elseif (in_array($n, ['suppliersdicode', 'sdicode', 'sdi', 'codicesdi', 'codicedestinatario'], true)) {
                $map['supplier_sdi_code'] = $colIdx;
            } elseif (in_array($n, ['suppliersdipec', 'sdipec', 'pec', 'postaelettronicacertificata'], true)) {
                $map['supplier_sdi_pec'] = $colIdx;
            } elseif (in_array($n, ['supplieractive', 'attivo', 'active'], true)) {
                $map['supplier_active'] = $colIdx;
            }
        }

        return $map;
    }

    private function extractRowData(array $row, array $map): array
    {
        $data = [];
        foreach ($map as $field => $colIdx) {
            $data[$field] = $row[$colIdx] ?? '';
        }

        return $data;
    }
}
