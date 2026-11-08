<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Codice ModalitaPagamento (MP01…MP23) sul metodo di pagamento.
 *
 * Il codice serve a scrivere il blocco DatiPagamento della fattura
 * elettronica. Finora Invoices::generateXml lo leggeva da una variabile
 * d'ambiente per ogni metodo (IT_METODO_PAGAMENTO_ID_<id>_CODICE): un dato
 * anagrafico tenuto in .env, invisibile dall'applicazione e legato all'id
 * numerico del metodo, quindi diverso fra installazioni e perso alla prima
 * reinstallazione. In questo database quelle variabili non erano mai state
 * scritte, con la conseguenza che nessuna fattura riportava la modalita' di
 * pagamento ne' l'IBAN del conto di saldo: il nodo non veniva proprio creato.
 *
 * Il codice sta quindi sulla riga del metodo, dove chi lo configura lo vede e
 * lo puo' correggere. La lettura da .env resta come ripiego per le
 * installazioni che l'avevano gia' impostata (vedi PaymentMethodModel::sdiCodeFor).
 *
 * Backfill: solo i nomi che corrispondono senza ambiguita' a una modalita'
 * dell'Agenzia delle Entrate. Un codice sbagliato finirebbe in un documento
 * fiscale, quindi nel dubbio si lascia vuoto e lo sceglie l'utente.
 */
class AddPaymentMethodSdiCode extends Migration
{
    /** Nome normalizzato (minuscolo, senza spazi doppi) => codice MP. */
    private const BACKFILL = [
        'contanti'            => 'MP01',
        'assegno'             => 'MP02',
        'assegno bancario'    => 'MP02',
        'assegno circolare'   => 'MP03',
        'bonifico'            => 'MP05',
        'bonifico bancario'   => 'MP05',
        'carta di credito'    => 'MP08',
        'carta di pagamento'  => 'MP08',
        'bancomat'            => 'MP08',
        'rid'                 => 'MP09',
        'riba'                => 'MP12',
        'ricevuta bancaria'   => 'MP12',
        'mav'                 => 'MP13',
        'sdd'                 => 'MP19',
        'sepa direct debit'   => 'MP19',
        'pagopa'              => 'MP23',
    ];

    public function up()
    {
        if (!$this->db->fieldExists('payment_method_sdi_code', 'ip_payment_methods')) {
            $this->forge->addColumn('ip_payment_methods', [
                'payment_method_sdi_code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 4,
                    'null'       => true,
                ],
            ]);
        }

        foreach ($this->db->table('ip_payment_methods')->get()->getResultArray() as $method) {
            if (!empty($method['payment_method_sdi_code'])) {
                continue;
            }

            $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) $method['payment_method_name'])));

            if (isset(self::BACKFILL[$name])) {
                $this->db->table('ip_payment_methods')
                         ->where('payment_method_id', $method['payment_method_id'])
                         ->update(['payment_method_sdi_code' => self::BACKFILL[$name]]);
            }
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('payment_method_sdi_code', 'ip_payment_methods')) {
            $this->forge->dropColumn('ip_payment_methods', 'payment_method_sdi_code');
        }
    }
}
