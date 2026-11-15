<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Codice destinatario SdI dell'azienda.
 *
 * L'azienda emette fatture elettroniche ma finora non aveva un posto dove
 * scrivere il proprio codice destinatario: il dato esisteva solo per i clienti
 * (`client_sdi_code`), cioè per chi le fatture le riceve. Il codice proprio
 * serve per farsi mandare le fatture (fornitori, commercialista, portale) e
 * per scriverlo dove serve senza andarlo a cercare altrove.
 */
class AddCompanySdiCode extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('company_sdi_code', 'ip_companies')) {
            return;
        }

        $this->forge->addColumn('ip_companies', [
            'company_sdi_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 7,
                'null'       => true,
                'after'      => 'company_tax_code',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('company_sdi_code', 'ip_companies')) {
            $this->forge->dropColumn('ip_companies', 'company_sdi_code');
        }
    }
}
