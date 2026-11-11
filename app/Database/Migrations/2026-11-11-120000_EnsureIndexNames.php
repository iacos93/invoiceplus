<?php

namespace App\Database\Migrations;

use App\Database\IndexCatalog;
use CodeIgniter\Database\Migration;

/**
 * Completa gli indici che le prime due migrazioni credevano di aver creato.
 *
 * Su PostgreSQL il nome di un indice e' unico per schema: `CREATE INDEX IF
 * NOT EXISTS idx_client ON ip_quotes (...)` non faceva niente se `idx_client`
 * esisteva gia' su ip_invoices, e non lo segnalava. Cosi' solo la prima
 * tabella di ogni nome restava indicizzata. IndexCatalog usa nomi
 * `<tabella>_<nome>`, che non possono collidere.
 *
 * Serve per i database che hanno gia' eseguito le migrazioni vecchie: li'
 * quelle righe sono gia' passate e non verranno rieseguite, quindi gli indici
 * mancanti vanno creati qui. Su un'installazione nuova non trova niente da
 * fare: IndexCatalog::ensure() salta quello che c'e' gia'.
 */
class EnsureIndexNames extends Migration
{
    public function up()
    {
        IndexCatalog::ensure($this->db, IndexCatalog::all());
    }

    public function down()
    {
        // Non si toglie niente: gli indici creati qui sono quelli che le
        // migrazioni precedenti volevano creare, e la loro discesa li toglie.
    }
}
