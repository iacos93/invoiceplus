<?php $this->setVar('pageTitle', trans('quotes')); ?>
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

// Il periodo va propagato ai link di stato (vedi invoices/index).
// year sempre esplicito (anche 0 = "Tutti"): senza, i link di stato
// perderebbero la scelta "Tutti" fatta nella barra periodo e ricadrebbero
// sul filtro anno globale della topbar al click successivo.
$favourite   = (string) ($favourite ?? '');
$months      = is_array($months ?? null) ? $months : [];
$monthQuery  = ip_month_query($months);
$periodBase  = '?year=' . (int) ($year ?? 0)
             . ($monthQuery !== [] ? '&' . http_build_query($monthQuery) : '');
$periodQuery = $periodBase . ($favourite !== '' ? '&fav=' . $favourite : '');

// Ogni filtro produce un descrittore, non markup: la barra lo disegna una
// volta sola (layout/list_filters) e resta identica su desktop e telefono.
// Prima le stesse voci erano scritte due volte, una nell'headerbar e una nel
// sottomenu mobile, e le due copie divergevano — "Preferiti" non esisteva su
// telefono.
$btn = function ($key, $label) use ($status, $periodQuery) {
    return [
        'url'    => site_url('quotes/status/' . $key) . $periodQuery,
        'label'  => $label,
        'active' => $status === $key,
    ];
};

// Il filtro preferiti resta accanto agli stati e ne conserva la scelta: sono
// tagli diversi dello stesso elenco, non alternativi.
//
// Il gruppo porta un'etichetta visibile ("Preferiti") perché senza di essa
// erano due controlli segmentati identici affiancati, ciascuno con la propria
// voce "Tutti" attiva: non si capiva quale dei due filtri si stesse togliendo.
// La voce "Tutti" è esplicita e in testa, come nel gruppo stati: il ritorno
// all'elenco completo non deve dipendere dal ricordarsi che un filtro si
// spegne ricliccandolo.
$favBtn = function ($value, $label, $icon) use ($status, $periodBase, $favourite) {
    // Ogni voce punta a se stessa; '' ("Tutti") omette del tutto &fav.
    $query = $periodBase . ($value !== '' ? '&fav=' . $value : '');

    return [
        'url'    => site_url('quotes/status/' . $status) . $query,
        'label'  => $label,
        'icon'   => $icon,
        'active' => $favourite === $value,
    ];
};

$filterGroups = [
    [
        'label'   => trans('status', '', 'Stato'),
        'buttons' => [
            $btn('all', trans('all', '', 'Tutti')),
            $btn('draft', trans('draft', '', 'Bozza')),
            $btn('sent', trans('sent', '', 'Inviato')),
            $btn('viewed', trans('viewed', '', 'Visto')),
            $btn('approved', trans('approved', '', 'Approvato')),
            $btn('rejected', trans('rejected', '', 'Rifiutato')),
            $btn('canceled', trans('canceled', '', 'Cancellato')),
        ],
    ],
    [
        'label'   => trans('favourites', '', 'Preferiti'),
        'buttons' => [
            $favBtn('', trans('all', '', 'Tutti'), ''),
            $favBtn('1', trans('yes', '', 'Sì'), 'fa-solid fa-star'),
            $favBtn('0', trans('no', '', 'No'), 'fa-regular fa-star'),
        ],
    ],
];

// Quanti filtri sono accesi oltre ai valori predefiniti (la prima voce di ogni
// gruppo è il "Tutti" che equivale a nessun filtro). Il numero finisce nel
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

$actions = '<button type="button" class="btn btn-secondary btn-sm submenu-toggle d-lg-none"'
         . ' data-bs-toggle="collapse" data-bs-target="#ip-status-submenu-collapse"'
         . ' aria-controls="ip-status-submenu-collapse" aria-expanded="false">'
         . '<i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . trans('filter', '', 'Filtra')
         . ($activeFilters > 0 ? ' <span class="ip-btn-count">' . $activeFilters . '</span>' : '')
         . '</button>'
         . '<a class="btn btn-sm btn-primary" href="' . site_url('quotes/form_create') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('create_quote') . '</a>';
