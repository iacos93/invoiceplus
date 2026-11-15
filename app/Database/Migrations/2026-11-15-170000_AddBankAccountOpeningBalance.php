<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Saldo iniziale e data di apertura dei conti di saldo.
 *
 * Senza un punto di partenza il saldo di un conto è la somma dei movimenti
 * registrati nell'app, che non è il saldo della banca: mancano i soldi che
 * c'erano prima. Il saldo iniziale è quel numero — quello che si legge
 * dall'estratto conto il giorno in cui si comincia a tenere la prima nota —
 * e la data dice da quando vale, così i movimenti precedenti non lo sporcano.
 *
 * Vuoto significa «non dichiarato»: il conto si comporta come prima (parte da
 * zero) e l'elenco lo mostra senza numero invece di inventarne uno.
 */
class AddBankAccountOpeningBalance extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('bank_account_opening_balance', 'ip_bank_accounts')) {
            $this->forge->addColumn('ip_bank_accounts', [
                'bank_account_opening_balance' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '15,2',
                    'null'       => true,
                    'after'      => 'bank_account_bic',
                ],
                'bank_account_opening_date' => [
                    'type'  => 'DATE',
                    'null'  => true,
                    'after' => 'bank_account_opening_balance',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('bank_account_opening_balance', 'ip_bank_accounts')) {
            $this->forge->dropColumn('ip_bank_accounts', 'bank_account_opening_date');
            $this->forge->dropColumn('ip_bank_accounts', 'bank_account_opening_balance');
        }
    }
}
