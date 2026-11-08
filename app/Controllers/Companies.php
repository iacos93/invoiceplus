<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\ExpenseCategoryModel;
use App\Models\InvoiceGroupModel;
use App\Models\PaymentMethodModel;

/**
 * Companies controller — soggetti giuridici e cambio azienda attiva.
 */
class Companies extends BaseController
{
    protected CompanyModel $companyModel;

    public function __construct()
    {
        $this->companyModel = new CompanyModel();
    }

    public function index()
    {
        return view('companies/index', [
            'companies' => $this->companyModel->orderBy('company_is_default', 'DESC')
                                               ->orderBy('company_name', 'ASC')
                                               ->findAll(),
        ]);
    }

    /**
     * Cambia l'azienda su cui si sta lavorando.
     *
     * Si azzera anche il filtro anno: gli anni disponibili dipendono dai
     * documenti dell'azienda, e restare su un anno in cui la nuova azienda non
     * ha nulla farebbe apparire un archivio vuoto invece del cambio riuscito.
     */
    public function switchCompany()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $companyId = (int) $request->getPost('company_id');
        $company   = $this->companyModel->find($companyId);

        if (!$company || !db_bool($company['company_is_active'])) {
            session()->setFlashdata('alert_error', 'Azienda non valida.');

            return redirect()->to('/dashboard');
        }

        session()->set('active_company_id', $companyId);
        session()->remove('selected_year');

        session()->setFlashdata('alert_success', 'Ora stai operando su ' . $company['company_name']);

        $back = $request->getPost('back');

        // Anche il cookie va riportato a "Tutti gli anni": altrimenti alla
        // prossima sessione (o al prossimo avvio del browser) l'anno vecchio
        // tornerebbe dal cookie e annullerebbe l'azzeramento appena fatto.
        return remember_selected_year(redirect()->to(ip_back_url('dashboard', $back)), 0);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            $post = $request->getPost();
            // Booleani nativi: PostgreSQL rifiuta gli interi su colonne boolean.
            $post['company_is_default'] = empty($post['company_is_default']) ? false : true;
            $post['company_is_active']  = empty($post['company_is_active']) ? false : true;
            $post['company_tax_extras'] = json_encode($this->normalizeTaxExtras($post), JSON_UNESCAPED_UNICODE);

            // Aliquote lasciate in bianco: NULL, non stringa vuota. Le colonne
            // sono decimal, e MySQL converte '' in 0,00 — cioe' proprio il
            // valore che il campo vuoto vuole escludere. Il report legge NULL
            // come "usa il parametro fiscale dell'anno" e 0 come "zero per
            // davvero": salvando '' l'addizionale spariva dalla stima e il
            // campo si ripresentava a 0,00, senza modo di tornare indietro.
            foreach (['company_coefficient', 'company_contributions',
                      'company_add_regionale', 'company_add_comunale',
                      'company_carta_docente_code'] as $rate) {
                if (isset($post[$rate]) && trim((string) $post[$rate]) === '') {
                    $post[$rate] = null;
                }
            }

            if ($this->companyModel->validate($post)) {
                $isNew     = !$id;
                $companyId = $this->companyModel->saveCompany($id, $this->companyModel->dbArrayFromPost($post));

                if ($isNew) {
                    // Configurazione minima riutilizzabile: senza serie di
                    // numerazione e categorie di costo la nuova azienda
                    // partirebbe inutilizzabile, e andrebbero ricreate a mano
                    // ogni volta con gli stessi nomi.
                    $created = $this->seedCompany($companyId, (bool) $request->getPost('copy_setup'));

                    session()->setFlashdata('alert_success',
                        'Azienda creata. Configurazione iniziale: ' . $created . '.');
                } else {
                    session()->setFlashdata('alert_success', 'Azienda aggiornata correttamente');
                }

                return redirect()->to('/companies');
            }

            $this->companyModel->formValues = $post;

