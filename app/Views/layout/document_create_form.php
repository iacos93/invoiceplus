<?php
/**
 * Partial condiviso: form "Nuovo documento" (fattura o preventivo).
 *
 * Impostazione alla Fatture in Cloud: una sola schermata a sezioni numerate
 * (cliente → dati documento → righe), tabella righe dinamica con totali che si
 * aggiornano mentre si digita. Totale e pulsanti Annulla/Salva vivono
 * nell'headerbar (li compone la view che include questo partial, vedi
 * invoices/form_create.php e quotes/form_create.php), non più in una barra
 * fissa in fondo alla pagina. Prima erano due card affiancate con una sola
 * riga "facoltativa": si creava il documento e le righe vere si aggiungevano
 * dopo, dalla vista di dettaglio.
 *
 * Le righe non hanno attributo name: vengono serializzate in JSON nel campo
 * nascosto "items" al submit (stesso formato che InvoicesAjax::save() e
 * QuotesAjax::create() già accettano per le richieste AJAX). Così aggiungere o
 * togliere righe non richiede indici nei name, e il POST resta identico per i
 * due controller.
 *
 *  Parametri attesi:
 *   $doc_title            string  titolo pagina ("Nuova fattura" / "Nuovo preventivo")
 *   $doc_action           string  URL di submit (invoices/ajax/save, quotes/ajax/create)
 *   $doc_date_field       string  nome del campo data (invoice_date_created / quote_date_created)
 *   $doc_date_label       string  etichetta del campo data
 *   $doc_password_field   string  nome del campo password (invoice_password / quote_password)
 *   $doc_password_label   string  etichetta del campo password
 *   $doc_password_default string  valore precompilato (dalle impostazioni)
 *   $doc_group_label      string  etichetta del gruppo ("Gruppo fatture" / "Gruppo preventivi")
 *   $doc_group_default    int     id gruppo preselezionato
 *   $doc_submit_label     string  etichetta del bottone di salvataggio
 *   $doc_show_einvoice    bool    (solo fatture) mostra la sezione "Fattura
 *                                 elettronica" (CIG/CUP/ritenute...): il POST
 *                                 finisce in dbArrayFromPost(), che le salva
 *                                 già in creazione. I preventivi non la hanno.
 *   $clients, $invoice_groups, $tax_rates, $selected_client  già forniti dal controller
 */

// Il formato valuta per il calcolo in pagina lo legge (e lo espone al motore)
// layout/document_item_table.php, che rende la tabella righe.

// Solo le fatture hanno il metodo di pagamento (il select lo usa) e solo
// Invoices::formCreate() passa l'elenco: qui il guard evita la variabile
// indefinita nel contesto preventivi.
$payment_methods = $payment_methods ?? [];
$bank_accounts   = $bank_accounts ?? [];

// Anagrafica minima per l'anteprima cliente sotto il select (solo i campi
// mostrati: non serve — e non è il caso di — spedire tutta la tabella).

// Etichette per l'autocompilazione dalle preferenze di fatturazione del
// cliente: aliquota e (solo fatture) metodo di pagamento si mostrano con il
// loro nome, non con il solo id. I preventivi non hanno metodo di pagamento:
// il blocco segue $doc_show_einvoice, che qui fa da marcatore "è fattura"
// (già usato così per doc_has_tasks).
$ip_tax_rate_labels = [];
foreach ($tax_rates as $tr) {
    $ip_tax_rate_labels[(int) $tr['tax_rate_id']] = format_amount($tr['tax_rate_percent']) . '%'
        . (!empty($tr['tax_rate_name']) ? ' — ' . $tr['tax_rate_name'] : '');
}
$ip_payment_method_names = [];
if (!empty($doc_show_einvoice)) {
    foreach ((new \App\Models\PaymentMethodModel())->getAllOrdered() as $pm) {
        $ip_payment_method_names[(int) $pm['payment_method_id']] = $pm['payment_method_name'];
    }
}

