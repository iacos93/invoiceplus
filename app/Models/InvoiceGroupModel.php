<?php

namespace App\Models;

/**
 * InvoiceGroupModel
 *
 * Porting CI4 del modulo invoice_groups (CI3: Mdl_Invoice_Groups).
 * Include la logica di generazione numero fattura (generate_invoice_number,
 * parse_identifier_format, set_next_invoice_number), usata dal futuro
 * modulo "invoices" — porting 1:1 dal comportamento originale.
 */
class InvoiceGroupModel extends IpBaseModel
{
    protected $table            = 'ip_invoice_groups';
    protected $primaryKey       = 'invoice_group_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'invoice_group_name',
        'invoice_group_identifier_format',
        'invoice_group_next_id',
        'invoice_group_left_pad',
        'company_id',
    ];

    protected $validationRules = [
        'invoice_group_name'               => 'required',
        'invoice_group_identifier_format'  => 'required',
        'invoice_group_next_id'            => 'required|numeric',
        'invoice_group_left_pad'           => 'required|numeric',
    ];

    protected $validationMessages = [
        'invoice_group_name' => [
            'required' => 'Il nome e\' obbligatorio',
        ],
        'invoice_group_identifier_format' => [
            'required' => 'Il formato identificatore e\' obbligatorio',
        ],
        'invoice_group_next_id' => [
            'required' => 'Il prossimo numero e\' obbligatorio',
            'numeric'  => 'Il prossimo numero deve essere un numero',
        ],
        'invoice_group_left_pad' => [
            'required' => 'Lo zero padding e\' obbligatorio',
            'numeric'  => 'Lo zero padding deve essere un numero',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->scopeToCompany()->orderBy('invoice_group_name', 'ASC');

        return $this->listFetch($perPage, $page);
    }
    /**
     * Quanti documenti sono agganciati alla serie.
     *
     * Volutamente non filtrato per azienda: una serie ancora usata dai
     * documenti di un'altra società non è comunque cancellabile, e limitare
     * il conteggio all'azienda attiva darebbe il via libera proprio nel caso
     * in cui il danno non si vede.
     */
    public function documentCount(int $invoiceGroupId): int
    {
        return $this->db->table('ip_invoices')->where('invoice_group_id', $invoiceGroupId)->countAllResults()
             + $this->db->table('ip_quotes')->where('invoice_group_id', $invoiceGroupId)->countAllResults();
    }

    /**
     * Serie da usare quando il documento di partenza non ne indica una.
     *
     * Serve perché company_setting() considera valido lo zero: una azienda con
     * default_invoice_group = 0 (il valore con cui nasce) faceva scattare il
     * fallback mai, e la conversione preventivo → fattura creava documenti con
     * invoice_group_id = 0, cioè una serie che non esiste e che non sa
     * generare un numero.
     *
     * @param int    $preferred serie del documento di origine, 0 se assente
     * @param string $setting   'default_invoice_group' oppure 'default_quote_group'
     */
    public function defaultGroupId(int $preferred = 0, string $setting = 'default_invoice_group'): int
    {
        $companyId = active_company_id();

        foreach ([$preferred, (int) company_setting($setting, 0)] as $candidate) {
            if ($candidate > 0 && $this->existsForCompany($candidate, $companyId)) {
                return $candidate;
            }
        }

        $row = $this->scopeToCompany()->orderBy('invoice_group_id', 'ASC')->first();

        return (int) ($row['invoice_group_id'] ?? 0);
    }

    private function existsForCompany(int $invoiceGroupId, ?int $companyId): bool
    {
        $builder = $this->db->table('ip_invoice_groups')->where('invoice_group_id', $invoiceGroupId);

        if ($companyId) {
            $builder->groupStart()
                    ->where('company_id', $companyId)
                    ->orWhere('company_id IS NULL', null, false)
                    ->groupEnd();
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Genera il prossimo numero fattura secondo il formato configurato
     * e (opzionalmente) incrementa il contatore. Porting 1:1 da CI3.
     */
    public function generateInvoiceNumber(int $invoiceGroupId, bool $setNext = true): string
    {
        // Lettura e incremento devono stare nella stessa transazione, con la
        // riga bloccata: find() e setNextInvoiceNumber() sono due query
        // separate, quindi due richieste che finalizzano un documento sulla
        // stessa serie nello stesso istante leggevano entrambe lo stesso
        // next_id e producevano DUE FATTURE CON LO STESSO NUMERO, saltandone
        // uno (il contatore avanza comunque due volte). Sulla numerazione
        // progressiva non e' un inconveniente, e' un requisito di legge.
        //
        // Il lock si prende solo quando si incrementa davvero: con
        // $setNext = false questo metodo serve solo a mostrare l'anteprima
        // della serie, e bloccare la riga per una lettura sarebbe contesa
        // inutile.
        if (!$setNext) {
            $invoiceGroup = $this->find($invoiceGroupId);

            if (!$invoiceGroup) {
                return '';
            }

            return $this->parseIdentifierFormat(
                (string) ($invoiceGroup['invoice_group_identifier_format'] ?? ''),
                (int) ($invoiceGroup['invoice_group_next_id'] ?? 1),
                (int) ($invoiceGroup['invoice_group_left_pad'] ?? 0)
            );
        }

        $this->db->transStart();

        // SELECT ... FOR UPDATE: una seconda richiesta concorrente attende qui
        // invece di leggere un next_id gia' assegnato ma non ancora scritto.
        $invoiceGroup = $this->db->query(
            'SELECT * FROM ' . $this->table . ' WHERE ' . $this->primaryKey . ' = ? FOR UPDATE',
            [$invoiceGroupId]
        )->getRowArray();

        if (!$invoiceGroup) {
            $this->db->transComplete();

            return '';
        }

        // Il formato può essere NULL su gruppi creati prima che fosse
        // obbligatorio: passarlo così com'è fa esplodere parseIdentifierFormat().
        $invoiceIdentifier = $this->parseIdentifierFormat(
            (string) ($invoiceGroup['invoice_group_identifier_format'] ?? ''),
            (int) ($invoiceGroup['invoice_group_next_id'] ?? 1),
            (int) ($invoiceGroup['invoice_group_left_pad'] ?? 0)
        );

        $this->setNextInvoiceNumber($invoiceGroupId);

        $this->db->transComplete();

        return $invoiceIdentifier;
    }

    /**
     * Numero che il gruppo produrrebbe adesso, senza incrementare il
     * contatore: usato dalle schermate per mostrare l'anteprima della serie.
     */
    public function previewNumber(array $invoiceGroup): string
    {
        return $this->parseIdentifierFormat(
            (string) ($invoiceGroup['invoice_group_identifier_format'] ?? ''),
            (int) ($invoiceGroup['invoice_group_next_id'] ?? 1),
            (int) ($invoiceGroup['invoice_group_left_pad'] ?? 0)
        );
    }

    public function parseIdentifierFormat(string $identifierFormat, int $nextId, int $leftPad): string
    {
        $paddedId = str_pad((string) $nextId, $leftPad, '0', STR_PAD_LEFT);

        // Un formato vuoto (o che non contiene {{{id}}}) genererebbe numeri
        // vuoti o tutti uguali: i documenti resterebbero senza identificativo
        // e indistinguibili fra loro. In quel caso si ripiega sul solo
        // progressivo, che è il minimo perché la numerazione abbia senso.
        if (trim($identifierFormat) === '') {
            return $paddedId;
        }

        if (!str_contains($identifierFormat, '{{{id}}}')) {
            return $identifierFormat . $paddedId;
        }

        if (preg_match_all('/{{{([^{|}]*)}}}/', $identifierFormat, $templateVars)) {
            foreach ($templateVars[1] as $var) {
                switch ($var) {
                    case 'year':
                        $replace = date('Y');
                        break;
                    case 'yy':
                        $replace = date('y');
                        break;
                    case 'month':
                        $replace = date('m');
                        break;
                    case 'day':
                        $replace = date('d');
                        break;
                    case 'id':
                        $replace = str_pad((string) $nextId, $leftPad, '0', STR_PAD_LEFT);
                        break;
                    default:
                        $replace = '';
                }

                $identifierFormat = str_replace('{{{' . $var . '}}}', $replace, $identifierFormat);
            }
        }

        return $identifierFormat;
    }

    public function setNextInvoiceNumber(int $invoiceGroupId): void
    {
        // NOTA: si usa il Query Builder diretto (builder()) e non
        // Model::update(), perche' quest'ultimo ricostruisce la
        // clausola SET a partire dal parametro $data passato (qui
        // assente), ignorando un set() grezzo fatto sul wrapper
        // Model e quindi non applicando l'incremento.
        $this->builder()
             ->set('invoice_group_next_id', 'invoice_group_next_id+1', false)
             ->where($this->primaryKey, $invoiceGroupId)
             ->update();
    }
}
