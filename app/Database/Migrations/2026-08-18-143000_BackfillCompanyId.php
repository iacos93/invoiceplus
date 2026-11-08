<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Assegna all'azienda predefinita le righe rimaste senza company_id.
 *
 * Le query dell'applicazione includono di proposito le righe con company_id
 * NULL, per non far sparire i dati preesistenti quando il multi-azienda e'
 * stato introdotto. Il rovescio e' che quelle righe compaiono in *tutte* le
 * aziende: una fattura senza azienda finiva nel fatturato sia della societa'
 * operativa sia della holding.
 *
 * Sono dati anteriori alla separazione, quindi appartengono all'azienda
 * predefinita — quella che esisteva quando sono stati creati.
 */
class BackfillCompanyId extends Migration
{
    private const TABLES = [
        'ip_invoices', 'ip_quotes', 'ip_clients', 'ip_products', 'ip_expenses',
        'ip_delivery_notes', 'ip_invoice_groups', 'ip_bank_accounts',
        'ip_bank_transactions', 'ip_email_senders', 'ip_f24',
    ];

    public function up()
    {
        $default = $this->db->table('ip_companies')
                            ->where('company_is_default', true)
                            ->get()->getRowArray();

        // Senza un'azienda predefinita non c'e' a chi attribuirle: si lasciano
        // come sono, e la fallback su NULL continua a mostrarle.
        if (!$default) {
            return;
        }

        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table) && $this->db->fieldExists('company_id', $table)) {
                $this->db->table($table)
                         ->where('company_id IS NULL', null, false)
                         ->update(['company_id' => $default['company_id']]);
            }
        }
    }

    /**
     * Irreversibile: non c'e' modo di distinguere le righe riassegnate qui da
     * quelle che appartenevano gia' all'azienda predefinita.
     */
    public function down()
    {
    }
}
