<?php
/**
 * Partial condiviso: tabella righe di un documento in modifica (fattura o
 * preventivo).
 *
 * Stesso motore della schermata "Nuova fattura": le righe vivono nel DOM come
 * cloni di un <template>, i totali si ricalcolano mentre si digita e tutto
 * viene serializzato in JSON in un campo nascosto al submit della testata
 * (ip-doc-items.js). Prima ogni riga aveva il proprio <form> e il proprio
 * salvataggio: aggiungere cinque righe voleva dire cinque ricaricamenti di
 * pagina, e ogni submit di riga buttava via, senza dirlo, quello che era stato
 * digitato nelle altre sezioni del documento. Ora c'e' un solo salvataggio,
 * quello della fattura, e finche' non lo si preme il documento sul server non
 * e' stato toccato: si puo' cambiare idea.
 *
 * I campi delle righe non hanno attributo name (solo data-field): non postano
 * da soli. A viaggiare sono i due campi nascosti qui sotto, legati al form
 * della testata con l'attributo HTML5 form="..." — cosi' non serve annidare
 * questa tabella dentro quel form (annidare <form> e' HTML non valido e il
 * parser scarta quello interno).
 *
 * Fattura e preventivo condividono questo file invece di averne uno per tipo:
 * le due copie precedenti erano quasi identiche e avevano gia' iniziato a
 * divergere (il ramo che decide se salvare una riga di sola descrizione si
 * comportava in modo diverso nei due documenti).
 *
 *  Parametri attesi:
 *   $items         array   righe gia' salvate
 *   $tax_rates     array   aliquote disponibili
 *   $unit_groups   array   unita' di misura
 *   $doc_form_id   string  id del <form> testata a cui legare i campi nascosti
 *   $doc_has_tasks bool    (solo fatture) tiene il collegamento riga-attivita'
 */

// $this->include(view, [...]) in CodeIgniter 4 non estrae il secondo parametro
// come variabili di vista (e' riservato alle render options): va letto da
// renderVars['options'], stesso trucco di layout/headerbar.php e
// layout/document_client_block.php.
$_di           = (array) ($this->renderVars['options'] ?? []);
$items         = $_di['items']         ?? ($items ?? []);
$tax_rates     = $_di['tax_rates']     ?? ($tax_rates ?? []);
$unit_groups   = $_di['unit_groups']   ?? ($unit_groups ?? []);
$doc_form_id   = $_di['doc_form_id']   ?? ($doc_form_id ?? 'invoice_details_form');
$doc_has_tasks = $_di['doc_has_tasks'] ?? ($doc_has_tasks ?? false);

// Voci di ricavo del Bilancino. Vuoto per i preventivi (non generano ricavi)
// e per le installazioni che non hanno ancora un piano dei conti: in quel
// caso il selettore non compare e la riga si salva come prima.
$doc_costing_accounts = $_di['doc_costing_accounts'] ?? ($doc_costing_accounts ?? []);

// Righe di partenza gia' pronte per il motore (form di creazione: ritorno da
// withInput o precompilazione da attivita'). Sono gia' nel formato in cui
// l'utente le digita, quindi non passano dalla conversione da riga di database
// piu' sotto.
$doc_initial_items = $_di['doc_initial_items'] ?? ($doc_initial_items ?? null);

$settings = new \App\Models\SettingModel();

// Il JS deve formattare gli importi esattamente come format_currency() lato
// server, altrimenti il totale in pagina e quello salvato sembrerebbero due
// numeri diversi.
$ip_decimal_point   = (string) $settings->setting('decimal_point');
$ip_thousands_sep   = (string) $settings->setting('thousands_separator');
$ip_currency_symbol = (string) $settings->setting('currency_symbol');
$ip_currency_place  = (string) $settings->setting('currency_symbol_placement');

/* Etichette delle aliquote calcolate una volta sola: senza nome+Natura, due
   aliquote diverse a 0% (es. "Esente N4" e "Reverse charge edile N6.3")
   risultano indistinguibili. data-percent alimenta il calcolo dell'IVA in
   pagina con gli stessi valori del database. */
$taxRateOptions = [];

