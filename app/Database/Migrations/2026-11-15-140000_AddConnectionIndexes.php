<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Indici sulle colonne di collegamento rimaste scoperte.
 *
 * Le chiavi esterne dichiarate nel progetto sono quindici: tutto il resto
 * (righe documento, tributi, stati, metodi di pagamento, movimento bancario)
 * è collegato per convenzione, e su PostgreSQL una colonna `*_id` senza indice
 * si legge con una scansione dell'intera tabella. Con dodici fatture non si
 * sente; con qualche migliaio, ogni elenco e ogni riquadro della bacheca paga
 * il conto. Su MariaDB il problema è lo stesso: crea l'indice da sé quando la
 * chiave esterna è dichiarata, e qui quasi mai lo è.
 *
 * `CREATE INDEX nome ON tabella (colonna)` è valido su entrambi i motori, quindi
 * non serve SQL per motore; cambia solo la cancellazione (`down()`), perché
 * MariaDB vuole anche il nome della tabella.
 *
 * Cosa NON c'è qui: `company_id`. Compare in quasi ogni WHERE, ma da solo
 * seleziona un'azienda su poche: l'indice che serve è quello che la query
 * esistente già usa (stato, cliente, data), con l'azienda in testa. Va deciso
 * guardando i piani di esecuzione con dati veri, non aggiunto a tappeto.
 */
class AddConnectionIndexes extends Migration
{
    /**
     * Tabella => colonne da indicizzare, in ordine di quanto pesano.
     *
     * Solo colonne che compaiono in WHERE o JOIN: le altre (un contatore
     * progressivo, un id di credenziale) restano senza, perché un indice che
     * nessuna query usa si paga in scrittura e non rende niente.
     */
    private const INDICI = [
        'ip_invoices'           => ['invoice_status_id', 'user_id', 'bank_account_id', 'creditinvoice_parent_id'],
        'ip_invoice_items'      => ['item_product_id', 'item_tax_rate_id', 'item_product_unit_id', 'item_family_id'],
        'ip_quotes'             => ['quote_status_id', 'user_id'],
        'ip_quote_items'        => ['item_product_id', 'item_tax_rate_id', 'item_product_unit_id'],
        'ip_invoice_tax_rates'  => ['tax_rate_id'],
        'ip_quote_tax_rates'    => ['tax_rate_id'],
        'ip_payments'           => ['payment_method_id'],
        'ip_products'           => ['tax_rate_id', 'unit_id'],
        'ip_bank_transactions'  => ['invoice_id', 'expense_id'],
        'ip_f24'                => ['bank_account_id'],
        'ip_delivery_notes'     => ['invoice_group_id'],
        'ip_client_notes'       => ['client_id'],
        'ip_clients'            => ['client_vat_id'],
        'ip_email_tracks'       => ['track_company_id'],
        'ip_import_details'     => ['import_record_id'],
        'ip_merchant_responses' => ['invoice_id'],
        'ip_tasks'              => ['tax_rate_id'],
        'ip_user_clients'       => ['user_id'],
    ];

    public function up(): void
    {
        foreach (self::INDICI as $tabella => $colonne) {
            if (! $this->db->tableExists($tabella)) {
                continue;
            }

            // Un indice che comincia con la colonna la copre già: la tabella
            // può averne uno dalla versione precedente o da una mano esterna.
            $coperte = $this->colonneConIndice($tabella);
            $testo   = $this->colonneDiTesto($tabella);

            foreach ($colonne as $colonna) {
                if (isset($coperte[$colonna]) || ! $this->db->fieldExists($colonna, $tabella)) {
                    continue;
                }

                $this->db->query(
                    'CREATE INDEX ' . $this->nomeIndice($tabella, $colonna)
                    . ' ON ' . $tabella . ' (' . $colonna . (isset($testo[$colonna]) ? '(64)' : '') . ')'
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDICI as $tabella => $colonne) {
            if (! $this->db->tableExists($tabella)) {
                continue;
            }

            $presenti = $this->colonneConIndice($tabella);

            foreach ($colonne as $colonna) {
                if (($presenti[$colonna] ?? null) !== $this->nomeIndice($tabella, $colonna)) {
                    continue;
                }

                $nome = $this->nomeIndice($tabella, $colonna);

                // PostgreSQL vuole il solo nome dell'indice, MariaDB anche la
                // tabella. `IF EXISTS` esiste in PostgreSQL e non in MariaDB,
                // che però qui non serve: si cancella solo quel che si è visto.
                $this->db->query(
                    $this->db->DBDriver === 'Postgre'
                        ? 'DROP INDEX IF EXISTS ' . $nome
                        : 'DROP INDEX ' . $nome . ' ON ' . $tabella
                );
            }
        }
    }

    /**
     * Nome dell'indice, unico in tutto lo schema: MariaDB lo vuole unico per
     * tabella, PostgreSQL per intero database.
     */
    private function nomeIndice(string $tabella, string $colonna): string
    {
        $breve = str_starts_with($tabella, 'ip_') ? substr($tabella, 3) : $tabella;

        return 'idx_' . $breve . '_' . $colonna;
    }

    /**
     * Colonna => nome dell'indice che la copre come primo campo.
     *
     * @return array<string, string>
     */
    private function colonneConIndice(string $tabella): array
    {
        $coperte = [];

        foreach ($this->db->getIndexData($tabella) as $indice) {
            $prima = $indice->fields[0] ?? null;

            if ($prima !== null && $prima !== '') {
                $coperte[$prima] = $indice->name;
            }
        }

        return $coperte;
    }

    /**
     * Colonne TEXT/BLOB della tabella, solo fuori da PostgreSQL: MySQL non
     * indicizza un TEXT senza lunghezza di prefisso e l'installazione pulita
     * si fermava qui (client_vat_id). 64 caratteri bastano a distinguere una
     * partita IVA o un codice fiscale; PostgreSQL indicizza il TEXT intero.
     *
     * @return array<string, true>
     */
    private function colonneDiTesto(string $tabella): array
    {
        if ($this->db->DBDriver === 'Postgre') {
            return [];
        }

        $testo = [];

        foreach ($this->db->getFieldData($tabella) as $campo) {
            if (preg_match('/text|blob/i', (string) $campo->type) === 1) {
                $testo[$campo->name] = true;
            }
        }

        return $testo;
    }
}