$ip_client_preview = [];
foreach ($clients as $c) {
    // Preferenze valorizzate sulla scheda cliente: oltre a pilotare
    // l'autocompilazione in JS vengono mostrate nell'anteprima, perché
    // una precompilazione che non si vede sembra un default globale
    // sbagliato e non una scelta del cliente.
    $ip_prefs   = \App\Models\ClientModel::billingPreferences($c);
    $ip_billing = [];

    if ($ip_prefs['client_default_tax_rate_id'] !== null && isset($ip_tax_rate_labels[$ip_prefs['client_default_tax_rate_id']])) {
        $ip_billing[] = trans('client_default_tax_rate', '', 'Aliquota IVA') . ': '
            . $ip_tax_rate_labels[$ip_prefs['client_default_tax_rate_id']];
    }
    if ($ip_prefs['client_discount_default'] !== null) {
        $ip_billing[] = trans('client_discount_default', '', 'Sconto') . ': '
            . format_amount($ip_prefs['client_discount_default']) . '%';
    }
    if (!empty($doc_show_einvoice)) {
        if ($ip_prefs['client_payment_days'] !== null) {
            $ip_billing[] = trans('client_payment_days', '', 'Pagamento') . ': '
                . format_amount($ip_prefs['client_payment_days']) . ' gg';
        }
        if ($ip_prefs['client_default_payment_method_id'] !== null && isset($ip_payment_method_names[$ip_prefs['client_default_payment_method_id']])) {
            $ip_billing[] = trans('payment_method', '', 'Metodo') . ': '
                . $ip_payment_method_names[$ip_prefs['client_default_payment_method_id']];
        }
    }

    $ip_client_preview[(int) $c['client_id']] = [
        'name'    => format_client($c),
        'address' => trim(implode(' ', array_filter([
            $c['client_address_1'] ?? '',
            $c['client_address_2'] ?? '',
        ]))),
        'city'    => trim(implode(' ', array_filter([
            $c['client_zip'] ?? '',
            $c['client_city'] ?? '',
            $c['client_state'] ?? '',
        ]))),
        'country' => $c['client_country'] ?? '',
        'vat'     => $c['client_vat_id'] ?? '',
        'tax'     => $c['client_tax_code'] ?? '',
        'email'   => $c['client_email'] ?? '',
        // Risolto qui (una sola volta, lato server) invece che in JS: la
        // regola "auto = solo PA" sta in un unico posto,
        // ClientModel::splitPaymentApplies(), che governa anche il form
        // cliente. Solo fatture: i preventivi non hanno il blocco fattura
        // elettronica, quindi il JS che lo legge lo ignora comunque.
        'split'   => \App\Models\ClientModel::splitPaymentApplies($c),
        // Autocompilazione righe/aliquote: id aliquota o stringa vuota.
        'taxRateId' => $ip_prefs['client_default_tax_rate_id'] !== null ? (int) $ip_prefs['client_default_tax_rate_id'] : 0,
        // Metodo di pagamento preselezionato (solo fatture; per i preventivi
        // resta 0 e il JS non trova il select).
        'paymentMethodId' => !empty($doc_show_einvoice) && $ip_prefs['client_default_payment_method_id'] !== null
            ? (int) $ip_prefs['client_default_payment_method_id'] : 0,
        // Riga riassuntiva già pronta ("Aliquota IVA: 22% · Sconto: 10%").
        'billing'   => implode(' · ', $ip_billing),
    ];
}

// Se la validazione lato server fallisce si torna qui con withInput(): senza
// questo le righe già digitate andrebbero perse e andrebbero riscritte tutte.
// Il valore arriva dal POST, quindi si decodifica e si ri-serializza invece di
// rimetterlo grezzo nello <script> (dove chiuderebbe il tag a piacere).
$ip_old_items = [];
$ip_old_raw   = old('items');

if (is_string($ip_old_raw) && $ip_old_raw !== '') {
    $ip_decoded = json_decode($ip_old_raw, true);

    if (is_array($ip_decoded)) {
        $ip_old_items = $ip_decoded;
    }
}

