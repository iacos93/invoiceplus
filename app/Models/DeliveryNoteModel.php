<?php

namespace App\Models;

/**
 * DeliveryNoteModel — documenti di trasporto.
 */
class DeliveryNoteModel extends IpBaseModel
{
    protected $table            = 'ip_delivery_notes';
    protected $primaryKey       = 'delivery_note_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'company_id', 'client_id', 'invoice_group_id', 'invoice_id',
        'delivery_note_number', 'delivery_note_date', 'transport_datetime',
        'transport_reason', 'goods_appearance', 'packages', 'weight', 'carrier',
        'transport_charge', 'shipping_address', 'delivery_note_notes',
        'delivery_note_url_key', 'delivery_note_date_created',
    ];

    protected $validationRules = [
        'client_id'            => 'required',
        'delivery_note_date'   => 'required',
        'invoice_group_id'     => 'permit_empty',
        'delivery_note_number' => 'permit_empty',
        'transport_datetime'   => 'permit_empty',
        'transport_reason'     => 'permit_empty',
        'goods_appearance'     => 'permit_empty',
        'packages'             => 'permit_empty',
        'weight'               => 'permit_empty',
        'carrier'              => 'permit_empty',
        'transport_charge'     => 'permit_empty',
        'shipping_address'     => 'permit_empty',
        'delivery_note_notes'  => 'permit_empty',
    ];

    protected $validationMessages = [
        'client_id'          => ['required' => 'Il destinatario è obbligatorio'],
        'delivery_note_date' => ['required' => 'La data è obbligatoria'],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /** Causali di trasporto previste dalla prassi. */
    public function transportReasons(): array
    {
        return ['Vendita', 'Conto visione', 'Conto lavorazione', 'Conto deposito',
                'Reso', 'Riparazione', 'Omaggio', 'Campionatura', 'Trasferimento interno'];
    }

    /** Porto: chi sostiene le spese di trasporto. */
    public function transportCharges(): array
    {
        return ['Franco' => 'Franco (a carico mittente)', 'Assegnato' => 'Assegnato (a carico destinatario)'];
    }

    private function withJoins(): static
    {
        $this->select('ip_delivery_notes.*, ip_clients.*, ip_invoices.invoice_number', false)
             ->join('ip_clients', 'ip_clients.client_id = ip_delivery_notes.client_id')
             ->join('ip_invoices', 'ip_invoices.invoice_id = ip_delivery_notes.invoice_id', 'left');

        $this->scopeToCompany();

        return $this;
    }

    public function getAll(int $year = 0, string $state = ''): array
    {
        $this->withJoins();

        if ($year > 0) {
            $this->where(year_sql('ip_delivery_notes.delivery_note_date'), $year);
        }

        // "Da fatturare": consegnato ma non ancora messo in fattura.
        if ($state === 'open') {
            $this->where('ip_delivery_notes.invoice_id IS NULL', null, false);
        } elseif ($state === 'invoiced') {
            $this->where('ip_delivery_notes.invoice_id IS NOT NULL', null, false);
        }

        return $this->orderBy('ip_delivery_notes.delivery_note_date', 'DESC')
                    ->orderBy('ip_delivery_notes.delivery_note_id', 'DESC')
                    ->findAll();
    }

    public function getById(int $id): ?array
    {
        return $this->withJoins()->where('ip_delivery_notes.delivery_note_id', $id)->first();
    }

    public function getAvailableYears(): array
    {
        $rows = $this->companyBuilder()
            ->select('EXTRACT(YEAR FROM delivery_note_date) AS y', false)
            ->distinct()->orderBy('y', 'DESC')->get()->getResultArray();

        return array_values(array_filter(array_map(static fn($r) => (int) $r['y'], $rows)));
    }

    /**
     * Ultimi N DDT con join su cliente e fattura collegata, e il flag
     * is_invoiced calcolato in SQL (DDT gia' messo in fattura oppure no).
     * Usato dalla dashboard "DDT recenti": un'unica query invece di
     * caricare tutti i DDT in PHP e controllare invoice_id riga per riga,
     * come per InvoiceModel::getLatest()/QuoteModel::getLatest().
     */
    public function getLatest(int $limit = 10, int $year = 0): array
    {
        $builder = $this->select("
                ip_delivery_notes.*,
                ip_clients.client_name, ip_clients.client_surname,
                COALESCE(ip_invoices.invoice_number, '') AS invoice_number,
                (CASE WHEN ip_delivery_notes.invoice_id IS NOT NULL
                       THEN 1 ELSE 0 END) AS is_invoiced", false)
            ->join('ip_clients', 'ip_clients.client_id = ip_delivery_notes.client_id', 'left')
            ->join('ip_invoices', 'ip_invoices.invoice_id = ip_delivery_notes.invoice_id', 'left');

        // Filtro azienda: come per le altre liste "recenti", senza scopeToCompany()
        // la dashboard mostrerebbe i DDT di tutte le aziende, ignorando il
        // soggetto selezionato in topbar (vedi InvoiceModel::getLatest()).
        $this->scopeToCompany();

        if ($year > 0) {
            $builder->where(year_sql('ip_delivery_notes.delivery_note_date'), $year);
        }

        return $builder
            ->orderBy('ip_delivery_notes.delivery_note_date', 'DESC')
            ->orderBy('ip_delivery_notes.delivery_note_id', 'DESC')
            ->limit($limit)
            ->findAll();
    }
}
