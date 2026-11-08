<?php

namespace App\Libraries;

use App\Models\ClientModel;
use App\Models\InvoiceAmountModel;
use App\Models\InvoiceGroupModel;
use App\Models\InvoiceModel;
use App\Models\ItemModel;
use App\Models\PaymentMethodModel;
use App\Models\TaxRateModel;

/**
 * Importa fatture EMESSE (XML FatturaPA) come fatture del gestionale.
 *
 * È il caso di chi arriva da un altro programma: un anno di fatture già
 * trasmesse allo SdI va portato qui perché dashboard, scadenzario, report e
 * "Utile e imposte" partano dai numeri veri. PurchaseInvoiceImporter fa lo
 * stesso per il ciclo passivo, e rifiuta di proposito questi documenti
 * (sono ricavi, non spese): finora non c'era un modo di importarli affatto.
 *
 * Ogni documento diventa una fattura nello stato "Inviata", con il numero e
 * la data originali, il cliente (cercato per partita IVA o codice fiscale
 * nell'azienda attiva, altrimenti creato), le righe con i loro sconti e le
 * aliquote, i riferimenti di commessa (ordine, contratto, CIG/CUP),
 * esigibilità, ritenuta, cassa, bollo e metodo di pagamento. I totali non
 * si copiano: si ricalcolano con InvoiceAmountModel come per qualunque
 * fattura, e se non tornano con quelli del tracciato il documento è
 * segnalato fra gli avvisi — un modo per accorgersi subito di un caso che
 * il modello non rappresenta, invece di scoprirlo in dichiarazione.
 *
 * Le fatture importate nascono di sola lettura e con una trasmissione SdI
 * registrata sul canale "import": sono già state inviate da un altro
 * programma, e SdiSender non deve proporne un secondo invio.
 */
class SalesInvoiceImporter
{
    /** Tolleranza sul confronto fra totale ricalcolato e totale del tracciato. */
    private const TOTAL_TOLERANCE = 0.01;

    private FatturaXml $reader;

    /** @var array<string, int> cache aliquota "22.00|" / "0.00|N2.2" → tax_rate_id */
    private array $taxRates = [];

    public function __construct()
    {
        $this->reader = new FatturaXml();
    }

