<?php

namespace App\Database\Migrations;

use App\Models\PaymentMethodModel;
use CodeIgniter\Database\Migration;

/**
 * Completa l'anagrafica dei metodi di pagamento con tutte le modalità SdI.
 *
 * ip_payment_methods è un vocabolario globale e un'installazione nata con la
 * vecchia lista di cinque metodi non aveva, per esempio, MAV, RID o PagoPA:
 * chi ne aveva bisogno doveva crearli a mano, e senza codice MP la fattura
 * usciva priva del blocco DatiPagamento. L'elenco vive in
 * PaymentMethodModel::standardMethods(), la stessa lista che usa il seeding
 * di una nuova azienda, così le due non possono divergere.
 *
 * Si aggiungono solo i metodi mancanti. Un metodo già presente si lascia
 * com'è — anche con un nome diverso, come «Carta di credito» per MP08 —
 * perché è anagrafica dell'utente e può essere già usata da fatture e
 * incassi. Il confronto è su nome *e* codice: due metodi possono condividere
 * la stessa modalità (MP08 per carta di credito e Carta del docente).
 */
class AddStandardPaymentMethods extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('ip_payment_methods')
            || ! $this->db->fieldExists('payment_method_sdi_code', 'ip_payment_methods')) {
            return;
        }

        $rows = $this->db->table('ip_payment_methods')
            ->select('payment_method_name, payment_method_sdi_code')
            ->get()
            ->getResultArray();

        $names = [];
        $codes = [];

        foreach ($rows as $row) {
            $names[mb_strtolower(trim((string) ($row['payment_method_name'] ?? '')))] = true;

            $code = trim((string) ($row['payment_method_sdi_code'] ?? ''));

            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        foreach (PaymentMethodModel::standardMethods() as $method) {
            $name = mb_strtolower($method['name']);

            // Nome o codice già in uso: il metodo c'è e non va duplicato.
            if (isset($names[$name]) || isset($codes[$method['sdi_code']])) {
                continue;
            }

            $this->db->table('ip_payment_methods')->insert([
                'payment_method_name'                  => $method['name'],
                'payment_method_sdi_code'              => $method['sdi_code'],
                'payment_method_requires_bank_account' => $method['requires_bank_account'],
            ]);

            $names[$name]                            = true;
            $codes[$method['sdi_code']]              = true;
        }
    }

    public function down(): void
    {
        // I metodi aggiunti possono essere già finiti su fatture e incassi:
        // toglierli lascerebbe quei documenti senza la modalità di pagamento.
        // Si lascia l'anagrafica com'è.
    }
}