foreach ($tax_rates as $tax_rate) {
    $natura = $tax_rate['tax_rate_natura'] ?? null;
    $label  = format_amount($tax_rate['tax_rate_percent']) . '% — ' . $tax_rate['tax_rate_name'];

    if ($natura) {
        $label .= \App\Models\TaxRateModel::isReverseCharge($natura)
            ? ' (inversione contabile ' . $natura . ')'
            : ' (' . $natura . ')';
    }

    $taxRateOptions[] = [
        'id'      => (int) $tax_rate['tax_rate_id'],
        'percent' => (float) $tax_rate['tax_rate_percent'],
        'label'   => $label,
    ];
}

/* Righe esistenti passate al motore come JSON: gli importi vanno nel formato
   locale (format_amount), lo stesso in cui l'utente li digita, perche' il
   parseAmount() del JS legge quel formato. */
$initialItems = $doc_initial_items ?? [];

foreach ($doc_initial_items === null ? $items : [] as $item) {
    $initialItems[] = [
        'item_id'               => (string) (int) $item['item_id'],
        'item_task_id'          => (string) (int) ($item['item_task_id'] ?? 0),
        'item_product_id'       => (string) (int) ($item['item_product_id'] ?? 0),
        'item_code'             => (string) ($item['item_code'] ?? ''),
        'item_name'             => (string) $item['item_name'],
        'item_description'      => (string) $item['item_description'],
        'item_quantity'         => format_amount($item['item_quantity']),
        'item_product_unit_id'  => (string) (int) ($item['item_product_unit_id'] ?? 0),
        'item_price'            => format_amount($item['item_price']),
        'item_discount_amount'  => format_amount($item['item_discount_amount']),
        'item_discount_percent' => format_amount($item['item_discount_percent']),
        'item_tax_rate_id'      => (string) (int) ($item['item_tax_rate_id'] ?? 0),
        'costing_account_id'    => ($item['costing_account_id'] ?? null) ? (string) (int) $item['costing_account_id'] : '',
    ];
}

// Aliquota di partenza delle righe nuove, come nel form di creazione.
$defaultItemTaxRateId = (int) get_setting('default_item_tax_rate', 0);
?>

<div class="ip-doc-items" id="doc-items"
     data-doc-items
     data-decimal="<?= esc($ip_decimal_point, 'attr') ?>"
     data-thousands="<?= esc($ip_thousands_sep, 'attr') ?>"
     data-symbol="<?= esc($ip_currency_symbol, 'attr') ?>"
     data-placement="<?= esc($ip_currency_place, 'attr') ?>"
     data-item-tax-default="<?= (int) $defaultItemTaxRateId ?>"
     <?php /* Testi della conferma di uscita senza salvare (ip-doc-items.js):
             stanno qui tradotti invece che nel JS, che non ha accesso ai
             dizionari. */ ?>
     data-leave-title="<?= esc(trans('unsaved_changes_title', '', 'Modifiche non salvate'), 'attr') ?>"
     data-leave-body="<?= esc(trans('unsaved_changes_body', '', 'Le modifiche a questo documento non sono state salvate: uscendo andranno perse.'), 'attr') ?>"
     data-leave-ok="<?= esc(trans('leave_without_saving', '', 'Esci senza salvare'), 'attr') ?>"
     data-doc-items-initial="<?= esc(json_encode($initialItems, JSON_UNESCAPED_UNICODE), 'attr') ?>">

    <?php /* I due campi che postano davvero. form="..." li lega alla testata
             senza annidare questa tabella dentro quel <form>. */ ?>
    <input type="hidden" name="items" form="<?= esc($doc_form_id, 'attr') ?>" data-doc-items-json value="">
    <input type="hidden" name="deleted_items" form="<?= esc($doc_form_id, 'attr') ?>" data-doc-items-deleted value="">

    <table class="ip-items-table" id="item_table">
        <thead>
        <tr>
            <th scope="col" class="ip-items-table__handle"><span class="visually-hidden"><?= trans('order', '', 'Ordine') ?></span></th>
            <?php /* Codice della riga: può essere il codice articolo del MEPA o
                     quello concordato con l'ente, e non coincide per forza con
                     il codice del prodotto in catalogo. Facoltativo: se vuoto
                     il documento mostra il codice del prodotto. */ ?>
            <th scope="col" class="ip-items-table__code"><?= trans('item_code', '', 'Codice') ?></th>
            <th scope="col" class="ip-items-table__desc"><?= trans('description', '', 'Descrizione') ?></th>
            <th scope="col" class="ip-items-table__num"><?= trans('quantity', '', 'Qtà') ?></th>
            <th scope="col" class="ip-items-table__unit"><?= trans('product_unit', '', 'Unità') ?></th>
            <th scope="col" class="ip-items-table__num"><?= trans('price', '', 'Prezzo') ?></th>
            <th scope="col" class="ip-items-table__num"><?= trans('discount', '', 'Sconto') ?></th>
            <th scope="col" class="ip-items-table__tax"><?= trans('tax_rate', '', 'IVA') ?></th>
            <th scope="col" class="ip-items-table__total"><?= trans('total', '', 'Totale') ?></th>
            <th scope="col" class="ip-items-table__actions"><span class="visually-hidden"><?= trans('options', '', 'Opzioni') ?></span></th>
        </tr>
        </thead>

        <?php /* Niente data-reorder-url: l'ordine non si salva piu' con una
                 chiamata a parte al rilascio del trascinamento, viaggia con il
                 documento (item_order assegnato in serializzazione). */ ?>
        <tbody data-doc-items-body></tbody>
    </table>

    <div class="ip-doc-items__footer">
        <button type="button" class="btn btn-sm btn-secondary" data-doc-items-add>
            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_new_row', '', 'Aggiungi riga') ?>
        </button>
    </div>

    <div class="ip-doc-totals">
        <dl class="ip-doc-totals__list">
            <dt><?= trans('subtotal', '', 'Imponibile') ?></dt>
            <dd data-doc-total="subtotal">—</dd>
            <dt><?= trans('discount', '', 'Sconto') ?></dt>
            <dd data-doc-total="discount">—</dd>
            <dt><?= trans('tax', '', 'IVA') ?></dt>
            <dd data-doc-total="tax">—</dd>
            <dt class="is-total"><?= trans('total', '', 'Totale') ?></dt>
            <dd class="is-total" data-doc-total="total">—</dd>
        </dl>
    </div>