    /**
     * Importa i documenti di un file (.xml, .p7m, ZIP, lotti compresi).
     *
     * @return array{imported: int, skipped: int, created_clients: int, failed: list<string>, rejected: list<string>, warnings: list<string>}
     */
    public function importFromPath(string $path, string $originalName): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'created_clients' => 0, 'failed' => [], 'rejected' => [], 'warnings' => []];

        $documents = $this->reader->extract($path, $originalName);

        if ($documents === []) {
            $result['failed'][] = $originalName . ': formato non riconosciuto (atteso XML, .p7m o ZIP)';

            return $result;
        }

        $maxNumber = 0;

        foreach ($documents as $document) {
            $bodies = $this->reader->splitBodies($document['xml']);

            foreach ($bodies as $i => $bodyXml) {
                $name = count($bodies) > 1
                    ? $document['name'] . ' (fattura ' . ($i + 1) . ' di ' . count($bodies) . ')'
                    : $document['name'];

                $outcome = $this->importDocument($bodyXml);

                if ($outcome === null) {
                    $result['failed'][] = $name . ': XML non leggibile o non è una fattura FatturaPA valida';
                    continue;
                }

                foreach (['error' => 'failed', 'rejected' => 'rejected'] as $key => $bucket) {
                    if (!empty($outcome[$key])) {
                        $result[$bucket][] = $name . ': ' . $outcome[$key];
                        continue 2;
                    }
                }

                if ($outcome['skipped']) {
                    $result['skipped']++;
                    continue;
                }

                $result['imported']++;
                $result['created_clients'] += $outcome['created_client'] ? 1 : 0;

                if (!empty($outcome['warning'])) {
                    $result['warnings'][] = $name . ': ' . $outcome['warning'];
                }

                if (ctype_digit($outcome['number'])) {
                    $maxNumber = max($maxNumber, (int) $outcome['number']);
                }
            }
        }

        $this->advanceNumbering($maxNumber);

        return $result;
    }

    /**
     * Importa un singolo documento (un body solo).
     *
     * @return array{skipped: bool, created_client: bool, invoice_id: int, number: string, warning?: string, rejected?: string, error?: string}|null
     */
    public function importDocument(string $xml): ?array
    {
        $doc = $this->reader->parseIssued($xml);

        if ($doc === null) {
            return null;
        }

        $none = ['skipped' => false, 'created_client' => false, 'invoice_id' => 0, 'number' => $doc['number']];

        $reason = $this->rejectionReason($doc);
        if ($reason !== null) {
            return $none + ['rejected' => $reason];
        }

        $invoiceModel = new InvoiceModel();

        // Stesso numero nello stesso anno, nell'azienda attiva: è la stessa
        // fattura (la numerazione riparte ogni anno). Reimportare lo stesso
        // ZIP non deve raddoppiare il fatturato.
        $existing = $invoiceModel->scopeToCompany()
            ->where('invoice_number', $doc['number'])
            ->where('EXTRACT(YEAR FROM invoice_date_created)', (int) substr($doc['date'], 0, 4))
            ->first();

        if ($existing) {
            return ['skipped' => true, 'created_client' => false, 'invoice_id' => (int) $existing['invoice_id'], 'number' => $doc['number']];
        }

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            [$clientId, $createdClient] = $this->client($doc);
            $invoiceId = $this->createInvoice($doc, $clientId);
            $this->createItems($doc, $invoiceId);

            (new InvoiceAmountModel())->calculate($invoiceId);

            // Già trasmessa da un altro programma: lo si registra con
            // l'esito IMP ("Trasmessa da altro gestionale"), che in lista
            // non la fa apparire "Non trasmessa" e blocca un secondo invio
            // da qui (Invoices::sendSdi). L'esito vero, se serve, si imposta
            // a mano dalla scheda.
            $db->table('ip_sdi_transmissions')->insert([
                'invoice_id' => $invoiceId,
                'filename'   => mb_substr('import-' . $doc['number'] . '-' . $doc['date'], 0, 64),
                'channel'    => 'import',
                'esito'      => 'IMP',
                'messaggio'  => 'Importata da XML: già trasmessa allo SdI con un altro gestionale',
                'sent_at'    => $doc['date'] . ' 00:00:00',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Import fattura emessa ' . $doc['number'] . ': ' . $e->getMessage()
                . ' (' . $e->getFile() . ':' . $e->getLine() . ')');

            return $none + ['error' => 'errore in salvataggio (' . $e->getMessage() . ')'];
        }

        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        $outcome = ['skipped' => false, 'created_client' => $createdClient, 'invoice_id' => $invoiceId, 'number' => $doc['number']];

        // Confronto con il tracciato. Lo split payment e la ritenuta non
        // cambiano il totale documento, quindi si confronta il totale lordo.
        $amounts = $db->table('ip_invoice_amounts')->where('invoice_id', $invoiceId)->get()->getRowArray();
        $sign    = $this->isCreditNote($doc) ? -1 : 1;
        $total   = (float) ($amounts['invoice_total'] ?? 0) * $sign;

        if (abs($total - $doc['total']) > self::TOTAL_TOLERANCE) {
            $outcome['warning'] = 'totale ricalcolato ' . number_format($total, 2, ',', '.')
                . ' diverso da quello del file ' . number_format($doc['total'], 2, ',', '.') . ': controllare le righe';
        }

        return $outcome;
    }

    /**
     * Perché un documento leggibile non va importato fra le fatture
     * dell'azienda attiva, o null se va bene. Specchio di
     * PurchaseInvoiceImporter::rejectionReason(): qui il cedente deve essere
     * l'azienda attiva.
     */
    private function rejectionReason(array $doc): ?string
    {
        $company = function_exists('active_company') ? active_company() : [];
        $own     = array_filter([
            PurchaseInvoiceImporter::normalizeId((string) ($company['company_vat_id'] ?? '')),
            PurchaseInvoiceImporter::normalizeId((string) ($company['company_tax_code'] ?? '')),
        ]);

        if ($own === []) {
            return 'impossibile verificare l\'emittente: inserire partita IVA o codice fiscale dell\'azienda attiva in Impostazioni > Aziende';
        }

        $supplierIds = array_filter([
            PurchaseInvoiceImporter::normalizeId($doc['supplier']['vat']),
            PurchaseInvoiceImporter::normalizeId($doc['supplier']['tax_code']),
        ]);

        if (array_intersect($supplierIds, $own) === []) {
            $customerIds = array_filter([
                PurchaseInvoiceImporter::normalizeId($doc['customer']['vat']),
                PurchaseInvoiceImporter::normalizeId($doc['customer']['tax_code']),
            ]);

            return array_intersect($customerIds, $own) !== []
                ? 'fattura ricevuta (ciclo passivo): va importata da Costi > Importa XML'
                : 'emessa da ' . ($doc['supplier']['vat'] ?: $doc['supplier']['tax_code'] ?: 'cedente non indicato') . ', non dall\'azienda attiva';
        }

        if ($doc['number'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $doc['date'])) {
            return 'numero o data del documento mancanti';
        }

        // Autofatture e integrazioni (TD16–TD19, TD28, TD20…) hanno il
        // proprio cedente come emittente ma non sono ricavi.
        if (!in_array($doc['document_type'], ['TD01', 'TD02', 'TD04', 'TD05', 'TD06', 'TD24', 'TD25', 'TD26'], true)) {
            return 'tipo documento ' . $doc['document_type'] . ' (autofattura/integrazione): non è un ricavo';
        }

        return null;
    }

    private function isCreditNote(array $doc): bool
    {
        return in_array($doc['document_type'], FatturaXml::CREDIT_NOTE_TYPES, true);
    }

    /**
     * Cliente dell'azienda attiva per partita IVA o codice fiscale, creato
     * se manca.
     *
     * @return array{0: int, 1: bool} id e "appena creato"
     */
    private function client(array $doc): array
    {
        $c     = $doc['customer'];
        $model = new ClientModel();

        foreach (['client_vat_id' => $c['vat'], 'client_tax_code' => $c['tax_code']] as $column => $value) {
            $value = PurchaseInvoiceImporter::normalizeId($value);

            if ($value === '') {
                continue;
            }

            // Le anagrafiche esistenti hanno la partita IVA con o senza
            // prefisso IT a seconda di chi l'ha inserita.
            $row = (new ClientModel())->scopeToCompany()
                ->groupStart()->where($column, $value)->orWhere($column, 'IT' . $value)->groupEnd()
                ->first();

            if ($row) {
                return [(int) $row['client_id'], false];
            }
        }

        $isPerson = $c['name'] === '' && $c['last'] !== '';
        // FPA12, o un codice destinatario a 6 caratteri (codice ufficio IPA):
        // è una pubblica amministrazione, e il tipo cliente decide formato
        // XML e split payment delle fatture future.
        $isPa = $doc['format'] === 'FPA12' || strlen($doc['sdi_code']) === 6;

        $sdiCode = in_array($doc['sdi_code'], ['', '0000000', 'XXXXXXX'], true) ? null : $doc['sdi_code'];

        $clientId = (int) $model->saveRecord(null, [
            'client_name'          => $isPerson ? $c['first'] : $c['name'],
            'client_surname'       => $isPerson ? $c['last'] : null,
            'client_type'          => $isPa ? 'pa' : ($isPerson ? 'person' : 'company'),
            'client_address_1'     => $c['address'],
            'client_zip'           => $c['zip'],
            'client_city'          => $c['city'],
            'client_state'         => $c['state'],
            'client_country'       => $c['nation'] ?: 'IT',
            'client_vat_id'        => $c['vat'] ?: null,
            'client_tax_code'      => $c['tax_code'] ?: null,
            'client_sdi_code'      => $sdiCode,
            'client_sdi_pec'       => $doc['sdi_pec'] ?: null,
            'client_split_payment' => 'auto',
            'client_active'        => 1,
        ]);

        if (!$clientId) {
            throw new \RuntimeException('cliente non salvato: ' . implode(', ', $model->errors()));
        }

        $model->assignToAllUsers($clientId);

        return [$clientId, true];
    }

    private function createInvoice(array $doc, int $clientId): int
    {
        $data = [
            'client_id'               => $clientId,
            'invoice_date_created'    => $doc['date'],
            'invoice_date_due'        => $doc['payment']['due_date'] ?: null,
            'invoice_number'          => $doc['number'],
            'invoice_number_is_draft' => false,
            'invoice_status_id'       => 2,
            'is_read_only'            => 1,
            'invoice_doc_type'        => $doc['document_type'],
            'invoice_vat_exigibility' => $doc['vat_exigibility'],
            'payment_method'          => $this->paymentMethodId($doc['payment']['mode']),
            'invoice_admin_reference' => $doc['admin_reference'],
        ];

        foreach (['order' => 'invoice_order', 'contract' => 'invoice_contract', 'convention' => 'invoice_convention', 'receipt' => 'invoice_receipt'] as $key => $prefix) {
            $ref = $doc[$key];
            if ($ref === null) {
                continue;
            }

            $data[$prefix . '_number'] = mb_substr($ref['id'], 0, 20);
            $data[$prefix . '_date']   = $ref['date'];

            if ($key === 'order' && $ref['line']) {
                $data['invoice_order_line'] = $ref['line'];
            }

            $data['invoice_cig'] ??= $ref['cig'];
            $data['invoice_cup'] ??= $ref['cup'];
        }

        if ($doc['withholding']) {
            $data['invoice_withholding_type']   = $doc['withholding']['type'];
            $data['invoice_withholding_rate']   = $doc['withholding']['rate'];
            $data['invoice_withholding_reason'] = $doc['withholding']['reason'] ?: null;
        }

        if ($doc['fund']) {
            $data['invoice_fund_type'] = $doc['fund']['type'];
            $data['invoice_fund_rate'] = $doc['fund']['rate'];
        }

        if ($doc['stamp'] !== null) {
            $data['invoice_stamp_mode']   = 'si';
            $data['invoice_stamp_amount'] = $doc['stamp'];
        }

        // Carta del Docente: una riga "Pagamento Buono" con il codice nel
        // CodiceArticolo. Si riaccende il flag con il suo codice, così la
        // fattura rigenerata da qui è la stessa che era stata trasmessa.
        foreach ($doc['lines'] as $line) {
            $voucher = $line['codes']['CARTA DEL DOCENTE'] ?? $line['codes']['CARTADELDOCENTE'] ?? null;
            if ($voucher !== null) {
                $data['invoice_carta_docente'] = true;
                $data['invoice_voucher_code']  = $voucher;
                break;
            }
        }

        if ($this->isCreditNote($doc) && $doc['linked'] !== null) {
            $parent = (new InvoiceModel())->scopeToCompany()
                ->where('invoice_number', $doc['linked']['id'])
                ->first();

            if ($parent) {
                $data['creditinvoice_parent_id'] = (int) $parent['invoice_id'];
            }
        }

        $model     = new InvoiceModel();
        $invoiceId = $model->create(array_filter($data, static fn($v) => $v !== null), false);

        if (!$invoiceId) {
            throw new \RuntimeException('fattura non salvata: ' . implode(', ', $model->errors()));
        }

        // create() mette le condizioni predefinite dell'azienda quando
        // invoice_terms è vuoto: su una fattura storica stamperebbero termini
        // che l'originale non aveva. Vale la Causale del tracciato, o niente.
        (new InvoiceModel())->update($invoiceId, ['invoice_terms' => $doc['causale']]);

        return $invoiceId;
    }

    private function createItems(array $doc, int $invoiceId): void
    {
        // Una nota di credito vive internamente negativa (vedi
        // InvoicesAjax::createCredit): la quantità porta il segno.
        $sign      = $this->isCreditNote($doc) ? -1 : 1;
        $itemModel = new ItemModel();

        foreach ($doc['lines'] as $i => $line) {
            // "Titolo // dettaglio" è la convenzione con cui molti gestionali
            // mettono nome e descrizione nell'unico campo Descrizione.
            $parts = array_map('trim', explode('//', $line['description'], 2));

            // Riga senza quantità né prezzo unitario coerente (solo
            // PrezzoTotale): quantità 1 al prezzo totale, che è ciò che il
            // tracciato dice.
            $quantity = $line['quantity'] ?: 1.0;
            $price    = $line['price'];

            $itemId = (int) $itemModel->saveRecord(null, [
                'invoice_id'            => $invoiceId,
                'item_name'             => $parts[0],
                'item_description'      => $parts[1] ?? '',
                'item_quantity'         => $quantity * $sign,
                'item_price'            => $price,
                'item_tax_rate_id'      => $this->taxRateId($line['rate'], $line['natura']),
                'item_discount_amount'  => $line['discount_amount'] ?: null,
                'item_discount_percent' => $line['discount_percent'] ?: null,
                'item_product_unit'     => $line['unit'] !== '' ? mb_substr($line['unit'], 0, 50) : null,
                'item_order'            => $i + 1,
                'item_is_recurring'     => 0,
                'item_date_added'       => $doc['date'],
            ]);

            (new \App\Models\ItemAmountModel())->calculate($itemId);
        }
    }

    /**
     * Aliquota censita per percentuale e Natura; se manca la si crea, invece
     * di ripiegare su un'aliquota "vicina" che cambierebbe l'IVA del
     * documento.
     */
    private function taxRateId(float $rate, ?string $natura): int
    {
        $key = number_format($rate, 2, '.', '') . '|' . ($natura ?? '');

        if (isset($this->taxRates[$key])) {
            return $this->taxRates[$key];
        }

        $model   = new TaxRateModel();
        $builder = $model->where('tax_rate_percent', $rate);
        $natura === null
            ? $builder->groupStart()->where('tax_rate_natura IS NULL', null, false)->orWhere('tax_rate_natura', '')->groupEnd()
            : $builder->where('tax_rate_natura', $natura);

        $row = $builder->orderBy('tax_rate_id', 'ASC')->first();

        if (!$row) {
            $id = (int) $model->insert([
                'tax_rate_name'    => $natura ? 'Natura ' . $natura : 'IVA ' . rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . '%',
                'tax_rate_percent' => $rate,
                'tax_rate_natura'  => $natura,
            ], true);
        } else {
            $id = (int) $row['tax_rate_id'];
        }

        return $this->taxRates[$key] = $id;
    }

    private function paymentMethodId(?string $mode): ?int
    {
        if (!$mode) {
            return null;
        }

        foreach ((new PaymentMethodModel())->findAll() as $method) {
            if (PaymentMethodModel::sdiCodeFor($method) === $mode) {
                return (int) $method['payment_method_id'];
            }
        }

        return null;
    }

    /**
     * Dopo l'import, il prossimo numero della serie fatture deve venire dopo
     * l'ultimo importato: altrimenti la prima fattura creata qui riprende da
     * 1 e duplica un numero già trasmesso allo SdI — uno scarto 00404.
     */
    private function advanceNumbering(int $maxNumber): void
    {
        if ($maxNumber <= 0) {
            return;
        }

        $groupModel = new InvoiceGroupModel();
        $groupId    = $groupModel->defaultGroupId(0, 'default_invoice_group');
        $group      = $groupId ? $groupModel->find($groupId) : null;

        if ($group && (int) $group['invoice_group_next_id'] <= $maxNumber) {
            $groupModel->update($groupId, ['invoice_group_next_id' => $maxNumber + 1]);
        }
    }
}
