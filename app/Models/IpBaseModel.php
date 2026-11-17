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
     * Fino a oggi i record con company_id NULL restavano visibili "perche'
     * anteriori al multi-azienda": l'effetto era che un residuo senza azienda
     * finiva nei totali di tutte. Ora il backfill li ha assegnati
     * (BackfillOrphanCompanyData) e `php spark companies:orphans` dice se ne
     * restano: il perimetro e' uguaglianza, non "uguale o nullo".
     *
     * La parametrizzazione per tabella serve a ip_payments, che non ha
     * company_id: li' l'azienda del pagamento e' quella della fattura pagata e
     * il chiamante passa la tabella in join.
     */
    public function scopeToCompany(?int $companyId = null, ?string $table = null): static
    {
        $companyId ??= active_company_id();
        $table     ??= $this->table;

        if (!$companyId || !self::tableHasCompany($this->db, $table)) {
            return $this;
        }

        $this->where($table . '.company_id', $companyId);

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

    /** @var array<string, true> tabelle con company_id, per questa richiesta */
    private static array $companyTables = [];

    /**
     * La tabella ha la colonna company_id?
     *
     * `fieldExists()` interroga `information_schema` una volta per tabella a
     * ogni richiesta: la Bacheca ne tocca quattro, e su PostgreSQL sono le
     * query più lente della pagina (da pochi millisecondi a oltre cento a
     * freddo) per una risposta che cambia solo con una migrazione.
     *
     * Si ricorda fra una richiesta e l'altra **solo il sì**. Il no si
     * ricontrolla sempre: se una migrazione aggiunge company_id a una tabella,
     * un "no" rimasto in cache farebbe saltare il perimetro in silenzio, cioè
     * mostrerebbe i dati di un'azienda dentro un'altra. Un "sì" rimasto dopo
     * la rimozione della colonna dà invece un errore SQL subito, che è il
     * guasto che si vede. Nei test la cache condivisa non si usa: le tabelle
     * vengono create e rifatte da un test all'altro.
     */
    public static function tableHasCompany(\CodeIgniter\Database\BaseConnection $db, string $table): bool
    {
        $chiave = $db->getDatabase() . '.' . $table;

        if (isset(self::$companyTables[$chiave])) {
            return true;
        }

        // Stessa lettura di PublicEndpointThrottle: la costante ENVIRONMENT è
        // nota all'analisi statica come valore fisso.
        $condivisa = (string) ($_SERVER['CI_ENVIRONMENT'] ?? 'production') !== 'testing';
        $nomeCache = 'ip_company_tables_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $db->getDatabase());

        if ($condivisa && self::$companyTables === []) {
            $note = cache()->get($nomeCache);

            if (is_array($note)) {
                self::$companyTables = $note;

                if (isset(self::$companyTables[$chiave])) {
                    return true;
                }
            }
        }

        if (! $db->fieldExists('company_id', $table)) {
            return false;
        }

        self::$companyTables[$chiave] = true;

        if ($condivisa) {
            cache()->save($nomeCache, self::$companyTables, DAY);
        }

        return true;
    }

    public function withCompany(array $dbArray): array
    {
        if (self::tableHasCompany($this->db, $this->table) && empty($dbArray['company_id'])) {
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
     * Salva (insert o update) e ritorna l'id, oppure 0 se non ha scritto nulla.
     * Equivalente di Response_Model::save() + MY_Model::save() in CI3.
     *
     * insert()/update() di CI4 non lanciano: quando la validazione rifiuta la
     * riga tornano false e il motivo finisce in errors(). Il ramo update
     * ignorava quell'esito e restituiva comunque l'id, quindi ogni form che si
     * fida del valore — e sono quasi tutti — annunciava «salvato
     * correttamente» su una riga rimasta com'era, e in modifica riportava
     * l'utente sulla scheda come se avesse scritto. Lo zero è il segnale:
     * nessun id valido è zero, e serve un int anche per l'insert, altrimenti
     * false non si distingue con === 0.
     */
    public function saveRecord(?int $id, array $dbArray)
    {
        if ($id) {
            return $this->update($id, $dbArray) ? $id : 0;
        }

        // Solo in creazione: in aggiornamento riscrivere company_id
        // sposterebbe un documento da un'azienda all'altra a ogni salvataggio
        // fatto mentre si è posizionati su un'altra.
        return (int) $this->insert($this->withCompany($dbArray), true);
    }

    /**
     * Motivo leggibile dell'ultimo salvataggio rifiutato.
     *
     * saveRecord() torna 0 e le regole che hanno bloccato la riga stanno in
     * errors(): i form lo mostrano senza dover conoscere il modello. Se non
     * c'è un errore di validazione è stato il database a rifiutare, e va detto
     * senza inventare un dettaglio che non abbiamo.
     */
    public function saveErrorSummary(): string
    {
        $errori = $this->errors();

        return $errori === [] ? 'salvataggio rifiutato dal database' : implode(' ', $errori);
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
