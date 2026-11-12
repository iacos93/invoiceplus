<?php $this->setVar('pageTitle', trans('invoices')); ?>
<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'paid' => 'text-bg-success',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-dark',
    'overdue' => 'text-bg-danger',
];

// Il periodo selezionato va propagato ai link di stato, altrimenti cambiando
// stato si perderebbe il mese/anno su cui si sta lavorando.
// year sempre esplicito (anche 0 = "Tutti"): senza, i link di stato
// perderebbero la scelta "Tutti" fatta nella barra periodo e ricadrebbero
// sul filtro anno globale della topbar al click successivo.
$favourite   = (string) ($favourite ?? '');
$sign        = (string) ($sign ?? '');
$months      = is_array($months ?? null) ? $months : [];
$monthQuery  = ip_month_query($months);
// Base senza il filtro tipo: serve ai pulsanti che cambiano proprio quel
// filtro, mentre gli altri la usano già completa.
$filterBase  = '?year=' . (int) ($year ?? 0)
             . ($monthQuery !== [] ? '&' . http_build_query($monthQuery) : '');
// Stessa query per filtri di stato e preferiti: anno, mesi (anche multipli),
// tipo documento e, dove serve, il filtro preferiti. Costruirla una volta
// sola evita che i gruppi di pulsanti divergano nel modo di serializzare la
// selezione.
$periodBase  = $filterBase . ($sign !== '' ? '&sign=' . $sign : '');
$periodQuery = $periodBase . ($favourite !== '' ? '&fav=' . $favourite : '');

// Ogni filtro produce un descrittore, non markup: la barra lo disegna una
// volta sola (layout/list_filters) e resta identica su desktop e telefono.
// Prima le stesse voci erano scritte due volte, una nell'headerbar e una nel
// sottomenu mobile, e le due copie divergevano — "Preferiti" non esisteva su
// telefono.
$btn = function ($key, $label) use ($status, $periodQuery) {
    return [
        'url'    => site_url('invoices/status/' . $key) . $periodQuery,
        'label'  => $label,
        'active' => $status === $key,
    ];
};

// Il filtro preferiti conserva lo stato selezionato: sono tagli diversi
// dello stesso elenco, non alternativi.
//
// Il gruppo porta un'etichetta visibile ("Preferiti") perché senza di essa
// erano due controlli segmentati identici affiancati, ciascuno con la propria
// voce "Tutte" attiva: non si capiva quale dei due filtri si stesse togliendo.
// La voce "Tutte" è esplicita e in testa, come nel gruppo stati: il ritorno
// all'elenco completo non deve dipendere dal ricordarsi che un filtro si
// spegne ricliccandolo.
$favBtn = function ($value, $label, $icon) use ($status, $periodBase, $favourite) {
    // Ogni voce punta a se stessa; '' ("Tutte") omette del tutto &fav.
    $query = $periodBase . ($value !== '' ? '&fav=' . $value : '');

    return [
        'url'    => site_url('invoices/status/' . $status) . $query,
        'label'  => $label,
        'icon'   => $icon,
        'active' => $favourite === $value,
    ];
};

// Tipo documento: le note di credito sono fatture con invoice_sign = -1, non
// uno stato. Il gruppo resta separato da quello degli stati perché i due
// filtri si combinano (una nota di credito può essere bozza o inviata).
$signBtn = function ($value, $label) use ($status, $filterBase, $sign, $favourite) {
    $query = $filterBase . ($value !== '' ? '&sign=' . $value : '')
           . ($favourite !== '' ? '&fav=' . $favourite : '');

    return [
        'url'    => site_url('invoices/status/' . $status) . $query,
        'label'  => $label,
        'active' => $sign === $value,
    ];
};

