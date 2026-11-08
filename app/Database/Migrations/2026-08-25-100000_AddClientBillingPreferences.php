<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Preferenze di fatturazione per cliente, ispirate alla sezione
 * "Rapporti Commerciali" di Fatture in Cloud: aliquota IVA
 * predefinita, metodo di pagamento, termini di pagamento (giorni)
 * e sconto predefinito. Quando il campo e' valorizzato sul cliente
 * sovrascrive il default globale (ip_settings) nella creazione di
 * nuove fatture.
 */
class AddClientBillingPreferences extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ip_clients', [
            'client_default_tax_rate_id' => [
                'type'       => 'INT',
                'null'       => true,
                'default'    => null,
                'after'      => 'client_split_payment',
            ],
            'client_default_payment_method_id' => [
                'type'       => 'INT',
                'null'       => true,
                'default'    => null,
                'after'      => 'client_default_tax_rate_id',
            ],
            'client_payment_days' => [
                'type'       => 'INT',
                'null'       => true,
                'default'    => null,
                'after'      => 'client_default_payment_method_id',
            ],
            'client_discount_default' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
                'default'    => null,
                'after'      => 'client_payment_days',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ip_clients', [
            'client_default_tax_rate_id',
            'client_default_payment_method_id',
            'client_payment_days',
            'client_discount_default',
        ]);
    }
}
