<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * IpBaseModel
 *
 * Porting in CI4 del vecchio MY_Model (CI3) di InvoicePlane.
 * Replica il comportamento essenziale usato dai moduli legacy:
 * - save() con gestione insert/update e messaggi flash
 * - db_array() costruito dai validation rules + $_POST
 * - prep_form() per popolare form_values in edit
 * - form_value() per leggere valori nel form
 *
 * Ogni modello di modulo (es. TaxRateModel) estende questa classe
 * e definisce: $table, $primaryKey, validationRules().
 */
class IpBaseModel extends Model
{
    /** @var array valori correnti del form, popolati da prep_form() o dal POST */
    public array $formValues = [];

    /**
     * Limita la query all'azienda attiva.
     *
     * Sta qui e non in ogni model perché la separazione fra aziende non deve
     * dipendere dal fatto che chi scrive una query si ricordi di aggiungerla:
     * il rischio non è una lista incompleta, è vedere i documenti di un altro
     * soggetto giuridico.
     *
     * I record con company_id NULL restano visibili: sono i dati anteriori
     * all'introduzione del multi-azienda, e nasconderli farebbe sparire
     * l'archivio invece di segnalarlo.
     */
    /**
     * $table permette di applicare il filtro a una tabella in join anziche' a
     * quella del model: ip_payments non ha company_id, ma l'azienda del
     * pagamento e' quella della fattura pagata.
     */
    public function scopeToCompany(?int $companyId = null, ?string $table = null): static
    {
        $companyId ??= active_company_id();
        $table     ??= $this->table;

        if (!$companyId || !$this->db->fieldExists('company_id', $table)) {
            return $this;
        }

        $this->groupStart()
             ->where($table . '.company_id', $companyId)
             ->orWhere($table . '.company_id IS NULL', null, false)
             ->groupEnd();

        return $this;
    }