// Precompilazione da un'attività ("Fattura questa attività", vedi
// Invoices::formCreate()). Ha priorità più bassa di old('items'): se la
// validazione è già fallita una volta, quello che l'utente ha già scritto
// non deve sparire e tornare alla riga dell'attività.
if (!$ip_old_items && !empty($doc_prefill_items ?? [])) {
    $ip_old_items = $doc_prefill_items;
}
?>
<div id="content">
    <form method="POST" action="<?= site_url($doc_action) ?>" id="document_create_form"
          class="ip-doc-form">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= session('user_id') ?>">
        <?php /* Il campo nascosto "items" (e "deleted_items") lo rende
                 layout/document_item_table.php insieme alla tabella: averne
                 uno anche qui significava due input con lo stesso name nello
                 stesso form, dove vince l'ultimo. */ ?>

        <!-- ① Cliente -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('client', '', 'Cliente') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-client">
                    <div class="ip-doc-client__field">
                        <label for="client_id" class="ip-doc-label"><?= trans('client', '', 'Cliente') ?></label>
                        <select name="client_id" id="client_id" class="form-control simple-select" required
                                data-placeholder="<?= esc(trans('select_client', '', 'Seleziona cliente'), 'attr') ?>">
                            <option value=""><?= trans('select_client', '', 'Seleziona cliente') ?></option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int) $client['client_id'] ?>"
                                    <?= (int) ($selected_client ?? 0) === (int) $client['client_id'] ? 'selected' : '' ?>>
                                    <?= esc(format_client($client)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a class="ip-doc-hint-link" href="<?= site_url('clients/form') ?>">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_client', '', 'Nuovo cliente') ?>
                        </a>
                    </div>
                    <div class="ip-doc-client__preview" id="doc_client_preview" hidden></div>
                </div>
            </div>
        </section>

        <!-- ② Dati documento -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('document_data', '', 'Dati documento') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <?php
                // La serie viene prima della data: e' la scelta che determina
                // il numero del documento, e l'anteprima accanto a ogni voce
                // dice quale numero uscira'. La password del PDF chiude la riga,
                // nella terza colonna: e' facoltativa, e l'hint sotto il campo
                // lo dice senza che un sottogruppo con un solo campo spezzi la
                // griglia (il sottogruppo lasciava una colonna vuota e una riga
                // quasi interamente bianca).
                ?>
                <div class="ip-doc-grid">

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_group_id"><?= esc($doc_group_label) ?></label>
                        <?php $ip_group_model = new \App\Models\InvoiceGroupModel(); ?>
                        <select name="invoice_group_id" id="invoice_group_id" class="form-control simple-select" required>
                            <?php foreach ($invoice_groups as $invoice_group): ?>
                                <option value="<?= (int) $invoice_group['invoice_group_id'] ?>"
                                    <?= (int) ($doc_group_default ?? 0) === (int) $invoice_group['invoice_group_id'] ? 'selected' : '' ?>>
                                    <?= esc($invoice_group['invoice_group_name']) ?>
                                    — <?= esc($ip_group_model->previewNumber($invoice_group)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a class="ip-doc-hint-link" href="<?= site_url('invoice_groups') ?>">
                            <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('manage_numbering', '', 'Gestisci numerazione') ?>
                        </a>
                    </div>
                    <?php if (!empty($doc_number_field)): ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="<?= esc($doc_number_field) ?>"><?= trans('document_number', '', 'Numero documento') ?></label>
                        <input type="text" name="<?= esc($doc_number_field) ?>" id="<?= esc($doc_number_field) ?>"
                               class="form-control" value="<?= esc(old($doc_number_field) ?? '') ?>"
                               autocomplete="off" placeholder="<?= trans('auto_generate', '', 'Auto') ?>">
                        <p class="ip-number-help">
                            <?= trans('optional', '', 'Facoltativo') ?> — <?= trans('manual_number_hint', '', 'lascia vuoto per la numerazione automatica') ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="<?= esc($doc_date_field) ?>"><?= esc($doc_date_label) ?></label>
                        <input type="text" name="<?= esc($doc_date_field) ?>" id="<?= esc($doc_date_field) ?>"
                               class="form-control datepicker"
                               value="<?= esc(old($doc_date_field) ?: date(date_format_setting())) ?>" required>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="<?= esc($doc_password_field) ?>"><?= esc($doc_password_label) ?></label>
                        <input type="text" name="<?= esc($doc_password_field) ?>" id="<?= esc($doc_password_field) ?>"
                               class="form-control" value="<?= esc(old($doc_password_field) ?? ($doc_password_default ?? '')) ?>"
                               autocomplete="off">
                        <p class="ip-number-help">
                            <?= trans('optional', '', 'Facoltativa') ?> — <?= trans('optional_later_hint', '', 'modificabile anche dopo il salvataggio') ?>
                        </p>
                    </div>

                    <?php if (!empty($doc_show_einvoice)): ?>
                        <?php /* Solo fatture (i preventivi non si "pagano"): il
                                 valore 0 significa "non scelto" e al salvataggio
                                 vince la preferenza del cliente, quindi il JS
                                 qui sotto lo preseleziona appena si sceglie
                                 l'anagrafica. Stesso campo della scheda di
                                 modifica (invoices/edit.php). */ ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="payment_method"><?= trans('payment_method', '', 'Metodo di pagamento') ?></label>
                            <select name="payment_method" id="payment_method" class="form-control simple-select">
                                <option value="0"><?= trans('payment_method_from_client', '', 'Da preferenze cliente') ?></option>
                                <?php foreach ($payment_methods as $ip_pm): ?>
                                    <option value="<?= (int) $ip_pm['payment_method_id'] ?>"
                                        data-requires-bank-account="<?= db_bool($ip_pm['payment_method_requires_bank_account']) ? 1 : 0 ?>">
                                        <?= esc($ip_pm['payment_method_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="ip-number-help">
                                <?= trans('payment_method_create_hint', '', 'Preselezionato dal cliente; scegliendone uno resta quello') ?>
                            </p>
                        </div>
                        <?php /* Conto di saldo: stesso campo della scheda di
                                 modifica (invoices/edit.php), stessa regola —
                                 visibile solo per i metodi bancari. Il POST lo
                                 salva già dbArrayFromPost() (InvoiceModel). */ ?>
                        <?php if (!empty($bank_accounts)): ?>
                            <div class="ip-doc-field" id="bank_account_id_wrap">
                                <label class="ip-doc-label" for="bank_account_id"><?= trans('bank_account', '', 'Conto di saldo') ?></label>
                                <select name="bank_account_id" id="bank_account_id" class="form-control simple-select">
                                    <option value=""><?= trans('default_bank_account', '', 'Conto predefinito') ?></option>
                                    <?php foreach ($bank_accounts as $ip_account): ?>
                                        <option value="<?= (int) $ip_account['bank_account_id'] ?>"><?= esc($ip_account['bank_account_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="ip-number-help">
                                    <?= trans('bank_account_field_hint', '', 'Su quale IBAN aziendale verrà accreditato il pagamento — resta nascosto se il metodo scelto non lo richiede (es. contanti)') ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($doc_show_einvoice)): ?>
                    <?php /* Condizioni fattura: in modifica sta nella sezione
                             Note; in creazione senza questo campo il testo
                             veniva preso in silenzio dal default delle
                             impostazioni e l'utente non poteva né vederlo né
                             personalizzarlo per il documento che stava
                             creando. Lasciando vuoto il campo vince comunque
                             il default (stessa regola del model). */ ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_terms"><?= trans('invoice_terms', '', 'Condizioni fattura') ?></label>
                        <textarea name="invoice_terms" id="invoice_terms" class="form-control" rows="3"
                                  placeholder="<?= esc(trans('default_terms_placeholder', '', 'Se vuoto vale le condizioni predefinite dalle impostazioni'), 'attr') ?>"><?= esc(old('invoice_terms', get_setting('default_invoice_terms'))) ?></textarea>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($doc_show_einvoice)): ?>
            <section class="ip-doc-section">
                <div class="ip-doc-section__head">
                    <span class="ip-doc-step">3</span>
                    <h2 class="ip-doc-section__title"><?= trans('electronic_invoice', '', 'Fattura elettronica') ?></h2>
                    <span class="ip-doc-section__hint">
                        <?= trans('einvoice_hint', '', 'CIG e CUP sono obbligatori verso la Pubblica Amministrazione: senza questi codici lo SdI scarta la fattura') ?>
                    </span>
                </div>
                <div class="ip-doc-section__body">
                    <?= view('invoices/partial_create_einvoice') ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- ③/④ Righe: la numerazione dipende dalla presenza della sezione
             "Fattura elettronica" (solo fatture), che si inserisce prima. -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><?= !empty($doc_show_einvoice) ? 4 : 3 ?></span>
                <h2 class="ip-doc-section__title"><?= trans('items', '', 'Righe') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('rows_optional_hint', '', 'Puoi aggiungerne altre anche dopo aver salvato.') ?></span>
            </div>

            <?php /* Stessa tabella righe della schermata di modifica: prima
                     creazione e modifica avevano due tabelle e due <template>
                     quasi identici, che avevano gia' iniziato a divergere.
                     doc_has_tasks segue $doc_show_einvoice perche' solo le
                     fatture hanno il collegamento riga-attivita'. */ ?>
            <?= $this->include('layout/document_item_table', [
                'tax_rates'         => $tax_rates,
                'unit_groups'       => $unit_groups,
                'doc_form_id'       => 'document_create_form',
                'doc_has_tasks'     => !empty($doc_show_einvoice),
                'doc_initial_items' => $ip_old_items,
                // Solo fatture: i preventivi non generano ricavi e non hanno
                // una voce nel Bilancino.
                'doc_costing_accounts' => $costing_accounts ?? [],
            ]) ?>
        </section>

    </form>
</div>


<script>
(function () {
    var form = document.getElementById('document_create_form');
    if (!form) { return; }

    // Righe e totali sono gestiti dal motore condiviso ip-doc-items.js (lo
    // stesso della schermata di modifica): qui restano solo le cose proprie
    // della creazione — anteprima cliente, esigibilita' IVA e guardia sul
    // doppio invio.
    /* ---- anteprima cliente ---- */

    var clientsData  = <?= json_encode($ip_client_preview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var clientSelect = document.getElementById('client_id');
    var preview      = document.getElementById('doc_client_preview');

    // Autocompilazione dalle preferenze di fatturazione: la radice delle righe
    // espone data-item-tax-default (l'aliquota con cui nascono le righe nuove,
    // all'avvio il default globale impostato dal server). Scegliendo un
    // cliente lo si riscrive e le righe non ancora personalizzate si allineano.
    var docItemsRoot     = document.querySelector('[data-doc-items]');
    var globalTaxDefault = docItemsRoot ? String(docItemsRoot.dataset.itemTaxDefault || '0') : '';
    var currentTaxDefault = globalTaxDefault;

    // Esigibilità IVA: solo le fatture hanno il blocco "Fattura elettronica"
    // (i preventivi non passano $doc_show_einvoice), quindi l'elemento può
    // non esistere — ogni uso qui sotto lo controlla prima di toccarlo.
    var exigibilitySelect = document.getElementById('invoice_vat_exigibility');
    // Una volta che l'utente sceglie a mano, l'automazione si ferma per il
    // resto della sessione: cambiare cliente dopo non deve più riscrivere
    // una scelta esplicita, nemmeno tornando su un cliente diverso.
    var exigibilityTouchedByUser = false;
    // applySplitPaymentDefault() deve simulare l'evento 'change' per
    // ridisegnare il menu custom (vedi sotto) — senza questo flag, quel
    // change sintetico si marcherebbe da solo come "scelta dell'utente" e
    // l'automazione si spegnerebbe al primo cliente selezionato.
    var applyingSplitPaymentDefault = false;

    if (exigibilitySelect) {
        exigibilitySelect.addEventListener('change', function () {
            if (!applyingSplitPaymentDefault) {
                exigibilityTouchedByUser = true;
            }
        });
    }

    function applySplitPaymentDefault(data) {
        if (!exigibilitySelect || exigibilityTouchedByUser) { return; }

        var wantsSplit = !!(data && data.split);
        var isSplitNow = exigibilitySelect.value === 'S';

        if (wantsSplit === isSplitNow) { return; }

        // Non wantsSplit: si torna al vuoto (immediata) — il cliente
        // precedente aveva lo split payment, questo no, e 'S' non deve
        // restare su un cliente per cui non vale.
        exigibilitySelect.value = wantsSplit ? 'S' : '';

        // .value da solo aggiorna il <select> nativo (quindi il POST è già
        // corretto) ma non il menu custom .simple-select sopra: quel
        // componente si ridisegna solo al proprio evento 'change'
        // (ip-components.js, IPSelect.prototype.commit), che l'assegnazione
        // diretta non genera. Senza questo, il campo salverebbe il valore
        // giusto mostrando ancora "Immediata" a schermo.
        applyingSplitPaymentDefault = true;
        exigibilitySelect.dispatchEvent(new Event('change', { bubbles: true }));
        applyingSplitPaymentDefault = false;
    }

    // Metodo di pagamento (solo fatture): preselezione dalla preferenza del
    // cliente. Una volta che l'utente sceglie a mano, l'automazione si ferma,
    // stessa regola dell'esigibilità IVA.
    var payMethodSelect = document.getElementById('payment_method');
    var payMethodTouchedByUser = false;
    var applyingPaymentMethodDefault = false;

    // Conto di saldo (solo fatture): segue il flag bancario del metodo
    // scelto, come nella scheda di modifica. La preselezione automatica del
    // metodo dal cliente passa comunque da un change sintetico, quindi la
    // visibilità si allinea anche in quel caso.
    var accountWrap = document.getElementById('bank_account_id_wrap');

    function toggleBankAccount() {
        if (!payMethodSelect || !accountWrap) { return; }

        var selected = payMethodSelect.options[payMethodSelect.selectedIndex];
        var requires = selected ? selected.getAttribute('data-requires-bank-account') : '1';
        accountWrap.hidden = requires === '0';
    }

    if (payMethodSelect) {
        payMethodSelect.addEventListener('change', function () {
            if (!applyingPaymentMethodDefault) {
                payMethodTouchedByUser = true;
            }
            toggleBankAccount();
        });
    }
    toggleBankAccount();

    function applyPaymentMethodDefault(data) {
        if (!payMethodSelect || payMethodTouchedByUser) { return; }

        // 0 = "da preferenze cliente": nessuna preferenza sull'anagrafica si
        // traduce nel valore 0, che al salvataggio il model risolve di nuovo
        // lato server. Cliente senza metodo = si torna a 0, non si resta
        // incollati al metodo del cliente scelto prima.
        var wants = data && data.paymentMethodId ? String(data.paymentMethodId) : '0';

        if (payMethodSelect.value === wants) { return; }

        applyingPaymentMethodDefault = true;
        payMethodSelect.value = wants;
        // Stesso motivo dello split payment: .value non ridisegna il menu
        // custom di IPSelect, serve il change sintetico.
        payMethodSelect.dispatchEvent(new Event('change', { bubbles: true }));
        applyingPaymentMethodDefault = false;
    }

    function applyBillingDefaults(data) {
        if (!docItemsRoot) { return; }

        // Nessuna preferenza sul cliente = si torna al default globale, non
        // si resta incollati all'aliquota del cliente scelto prima.
        var newDefault = data && data.taxRateId ? String(data.taxRateId) : globalTaxDefault;
        var previous   = currentTaxDefault;
        currentTaxDefault = newDefault;

        // Righe future: lo legge ip-doc-items.js a ogni "Aggiungi riga".
        if (newDefault) {
            docItemsRoot.dataset.itemTaxDefault = newDefault;
        } else {
            delete docItemsRoot.dataset.itemTaxDefault;
        }

        // Righe già presenti: si allineano solo quelle mai personalizzate,
        // riconoscibili perché il select IVA sta ancora sul default corrente.
        // Una riga con aliquota diversa è una scelta (dell'utente o della
        // precompilazione da attività/prodotto) e non viene toccata. Non è
        // infallibile — chi ha scelto a mano proprio il default corrente
        // viene riscritto — ma è la stessa semplificazione accettata per lo
        // split payment qui sopra.
        if (previous === newDefault) { return; }

        var bodyEl = docItemsRoot.querySelector('[data-doc-items-body]');
        if (!bodyEl) { return; }

        bodyEl.querySelectorAll('.ip-item-row').forEach(function (row) {
            var sel = row.querySelector('[data-field="item_tax_rate_id"]');
            if (!sel || sel.value !== previous) { return; }

            sel.value = newDefault;

            // .value aggiorna il POST ma non il menu custom di IPSelect e non
            // i totali: serve il change, stesso motivo di applySplitPaymentDefault().
            // Il change fa anche ricalcolare la riga, che è ciò che serve.
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function renderClient() {
        var data = clientsData[clientSelect.value];

        applySplitPaymentDefault(data);
        applyPaymentMethodDefault(data);
        applyBillingDefaults(data);

        if (!data) {
            preview.hidden = true;
            preview.innerHTML = '';
            return;
        }

        var lines = [data.address, data.city, data.country].filter(Boolean);
        var meta  = [];

        if (data.vat)   { meta.push('<?= esc(trans('vat_id', '', 'P. IVA'), 'js') ?>: ' + data.vat); }
        if (data.tax)   { meta.push('<?= esc(trans('tax_code', '', 'Cod. fiscale'), 'js') ?>: ' + data.tax); }
        if (data.email) { meta.push(data.email); }

        var html = '<p class="ip-doc-client__name"></p>';
        preview.innerHTML = html
            + (lines.length ? '<p class="ip-doc-client__address"></p>' : '')
            + (meta.length ? '<p class="ip-doc-client__meta"></p>' : '')
            + (data.billing ? '<p class="ip-doc-client__meta ip-doc-client__billing"></p>' : '');

        preview.querySelector('.ip-doc-client__name').textContent = data.name;
        if (lines.length) { preview.querySelector('.ip-doc-client__address').textContent = lines.join(' · '); }
        if (meta.length)  { preview.querySelector('.ip-doc-client__meta').innerHTML = meta.join(' &middot; '); }
        if (data.billing) { preview.querySelector('.ip-doc-client__billing').textContent = data.billing; }

        preview.hidden = false;
    }

    clientSelect.addEventListener('change', function () {
        renderClient();

        var invalid = form.querySelector('.ip-doc-section.is-invalid');
        if (invalid) { invalid.classList.remove('is-invalid'); }
    });

    /* ---- submit ---- */

    form.addEventListener('submit', function (e) {
        // Doppio invio: un secondo click creerebbe un secondo documento
        // identico. Il guard si alza solo quando la validazione è passata,
        // così un errore (cliente mancante) non blocca il ritentare.
        if (form.dataset.submitted) {
            e.preventDefault();
            return;
        }

        // Rete di sicurezza: il select nativo è nascosto (lo sostituisce
        // IPSelect) e normalmente il "required" del browser scatta comunque,
        // perché è nascosto con clip e non con display:none. Se però un
        // browser lo considerasse non focalizzabile salterebbe il controllo e
        // si arriverebbe al server con client_id vuoto: qui si blocca l'invio
        // e si porta il focus sul controllo visibile.
        if (!clientSelect.value) {
            e.preventDefault();

            var control = clientSelect.closest('.ip-select');
            control = control ? control.querySelector('.ip-select__control') : clientSelect;
            control.focus();
            clientSelect.closest('.ip-doc-section').classList.add('is-invalid');

            return;
        }

        var btn = form.querySelector('button[type=submit]');
        if (btn) { btn.disabled = true; }
        form.dataset.submitted = '1';

        // La serializzazione delle righe nel campo "items" la fa il motore
        // condiviso, con un proprio listener di submit su questo stesso form.
    });

    /* ---- avvio ---- */

    // Le righe iniziali (ritorno da withInput, precompilazione da attività) le
    // ricarica il motore condiviso da data-doc-items-initial.
    renderClient();
})();
</script>