            return view('companies/form', [
                'company_id' => $id, 'errors' => $this->companyModel->errors(), 'model' => $this->companyModel,
                'invoice_groups' => $this->groupsOf($id),
                'countries' => get_country_list(trans('cldr')),
            ]);
        }

        if ($id && !$this->companyModel->prepForm($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('companies/form', [
            'company_id' => $id, 'errors' => [], 'model' => $this->companyModel,
            'invoice_groups' => $this->groupsOf($id),
            'countries' => get_country_list(trans('cldr')),
        ]);
    }

    /**
     * Voci contributive aggiuntive del POST, normalizzate per il salvataggio.
     *
     * Dal form arrivano come array paralleli (tax_extra_name[], tax_extra_rate[],
     * tax_extra_base[], tax_extra_deductible[]): qui diventano una lista di
     * oggetti pronta per il JSON. Le righe vuote o con aliquota non positiva
     * vengono scartate: sono residui di righe aggiunte e poi svuotate.
     *
     * @return list<array{name: string, rate: float, base: string, deductible: bool}>
     */
    private function normalizeTaxExtras(array $post): array
    {
        $extras = [];

        foreach ((array) ($post['tax_extra_name'] ?? []) as $i => $rawName) {
            $name = trim((string) $rawName);

            if ($name === '') {
                continue;
            }

            $rate = (float) ($post['tax_extra_rate'][$i] ?? 0);

            if ($rate <= 0) {
                continue;
            }

            $base = (string) ($post['tax_extra_base'][$i] ?? 'taxable');

            if (!in_array($base, ['taxable', 'profit', 'revenue'], true)) {
                $base = 'taxable';
            }

            $extras[] = [
                'name'       => $name,
                'rate'       => round($rate, 2),
                'base'       => $base,
                'deductible' => !empty($post['tax_extra_deductible'][$i]),
            ];
        }

        return $extras;
    }

    /**
     * Serie di numerazione selezionabili come predefinite per un'azienda.
     *
     * Sono quelle dell'azienda in questione — non dell'azienda attiva: si
     * modifica la Holding restando operativi su Novatech, e offrire le serie
     * di Novatech produrrebbe esattamente il riferimento incrociato che le
     * colonne per azienda servono a evitare. Le serie senza company_id sono
     * incluse perche' condivise da tutte (installazioni pre multi-azienda).
     */
    private function groupsOf(?int $companyId): array
    {
        $model = new InvoiceGroupModel();

        if (!$companyId) {
            return [];
        }

        return $model->groupStart()
                     ->where('company_id', $companyId)
                     ->orWhere('company_id IS NULL', null, false)
                     ->groupEnd()
                     ->orderBy('invoice_group_name', 'ASC')
                     ->findAll();
    }

    /**
     * Dotazione iniziale di una nuova azienda: serie di numerazione e, se
     * richiesto, le categorie di spesa già in uso (che sono condivise, quindi
     * si limita a segnalarlo).
     */
    private function seedCompany(int $companyId, bool $copySetup): string
    {
        $groupModel = new InvoiceGroupModel();

        // Ogni azienda deve possedere le proprie serie di numerazione: senza
        // company_id giusto, le bozze e i preventivi finivano sulla serie
        // dell'azienda predefinita (fallback globale) o riusavano la serie
        // fatture. Si creano le quattro serie e si collegano alla colonna
        // corrispondente dell'azienda, cosi' defaultGroupId() le risolve.
        $invoiceGroupId = (int) $groupModel->insert([
            'invoice_group_name'              => 'Fatture',
            'invoice_group_identifier_format' => '{{{id}}}',
            'invoice_group_next_id'           => 1,
            'invoice_group_left_pad'          => 4,
            'company_id'                      => $companyId,
        ]);

        $invoiceDraftGroupId = (int) $groupModel->insert([
            'invoice_group_name'              => 'Bozze fatture',
            'invoice_group_identifier_format' => 'T-{{{id}}}',
            'invoice_group_next_id'           => 1,
            'invoice_group_left_pad'          => 4,
            'company_id'                      => $companyId,
        ]);

        $quoteGroupId = (int) $groupModel->insert([
            'invoice_group_name'              => 'Preventivi',
            'invoice_group_identifier_format' => 'P-{{{id}}}',
            'invoice_group_next_id'           => 1,
            'invoice_group_left_pad'          => 4,
            'company_id'                      => $companyId,
        ]);

        $quoteDraftGroupId = (int) $groupModel->insert([
            'invoice_group_name'              => 'Bozze preventivi',
            'invoice_group_identifier_format' => 'PB-{{{id}}}',
            'invoice_group_next_id'           => 1,
            'invoice_group_left_pad'          => 4,
            'company_id'                      => $companyId,
        ]);

        $this->companyModel->update($companyId, [
            'default_invoice_group' => $invoiceGroupId,
            'draft_invoice_group'   => $invoiceDraftGroupId,
            'default_quote_group'   => $quoteGroupId,
            'draft_quote_group'     => $quoteDraftGroupId,
        ]);

        $created = ['serie fatture', 'serie bozze fatture', 'serie preventivi', 'serie bozze preventivi'];

        // Categorie di spesa: tabella globale (condivisa tra aziende), quindi si
        // creano una sola volta e si riusano. Le si precompila sempre, non solo
        // con "copia configurazione", altrimenti una nuova azienda partiva senza
        // voci selezionabili nelle spese.
        if ((new ExpenseCategoryModel())->countAllResults() === 0) {
            foreach (['Utenze', 'Consulenze', 'Acquisti', 'Servizi'] as $name) {
                (new ExpenseCategoryModel())->insert([
                    'expense_category_name'       => $name,
                    'expense_category_deductible' => true,
                ]);
            }

            $created[] = 'categorie di spesa';
        }

        // Metodi di pagamento: anch'essi globali (ip_payment_methods non ha
        // company_id). Si precompilano con le modalita' fiscali italiane piu'
        // comuni e il relativo codice MP della fattura elettronica, cosi' la
        // fattura emessa riporta gia' DatiPagamento/ModalitaPagamento validi.
        if ((new PaymentMethodModel())->countAllResults() === 0) {
            $methods = [
                ['Bonifico',            'MP05', true],
                ['Contanti',            'MP01', false],
                ['Assegno',             'MP02', false],
                ['Carta di credito',    'MP08', false],
                ['Ricevuta bancaria',   'MP12', true],
            ];

            foreach ($methods as [$name, $sdi, $needsBank]) {
                (new PaymentMethodModel())->insert([
                    'payment_method_name'                => $name,
                    'payment_method_sdi_code'           => $sdi,
                    'payment_method_requires_bank_account' => $needsBank,
                ]);
            }

            $created[] = 'metodi di pagamento';
        }

        return implode(', ', $created);
    }

    /**
     * Attiva/sospende un'azienda: la sospende从 la lista delle selezionabili
     * senza cancellare i dati. Non consente di sospendere l'azienda attiva
     * per evitare di restare senza un contesto valido.
     */
    public function toggleActive(int $id)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $company = $this->companyModel->find($id);

        if (!$company) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Non si puo' sospendere l'azienda su cui si sta operando.
        if ((int) $id === (int) active_company_id()) {
            session()->setFlashdata('alert_error', 'Non puoi sospendere l\'azienda attiva.');

            return redirect()->to('/companies');
        }

        $newState = !db_bool($company['company_is_active']);
        $this->companyModel->update($id, ['company_is_active' => $newState]);

        $label = $newState ? 'riattivata' : 'sospesa';
        session()->setFlashdata('alert_success', 'Azienda ' . $label . ' correttamente');

        return redirect()->to('/companies');
    }

    /**
     * Elimina un'azienda e tutti i suoi dati collegati.
     *
     * Non consente di eliminare l'azienda attiva ne' quella predefinita:
     * quest'ultima e' il fallback all'accesso e la prima e' il contesto
     * corrente. L'eliminazione e' irreversibile.
     */
    public function delete(int $id)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $company = $this->companyModel->find($id);

        if (!$company) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if ((int) $id === (int) active_company_id()) {
            session()->setFlashdata('alert_error', 'Non puoi eliminare l\'azienda attiva.');

            return redirect()->to('/companies');
        }

        if (db_bool($company['company_is_default'])) {
            session()->setFlashdata('alert_error', 'Non puoi eliminare l\'azienda predefinita.');

            return redirect()->to('/companies');
        }

        $this->companyModel->delete($id);
        session()->setFlashdata('alert_success', 'Azienda eliminata correttamente');

        return redirect()->to('/companies');
    }
}
