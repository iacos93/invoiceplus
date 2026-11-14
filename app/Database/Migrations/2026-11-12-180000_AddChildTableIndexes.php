<?php

namespace App\Database\Migrations;

use App\Database\IndexCatalog;
use CodeIgniter\Database\Migration;

/**
 * Indici sulle chiavi esterne delle tabelle figlie (importi, righe, aliquote,
 * pagamenti, ricorrenze, allegati).
 *
 * Le migrazioni precedenti dichiaravano le chiavi esterne e si fermavano li':
 * su MariaDB basta, perche' InnoDB indicizza da solo la colonna del vincolo;
 * su PostgreSQL il vincolo non crea nessun indice. Controllando pg_indexes sul
 * database di sviluppo, ip_invoice_amounts, ip_invoice_items,
 * ip_invoice_tax_rates e le gemelle ip_quote_* avevano solo la chiave primaria.
 *
 * Con un archivio piccolo non si nota; il costo cresce con le righe, perche'
 * ogni lista documenti e ogni calculate() dei totali passano da questi JOIN.
 * L'elenco e il motivo di ogni voce stanno in IndexCatalog::CHILDREN.
 */
class AddChildTableIndexes extends Migration
{
    public function up()
    {
        IndexCatalog::ensure($this->db, IndexCatalog::CHILDREN);
    }

    public function down()
    {
        IndexCatalog::drop($this->db, IndexCatalog::CHILDREN);
    }
}
