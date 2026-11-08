<?php

namespace App\Models;

/**
 * ItemModel
 *
 * Porting CI4 di Mdl_Items (CI3): righe fattura (tabella
 * ip_invoice_items) con join su importi, aliquote e prodotti,
 * ricalcolo degli importi a ogni salvataggio/cancellazione.
 */
class ItemModel extends IpBaseModel
{
    protected $table            = 'ip_invoice_items';
    protected $primaryKey       = 'item_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'invoice_id',
        'item_tax_rate_id',
        'item_product_id',
        'item_code',
        'item_date_added',
        'item_date',
        'item_name',
        'item_description',
        'item_quantity',
        'item_price',
        'item_discount_amount',
        'item_discount_percent',
        'item_order',
        'item_is_recurring',
        'item_product_unit',
        'item_product_unit_id',
        // Voce del piano dei conti del Bilancino (vedi CostingAccountModel).
        'costing_account_id',
    ];

    protected $validationRules = [
        'invoice_id'      => 'required',
        'item_name'       => 'required',
        'item_description' => 'permit_empty',
        'item_quantity'   => 'required',
        'item_price'      => 'required',
        'item_tax_rate_id' => 'permit_empty',
        'item_product_id' => 'permit_empty',
        'item_code'       => 'permit_empty|max_length[60]',
        'item_task_id'    => 'permit_empty',
        'item_date'       => 'permit_empty',
        'item_discount_amount'  => 'permit_empty',
        'item_discount_percent' => 'permit_empty',
    ];

    protected $validationMessages = [
        'invoice_id' => [
            'required' => "L'id fattura e' obbligatorio",
        ],
        'item_name' => [
            'required' => 'Il nome dell\'articolo e\' obbligatorio',
        ],
        'item_quantity' => [
            'required' => 'La quantita\' e\' obbligatoria',
        ],
        'item_price' => [
            'required' => 'Il prezzo e\' obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Righe di una fattura con importi, aliquota e prodotto
     * (equivalente di default_select() + default_join() CI3).
     */
    public function getByInvoice(int $invoiceId): array
    {
        return $this->select('ip_invoice_item_amounts.*, ip_products.*, ip_invoice_items.*,
            item_tax_rates.tax_rate_percent AS item_tax_rate_percent,
            item_tax_rates.tax_rate_name AS item_tax_rate_name,
            item_tax_rates.tax_rate_natura AS item_tax_rate_natura')
            ->join('ip_invoice_item_amounts', 'ip_invoice_item_amounts.item_id = ip_invoice_items.item_id', 'left')
            ->join('ip_tax_rates AS item_tax_rates', 'item_tax_rates.tax_rate_id = ip_invoice_items.item_tax_rate_id', 'left')
            ->join('ip_products', 'ip_products.product_id = ip_invoice_items.item_product_id', 'left')
            ->where('ip_invoice_items.invoice_id', $invoiceId)
            ->orderBy('ip_invoice_items.item_order', 'ASC')
            ->orderBy('ip_invoice_items.item_id', 'ASC')
            ->findAll();
    }

    /**
     * Righe di piu' fatture in una sola query, raggruppate per invoice_id.
     *
     * L'esportazione "Righe fatture" chiamava getByInvoice() dentro il ciclo
     * sulle fatture: un anno di documenti significava una query per fattura
     * (piu' i quattro join ogni volta) per produrre un unico CSV.
     *
     * @param list<int> $invoiceIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function getByInvoices(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));

        if ($invoiceIds === []) {
            return [];
        }

        $rows = $this->select('ip_invoice_item_amounts.*, ip_products.*, ip_invoice_items.*,
            item_tax_rates.tax_rate_percent AS item_tax_rate_percent,
            item_tax_rates.tax_rate_name AS item_tax_rate_name,
            item_tax_rates.tax_rate_natura AS item_tax_rate_natura')
            ->join('ip_invoice_item_amounts', 'ip_invoice_item_amounts.item_id = ip_invoice_items.item_id', 'left')
            ->join('ip_tax_rates AS item_tax_rates', 'item_tax_rates.tax_rate_id = ip_invoice_items.item_tax_rate_id', 'left')
            ->join('ip_products', 'ip_products.product_id = ip_invoice_items.item_product_id', 'left')
            ->whereIn('ip_invoice_items.invoice_id', $invoiceIds)
            ->orderBy('ip_invoice_items.invoice_id', 'ASC')
            ->orderBy('ip_invoice_items.item_order', 'ASC')
            ->orderBy('ip_invoice_items.item_id', 'ASC')
            ->findAll();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['invoice_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Salva una riga (crea o aggiorna) e ricalcola gli importi
     * della riga e della fattura (equivalente di save() CI3).
     *
     * @param array|null $dbArray
     */
    public function saveItem(?int $id = null, $dbArray = null): int
    {
        $savedId = (int) $this->saveRecord($id, is_object($dbArray) ? (array) $dbArray : $dbArray);

        (new ItemAmountModel())->calculate($savedId);

        if (is_object($dbArray) && isset($dbArray->invoice_id)) {
            (new InvoiceAmountModel())->calculate($dbArray->invoice_id);
        } elseif (is_array($dbArray) && isset($dbArray['invoice_id'])) {
            (new InvoiceAmountModel())->calculate($dbArray['invoice_id']);
        }

        $this->clearFinanceCache();

        return $savedId;
    }

    /**
     * Elimina una riga (e i suoi importi) e ricalcola la fattura.
     * Ritorna la riga eliminata (per ripristinare i task) o null.
     */
    public function deleteItem(int $itemId): ?array
    {
        $item = $this->find($itemId);

        if (!$item) {
            return null;
        }

        $invoiceId = (int) $item['invoice_id'];

        $this->delete($itemId);

        $this->db->table('ip_invoice_item_amounts')->where('item_id', $itemId)->delete();

        (new InvoiceAmountModel())->calculate($invoiceId);

        $this->clearFinanceCache();

        return $item;
    }

    /**
     * Il report finanziario è in cache per azienda/anno, e cambiare una riga
     * cambia il totale della fattura: senza questo, aggiungere o togliere una
     * riga lasciava il report a mostrare il totale precedente finché la cache
     * non scadeva. InvoiceModel lo fa già nei propri callback, ma i suoi
     * callback non scattano quando a cambiare sono gli importi delle righe.
     */
    private function clearFinanceCache(): void
    {
        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }
    }

    /**
     * Converte un POST in array per il salvataggio con le stesse
     * normalizzazioni dell'Ajax CI3: importi standardizzati,
     * date convertite, id vuoti a null, task a null se assente.
     *
     * @param array|object $post
     */
    public function dbArrayFromItem($post): array
    {
        $data = is_object($post) ? get_object_vars($post) : $post;

        $dbArray = [
            'invoice_id'           => $data['invoice_id'] ?? null,
            'item_name'            => $data['item_name'] ?? '',
            'item_description'     => $data['item_description'] ?? '',
            'item_quantity'        => !empty($data['item_quantity'] ?? null) ? standardize_amount($data['item_quantity']) : 0,
            'item_price'           => !empty($data['item_price'] ?? null) ? standardize_amount($data['item_price']) : 0,
            // item_tax_rate_id è NOT NULL (DEFAULT 0) sullo schema: a differenza di
            // item_product_id/item_task_id, scrivere null qui rompe l'insert quando
            // l'utente seleziona "Nessuna" aliquota.
            'item_tax_rate_id'     => !empty($data['item_tax_rate_id'] ?? null) ? $data['item_tax_rate_id'] : 0,
            'item_product_id'      => !empty($data['item_product_id'] ?? null) ? $data['item_product_id'] : null,
            // Codice scritto sulla riga (codice MEPA o articolo concordato):
            // libero e facoltativo, convive con il prodotto di catalogo.
            'item_code'            => trim((string) ($data['item_code'] ?? '')),
            'item_task_id'         => !empty($data['item_task_id'] ?? null) ? $data['item_task_id'] : null,
            'item_discount_amount' => !empty($data['item_discount_amount'] ?? null) ? standardize_amount($data['item_discount_amount']) : null,
            'item_discount_percent' => !empty($data['item_discount_percent'] ?? null) ? standardize_amount($data['item_discount_percent']) : null,
            'item_order'           => $data['item_order'] ?? 0,
            'item_is_recurring'    => $data['item_is_recurring'] ?? 0,
            // La voce di ricavo viaggia con la riga dal motore dei documenti:
            // un valore vuoto significa "non assegnata", non zero (0 non è un
            // id valido e farebbe fallire il vincolo logico del report).
            'costing_account_id'   => !empty($data['costing_account_id'] ?? null) ? (int) $data['costing_account_id'] : null,
        ];

        if (isset($data['item_date']) && $data['item_date'] !== '') {
            $dbArray['item_date'] = date_to_mysql($data['item_date']);
        }

        if (!empty($data['item_product_unit_id'] ?? null)) {
            $unit = (new UnitModel())->find($data['item_product_unit_id']);

            if ($unit) {
                $dbArray['item_product_unit_id'] = $data['item_product_unit_id'];
                $qty                            = !empty($data['item_quantity'])
                    ? standardize_amount($data['item_quantity'])
                    : 0;
                // Singolare/plurale coerente con QuoteItemModel::getProductUnitName():
                // il nome dell'unità va al plurale quando la quantità non è 1.
                $dbArray['item_product_unit'] = ((float) $qty == 1)
                    ? $unit['unit_name']
                    : $unit['unit_name_plrl'];
            }
        }

        if (!empty($data['item_product_unit'] ?? null)) {
            $dbArray['item_product_unit'] = $data['item_product_unit'];
        }

        return $dbArray;
    }
}