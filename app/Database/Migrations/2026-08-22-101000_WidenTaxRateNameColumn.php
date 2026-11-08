<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ip_tax_rates.tax_rate_name era rimasto varchar(25) dallo schema CI3
 * originale, dimensionato per nomi come "IVA 22%". Con l'introduzione della
 * Natura IVA (migration precedente) i nomi delle aliquote sono diventati
 * descrittivi ("Esclusa – art. 15 DPR 633/72", 29 caratteri) e MySQL li
 * tronca in silenzio all'inserimento: nessun errore, nessun avviso, solo un
 * nome mutilato ("Esclusa – art. 15 DPR 633") che resta cosi' per sempre in
 * lista. Non e' un problema di layout della vista (li' il testo c'e' per
 * intero, la tabella si limita a mostrare quello che il database contiene):
 * il dato arriva gia' troncato dal database stesso. Da qui l'ampliamento a
 * varchar(100), la stessa dimensione usata altrove per i campi "nome"
 * (es. ip_clients.client_name).
 */
class WidenTaxRateNameColumn extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('ip_tax_rates', [
            // Senza la chiave 'name': con nome identico il driver PostgreSQL
            // genererebbe un ALTER TABLE ... RENAME sullo stesso nome, che
            // fallisce ("column already exists"). Qui cambia solo la larghezza.
            'tax_rate_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
        ]);
    }

    public function down()
    {
        // Nessun rollback della larghezza: tornare a varchar(25) troncherebbe
        // di nuovo silenziosamente i nomi gia' salvati piu' lunghi di 25
        // caratteri, lo stesso difetto che questa migration corregge.
    }
}