</div>

<!-- Modello di riga: clonato dal motore a ogni "Aggiungi riga" e per ogni riga
     gia' salvata. Identico a quello del form di creazione: la stessa fattura
     non deve piu' avere due aspetti a seconda che la si crei o la si modifichi. -->
<template data-doc-items-template>
    <tr class="ip-item-row" data-item-id="">
        <td class="ip-items-table__handle">
            <span class="ip-item-row__grip">
                <button type="button" class="ip-item-row__handle" aria-label="<?= esc(trans('drag_to_reorder', '', 'Trascina per riordinare'), 'attr') ?>">
                    <i class="fa fa-solid fa-grip-vertical" aria-hidden="true"></i>
                </button>
            </span>
            <span class="ip-item-row__reorder">
                <button type="button" class="ip-item-row__move" data-dir="up"
                        title="<?= esc(trans('move_up', '', 'Sposta su'), 'attr') ?>"
                        aria-label="<?= esc(trans('move_up', '', 'Sposta su'), 'attr') ?>">
                    <i class="fa fa-solid fa-chevron-up" aria-hidden="true"></i>
                </button>
                <button type="button" class="ip-item-row__move" data-dir="down"
                        title="<?= esc(trans('move_down', '', 'Sposta giù'), 'attr') ?>"
                        aria-label="<?= esc(trans('move_down', '', 'Sposta giù'), 'attr') ?>">
                    <i class="fa fa-solid fa-chevron-down" aria-hidden="true"></i>
                </button>
            </span>
        </td>
        <td class="ip-items-table__code">
            <input type="text" class="form-control input-sm" data-field="item_code" maxlength="60"
                   placeholder="<?= esc(trans('item_code_placeholder', '', 'Cod. articolo / MEPA'), 'attr') ?>"
                   aria-label="<?= esc(trans('item_code', '', 'Codice'), 'attr') ?>">
        </td>
        <td class="ip-items-table__desc">
            <?php /* Legami della riga che nessun controllo visibile tocca ma che
                     devono sopravvivere al salvataggio: il prodotto di origine e
                     (solo fatture) l'attivita', che al salvataggio il controller
                     marca come fatturata. Stanno dentro il <td> e non come figli
                     diretti del <tr>: un <input> fuori da una cella e' HTML non
                     valido e il parser lo sposterebbe fuori dalla riga, dove
                     data-field non lo troverebbe piu'. */ ?>
            <input type="hidden" data-field="item_product_id">
            <?php if ($doc_has_tasks): ?>
                <input type="hidden" data-field="item_task_id">
            <?php endif; ?>
            <input type="text" class="form-control input-sm" data-field="item_name"
                   placeholder="<?= esc(trans('item', '', 'Articolo'), 'attr') ?>">
            <textarea rows="1" class="form-control input-sm ip-item-row__desc ip-desc-counter-field"
                      data-field="item_description" maxlength="1000"
                      placeholder="<?= esc(trans('description', '', 'Descrizione'), 'attr') ?>"></textarea>
            <?php /* Voce di ricavo del Bilancino, per riga: una fattura può
                     contenere vendita di merce e prestazione di servizio, che
                     nel conto economico riclassificato vanno in voci diverse.
                     Senza piano dei conti il campo non esiste e nulla cambia. */ ?>
            <?php if ($doc_costing_accounts !== []): ?>
                <select class="form-control input-sm ip-item-row__account simple-select"
                        data-field="costing_account_id"
                        data-placeholder="<?= esc(trans('management_no_account', '', 'Nessuna voce'), 'attr') ?>"
                        data-allow-clear="true" data-minimum-results-for-search="Infinity"
                        aria-label="<?= esc(trans('management_revenue_account', '', 'Voce di ricavo'), 'attr') ?>">
                    <option value=""><?= trans('management_no_account', '', 'Nessuna voce') ?></option>
                    <?php foreach ($doc_costing_accounts as $accountId => $accountLabel): ?>
                        <option value="<?= (int) $accountId ?>"><?= esc($accountLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </td>
        <td class="ip-items-table__num">
            <input type="text" class="form-control input-sm amount" data-field="item_quantity" value="1"
                   aria-label="<?= esc(trans('quantity', '', 'Qtà'), 'attr') ?>">
        </td>
        <td class="ip-items-table__unit">
            <select class="form-control input-sm simple-select" data-field="item_product_unit_id"
                    data-placeholder="<?= esc(trans('none', '', 'Nessuna'), 'attr') ?>" data-allow-clear="true"
                    aria-label="<?= esc(trans('product_unit', '', 'Unità di misura'), 'attr') ?>">
                <option value="0"><?= trans('none', '', 'Nessuna') ?></option>
                <?= view('layout/unit_options', ['unit_groups' => $unit_groups]) ?>
            </select>
        </td>
        <td class="ip-items-table__num">
            <input type="text" class="form-control input-sm amount" data-field="item_price" placeholder="0"
                   aria-label="<?= esc(trans('price', '', 'Prezzo'), 'attr') ?>">
        </td>
        <td class="ip-items-table__num">
            <input type="text" class="form-control input-sm amount" data-field="item_discount_amount" placeholder="0"
                   title="<?= esc(trans('item_discount', '', 'Sconto per unità'), 'attr') ?>"
                   aria-label="<?= esc(trans('item_discount', '', 'Sconto per unità'), 'attr') ?>">
            <input type="text" class="form-control input-sm amount ip-item-row__desc" data-field="item_discount_percent" placeholder="%"
                   title="<?= esc(trans('item_discount_percent', '', 'Sconto %'), 'attr') ?>"
                   aria-label="<?= esc(trans('item_discount_percent', '', 'Sconto %'), 'attr') ?>">
        </td>
        <td class="ip-items-table__tax">
            <select class="form-control input-sm simple-select" data-field="item_tax_rate_id"
                    aria-label="<?= esc(trans('tax_rate', '', 'Aliquota'), 'attr') ?>">
                <option value="0" data-percent="0" <?= check_select($defaultItemTaxRateId, 0) ?>><?= trans('none', '', 'Nessuna') ?></option>
                <?php foreach ($taxRateOptions as $option): ?>
                    <option value="<?= $option['id'] ?>" data-percent="<?= $option['percent'] ?>"
                        <?= check_select($defaultItemTaxRateId, $option['id']) ?>>
                        <?= esc($option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="ip-items-table__total">
            <span class="ip-item-row__total">—</span>
        </td>
        <td class="ip-items-table__actions">
            <button type="button" class="ip-item-row__remove" data-action="remove-row"
                    title="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>"
                    aria-label="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>">
                <i class="fa fa-solid fa-trash-can" aria-hidden="true"></i>
            </button>
        </td>
    </tr>
</template>
