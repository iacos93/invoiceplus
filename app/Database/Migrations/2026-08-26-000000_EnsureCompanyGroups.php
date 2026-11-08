<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ogni azienda deve possedere le proprie serie di numerazione.
 *
 * Prima le serie erano globali (company_id NULL) e BackfillCompanyId le aveva
 * riassegnate tutte all'azienda predefinita; seedCompany(), poi, creava una
 * sola serie "Fatture" e non collegava MAI le colonne default e draft della
 * compagnia. Risultato: una seconda azienda non aveva serie proprie e, tramite
 * il fallback globale, le sue bozze/preventivi finivano sulla serie dell'azienda
 * predefinita (o riusavano la serie fatture). Qui si creano le quattro serie per
 * ogni azienda esistente e si collegano le colonne, in modo idempotente (match
 * per nome, non duplica).
 */
class EnsureCompanyGroups extends Migration
{
    private const ROLES = [
        'Fatture'          => ['column' => 'default_invoice_group', 'format' => '{{{id}}}',  'pad' => 4],
        'Bozze fatture'    => ['column' => 'draft_invoice_group',   'format' => 'T-{{{id}}}', 'pad' => 4],
        'Preventivi'       => ['column' => 'default_quote_group',   'format' => 'P-{{{id}}}', 'pad' => 4],
        'Bozze preventivi' => ['column' => 'draft_quote_group',     'format' => 'PB-{{{id}}}','pad' => 4],
    ];

    public function up()
    {
        $companies = $this->db->table('ip_companies')->get()->getResultArray();

        foreach ($companies as $company) {
            $companyId = (int) $company['company_id'];

            $existing      = [];
            $existingIds   = [];
            foreach ($this->db->table('ip_invoice_groups')
                         ->where('company_id', $companyId)->get()->getResultArray() as $g) {
                $existing[(string) $g['invoice_group_name']]      = (int) $g['invoice_group_id'];
                $existingIds[(int) $g['invoice_group_id']]         = true;
            }

            $update = [];

            foreach (self::ROLES as $name => $role) {
                $groupId = $existing[$name]
                    ?? (int) $this->db->table('ip_invoice_groups')->insert([
                        'invoice_group_name'               => $name,
                        'invoice_group_identifier_format'  => $role['format'],
                        'invoice_group_next_id'           => 1,
                        'invoice_group_left_pad'          => $role['pad'],
                        'company_id'                      => $companyId,
                    ], true);

                // Si collega la colonna alla serie della relativa funzione
                // (fatture/bozze/prev/bozze prev), creandola se manca. La
                // colonna va riscritta in ogni caso: se puntava a un'altra
                // serie della stessa azienda (es. tutte alla serie "Fatture")
                // andava comunque corretta.
                $update[$role['column']] = $groupId;
            }

            if ($update) {
                $this->db->table('ip_companies')
                         ->where('company_id', $companyId)
                         ->update($update);
            }
        }
    }

    public function down()
    {
        // Non reversibile: non si distingue una serie creata qui da una preesistente.
    }
}
