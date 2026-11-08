<?php

namespace App\Libraries;

use App\Models\ExpenseModel;
use App\Models\SupplierModel;

/**
 * Importa fatture di acquisto (XML FatturaPA) come spese.
 *
 * Punto unico condiviso fra l'import manuale (Expenses::runImport(), file
 * .xml/.xml.p7m/.zip caricati a mano da /expenses/import) e il futuro
 * innesto automatico di SdiRicezioneEndpoint una volta completato
 * l'accreditamento SDICoop-Ricezione (vedi il commento di classe di
 * SdiRicezioneEndpoint per il motivo per cui quell'innesto non c'è ancora:
 * lì l'XML atterra solo su disco, in attesa dei test di interoperabilità).
 *
 * Il parsing/estrazione (.xml, .xml.p7m, ZIP) è delegato a FatturaXml,
 * già usata dall'anteprima; qui restano solo dedup, anagrafica fornitore
 * e salvataggio spesa + XML.
 */
class PurchaseInvoiceImporter
{
    private FatturaXml $reader;
    private ExpenseModel $expenseModel;
    private SupplierModel $supplierModel;

    public function __construct()
    {
        $this->reader        = new FatturaXml();
        $this->expenseModel  = new ExpenseModel();
        $this->supplierModel = new SupplierModel();
    }