$filterGroups = [
    [
        'label'   => trans('status', '', 'Stato'),
        'buttons' => [
            $btn('all', trans('all', '', 'Tutte')),
            $btn('draft', trans('draft', '', 'Bozze')),
            $btn('sent', trans('sent', '', 'Inviate')),
            $btn('viewed', trans('viewed', '', 'Viste')),
            $btn('paid', trans('paid', '', 'Pagate')),
            $btn('overdue', trans('overdue', '', 'Scadute')),
        ],
    ],
    [
        'label'   => trans('type', '', 'Tipo'),
        'buttons' => [
            $signBtn('', trans('all', '', 'Tutte')),
            $signBtn('invoice', trans('invoices', '', 'Fatture')),
            $signBtn('credit', trans('credit_notes', '', 'Note di credito')),
        ],
    ],
    [
        'label'   => trans('favourites', '', 'Preferiti'),
        'buttons' => [
            $favBtn('', trans('all', '', 'Tutte'), ''),
            $favBtn('1', trans('yes', '', 'Sì'), 'fa-solid fa-star'),
            $favBtn('0', trans('no', '', 'No'), 'fa-regular fa-star'),
        ],
    ],
];

// Quanti filtri sono accesi oltre ai valori predefiniti (la prima voce di ogni
// gruppo è il "Tutte" che equivale a nessun filtro). Il numero finisce nel
// pulsante "Filtra" del telefono, così si vede che c'è un filtro attivo anche
// a pannello chiuso.
$activeFilters = 0;
foreach ($filterGroups as $filterGroup) {
    foreach ($filterGroup['buttons'] as $index => $filterButton) {
        if ($index > 0 && !empty($filterButton['active'])) {
            $activeFilters++;
        }
    }
}

// Niente "Importa XML" nella barra della lista: l'importazione delle fatture
// emesse ha già la sua voce sotto Fatture nella sidebar, e l'ingresso dei dati
// sta nell'hub Importa / Esporta. Un terzo pulsante in testa alla lista
// spingeva "Nuova fattura" — l'azione che si cerca davvero qui — più a destra,
// senza aprire una strada che non fosse già a un clic di distanza.
$actions = '<button type="button" class="btn btn-secondary btn-sm submenu-toggle d-lg-none"'
         . ' data-bs-toggle="collapse" data-bs-target="#ip-status-submenu-collapse"'
         . ' aria-controls="ip-status-submenu-collapse" aria-expanded="false">'
         . '<i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . trans('filter', '', 'Filtra')
         . ($activeFilters > 0 ? ' <span class="ip-btn-count">' . $activeFilters . '</span>' : '')
         . '</button>'
         . '<a class="btn btn-sm btn-primary" href="' . site_url('invoices/form_create') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new_invoice', '', 'Nuova fattura') . '</a>';
?>
<?= $this->include('layout/headerbar', [
    'title'   => trans('invoices'),
    'actions' => $actions,
]) ?>

<?php
/* Un solo esemplare dei filtri. Su desktop la regola CSS lo tiene sempre
   aperto (il pulsante "Filtra" lì non esiste), su telefono lo apre il
   pulsante. Tenendo una copia sola, le due versioni non possono divergere. */
?>
<div id="submenu" class="ip-filter-panel">
    <div class="collapse clearfix" id="ip-status-submenu-collapse">
        <?= view('layout/list_filters', ['groups' => $filterGroups]) ?>
    </div>
</div>

<?= $this->include('layout/period_nav', [
    'base_url'          => 'invoices/status/' . $status,
    'year'              => $year ?? 0,
    'months'            => $months,
    'month'             => $month ?? 0,
    'totals'            => $period_totals ?? [],
    'month_totals'      => $month_totals ?? [],
    'month_total_label' => trans('invoiced', '', 'Fatturato'),
    // La barra periodo ricostruisce l'URL da zero: ogni filtro che non
    // viaggia in extra_query sparisce al primo clic su un mese. Tipo
    // documento e preferiti devono quindi passare di qui.
    'extra_query'       => array_filter([
        'sign' => $sign,
        'fav'  => $favourite,
    ], static fn ($v) => $v !== '' && $v !== null),
]) ?>

<?php
/* Azioni di gruppo. Il modulo contiene solo la barra e si chiude subito:
   le caselle di selezione gli appartengono tramite l'attributo HTML5 "form",
   lo stesso schema della tabella righe dei documenti. Avvolgere la tabella
   avrebbe annidato i moduli di riga già presenti (preferito, elimina, invio
   a SdI) — moduli annidati non sono HTML valido e il browser scarta quelli
   interni, quindi le azioni di riga avrebbero smesso di funzionare.

   Le azioni offerte sono le sole che esistono già su una riga singola —
   preferiti ed elimina — più "segna come inviata", che è la transizione che
   il menu Opzioni non copriva e che obbligava a entrare in ogni documento.
   Non c'è "segna come pagata": lo stato "pagata" segue i pagamenti
   registrati, metterlo a mano scollegherebbe lo stato dal saldo. */
