<?php

namespace App\Database\Migrations;

use App\Libraries\FatturaXml;
use App\Libraries\FatturaXmlStore;
use CodeIgniter\Database\Migration;

/**
 * Riallinea le spese già importate da XML al trattamento per tipo documento.
 *
 * Fino a questa versione l'importer ignorava TipoDocumento: le note di
 * credito ricevute (TD04/TD08) entravano come costi positivi, e il report
 * Utile e imposte le sommava invece di sottrarle; le autofatture e
 * integrazioni per inversione contabile (TD16-TD19, TD28) portavano l'IVA
 * fra i costi. L'XML originale è archiviato accanto a ogni spesa importata
 * (FatturaXmlStore), quindi lo si rilegge con il parser corrente e si
 * riscrivono solo imponibile, imposta e totale -- non il pagato, che è un
 * dato inserito a mano.
 *
 * Si toccano soltanto le spese di quei tipi documento: le fatture ordinarie
 * importate prima restano come sono, anche se un parser diverso desse oggi
 * un arrotondamento diverso.
 */
class FixImportedCreditNoteSigns extends Migration
{
    public function up()
    {
        $reader = new FatturaXml();
        $store  = new FatturaXmlStore();
        $types  = array_merge(FatturaXml::CREDIT_NOTE_TYPES, FatturaXml::REVERSE_CHARGE_TYPES);

        $rows = $this->db->table('ip_expenses')
            ->select('expense_id, expense_amount, expense_tax, expense_total, expense_note')
            ->where('expense_source', 'xml')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $xml    = $store->get((int) $row['expense_id']);
            $parsed = $xml !== null ? $reader->parse($xml) : null;

            if ($parsed === null || !in_array($parsed['document_type'], $types, true)) {
                continue;
            }

            $update = [
                'expense_amount' => $parsed['amount'],
                'expense_tax'    => $parsed['tax'],
                'expense_total'  => $parsed['total'],
            ];

            if (trim((string) $row['expense_note']) === '') {
                $update['expense_note'] = in_array($parsed['document_type'], FatturaXml::CREDIT_NOTE_TYPES, true)
                    ? 'Nota di credito (' . $parsed['document_type'] . '): importo registrato in negativo.'
                    : 'Autofattura/integrazione ' . $parsed['document_type'] . ' (inversione contabile): IVA a debito e a credito, non registrata come costo.';
            }

            $this->db->table('ip_expenses')->where('expense_id', $row['expense_id'])->update($update);
        }

        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }
    }

    public function down()
    {
        // Nessun ritorno: rimettere in positivo una nota di credito
        // ricostruirebbe solo l'errore.
    }
}