    /**
     * Importa uno o più documenti da un file caricato (.xml, .xml.p7m o uno
     * ZIP con più fatture dentro, come quello scaricabile dal portale
     * Fatture e Corrispettivi).
     *
     * "rejected" è distinto da "failed": un file rifiutato è leggibilissimo,
     * semplicemente non è una spesa di questa azienda (una fattura emessa,
     * un documento intestato a un'altra società, un'integrazione TD16).
     *
     * @return array{imported: int, skipped: int, created_suppliers: int, failed: list<string>, rejected: list<string>}
     */
    public function importFromPath(string $path, string $originalName, ?int $categoryId = null): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'created_suppliers' => 0, 'failed' => [], 'rejected' => []];

        $documents = $this->reader->extract($path, $originalName);

        if ($documents === []) {
            $result['failed'][] = $originalName . ': formato non riconosciuto (atteso XML, .p7m o ZIP)';

            return $result;
        }

        // Un file lotto (più FatturaElettronicaBody sotto la stessa testata)
        // diventa un documento per fattura: prima si importava solo la
        // prima, e le altre del lotto non lasciavano traccia nemmeno fra gli
        // errori. Ogni pezzo porta nel nome il numero d'ordine nel lotto,
        // così un rifiuto o un errore dice quale fattura riguarda.
        $pieces = [];
        foreach ($documents as $document) {
            $bodies = $this->reader->splitBodies($document['xml']);

            foreach ($bodies as $i => $bodyXml) {
                $pieces[] = [
                    'xml'  => $bodyXml,
                    'name' => count($bodies) > 1
                        ? $document['name'] . ' (fattura ' . ($i + 1) . ' di ' . count($bodies) . ')'
                        : $document['name'],
                ];
            }
        }

        foreach ($pieces as $document) {
            $outcome = $this->importDocument($document['xml'], $categoryId, $document['name']);

            if ($outcome === null) {
                $result['failed'][] = $document['name'] . ': XML non leggibile o non è una fattura FatturaPA valida';

                continue;
            }

            if (!empty($outcome['error'])) {
                $result['failed'][] = $document['name'] . ': ' . $outcome['error'];

                continue;
            }

            if (!empty($outcome['rejected'])) {
                $result['rejected'][] = $document['name'] . ': ' . $outcome['rejected'];

                continue;
            }

            if ($outcome['skipped']) {
                $result['skipped']++;

                continue;
            }

            $result['imported']++;

            if ($outcome['created_supplier']) {
                $result['created_suppliers']++;
            }
        }

        return $result;
    }

    /**
     * Importa un singolo documento XML già estratto. Pubblico e riusabile
     * anche dal futuro innesto SDICoop-Ricezione, che riceve un documento
     * per volta invece di un file caricato — ma un documento può essere un
     * lotto: chi lo chiama da lì passi prima da FatturaXml::splitBodies(),
     * come fa importFromPath(), perché qui si legge un body solo.
     *
     * @return array{skipped: bool, created_supplier: bool, expense_id: int, rejected?: string, error?: string}|null null se l'XML non è una fattura leggibile
     */
    public function importDocument(string $xml, ?int $categoryId, string $fileName = ''): ?array
    {
        $parsed = $this->reader->parse($xml);

        if ($parsed === null) {
            return null;
        }

        $none = ['skipped' => false, 'created_supplier' => false, 'expense_id' => 0];

        $reason = $this->rejectionReason($parsed);

        if ($reason !== null) {
            return $none + ['rejected' => $reason];
        }

        // Chiave di deduplica: fornitore + numero + data del documento,
        // univoca per azienda grazie al vincolo (company_id, expense_source_ref)
        // su ip_expenses -- reimportare lo stesso file, o lo stesso ZIP due
        // volte, non deve raddoppiare la spesa. Lo stesso documento può
        // invece esistere in due aziende diverse (era il vincolo globale,
        // tolto da DropGlobalExpenseSourceRefUnique, a impedirlo).
        $sourceRef = sha1('fatturapa:' . $parsed['supplier_vat'] . ':' . $parsed['number'] . ':' . $parsed['date']);

        $existing = $this->expenseModel
            ->scopeToCompany()
            ->where('expense_source_ref', $sourceRef)
            ->first();

        if ($existing) {
            return ['skipped' => true, 'created_supplier' => false, 'expense_id' => (int) $existing['expense_id']];
        }

        // Fornitore e spesa nella stessa transazione: prima, se l'inserimento
        // della spesa falliva (il vincolo globale qui sopra, ma vale per
        // qualunque errore), il fornitore appena creato restava in anagrafica
        // senza nessun documento collegato.
        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $supplier        = $this->supplierModel->findExisting($parsed['supplier_vat'], $parsed['supplier_name']);
            $createdSupplier = !$supplier;

            $supplierId = $supplier
                ? (int) $supplier['supplier_id']
                : $this->supplierModel->saveRecord(null, [
                    'supplier_name'     => $parsed['supplier_name'],
                    'supplier_vat_id'   => $parsed['supplier_vat'],
                    'supplier_tax_code' => $parsed['supplier_tax_code'],
                    'supplier_active'   => true,
                ]);

            // 'xml' e non un'altra etichetta: la lista spese e il form leggono
            // esattamente questo valore per mostrare il badge "XML" e la voce
            // "Anteprima fattura elettronica" (vedi expenses/index.php).
            $expenseId = $this->expenseModel->saveRecord(null, [
                'expense_date'         => $parsed['date'],
                'expense_number'       => $parsed['number'],
                'supplier_name'        => $parsed['supplier_name'],
                'supplier_vat'         => $parsed['supplier_vat'],
                'supplier_tax_code'    => $parsed['supplier_tax_code'],
                'supplier_id'          => $supplierId,
                'expense_category_id'  => $categoryId,
                'expense_amount'       => $parsed['amount'],
                'expense_tax'          => $parsed['tax'],
                'expense_total'        => $parsed['total'],
                'expense_paid'         => 0,
                'expense_note'         => $this->noteFor($parsed['document_type']),
                'expense_source'       => 'xml',
                'expense_source_ref'   => $sourceRef,
                'expense_file'         => $fileName !== '' ? $fileName : ($parsed['number'] . '.xml'),
                'expense_date_created' => date('Y-m-d H:i:s'),
            ]);

            if (!$expenseId) {
                throw new \RuntimeException('salvataggio della spesa non riuscito');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            log_message('error', 'Import fattura passiva ' . $fileName . ': ' . $e->getMessage());

            return $none + ['error' => 'errore in salvataggio (' . $e->getMessage() . ')'];
        }

        (new FatturaXmlStore())->save((int) $expenseId, $xml);

        return ['skipped' => false, 'created_supplier' => $createdSupplier, 'expense_id' => (int) $expenseId];
    }

    /**
     * Perché un documento leggibile non va registrato come spesa
     * dell'azienda attiva, o null se va bene.
     *
     * Il controllo è sul contenuto, non sulla cartella da cui arriva il
     * file: caricare per sbaglio le fatture EMESSE in "Importa XML" le
     * trasformava in spese con la propria ditta come fornitore, e un ZIP
     * del portale di un'altra società finiva nei costi di questa.
     *
     * @param array<string, mixed> $parsed
     */
    private function rejectionReason(array $parsed): ?string
    {
        $company = function_exists('active_company') ? active_company() : [];
        $own     = array_filter([
            self::normalizeId((string) ($company['company_vat_id'] ?? '')),
            self::normalizeId((string) ($company['company_tax_code'] ?? '')),
        ]);

        if ($own === []) {
            // Senza identificativi non si può dire a chi è intestata la
            // fattura: accettarla vorrebbe dire importare anche i documenti
            // di un'altra azienda gestita nella stessa installazione.
            return 'impossibile verificare il destinatario: inserire partita IVA o codice fiscale dell\'azienda attiva in Impostazioni > Aziende';
        }

        $supplierIds = array_filter([
            self::normalizeId($parsed['supplier_vat']),
            self::normalizeId($parsed['supplier_tax_code']),
        ]);

        if (array_intersect($supplierIds, $own) !== []) {
            return 'fattura emessa dall\'azienda attiva (ciclo attivo), non una spesa: va importata da Fatture > Importa XML';
        }

        $customerIds = array_filter([
            self::normalizeId($parsed['customer_vat']),
            self::normalizeId($parsed['customer_tax_code']),
        ]);

        if (array_intersect($customerIds, $own) === []) {
            return 'intestata a ' . ($parsed['customer_vat'] ?: $parsed['customer_tax_code'] ?: 'committente non indicato')
                . ', non all\'azienda attiva';
        }

        if ($parsed['document_type'] === FatturaXml::REVERSE_CHARGE_INTEGRATION) {
            return 'integrazione TD16 di una fattura già ricevuta (inversione contabile interna): l\'imponibile è già nei costi con la fattura originale e l\'IVA non è un costo';
        }

        return null;
    }

    /**
     * Partita IVA o codice fiscale confrontabili: maiuscolo, senza spazi e
     * senza il prefisso IT davanti alle 11 cifre (nel tracciato IdPaese e
     * IdCodice sono separati, in anagrafica azienda il prefisso c'è o no a
     * seconda di chi l'ha inserita).
     */
    public static function normalizeId(string $id): string
    {
        $id = strtoupper(preg_replace('/\s+/', '', $id));

        return preg_match('/^IT(\d{11})$/', $id, $m) ? $m[1] : $id;
    }

    /** Annotazione automatica per i documenti che non sono una fattura ordinaria. */
    private function noteFor(string $type): ?string
    {
        if (in_array($type, FatturaXml::CREDIT_NOTE_TYPES, true)) {
            return 'Nota di credito (' . $type . '): importo registrato in negativo.';
        }

        if (in_array($type, FatturaXml::REVERSE_CHARGE_TYPES, true)) {
            return 'Autofattura/integrazione ' . $type . ' (inversione contabile): IVA a debito e a credito, non registrata come costo.';
        }

        return null;
    }
}
