<?php

namespace App\Models;

/**
 * CompanyModel — soggetti giuridici gestiti dall'applicazione.
 */
class CompanyModel extends IpBaseModel
{
    protected $table            = 'ip_companies';
    protected $primaryKey       = 'company_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    // Il regime fiscale dell'azienda e' un ingresso della stima in "Utile e
    // imposte", che sta in cache un'ora: senza queste callback si cambiava
    // forma o schema contributivo e il prospetto continuava a mostrare la
    // stima calcolata con i valori vecchi.
    protected $afterInsert = ['clearFinanceCache'];
    protected $afterUpdate = ['clearFinanceCache'];
    protected $afterDelete = ['clearFinanceCache'];

    protected $allowedFields = [
        'company_name', 'company_vat_id', 'company_tax_code', 'company_address_1',
        'company_city', 'company_state', 'company_zip', 'company_country',
        'company_phone', 'company_email', 'company_form', 'company_coefficient',
        'company_contributions', 'company_inps_scheme', 'company_forfettario_startup',
        'company_add_regionale', 'company_add_comunale',
        'company_tax_extras',
        'company_is_default',
        'company_is_active', 'company_date_created',
        // Codice esercente Carta del Docente: id convenzionato con cui la
        // piattaforma identifica l'azienda nelle fatture dei buoni (1.2.6).
        'company_carta_docente_code',
        'default_invoice_group', 'draft_invoice_group',
        'default_quote_group', 'draft_quote_group',
        // Casella email di sistema (notifiche cron, reset password, SdI di
        // ripiego): scelta per-azienda, letta via company_setting().
        'company_system_sender_id',
    ];

    /**
     * Callback eventi CI4: invalida la cache della pagina Finance.
     */
    protected function clearFinanceCache(array $data): array
    {
        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        return $data;
    }

    protected $validationRules = [
        'company_name'          => 'required',
        'company_vat_id'        => 'permit_empty',
        'company_tax_code'      => 'permit_empty',
        'company_address_1'     => 'permit_empty',
        'company_city'          => 'permit_empty',
        'company_state'         => 'permit_empty',
        'company_zip'           => 'permit_empty',
        'company_country'       => 'permit_empty|max_length[5]',
        'company_phone'         => 'permit_empty',
        'company_email'         => 'permit_empty',
        // Il regime decide come si calcola tutta la stima: un valore fuori
        // elenco la farebbe ripiegare in silenzio su quello ordinario.
        'company_form'          => 'permit_empty|in_list[forfettario,semplificato,societa_capitali]',
        'company_coefficient'   => 'permit_empty|decimal|greater_than[0]|less_than_equal_to[100]',
        'company_contributions' => 'permit_empty|decimal|greater_than_equal_to[0]',
        'company_inps_scheme'   => 'permit_empty|in_list[separata,artigiani]',
        'company_forfettario_startup' => 'permit_empty|in_list[0,1]',
        'company_add_regionale' => 'permit_empty|decimal|greater_than_equal_to[0]',
        'company_add_comunale'  => 'permit_empty|decimal|greater_than_equal_to[0]',
        'company_tax_extras'    => 'permit_empty',
        'company_carta_docente_code' => 'permit_empty|max_length[20]',
        'company_is_default'    => 'permit_empty',
        'company_is_active'     => 'permit_empty',
        // Serie di numerazione: appartengono all'azienda, non all'installazione.
        'default_invoice_group' => 'permit_empty|is_natural',
        'draft_invoice_group'   => 'permit_empty|is_natural',
        'default_quote_group'   => 'permit_empty|is_natural',
        'draft_quote_group'     => 'permit_empty|is_natural',
        'company_system_sender_id' => 'permit_empty|is_natural',
    ];

    protected $validationMessages = [
        'company_name' => ['required' => 'La ragione sociale è obbligatoria'],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    public function getAllActive(): array
    {
        return $this->where('company_is_active', true)
                    ->orderBy('company_is_default', 'DESC')
                    ->orderBy('company_name', 'ASC')
                    ->findAll();
    }

    public function getDefault(): ?array
    {
        return $this->where('company_is_default', true)->first()
            ?: $this->orderBy('company_id', 'ASC')->first();
    }

    /**
     * Il predefinito è uno solo: senza questo, quale azienda si apre
     * all'accesso dipenderebbe dall'ordine di lettura.
     */
    public function saveCompany(?int $id, array $dbArray): int
    {
        if (!$id) {
            $dbArray['company_date_created'] = date('Y-m-d H:i:s');
        }

        $savedId = (int) $this->saveRecord($id, $dbArray);

        if (!empty($dbArray['company_is_default'])) {
            $this->builder()->where('company_id !=', $savedId)
                 ->update(['company_is_default' => false]);
        }

        return $savedId;
    }
}
