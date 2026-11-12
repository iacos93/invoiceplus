<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Popola l'anagrafica fornitori dalle spese gia' registrate e collega le spese
 * al fornitore corrispondente.
 *
 * Senza questo passaggio la sezione Fornitori nascerebbe vuota anche in
 * un'installazione con anni di spese alle spalle, e i totali per fornitore
 * partirebbero da zero.
 *
 * Il raggruppamento e' per (azienda, partita IVA) quando la P.IVA c'e' — e'
 * l'identificativo affidabile — altrimenti per (azienda, nome).
 */
class BackfillSuppliers extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_suppliers') || !$this->db->fieldExists('supplier_id', 'ip_expenses')) {
            return;
        }

        $rows = $this->db->table('ip_expenses')
            ->select('company_id, supplier_name, supplier_vat, supplier_tax_code')
            ->where('supplier_id IS NULL', null, false)
            ->where("TRIM(COALESCE(supplier_name, '')) <>", '')
            ->get()
            ->getResultArray();

        $now  = date('Y-m-d H:i:s');
        $seen = [];

        foreach ($rows as $row) {
            $companyId = $row['company_id'] !== null ? (int) $row['company_id'] : null;
            $vat       = trim((string) $row['supplier_vat']);
            $name      = trim((string) $row['supplier_name']);
            $key       = $companyId . '|' . ($vat !== '' ? 'p:' . mb_strtolower($vat) : 'n:' . mb_strtolower($name));

            if (isset($seen[$key])) {
                continue;
            }

            $existing = $this->db->table('ip_suppliers');
            $existing = $companyId === null
                ? $existing->where('company_id IS NULL', null, false)
                : $existing->where('company_id', $companyId);

            $existing = $vat !== ''
                ? $existing->where('supplier_vat_id', $vat)->get()->getRowArray()
                : $existing->where('supplier_name', $name)->get()->getRowArray();

            if ($existing) {
                $seen[$key] = (int) $existing['supplier_id'];
                continue;
            }

            $this->db->table('ip_suppliers')->insert([
                'supplier_name'         => $name,
                'supplier_vat_id'       => $vat !== '' ? $vat : null,
                'supplier_tax_code'     => trim((string) $row['supplier_tax_code']) ?: null,
                // true e non 1: su PostgreSQL la colonna è boolean e un
                // intero fa fallire l'INSERT su un'installazione nuova.
                'supplier_active'       => true,
                'company_id'            => $companyId,
                'supplier_date_created' => $now,
                'supplier_date_modified' => $now,
            ]);

            $seen[$key] = (int) $this->db->insertID();
        }

        // Collegamento delle spese ai fornitori appena creati.
        foreach ($seen as $key => $supplierId) {
            [$companyPart, $idPart] = explode('|', $key, 2);
            $companyId = $companyPart === '' ? null : (int) $companyPart;

            $builder = $this->db->table('ip_expenses')->where('supplier_id IS NULL', null, false);

            $builder = $companyId === null
                ? $builder->where('company_id IS NULL', null, false)
                : $builder->where('company_id', $companyId);

            if (str_starts_with($idPart, 'p:')) {
                $builder->where('LOWER(TRIM(supplier_vat))', substr($idPart, 2));
            } else {
                $builder->where('LOWER(TRIM(supplier_name))', substr($idPart, 2));
            }

            $builder->update(['supplier_id' => $supplierId]);
        }
    }

    public function down()
    {
        // I fornitori creati qui non si distinguono da quelli inseriti a mano:
        // il rollback si limita a staccare i collegamenti.
        if ($this->db->fieldExists('supplier_id', 'ip_expenses')) {
            $this->db->table('ip_expenses')->update(['supplier_id' => null], ['supplier_id IS NOT NULL' => null]);
        }
    }
}