?>
<?= $this->include('layout/headerbar', [
    'title'   => trans('quotes'),
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
    'base_url'          => 'quotes/status/' . $status,
    'year'              => $year ?? 0,
    'months'            => $months,
    'month'             => $month ?? 0,
    'totals'            => $period_totals ?? [],
    'month_totals'      => $month_totals ?? [],
    'month_total_label' => trans('quoted', '', 'Preventivato'),
    // La barra periodo ricostruisce l'URL da zero: ogni filtro che non
    // viaggia in extra_query sparisce al primo clic su un mese.
    'extra_query'       => array_filter([
        'fav' => $favourite,
    ], static fn ($v) => $v !== '' && $v !== null),
]) ?>

<div id="content" class="table-content">
    <form method="POST" action="<?= site_url('quotes/bulk') ?>" id="ip-bulk-form" class="ip-bulk">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= esc(current_url(true), 'attr') ?>">

        <div class="ip-bulk-bar" id="ip-bulk-bar" hidden>
            <span class="ip-bulk-bar__count" id="ip-bulk-count"
                  data-template="<?= esc(trans('bulk_selected', '', '%s selezionati'), 'attr') ?>"></span>
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
                        data-confirm-body="<?= esc(trans('bulk_mark_sent_confirm_quote', '', 'Le bozze selezionate passano a "inviato" e ricevono il numero definitivo.'), 'attr') ?>"
                        data-confirm-ok="<?= esc(trans('bulk_mark_sent', '', 'Segna come inviato'), 'attr') ?>">
                    <i class="fa fa-regular fa-paper-plane" aria-hidden="true"></i> <?= trans('bulk_mark_sent', '', 'Segna come inviato') ?>
                </button>
                <button type="submit" name="bulk_action" value="delete" class="btn btn-sm btn-secondary ip-bulk-bar__danger"
                        data-bs-toggle="confirm"
                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                        data-confirm-body="<?= esc(trans('bulk_delete_confirm_quote', '', 'Eliminare i preventivi selezionati?'), 'attr') ?>"
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
                <span class="card-header-icon"><i class="fa fa-regular fa-file" aria-hidden="true"></i></span>
                <h2 class="ip-table-card-label"><?= esc(trans('quotes')) ?></h2>
                <span class="badge text-bg-secondary"><?= count($quotes) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search"
                 data-target="#quotes-table-wrap"
                 data-action="<?= site_url('filter/filter_quotes') ?>">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca preventivo, cliente…')) ?>"
                       autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>
        <div id="quotes-table-wrap">
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse">
            <thead>
            <tr>
                <th class="ip-bulk-col ip-hide-on-mobile" scope="col">
                    <label class="ip-bulk-hit">
                        <input type="checkbox" class="form-check-input" id="ip-bulk-all"
                               aria-label="<?= esc(trans('bulk_select_all', '', 'Seleziona tutti i preventivi in pagina'), 'attr') ?>">
                    </label>
                </th>
                <th class="ip-fav-col ip-hide-on-mobile" scope="col"><span class="visually-hidden"><?= trans('favourite', '', 'Preferito') ?></span></th>
                <th scope="col"><?= trans('status') ?></th>
                <th scope="col"><?= trans('quote') ?></th>
                <th scope="col" class="ip-col-secondary"><?= trans('created') ?></th>
                <th scope="col"><?= trans('expires') ?></th>
                <th scope="col"><?= trans('client_name') ?></th>
                <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                <th class="amount" scope="col"><?= trans('total') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($quotes as $quote): ?>
                <?php
                $statusInfo  = $quote_statuses[(string) $quote['quote_status_id']] ?? ['label' => '', 'class' => 'draft'];
                $isFavourite = !empty($quote['quote_is_favourite']);
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
                                   name="ids[]" value="<?= (int) $quote['quote_id'] ?>"
                                   aria-label="<?= esc(trans('bulk_select_row', '', 'Seleziona il preventivo') . ' ' . ($quote['quote_number'] ?: '#' . $quote['quote_id']), 'attr') ?>">
                        </label>
                    </td>
                    <td class="ip-fav-col ip-hide-on-mobile" data-label="<?= esc(trans('favourite', '', 'Preferito')) ?>">
                        <form method="POST" action="<?= site_url('quotes/toggle_favourite/' . (int) $quote['quote_id']) ?>">
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
                    <td data-cell="status" data-label="<?= esc(trans('status')) ?>">
                        <span class="badge ip-status <?= $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary' ?>">
                            <?= esc($statusInfo['label']) ?>
                        </span>
                    </td>
                    <td data-cell="number" data-label="<?= esc(trans('quote')) ?>">
                        <a href="<?= site_url('quotes/view/' . $quote['quote_id']) ?>">
                            <span class="ip-num"><?= esc($quote['quote_number'] ?: $quote['quote_id']) ?></span>
                        </a>
                    </td>
                    <td class="ip-hide-on-mobile ip-col-secondary" data-label="<?= esc(trans('created')) ?>"><?= esc(date_from_mysql($quote['quote_date_created'])) ?></td>
                    <td data-cell="due" data-label="<?= esc(trans('expires')) ?>"><?= esc(date_from_mysql($quote['quote_date_expires'])) ?></td>
                    <td data-cell="client" data-label="<?= esc(trans('client_name')) ?>">
                        <a href="<?= site_url('clients/view/' . $quote['client_id']) ?>">
                            <?= esc(trim($quote['client_name'] . ' ' . ($quote['client_surname'] ?? ''))) ?>
                        </a>
                    </td>
                    <td class="ip-hide-on-mobile" data-label="<?= esc(trans('invoice', '', 'Fattura')) ?>">
                        <?php if (!empty($quote['invoice_id'])): ?>
                            <?php /* Preventivo già convertito: il collegamento evita di
                                    doverla cercare fra le fatture per sapere com'è finita. */ ?>
                            <a href="<?= site_url('invoices/view/' . (int) $quote['invoice_id']) ?>"
                               class="ip-quote-invoice-ref"
                               title="<?= esc(trans('view_invoice', '', 'Vai alla fattura'), 'attr') ?>">
                                <i class="fa fa-regular fa-file-text" aria-hidden="true"></i>
                                <span class="ip-num"><?= esc($quote['invoice_number'] ?: ('#' . (int) $quote['invoice_id'])) ?></span>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="amount" data-cell="amount" data-label="<?= esc(trans('total')) ?>"><?= format_currency($quote['quote_total']) ?></td>
                    <td class="ip-hide-on-mobile" data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('quotes/view/' . $quote['quote_id']) ?>">
                                        <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view') ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= site_url('quotes/edit/' . $quote['quote_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= site_url('quotes/generate_pdf/' . $quote['quote_id']) ?>" target="_blank">
                                        <i class="fa fa-regular fa-file-pdf fa-margin" aria-hidden="true"></i> <?= trans('pdf') ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= site_url('mailer/quote/' . $quote['quote_id']) ?>">
                                        <i class="fa fa-solid fa-envelope fa-margin" aria-hidden="true"></i> <?= trans('email_quote', '', 'Invia preventivo') ?>
                                    </a>
                                </li>
                                <?php if ((int) $quote['quote_status_id'] === 1): ?>
                                <li>
                                    <?php /* La modifica di un preventivo esistente sta in
                                             quotes/edit: form_create apre un documento nuovo e
                                             non accetta un id, quindi questo link portava a
                                             un 404. */ ?>
                                    <a href="<?= site_url('quotes/edit/' . $quote['quote_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <li>
                                    <form action="<?= site_url('quotes/delete/' . $quote['quote_id']) ?>" method="POST">
                                        <?= csrf_field() ?>
                                        <button type="button" class="dropdown-button"
                                                data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_quote_warning')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete') ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($quotes)): ?>
                <tr class="empty-row">
                    <td colspan="9">
                        <i class="fa fa-regular fa-file empty-icon" aria-hidden="true"></i>
                        <?= trans('no_quotes') ?>
                        <a href="<?= site_url('quotes/form_create') ?>" class="empty-cta">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('create_quote', '', 'Nuovo preventivo') ?>
                        </a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>