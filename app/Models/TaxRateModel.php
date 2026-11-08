<?php

namespace App\Models;

/**
 * TaxRateModel
 *
 * Porting CI4 del modulo tax_rates (CI3: Mdl_Tax_Rates).
 * Tabella e struttura invariate rispetto all'originale, per compatibilita'
 * con il database esistente e con l'app CI3 durante la transizione.
 */
class TaxRateModel extends IpBaseModel
{
    protected $table            = 'ip_tax_rates';
    protected $primaryKey       = 'tax_rate_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'tax_rate_name',
        'tax_rate_percent',
        'tax_rate_natura',
    ];

    protected $validationRules = [
        'tax_rate_name'    => 'required|max_length[100]',
        'tax_rate_percent' => 'required|numeric',
        'tax_rate_natura'  => 'permit_empty|in_list[' . self::NATURA_LIST . ']',
    ];

    protected $validationMessages = [
        'tax_rate_name' => [
            'required'   => 'Il nome dell\'aliquota e\' obbligatorio',
            // Senza questa regola un nome troppo lungo veniva accettato dalla
            // validation e poi troncato in silenzio da MySQL a insert/update
            // (colonna varchar(100)): nessun errore visibile, solo un nome
            // mutilato in lista. Meglio bloccarlo qui con un messaggio chiaro.
            'max_length' => 'Il nome dell\'aliquota puo\' avere al massimo 100 caratteri',
        ],
        'tax_rate_percent' => [
            'required' => 'La percentuale e\' obbligatoria',
            'numeric'  => 'La percentuale deve essere un numero',
        ],
        'tax_rate_natura' => [
            'in_list' => 'Codice Natura non riconosciuto',
        ],
    ];

    /**
     * Codici Natura IVA ufficiali dell'Agenzia delle Entrate (tracciato
     * FatturaPA), raggruppati come nelle specifiche tecniche. Il gruppo N6
     * (inversione contabile / reverse charge) e' quello che l'operatore
     * cerca piu' spesso e prima non c'era alcun modo di censirlo: da qui la
     * richiesta di "tutti i tipi di imposte ... compresa inversione
     * contabile". Elenco completo, non solo i codici piu' comuni: un
     * sottoinsieme avrebbe comunque lasciato l'utente a cercare altrove il
     * codice mancante.
     *
     * 'label' e' autosufficiente (non presuppone che chi legge veda anche
     * 'group'): compare da solo accanto al badge in tax_rates/index.php, dove
     * il raggruppamento per famiglia non esiste. Prima ripeteva il nome del
     * gruppo in modo secco ("Esenti" per N4, "Escluse ex art. 15" per N1):
     * corretto nella select (dove il gruppo e' comunque visibile
     * nell'optgroup), ma un badge isolato con solo "Esenti" in lista non dice
     * *cosa* rende esente quell'operazione.
     *
     * @var array<string, array{group: string, label: string}>
     */
    public const NATURA_CODES = [
        'N1'   => ['group' => 'Escluse',            'label' => 'Operazione esclusa da IVA (art. 15 DPR 633/72)'],
        'N2.1' => ['group' => 'Non soggette',        'label' => 'Non soggetta ad IVA – artt. 7-7septies DPR 633/72'],
        'N2.2' => ['group' => 'Non soggette',        'label' => 'Non soggetta ad IVA – altri casi (es. regime forfettario)'],
        'N3.1' => ['group' => 'Non imponibili',      'label' => 'Non imponibile – esportazione'],
        'N3.2' => ['group' => 'Non imponibili',      'label' => 'Non imponibile – cessione intracomunitaria'],
        'N3.3' => ['group' => 'Non imponibili',      'label' => 'Non imponibile – cessione verso San Marino'],
        'N3.4' => ['group' => 'Non imponibili',      'label' => 'Non imponibile – operazione assimilata alle cessioni all\'esportazione'],
        'N3.5' => ['group' => 'Non imponibili',      'label' => 'Non imponibile – a seguito di dichiarazione d\'intento'],
        'N3.6' => ['group' => 'Non imponibili',      'label' => 'Non imponibile – altra operazione senza formazione di plafond'],
        'N4'   => ['group' => 'Esenti',              'label' => 'Esente da IVA (art. 10 DPR 633/72)'],
        'N5'   => ['group' => 'Regime del margine',  'label' => 'Regime del margine – IVA non esposta in fattura'],
        'N6.1' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – cessione di rottami e altri materiali di recupero'],
        // Testo allineato alla Guida AdE (v1.7, 2022), par. "N6.2": non e'
        // "oro e argento puro" (formulazione imprecisa presente in una
        // versione precedente) ma la cessione di oro/argento ai sensi della
        // L. 7/2000, che comprende anche l'oreficeria usata ceduta ad OPO
        // (Operatori Professionali in Oro) — un caso concreto e diverso
        // dal solo oro industriale, che l'etichetta precedente ometteva.
        'N6.2' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – oro e argento (L. 7/2000) e oreficeria usata ad OPO'],
        'N6.3' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – subappalto nel settore edile'],
        'N6.4' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – cessione di fabbricati'],
        'N6.5' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – cessione di telefoni cellulari'],
        'N6.6' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – cessione di prodotti elettronici'],
        'N6.7' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – prestazioni comparto edile e settori connessi'],
        'N6.8' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – operazioni settore energetico'],
        'N6.9' => ['group' => 'Inversione contabile', 'label' => 'Inversione contabile – altri casi'],
        'N7'   => ['group' => 'Altro stato UE',       'label' => 'IVA assolta in altro stato UE (vendite a distanza)'],
    ];

    /** Lista dei codici, per la validation rule in_list[...]. */
    private const NATURA_LIST = 'N1,N2.1,N2.2,N3.1,N3.2,N3.3,N3.4,N3.5,N3.6,N4,N5,N6.1,N6.2,N6.3,N6.4,N6.5,N6.6,N6.7,N6.8,N6.9,N7';

    /**
     * Raggruppa NATURA_CODES per la select del form (un optgroup per
     * famiglia, nell'ordine del tracciato FatturaPA).
     *
     * @return array<string, array<string, string>> gruppo => [codice => label]
     */
    public static function naturaGroups(): array
    {
        $groups = [];

        foreach (self::NATURA_CODES as $code => $info) {
            $groups[$info['group']][$code] = $code . ' – ' . $info['label'];
        }

        return $groups;
    }

    /**
     * true per i codici N6.x: sono l'unica famiglia in cui l'imposta non
     * manca (esenzione/esclusione) ma si sposta sul cessionario/committente
     * (inversione contabile / reverse charge), un'informazione che vale la
     * pena distinguere visivamente in lista invece di trattarla come una
     * "non imponibile" qualsiasi.
     */
    public static function isReverseCharge(?string $natura): bool
    {
        return $natura !== null && str_starts_with($natura, 'N6.');
    }
    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Lista ordinata come nell'originale CI3 (default_order_by).
     */
    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->orderBy('tax_rate_percent', 'ASC');

        return $this->listFetch($perPage, $page);
    }
}
