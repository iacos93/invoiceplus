<?php

namespace App\Database\Migrations;

use App\Database\IndexCatalog;
use CodeIgniter\Database\Migration;

/**
 * Indici mancanti su colonne usate in JOIN/WHERE reali, sfuggiti al primo
 * giro (AddPerformanceIndexes, 2026-08-18): quello si e' concentrato su
 * company_id e sulle date dei documenti principali, ma non ha coperto le
 * relazioni secondarie sotto — verificate con EXPLAIN, non per sospetto:
 *
 * - ip_expenses.supplier_id: SupplierModel fa left join su questa colonna
 *   per il totale spese di ogni fornitore (scheda fornitore, lista
 *   fornitori), e ExpenseModel::forSupplier() la filtra direttamente.
 *   Senza indice ogni pagina fornitore scansiona l'intera tabella spese.
 * - ip_invoice_items.item_task_id: TaskModel la usa per risalire dalla
 *   attivita' alla riga fattura che l'ha fatturata.
 * - ip_projects.client_id / ip_tasks.project_id: ProjectModel e TaskModel
 *   fanno join lungo tutta la catena tasks -> projects -> clients (serve
 *   per lo scope azienda, dato che ip_projects non ha company_id proprio),
 *   e nessuna delle due colonne aveva un indice: ogni lista progetti,
 *   riepilogo attivita' e la pagina progetto scansionavano le due tabelle
 *   per intero.
 * - ip_user_clients.client_id: l'indice composito esistente (user_id,
 *   client_id) copre le ricerche per utente, ma ClientModel::delete()
 *   filtra per solo client_id quando elimina un cliente — fuori dal
 *   prefisso sinistro dell'indice composito, quindi scan completo ad ogni
 *   cancellazione cliente.
 */
class AddMissingRelationIndexes extends Migration
{
    public function up()
    {
        IndexCatalog::ensure($this->db, IndexCatalog::RELATIONS);
    }

    public function down()
    {
        IndexCatalog::drop($this->db, IndexCatalog::RELATIONS);
    }
}