    /**
     * Riga dell'azienda attiva, o null se l'id appartiene a un'altra.
     *
     * find() non applica scopeToCompany(): con l'id preso dall'URL si apriva
     * in modifica -- e si cancellava -- il record di un altro soggetto
     * giuridico. E' lo stesso baco per cui era gia' nato
     * BankAccountModel::findScoped(); qui il metodo e' generalizzato perche'
     * la query e' identica ovunque la tabella abbia company_id.
     *
     * Attenzione dove company_id non c'e' (ip_payments prende l'azienda dalla
     * fattura): li' scopeToCompany() fa da no-op e questo metodo tornerebbe
     * righe di tutte le aziende, quindi serve un override che passi dal join.
     */
    public function findScoped(int $id): ?array
    {
        $row = $this->scopeToCompany()
                    ->where($this->table . '.' . $this->primaryKey, $id)
                    ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Valori da salvare con l'azienda attiva impostata, se la tabella la
     * prevede: un documento creato senza company_id resterebbe fuori da ogni
     * filtro e comparirebbe in tutte le aziende.
     */

    /**
     * Condizione LIKE su un'espressione SQL (di norma un CONCAT_WS di piu'
     * colonne) mantenendo il valore cercato correttamente quotato.
     *
     * like($expr, $kw, 'both', false) non serve: il quarto argomento disattiva
     * l'escaping dell'intero predicato, valore compreso, e il termine finiva
     * nella query come %testo% nudo — sintassi non valida, quindi ogni ricerca
     * rispondeva con un errore SQL.
     */
    protected function likeExpression(string $expression, string $keyword): static
    {
        $value = $this->db->escape('%' . $this->db->escapeLikeString($keyword) . '%');

        // LOWER() sui due lati: il confronto non deve dipendere dal caso né dal
        // collation. I chiamanti attuali (filterByKeywords) passano già keyword
        // e colonne in minuscolo, ma il metodo deve reggere anche un'espressione
        // in chiaro — e su PostgreSQL, il driver in uso, LIKE è case-sensitive,
        // a differenza del MySQL con collation _ci da cui il progetto arriva.
        // Il LIKE con % iniziale non può usare un indice, quindi LOWER() non
        // toglie prestazioni.
        return $this->where("LOWER({$expression}) LIKE LOWER({$value}) ESCAPE '!'", null, false);
    }


    /**
     * Query builder grezzo gia' ristretto all'azienda attiva.
     *
     * builder() scavalca le where del model, quindi ogni aggregato scritto su
     * di esso (totali, anni disponibili, somme per categoria) usciva dal
     * perimetro dell'azienda anche quando le liste corrispondenti no.
     */
    protected function companyBuilder()
    {
        return scope_builder_to_company($this->builder(), $this->table);
    }

    /**
     * Tipi colonna per tabella, letti una volta per richiesta.
     *
     * @var array<string, array<string, array{type: string, nullable: bool}>>
     */
    private static array $columnTypes = [];

    /**
     * Ogni insert/update dei model passa di qui prima di arrivare al
     * builder: vedi coerceForColumns().
     */
    protected function doInsert(array $row)
    {
        return parent::doInsert($this->coerceForColumns($row));
    }

    protected function doUpdate($id = null, $row = null): bool
    {
        return parent::doUpdate($id, is_array($row) ? $this->coerceForColumns($row) : $row);
    }

    /**
     * Adatta i valori al tipo della colonna, come faceva in silenzio MySQL.
     *
     * Il codice arriva da CI3 su MySQL, che accettava 0/1 in una colonna
     * boolean (lì era un tinyint) e convertiva '' in 0 nelle colonne numeriche.
     * PostgreSQL rifiuta entrambi, e ogni form con una checkbox scritta come
     * "? 0 : 1" o un campo numerico lasciato vuoto andava in crash al
     * salvataggio (il conto di saldo, per esempio, non si poteva né creare
     * né modificare). Correggere i form uno per uno lascia scoperto il
     * prossimo che si scrive: qui il tipo lo dice il database stesso.
     *
     * Si tocca solo ciò che PostgreSQL rifiuterebbe comunque -- interi e
     * stringhe in un boolean, '' in una colonna numerica o data -- quindi
     * nessuna scrittura che oggi funziona cambia significato.
     */
    protected function coerceForColumns(array $row): array
    {
        if ($row === [] || $this->db->DBDriver !== 'Postgre') {
            return $row;
        }

        if (!isset(self::$columnTypes[$this->table])) {
            $types = [];

            foreach ($this->db->getFieldData($this->table) as $field) {
                $types[$field->name] = ['type' => strtolower((string) $field->type), 'nullable' => (bool) $field->nullable];
            }

            self::$columnTypes[$this->table] = $types;
        }

        $columns = self::$columnTypes[$this->table];

        foreach ($row as $key => $value) {
            if (!isset($columns[$key]) || $value === null || is_bool($value) && $columns[$key]['type'] === 'boolean') {
                continue;
            }

            ['type' => $type, 'nullable' => $nullable] = $columns[$key];

            if ($type === 'boolean') {
                if ($value === '') {
                    $row[$key] = $nullable ? null : false;
                } elseif (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    $row[$key] = (int) $value !== 0;
                } elseif (is_string($value)) {
                    $lower = strtolower(trim($value));

                    if (in_array($lower, ['t', 'true', 'on', 'yes', 'y'], true)) {
                        $row[$key] = true;
                    } elseif (in_array($lower, ['f', 'false', 'off', 'no', 'n'], true)) {
                        $row[$key] = false;
                    }
                }
            } elseif (in_array($type, ['smallint', 'integer', 'bigint', 'numeric', 'real', 'double precision'], true)) {
                if ($value === '') {
                    $row[$key] = $nullable ? null : 0;
                } elseif (is_bool($value)) {
                    $row[$key] = (int) $value;
                }
            } elseif ($value === '' && $nullable && (str_starts_with($type, 'date') || str_starts_with($type, 'timestamp') || str_starts_with($type, 'time'))) {
                $row[$key] = null;
            }
        }

        return $row;
    }

    public function withCompany(array $dbArray): array
    {
        if ($this->db->fieldExists('company_id', $this->table) && empty($dbArray['company_id'])) {
            $dbArray['company_id'] = active_company_id();
        }

        return $dbArray;
    }

    /**
     * Restituisce le righe della lista, con paginazione se $perPage e' valorizzato.
     *
     * Prepari il builder (where/select/orderBy) prima di chiamarla. Se $perPage
     * e' null ritorna tutti i record (comportamento storico, usato dai form
     * a cascata). Altrimenti pagina usando $group come chiave del Pager, cosi'
     * la vista puo' renderizzare lo stesso gruppo con $pager->links($group).
     * Il gruppo di default e' il nome della tabella, univoco per ogni lista.
     */
    protected function listFetch(?int $perPage = null, int $page = 1, ?string $group = null): array
    {
        $group ??= $this->table;

        if ($perPage === null) {
            return $this->findAll();
        }

        return $this->paginate($perPage, $group, $page);
    }

    /** @var string|null errori di validazione dell'ultimo run */
    public ?string $validationErrorsText = null;

    /**
     * Regole di validazione in stile InvoicePlane legacy.
     * Da sovrascrivere nel modello figlio.
     */
    public function validationRulesLegacy(): array
    {
        return [];
    }

    /**
     * Costruisce l'array da salvare su DB a partire dal POST,
     * filtrando solo le chiavi presenti nelle validation rules
     * (stesso comportamento di db_array() in CI3).
     */
    public function dbArrayFromPost(array $post): array
    {
        $rules = $this->validationRulesLegacy();
        $dbArray = [];

        foreach ($post as $key => $value) {
            if (array_key_exists($key, $rules)) {
                $dbArray[$key] = $value;
            }
        }

        return $dbArray;
    }

    /**
     * Salva (insert o update) e ritorna l'id.
     * Equivalente di Response_Model::save() + MY_Model::save() in CI3.
     */
    public function saveRecord(?int $id, array $dbArray)
    {
        if ($id) {
            $this->update($id, $dbArray);
            return $id;
        }

        // Solo in creazione: in aggiornamento riscrivere company_id
        // sposterebbe un documento da un'azienda all'altra a ogni salvataggio
        // fatto mentre si è posizionati su un'altra.
        return $this->insert($this->withCompany($dbArray), true);
    }

    /**
     * Popola formValues per la modalità edit (equivalente di prep_form in CI3).
     */
    public function prepForm(?int $id, array $postData = []): bool
    {
        if (empty($postData) && $id) {
            $row = $this->find($id);

            if ($row) {
                $this->formValues = is_array($row) ? $row : (array) $row;
                return true;
            }

            return false;
        }

        if (!$id) {
            return true;
        }

        return true;
    }

    /**
     * Legge un valore dal form corrente (equivalente di form_value() in CI3).
     */
    public function formValue(string $key, bool $escape = false)
    {
        $value = $this->formValues[$key] ?? '';
        return $escape ? htmlspecialchars((string) $value, ENT_QUOTES) : $value;
    }
}
