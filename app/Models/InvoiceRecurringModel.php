<?php

namespace App\Models;

/**
 * InvoiceRecurringModel
 *
 * Porting CI4 di Mdl_Invoices_Recurring (CI3): fatture ricorrenti
 * (tabella ip_invoices_recurring) con frequenze, attive, stop e
 * aggiornamento della prossima data.
 */
class InvoiceRecurringModel extends IpBaseModel
{
    protected $table            = 'ip_invoices_recurring';
    protected $primaryKey       = 'invoice_recurring_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'invoice_id',
        'recur_start_date',
        'recur_end_date',
        'recur_frequency',
        'recur_next_date',
    ];

    protected $validationRules = [
        'invoice_id'       => 'required',
        'recur_start_date' => 'required',
        'recur_end_date'   => 'permit_empty',
        'recur_frequency'  => 'required',
    ];

    protected $validationMessages = [
        'invoice_id' => [
            'required' => "L'id fattura e' obbligatorio",
        ],
        'recur_start_date' => [
            'required' => 'La data di inizio e\' obbligatoria',
        ],
        'recur_frequency' => [
            'required' => 'La frequenza e\' obbligatoria',
        ],
    ];

    /**
     * Frequenze disponibili (chiave => chiave lingua).
     */
    public function recurFrequencies(): array
    {
        return [
            '1D'  => 'calendar_day_1',
            '2D'  => 'calendar_day_2',
            '3D'  => 'calendar_day_3',
            '4D'  => 'calendar_day_4',
            '5D'  => 'calendar_day_5',
            '6D'  => 'calendar_day_6',
            '15D' => 'calendar_day_15',
            '30D' => 'calendar_day_30',
            '7D'  => 'calendar_week_1',
            '14D' => 'calendar_week_2',
            '21D' => 'calendar_week_3',
            '28D' => 'calendar_week_4',
            '1M'  => 'calendar_month_1',
            '2M'  => 'calendar_month_2',
            '3M'  => 'calendar_month_3',
            '4M'  => 'calendar_month_4',
            '5M'  => 'calendar_month_5',
            '6M'  => 'calendar_month_6',
            '7M'  => 'calendar_month_7',
            '8M'  => 'calendar_month_8',
            '9M'  => 'calendar_month_9',
            '10M' => 'calendar_month_10',
            '11M' => 'calendar_month_11',
            '1Y'  => 'calendar_year_1',
            '2Y'  => 'calendar_year_2',
            '3Y'  => 'calendar_year_3',
            '4Y'  => 'calendar_year_4',
            '5Y'  => 'calendar_year_5',
        ];
    }

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Lista ricorrenti con numero fattura, cliente e stato
     * (equivalente di default_select() + default_join() CI3).
     */
    public function getAllWithJoins(): array
    {
        $companyId = active_company_id();

        $builder = $this->select("ip_invoices.*, ip_clients.client_name,
                ip_invoices_recurring.*,
                CASE WHEN recur_end_date > CURRENT_DATE OR recur_end_date IS NULL THEN 'active' ELSE 'inactive' END AS recur_status", false)
            ->join('ip_invoices', 'ip_invoices.invoice_id = ip_invoices_recurring.invoice_id')
            ->join('ip_clients', 'ip_clients.client_id = ip_invoices.client_id')
            ->orderBy('ip_invoices_recurring.recur_next_date', 'ASC');

        // Le ricorrenti non hanno company_id proprio: lo si prende dalla fattura
        // joinata, altrimenti la lista admin mostra (e lascia agire su) quelle di
        // tutte le aziende. Le righe pre-multi-company (company_id NULL) restano
        // visibili a tutte, come da convenzione.
        if ($companyId) {
            $builder->where('(ip_invoices.company_id = ' . (int) $companyId . ' OR ip_invoices.company_id IS NULL)');
        }

        return $builder->findAll();
    }

    /**
     * Ricorrenti da generare ora: prossima data <= oggi e (end > oggi
     * oppure end non impostata). Equivalente di active() CI3.
     */
    public function getActive(): array
    {
        return $this->where("recur_next_date <= CURRENT_DATE AND (recur_end_date > CURRENT_DATE OR recur_end_date IS NULL)")
            ->findAll();
    }

    /**
     * Aggiorna la prossima data di una ricorrente in base alla frequenza
     * (equivalente di set_next_recur_date() CI3, chiave = invoice_id).
     */
    public function setNextRecurDate(int $invoiceId): void
    {
        $invoiceRecurring = $this->where('invoice_id', $invoiceId)->first();

        if (!$invoiceRecurring) {
            return;
        }

        $recurNextDate = increment_date($invoiceRecurring['recur_next_date'], $invoiceRecurring['recur_frequency']);

        $this->where('invoice_id', $invoiceId)->set(['recur_next_date' => $recurNextDate])->update();
    }

    /**
     * Ferma una ricorrente: end = oggi e next = NULL
     * (equivalente di stop() CI3, chiave = invoice_id).
     */
    public function stop(int $invoiceId): void
    {
        $this->where('invoice_id', $invoiceId)->set([
            'recur_end_date'  => date('Y-m-d'),
            'recur_next_date' => null,
        ])->update();
    }

    /**
     * Costruisce l'array da salvare: date convertite in formato SQL,
     * recur_next_date = recur_start_date, end vuota a NULL
     * (equivalente di db_array() CI3).
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        $dbArray['recur_start_date'] = date_to_mysql($dbArray['recur_start_date'] ?? null);
        $dbArray['recur_next_date']  = $dbArray['recur_start_date'];

        if (!empty($dbArray['recur_end_date'] ?? null)) {
            $dbArray['recur_end_date'] = date_to_mysql($dbArray['recur_end_date']);
        } else {
            $dbArray['recur_end_date'] = null;
        }

        return $dbArray;
    }
}