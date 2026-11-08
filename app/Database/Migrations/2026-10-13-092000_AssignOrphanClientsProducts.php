<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Assegna all'azienda giusta i clienti e i prodotti nati senza company_id.
 *
 * ClientModel e ProductModel non avevano company_id fra gli $allowedFields:
 * withCompany() lo impostava e insert() lo scartava, quindi ogni cliente o
 * prodotto creato dal form finiva con company_id NULL, che per convenzione
 * vuol dire "archivio pre multi-azienda, visibile ovunque". Risultato: la
 * seconda azienda vedeva (e poteva fatturare a) i clienti della prima.
 *
 * Il recupero è prudente, perché NULL è anche un valore legittimo:
 *   - un cliente si assegna solo se è stato creato dopo che esisteva più di
 *     un'azienda (prima era archivio vero) e se tutte le sue fatture e i suoi
 *     preventivi stanno in un'unica azienda;
 *   - un prodotto (non ha data di creazione) solo se compare nelle righe di
 *     documenti di un'unica azienda.
 * Quello che resta ambiguo -- mai usato, o usato da più aziende -- rimane
 * NULL e visibile a tutte, come prima: meglio un cliente in più nella
 * tendina che un cliente sparito.
 */
class AssignOrphanClientsProducts extends Migration
{
    public function up()
    {
        // UPDATE ... FROM è sintassi PostgreSQL, l'unico motore su cui
        // l'applicazione gira oggi; su MySQL la migrazione non fa nulla.
        if ($this->db->DBDriver !== 'Postgre' || !$this->db->fieldExists('company_id', 'ip_clients')) {
            return;
        }

        $second = $this->db->query(
            'SELECT company_date_created FROM ip_companies ORDER BY company_date_created OFFSET 1 LIMIT 1'
        )->getRowArray();

        if ($second && $second['company_date_created'] !== null) {
            $this->db->query(
                'UPDATE ip_clients c SET company_id = d.company_id
                   FROM (
                        SELECT client_id, MIN(company_id) AS company_id
                          FROM (
                                SELECT client_id, company_id FROM ip_invoices WHERE company_id IS NOT NULL
                                UNION ALL
                                SELECT client_id, company_id FROM ip_quotes WHERE company_id IS NOT NULL
                          ) docs
                         GROUP BY client_id
                        HAVING COUNT(DISTINCT company_id) = 1
                   ) d
                  WHERE d.client_id = c.client_id
                    AND c.company_id IS NULL
                    AND c.client_date_created >= ?',
                [$second['company_date_created']]
            );
        }

        if ($this->db->fieldExists('company_id', 'ip_products')) {
            $this->db->query(
                'UPDATE ip_products p SET company_id = d.company_id
                   FROM (
                        SELECT product_id, MIN(company_id) AS company_id
                          FROM (
                                SELECT ii.item_product_id AS product_id, i.company_id
                                  FROM ip_invoice_items ii
                                  JOIN ip_invoices i ON i.invoice_id = ii.invoice_id
                                 WHERE ii.item_product_id IS NOT NULL AND i.company_id IS NOT NULL
                                UNION ALL
                                SELECT qi.item_product_id, q.company_id
                                  FROM ip_quote_items qi
                                  JOIN ip_quotes q ON q.quote_id = qi.quote_id
                                 WHERE qi.item_product_id IS NOT NULL AND q.company_id IS NOT NULL
                          ) docs
                         GROUP BY product_id
                        HAVING COUNT(DISTINCT company_id) = 1
                   ) d
                  WHERE d.product_id = p.product_id
                    AND p.company_id IS NULL'
            );
        }
    }

    public function down()
    {
        // Nessun ritorno: rimettere NULL renderebbe di nuovo i record
        // visibili a tutte le aziende.
    }
}
