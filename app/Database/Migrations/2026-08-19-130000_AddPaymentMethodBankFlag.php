<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Flag "richiede conto bancario" sui metodi di pagamento.
 *
 * Metodo di pagamento (globale, es. Bonifico/Contanti/RID) e conto di saldo
 * (per azienda, l'IBAN specifico) sono due anagrafiche distinte e non vanno
 * fuse — vedi analisi in chat. Il punto debole era solo l'UI: sul form
 * fattura le due select stavano affiancate senza indicare che per un metodo
 * "in contanti" il conto di saldo non ha senso. Questo flag per-metodo
 * permette al form di nascondere il campo conto quando non serve, invece di
 * indovinarlo dal nome del metodo (testo libero, non affidabile per un
 * confronto).
 *
 * Default 1 (richiede conto): i metodi esistenti sono per lo piu' bancari
 * (bonifico, RID, assegno circolare...), quindi il default non nasconde per
 * errore un campo che serviva.
 */
class AddPaymentMethodBankFlag extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('payment_method_requires_bank_account', 'ip_payment_methods')) {
            $this->forge->addColumn('ip_payment_methods', [
                'payment_method_requires_bank_account' => [
                    'type'       => 'BOOLEAN',
                    'null'       => false,
                    'default'    => true,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('payment_method_requires_bank_account', 'ip_payment_methods')) {
            $this->forge->dropColumn('ip_payment_methods', 'payment_method_requires_bank_account');
        }
    }
}
