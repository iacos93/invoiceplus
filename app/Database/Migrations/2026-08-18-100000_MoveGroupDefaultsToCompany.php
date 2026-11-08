<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Serie predefinite per azienda.
 *
 * Erano impostazioni globali, ma le serie di numerazione appartengono a una
 * singola azienda: passando alla seconda, il gruppo predefinito continuava a
 * puntare a una serie della prima — che la seconda non può nemmeno vedere, e
 * da cui avrebbe preso i numeri.
 *
 * I valori globali esistenti vengono copiati sull'azienda predefinita, così
 * chi ne gestisce una sola non deve riconfigurare nulla.
 */
class MoveGroupDefaultsToCompany extends Migration
{
    private const COLUMNS = [
        'default_invoice_group',
        'draft_invoice_group',
        'default_quote_group',
        'draft_quote_group',
    ];

    public function up()
    {
        foreach (self::COLUMNS as $column) {
            if (!$this->db->fieldExists($column, 'ip_companies')) {
                $this->forge->addColumn('ip_companies', [
                    $column => ['type' => 'INT', 'null' => true],
                ]);
            }
        }

        $default = $this->db->table('ip_companies')
            ->where('company_is_default', true)
            ->get()->getRowArray();

        if (!$default) {
            return;
        }

        $update = [];

        foreach (self::COLUMNS as $column) {
            $row = $this->db->table('ip_settings')
                ->where('setting_key', $column)
                ->get()->getRowArray();

            if ($row && $row['setting_value'] !== '' && $row['setting_value'] !== null) {
                $update[$column] = (int) $row['setting_value'];
            }
        }

        if ($update) {
            $this->db->table('ip_companies')
                ->where('company_id', $default['company_id'])
                ->update($update);
        }
    }

    public function down()
    {
        foreach (self::COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'ip_companies')) {
                $this->forge->dropColumn('ip_companies', $column);
            }
        }
    }
}
