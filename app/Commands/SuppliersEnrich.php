<?php

namespace App\Commands;

use App\Libraries\FatturaXml;
use App\Libraries\FatturaXmlStore;
use App\Models\SupplierModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Riempie le anagrafiche fornitori con i dati delle fatture elettroniche già
 * ricevute.
 *
 * L'importazione scrive i dati del fornitore quando il documento entra; le
 * fatture importate prima che lo facesse — e i fornitori creati a mano dal form
 * della spesa, che chiede solo nome, P.IVA e codice fiscale — sono rimasti
 * senza indirizzo, comune, CAP, email e IBAN, pur avendo in archivio il
 * documento che li contiene. Questa è la passata che li riporta dentro, con la
 * stessa regola dell'importazione: si scrive solo dove il campo è vuoto
 * (SupplierModel::emptyFields), mai sopra un valore già presente.
 *
 * Vince l'XML più recente per data del documento: un fornitore con più sedi
 * emette dallo stabilimento, e l'ultima fattura dice dove si trova adesso.
 * Niente viene dedotto: il codice destinatario di un documento ricevuto è il
 * nostro, non quello del fornitore, quindi il SIA/SDI resta vuoto.
 */
class SuppliersEnrich extends BaseCommand
{
    protected $group       = 'Manutenzione';
    protected $name        = 'suppliers:enrich';
    protected $description = 'Riempie i campi vuoti dei fornitori leggendo gli XML delle spese già importate.';
    protected $usage       = 'suppliers:enrich [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Mostra il diff e non scrive niente',
    ];

    /** Colonna di ip_suppliers => chiave restituita da FatturaXml::parse(). */
    private const MAP = [
        'supplier_address_1' => 'supplier_address',
        'supplier_city'      => 'supplier_city',
        'supplier_state'     => 'supplier_state',
        'supplier_zip'       => 'supplier_zip',
        'supplier_country'   => 'supplier_country',
        'supplier_email'     => 'supplier_email',
        'supplier_phone'     => 'supplier_phone',
        'supplier_iban'      => 'supplier_iban',
    ];

    public function run(array $params)
    {
        $dryRun = (bool) CLI::getOption('dry-run');

        $model     = new SupplierModel();
        $suppliers = $model->getAllOrdered();
        $byId      = [];

        foreach ($suppliers as $supplier) {
            $byId[(int) $supplier['supplier_id']] = $supplier;
        }

        if ($byId === []) {
            CLI::write('Nessun fornitore nell\'azienda attiva.');

            return EXIT_SUCCESS;
        }

        $letti  = $this->readFromXml();
        $updates = [];

        foreach ($letti['values'] as $supplierId => $candidate) {
            if (! isset($byId[$supplierId])) {
                continue;
            }

            $patch = SupplierModel::emptyFields($byId[$supplierId], $candidate);

            if ($patch !== []) {
                $updates[$supplierId] = $patch;
            }
        }

        CLI::write(sprintf(
            'XML letti: %d · spese senza file: %d · XML illeggibili: %d',
            $letti['xml'],
            $letti['missing'],
            $letti['unreadable']
        ), 'yellow');
        CLI::write(sprintf(
            'Fornitori aggiornabili: %d su %d',
            count($updates),
            count($byId)
        ));

        foreach ($updates as $supplierId => $patch) {
            CLI::write('');
            CLI::write('#' . $supplierId . ' ' . $byId[$supplierId]['supplier_name']);

            foreach ($patch as $field => $value) {
                $attuale = trim((string) ($byId[$supplierId][$field] ?? ''));
                CLI::write('   ' . str_pad($field, 20) . ($attuale === '' ? '(vuoto)' : $attuale) . ' → ' . $value);
            }
        }

        if ($updates === []) {
            return EXIT_SUCCESS;
        }

        if ($dryRun) {
            CLI::write('');
            CLI::write('Dry run: nessuna modifica scritta. Rilanciare senza --dry-run per applicare.', 'yellow');

            return EXIT_SUCCESS;
        }

        $campi = 0;

        foreach ($updates as $supplierId => $patch) {
            $model->saveRecord($supplierId, $patch);
            $campi += count($patch);
        }

        CLI::write('');
        CLI::write(sprintf('Aggiornati %d fornitori (%d campi).', count($updates), $campi), 'green');

        return EXIT_SUCCESS;
    }

    /**
     * Dati del cedente ricavati dagli XML delle spese dell'azienda attiva.
     *
     * Le spese si leggono in ordine di data crescente e, per ogni campo, vince
     * l'ultimo documento: è la stessa regola con cui si guarda l'ultima fattura
     * ricevuta per sapere dove sta il fornitore oggi.
     *
     * @return array{values: array<int, array<string, string>>, xml: int, missing: int, unreadable: int}
     */
    private function readFromXml(): array
    {
        $reader = new FatturaXml();
        $store  = new FatturaXmlStore();
        $db     = \Config\Database::connect();

        $righe = scope_builder_to_company(
            $db->table('ip_expenses')
               ->select('expense_id, supplier_id')
               ->where('supplier_id >', 0)
               ->orderBy('expense_date', 'ASC')
               ->orderBy('expense_id', 'ASC'),
            'ip_expenses'
        )->get()->getResultArray();

        $values = [];
        $stats  = ['xml' => 0, 'missing' => 0, 'unreadable' => 0];

        foreach ($righe as $riga) {
            $expenseId  = (int) $riga['expense_id'];
            $supplierId = (int) $riga['supplier_id'];
            $xml        = $store->has($expenseId) ? $store->get($expenseId) : null;

            if ($xml === null || $xml === '') {
                $stats['missing']++;

                continue;
            }

            $parsed = $reader->parse($xml);

            if ($parsed === null) {
                $stats['unreadable']++;

                continue;
            }

            $stats['xml']++;

            foreach (self::MAP as $column => $key) {
                $value = trim((string) ($parsed[$key] ?? ''));

                if ($value !== '') {
                    $values[$supplierId][$column] = $value;
                }
            }
        }

        return ['values' => $values] + $stats;
    }
}
