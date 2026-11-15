<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Conto di saldo sugli incassi, e conto predefinito sui metodi di pagamento.
 *
 * Le spese sanno da quale banca o cassa sono uscite (`expense_amount` +
 * `bank_account_id`), gli incassi no: si poteva dire quanto è entrato in
 * totale, non su quale conto. È il pezzo che manca perché il saldo di una
 * banca o di una cassa possa tornare, ed è la premessa della prima nota.
 *
 * Il conto non lo si sceglie ogni volta: ogni metodo di pagamento può indicarne
 * uno (il bonifico va sul conto corrente, i contanti sulla cassa), e il form
 * dell'incasso lo propone. Se il metodo non ne ha uno, vale il conto
 * predefinito dell'azienda (vedi default_bank_account_for_payment()).
 */
class AddPaymentBankAccount extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('bank_account_id', 'ip_payments')) {
            $this->forge->addColumn('ip_payments', [
                'bank_account_id' => [
                    'type'    => 'INT',
                    'null'    => true,
                    'after'   => 'payment_method_id',
                ],
            ]);

            // Il conto si filtra in ogni saldo e in ogni prima nota: senza
            // indice sarebbe una scansione di ip_payments per conto.
            $this->db->query('CREATE INDEX idx_payments_bank_account_id ON ip_payments (bank_account_id)');
        }

        if (! $this->db->fieldExists('payment_method_bank_account_id', 'ip_payment_methods')) {
            $this->forge->addColumn('ip_payment_methods', [
                'payment_method_bank_account_id' => [
                    'type'  => 'INT',
                    'null'  => true,
                    'after' => 'payment_method_requires_bank_account',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('bank_account_id', 'ip_payments')) {
            $indici = $this->db->getIndexData('ip_payments');

            if (isset($indici['idx_payments_bank_account_id'])) {
                // PostgreSQL vuole il solo nome, MariaDB anche la tabella.
                $this->db->query(
                    $this->db->DBDriver === 'Postgre'
                        ? 'DROP INDEX IF EXISTS idx_payments_bank_account_id'
                        : 'DROP INDEX idx_payments_bank_account_id ON ip_payments'
                );
            }

            $this->forge->dropColumn('ip_payments', 'bank_account_id');
        }

        if ($this->db->fieldExists('payment_method_bank_account_id', 'ip_payment_methods')) {
            $this->forge->dropColumn('ip_payment_methods', 'payment_method_bank_account_id');
        }
    }
}