?>
<div id="content" class="table-content">
    <form method="POST" action="<?= site_url('invoices/bulk') ?>" id="ip-bulk-form" class="ip-bulk">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= esc(current_url(true), 'attr') ?>">

        <div class="ip-bulk-bar" id="ip-bulk-bar" hidden>
            <span class="ip-bulk-bar__count" id="ip-bulk-count"
                  data-template="<?= esc(trans('bulk_selected', '', '%s selezionate'), 'attr') ?>"></span>
            <div class="ip-bulk-bar__actions">
                <button type="submit" name="bulk_action" value="favourite_on" class="btn btn-sm btn-secondary">
                    <i class="fa fa-solid fa-star" aria-hidden="true"></i> <?= trans('bulk_favourite_on', '', 'Aggiungi ai preferiti') ?>
                </button>
                <button type="submit" name="bulk_action" value="favourite_off" class="btn btn-sm btn-secondary">
                    <i class="fa fa-regular fa-star" aria-hidden="true"></i> <?= trans('bulk_favourite_off', '', 'Togli dai preferiti') ?>
                </button>
                <button type="submit" name="bulk_action" value="mark_sent" class="btn btn-sm btn-secondary"
                        data-bs-toggle="confirm"
                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                        data-confirm-body="<?= esc(trans('bulk_mark_sent_confirm', '', 'Le bozze selezionate passano a "inviata" e ricevono il numero definitivo.'), 'attr') ?>"
                        data-confirm-ok="<?= esc(trans('bulk_mark_sent', '', 'Segna come inviata'), 'attr') ?>">
                    <i class="fa fa-regular fa-paper-plane" aria-hidden="true"></i> <?= trans('bulk_mark_sent', '', 'Segna come inviata') ?>
                </button>
                <button type="submit" name="bulk_action" value="delete" class="btn btn-sm btn-secondary ip-bulk-bar__danger"
                        data-bs-toggle="confirm"
                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                        data-confirm-body="<?= esc(trans('bulk_delete_confirm', '', 'Eliminare le fatture selezionate?'), 'attr') ?>"
                        data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                    <i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" id="ip-bulk-clear">
                    <?= trans('bulk_clear', '', 'Annulla selezione') ?>
                </button>
            </div>
        </div>
    </form>

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i></span>
                <h2 class="ip-table-card-label"><?= esc(trans('invoices')) ?></h2>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_invoices') : count($invoices) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search"
                 data-target="#filter_results"
                 data-action="<?= site_url('filter/filter_invoices') ?>">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca fattura, cliente…')) ?>"
                       autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>
        <div id="filter_results">
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse">

                <thead>
                <tr>
                    <th class="ip-bulk-col ip-hide-on-mobile" scope="col">
                        <?php /* La casella sta dentro una <label>: da sola è 16x16,
                                 sotto il minimo di 24px per un bersaglio (WCAG 2.5.8).
                                 L'etichetta porta l'area cliccabile a 24x24 senza
                                 ingrandire il quadratino. */ ?>
                        <label class="ip-bulk-hit">
                            <input type="checkbox" class="form-check-input" id="ip-bulk-all"
                                   aria-label="<?= esc(trans('bulk_select_all', '', 'Seleziona tutte le fatture in pagina'), 'attr') ?>">
                        </label>
                    </th>
                    <th class="ip-fav-col ip-hide-on-mobile" scope="col"><span class="visually-hidden"><?= trans('favourite', '', 'Preferito') ?></span></th>
                    <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                    <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                    <th class="ip-hide-on-mobile ip-col-secondary" scope="col"><?= trans('created', '', 'Creata') ?></th>
                    <th scope="col"><?= trans('due_date', '', 'Scadenza') ?></th>
                    <th scope="col"><?= trans('client_name', '', 'Cliente') ?></th>
                    <?php
                    // Imponibile e IVA restano colonne proprie, come nella
                    // lista spese: senza, il piede totale non aveva una colonna
                    // su cui allinearsi e finiva sotto l'importo lordo.
                    // L'IVA compare solo sugli schermi larghi (>= 1400 px).
                    ?>
                    <th class="amount ip-hide-on-mobile ip-col-secondary" scope="col"><?= trans('subtotal', '', 'Imponibile') ?></th>
                    <th class="amount d-none d-xxl-table-cell" scope="col"><?= trans('vat', '', 'IVA') ?></th>
                    <th class="amount" scope="col"><?= trans('total', '', 'Totale') ?></th>
                    <th class="amount" scope="col"><?= trans('balance', '', 'Saldo') ?></th>
                    <th class="ip-hide-on-mobile" scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php
                    $invoice_status_class = $invoice_statuses[$invoice['invoice_status_id']]['class'] ?? 'draft';
                    $isFavourite          = !empty($invoice['invoice_is_favourite']);
                    // Nome accessibile esplicito: il solo title non è affidabile
                    // come etichetta per i lettori di schermo.
                    $favAction = $isFavourite
                        ? trans('remove_favourite', '', 'Togli dai preferiti')
                        : trans('add_favourite', '', 'Aggiungi ai preferiti');
                    ?>
                    <tr>
                        <td class="ip-bulk-col ip-hide-on-mobile">
                            <label class="ip-bulk-hit">
                                <input type="checkbox" class="form-check-input ip-bulk-check" form="ip-bulk-form"
                                       name="ids[]" value="<?= (int) $invoice['invoice_id'] ?>"
                                       aria-label="<?= esc(trans('bulk_select_row', '', 'Seleziona la fattura') . ' ' . ($invoice['invoice_number'] ?: '#' . $invoice['invoice_id']), 'attr') ?>">
                            </label>
                        </td>
                        <td class="ip-fav-col ip-hide-on-mobile" data-label="<?= esc(trans('favourite', '', 'Preferito')) ?>">
                            <form method="POST" action="<?= site_url('invoices/toggle_favourite/' . (int) $invoice['invoice_id']) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="back" value="<?= esc(current_url(true), 'attr') ?>">
                                <button type="submit" class="ip-fav-toggle <?= $isFavourite ? 'is-on' : '' ?>"
                                        title="<?= esc($favAction, 'attr') ?>"
                                        aria-label="<?= esc($favAction, 'attr') ?>"
                                        aria-pressed="<?= $isFavourite ? 'true' : 'false' ?>">
                                    <i class="fa <?= $isFavourite ? 'fa-solid fa-star' : 'fa-regular fa-star' ?>" aria-hidden="true"></i>
                                </button>
                            </form>
                        </td>
                        <td data-cell="status" data-label="<?= esc(trans('status', '', 'Stato')) ?>">
                            <span class="badge ip-status <?= $status_label_class[$invoice_status_class] ?? 'text-bg-secondary' ?>">
                                <?= esc($invoice_statuses[$invoice['invoice_status_id']]['label'] ?? trans('draft')) ?>
                                <?php /* Icone portatrici di significato (informazione
                                         non presente nel testo del badge): role="img" +
                                         aria-label, non aria-hidden che le renderebbe
                                         mute per chi usa screen reader. */ ?>
                                <?php if ($invoice['invoice_sign'] == '-1'): ?>
                                    &nbsp;<i class="fa fa-solid fa-minus" role="img"
                                             title="<?= trans('credit_invoice', '', 'Nota di credito') ?>"
                                             aria-label="<?= trans('credit_invoice', '', 'Nota di credito') ?>"></i>
                                <?php endif; ?>
                                <?php if (!empty($invoice['invoice_is_recurring'])): ?>
                                    &nbsp;<i class="fa fa-solid fa-refresh" role="img"
                                             title="<?= trans('recurring', '', 'Ricorrente') ?>"
                                             aria-label="<?= trans('recurring', '', 'Ricorrente') ?>"></i>
                                <?php endif; ?>
                            </span>
                        </td>

                        <td data-cell="number" data-label="<?= esc(trans('invoice', '', 'Fattura')) ?>">
                            <a href="<?= site_url('invoices/view/' . (int) $invoice['invoice_id']) ?>"
                               title="<?= trans('edit', '', 'Modifica') ?>">
                                <span class="ip-num"><?= esc($invoice['invoice_number'] ?: ('#' . $invoice['invoice_id'])) ?></span>
                            </a>
                            <?php if (isset($sdi_map[$invoice['invoice_id']])): ?>
                                <?php $sdi = $sdi_map[$invoice['invoice_id']]; ?>
                                <?php if ($sdi['esito'] === 'IMP'): ?>
                                    <?php
                                    // Fattura importata da un altro gestionale: è uno stato
                                    // informativo, uguale su decine di righe. Come badge in
                                    // maiuscolo allargava la colonna Fattura a 230 px e la
                                    // tabella usciva dal contenitore; qui resta una nota
                                    // discreta, con il testo completo nel title.
                                    ?>
                                    <span class="ip-sdi-note" title="<?= esc(\App\Libraries\Sdi\SdiSender::esitoLabel($sdi['esito']) . (!empty($sdi['messaggio']) ? ' — ' . $sdi['messaggio'] : ''), 'attr') ?>">
                                        <i class="fa fa-solid fa-download" aria-hidden="true"></i> <?= trans('sdi_imported', '', 'Importata') ?>
                                    </span>
                                <?php else: ?>
                                <span class="badge ip-sdi-badge text-bg-<?= esc(\App\Libraries\Sdi\SdiSender::esitoClass($sdi['esito'])) ?>"
                                      title="<?= esc($sdi['filename'], 'attr') ?><?= !empty($sdi['messaggio']) ? ' — ' . esc($sdi['messaggio'], 'attr') : '' ?>">
                                    <?= esc(\App\Libraries\Sdi\SdiSender::esitoLabel($sdi['esito'])) ?>
                                </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="ip-hide-on-mobile ip-col-secondary" data-label="<?= esc(trans('created', '', 'Creata')) ?>">
                            <?= date_from_mysql($invoice['invoice_date_created']) ?>
                        </td>

                        <td data-cell="due" data-label="<?= esc(trans('due_date', '', 'Scadenza')) ?>">
                            <span class="<?= !empty($invoice['is_overdue']) ? 'text-danger fw-semibold' : '' ?>">
                                <?= date_from_mysql($invoice['invoice_date_due']) ?>
                            </span>
                        </td>

                        <td data-cell="client" data-label="<?= esc(trans('client_name', '', 'Cliente')) ?>">
                            <a href="<?= site_url('clients/view/' . (int) $invoice['client_id']) ?>"
                               title="<?= trans('view_client', '', 'Vedi cliente') ?>">
                                <?= esc(format_client($invoice)) ?>
                            </a>
                        </td>

                        <td class="amount ip-hide-on-mobile ip-col-secondary" data-label="<?= esc(trans('subtotal', '', 'Imponibile')) ?>">
                            <?= format_currency($invoice['invoice_item_subtotal']) ?>
                        </td>

                        <td class="amount d-none d-xxl-table-cell" data-label="<?= esc(trans('vat', '', 'IVA')) ?>">
                            <?= format_currency((float) $invoice['invoice_item_tax_total'] + (float) $invoice['invoice_tax_total']) ?>
                        </td>

                        <td class="amount <?= $invoice['invoice_sign'] == '-1' ? 'text-danger' : '' ?>" data-cell="amount" data-label="<?= esc(trans('total', '', 'Totale')) ?>">
                            <?= format_currency($invoice['invoice_total']) ?>
                        </td>

                        <td class="amount" data-cell="balance" data-label="<?= esc(trans('balance', '', 'Saldo')) ?>">
                            <?= format_currency($invoice['invoice_balance']) ?>
                        </td>

                        <td class="ip-hide-on-mobile" data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a href="<?= site_url('invoices/view/' . (int) $invoice['invoice_id']) ?>">
                                            <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Vista') ?>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="<?= site_url('invoices/edit/' . (int) $invoice['invoice_id']) ?>">
                                            <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="<?= site_url('invoices/generate_pdf/' . (int) $invoice['invoice_id']) ?>"
                                           target="_blank">
                                            <i class="fa fa-regular fa-file-pdf fa-margin" aria-hidden="true"></i> <?= trans('download_pdf', '', 'PDF') ?>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="<?= site_url('mailer/invoice/' . (int) $invoice['invoice_id']) ?>">
                                            <i class="fa fa-solid fa-envelope fa-margin" aria-hidden="true"></i> <?= trans('email_invoice', '', 'Invia fattura') ?>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="<?= site_url('invoices/preview_xml/' . (int) $invoice['invoice_id']) ?>">
                                            <i class="fa fa-regular fa-file-code fa-margin" aria-hidden="true"></i> <?= trans('xml_preview', '', 'Anteprima fattura elettronica') ?>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="<?= site_url('invoices/generate_xml/' . (int) $invoice['invoice_id']) ?>"
                                           target="_blank">
                                            <i class="fa fa-regular fa-file-code fa-margin" aria-hidden="true"></i> <?= trans('it_download_xml', '', 'XML FatturaPA') ?>
                                        </a>
                                    </li>
                                    <?php if ((int) $invoice['invoice_status_id'] === 1): ?>
                                        <li>
                                            <form action="<?= site_url('invoices/delete/' . (int) $invoice['invoice_id']) ?>"
                                                  method="POST">
                                                <?= csrf_field() ?>
                                                <button type="button" class="dropdown-button"
                                                        data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_invoice_warning', '', 'Eliminare la fattura?')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                                    <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                    <li>
                                        <form action="<?= site_url('invoices/send_sdi/' . (int) $invoice['invoice_id']) ?>"
                                              method="POST">
                                            <?= csrf_field() ?>
                                            <button type="button" class="dropdown-button"
                                                    data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>"
                                                    data-confirm-body="<?= esc(trans('send_to_sdi_confirm', '', 'Inviare la fattura allo SdI (Sistema di Interscambio) tramite il canale configurato?')) ?>"
                                                    data-confirm-ok="<?= esc(trans('send_to_sdi', '', 'Invia a SdI')) ?>">
                                                <i class="fa fa-regular fa-paper-plane fa-margin" aria-hidden="true"></i> <?= trans('send_to_sdi', '', 'Invia a SdI') ?>
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($invoices)): ?>
                    <tr class="empty-row">
                        <td colspan="12">
                            <i class="fa fa-regular fa-file-text empty-icon" aria-hidden="true"></i>
                            <?= trans('no_invoices') ?>
                            <a href="<?= site_url('invoices/form_create') ?>" class="empty-cta">
                                <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('new_invoice', '', 'Nuova fattura') ?>
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>

                <?php
                /* Totali sotto le colonne che riassumono. Con l'elenco vuoto
                   il partial non stampa nulla: gli zeri sarebbero una terza
                   copia del "non c'è niente" (empty-state + riepilogo in
                   testa) e ruberebbero spazio alla chiamata all'azione. */
                ?>
                <?= $this->include('layout/list_totals', [
                    'totals'        => $period_totals ?? [],
                    'label_colspan' => 7,
                    // "Creata" (dentro le 7) e "Imponibile" sono colonne
                    // secondarie: il piede deve sparire con loro.
                    'secondary_before' => 1,
                    'net_secondary'    => true,
                ]) ?>

            </table>
        </div>
        </div>
        <?php
        /* Esportazioni coerenti con il periodo mostrato: anno e mesi passano
           nella query string, così il file contiene le stesse righe che si
           stanno guardando. Con la lista vuota la barra sparisce: non c'è
           nulla da esportare. */
        $exportQuery = '?year=' . (int) ($year ?? 0)
                     . ($monthQuery !== [] ? '&' . http_build_query($monthQuery) : '')
                     . ($sign !== '' ? '&sign=' . $sign : '');
        ?>
        <?= $this->include('layout/list_export_bar', [
            'empty' => empty($invoices),
            'links' => [
                [
                    'url'   => site_url('exports/invoices') . $exportQuery,
                    'label' => trans('export_invoices_xls', '', 'Fatture in XLS'),
                    'icon'  => 'fa-solid fa-file-excel',
                ],
                [
                    'url'   => site_url('exports/xml') . $exportQuery,
                    'label' => trans('export_invoices_xml', '', 'Archivio XML fatture'),
                    'icon'  => 'fa-solid fa-file-code',
                ],
            ],
        ]) ?>
        <?= $this->include('layout/pager_footer', ['group' => 'ip_invoices']) ?>
    </div>
</div>

<?= $this->endSection() ?>