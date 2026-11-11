<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('expenses', '', 'Spese'));

$months     = is_array($months ?? null) ? $months : [];
$paid       = (string) ($paid ?? '');
$sign       = (string) ($sign ?? '');
$monthQuery = ip_month_query($months);
// Stessa forma della lista fatture: anno e mesi (anche multipli) in un'unica
// query, riusata dai pulsanti di filtro e dai link di esportazione.
// Base senza il filtro tipo: serve ai pulsanti che cambiano proprio quel
// filtro, mentre gli altri la usano già completa.
$filterBase = '?year=' . (int) ($year ?? 0)
            . ($monthQuery !== [] ? '&' . http_build_query($monthQuery) : '');
$periodBase = $filterBase . ($sign !== '' ? '&sign=' . $sign : '');

// Ogni filtro produce un descrittore, non markup: la barra lo disegna una
// volta sola (layout/list_filters) e resta identica su desktop e telefono.
$btn = function ($key, $label) use ($periodBase, $paid) {
    $q = $periodBase . ($key !== '' ? '&paid=' . $key : '');

    return [
        'url'    => site_url('expenses') . $q,
        'label'  => $label,
        'active' => $paid === $key,
    ];
};

// Tipo documento: le note di credito ricevute sono spese con totale negativo,
// non uno stato. Gruppo separato perché i due filtri si combinano (una nota
// di credito può essere ancora da pagare o già chiusa).
$signBtn = function ($value, $label) use ($filterBase, $sign, $paid) {
    $query = $filterBase . ($value !== '' ? '&sign=' . $value : '')
           . ($paid !== '' ? '&paid=' . $paid : '');

    return [
        'url'    => site_url('expenses') . $query,
        'label'  => $label,
        'active' => $sign === $value,
    ];
};

$filterGroups = [
    [
        'label'   => trans('status', '', 'Stato'),
        'buttons' => [
            $btn('', trans('all', '', 'Tutte')),
            $btn('open', trans('unpaid', '', 'Da pagare')),
            $btn('paid', trans('paid', '', 'Pagate')),
        ],
    ],
    [
        'label'   => trans('type', '', 'Tipo'),
        'buttons' => [
            $signBtn('', trans('all', '', 'Tutte')),
            $signBtn('invoice', trans('expenses', '', 'Spese')),
            $signBtn('credit', trans('credit_notes', '', 'Note di credito')),
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

// Niente "Importa XML" nella barra della lista, come in Fatture: l'import
// delle fatture ricevute ha già la sua voce sotto Spese nella sidebar. Resta
// invece la chiamata all'import nello stato vuoto, dove è l'uscita utile
// quando non c'è ancora nulla da guardare.
$actions = '<button type="button" class="btn btn-secondary btn-sm submenu-toggle d-lg-none"'
         . ' data-bs-toggle="collapse" data-bs-target="#ip-status-submenu-collapse"'
         . ' aria-controls="ip-status-submenu-collapse" aria-expanded="false">'
         . '<i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . trans('filter', '', 'Filtra')
         . ($activeFilters > 0 ? ' <span class="ip-btn-count">' . $activeFilters . '</span>' : '')
         . '</button>'
         . '<a class="btn btn-sm btn-primary" href="' . site_url('expenses/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('add_expense', '', 'Nuova spesa') . '</a>';
?>
<?= $this->include('layout/headerbar', [
    'title'   => trans('expenses', '', 'Spese'),
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
    'base_url'          => 'expenses',
    'year'              => $year ?? 0,
    'months'            => $months,
    'month'             => $month ?? 0,
    'totals'            => $period_totals ?? [],
    'month_totals'      => $month_totals ?? [],
    'month_total_label' => trans('expenses', '', 'Spese'),
    // La barra periodo ricostruisce l'URL da zero: ogni filtro che non
    // viaggia in extra_query sparisce al primo clic su un mese.
    'extra_query'       => array_filter([
        'sign' => $sign,
        'paid' => $paid,
    ], static fn ($v) => $v !== '' && $v !== null),
]) ?>

<div id="content" class="table-content">
    <?php
    /* Azioni di gruppo, come nelle fatture. Il modulo contiene solo la barra e
       si chiude subito: le caselle di selezione gli appartengono tramite
       l'attributo HTML5 "form", lo stesso schema della lista fatture.
       Avvolgere la tabella avrebbe annidato i moduli di riga gia' presenti
       (elimina), e i moduli annidati non sono HTML valido.

       Le azioni sono quelle che una spesa ha davvero: segnare il pagato (o
       azzerarlo) e cancellare. Non c'e' il preferito, che le spese non hanno. */
    ?>
    <form method="POST" action="<?= site_url('expenses/bulk') ?>" id="ip-bulk-form" class="ip-bulk">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= esc(current_url(true), 'attr') ?>">

        <div class="ip-bulk-bar" id="ip-bulk-bar" hidden>
            <span class="ip-bulk-bar__count" id="ip-bulk-count"
                  data-template="<?= esc(trans('bulk_selected', '', '%s selezionate'), 'attr') ?>"></span>
            <div class="ip-bulk-bar__actions">
                <button type="submit" name="bulk_action" value="mark_paid" class="btn btn-sm btn-secondary"
                        data-bs-toggle="confirm"
                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                        data-confirm-body="<?= esc(trans('bulk_mark_paid_confirm', '', 'Le spese selezionate risultano pagate per intero: le note di credito risultano rimborsate. Il residuo va a zero, come se avessi scritto l\'importo pagato a mano.'), 'attr') ?>"
                        data-confirm-ok="<?= esc(trans('bulk_mark_paid', '', 'Segna come pagate'), 'attr') ?>">
                    <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('bulk_mark_paid', '', 'Segna come pagate') ?>
                </button>
                <button type="submit" name="bulk_action" value="mark_unpaid" class="btn btn-sm btn-secondary"
                        data-bs-toggle="confirm"
                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                        data-confirm-body="<?= esc(trans('bulk_mark_unpaid_confirm', '', 'Le spese selezionate tornano con pagato zero: il residuo torna al totale del documento, e una nota di credito torna da rimborsare.'), 'attr') ?>"
                        data-confirm-ok="<?= esc(trans('bulk_mark_unpaid', '', 'Segna come da pagare'), 'attr') ?>">
                    <i class="fa fa-solid fa-rotate-left" aria-hidden="true"></i> <?= trans('bulk_mark_unpaid', '', 'Segna come da pagare') ?>
                </button>
                <button type="submit" name="bulk_action" value="delete" class="btn btn-sm btn-secondary ip-bulk-bar__danger"
                        data-bs-toggle="confirm"
                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                        data-confirm-body="<?= esc(trans('bulk_delete_confirm_expenses', '', 'Eliminare le spese selezionate? Insieme alle spese vengono eliminati anche gli XML archiviati.'), 'attr') ?>"
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
                <span class="card-header-icon"><i class="fa fa-solid fa-shopping-cart" aria-hidden="true"></i></span>
                <h2 class="ip-table-card-label"><?= esc(trans('expenses', '', 'Spese')) ?></h2>
                <?php /* Il totale del periodo filtrato, non le righe in pagina:
                         con la paginazione attiva il badge diceva "15" anche
                         con trecento spese. */ ?>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_expenses') : count($expenses) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search"
                 data-target="#expenses-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca fornitore, numero…')) ?>"
                       autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div id="expenses-table-wrap">
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="expenses-list-table">
                <thead>
                <tr>
                    <th class="ip-bulk-col ip-hide-on-mobile" scope="col">
                        <?php /* La casella sta dentro una <label>: da sola e' 16x16,
                                 sotto il minimo di 24px per un bersaglio (WCAG 2.5.8).
                                 L'etichetta porta l'area cliccabile a 24x24 senza
                                 ingrandire il quadratino. */ ?>
                        <label class="ip-bulk-hit">
                            <input type="checkbox" class="form-check-input" id="ip-bulk-all"
                                   aria-label="<?= esc(trans('bulk_select_all_expenses', '', 'Seleziona tutte le spese in pagina'), 'attr') ?>">
                        </label>
                    </th>
                    <th scope="col"><?= trans('date', '', 'Data') ?></th>
                    <th scope="col"><?= trans('number', '', 'Numero') ?></th>
                    <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                    <th scope="col"><?= trans('supplier', '', 'Fornitore') ?></th>
                    <th scope="col" class="ip-col-secondary"><?= trans('category', '', 'Categoria') ?></th>
                    <th scope="col" class="amount ip-col-secondary"><?= trans('subtotal', '', 'Imponibile') ?></th>
                    <?php
                    // IVA solo sugli schermi larghi (>= 1400 px): è la differenza
                    // fra Totale e Imponibile, e sotto quella larghezza la tabella
                    // usciva dal contenitore facendo scorrere tutta la pagina.
                    ?>
                    <th scope="col" class="amount d-none d-xxl-table-cell"><?= trans('vat', '', 'IVA') ?></th>
                    <th scope="col" class="amount"><?= trans('total', '', 'Totale') ?></th>
                    <th scope="col" class="amount"><?= trans('balance', '', 'Da pagare') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($expenses as $expense): ?>
                    <?php
                    $open          = (float) $expense['expense_total'] - (float) $expense['expense_paid'];
                    // Stato e residuo sono due letture dello stesso dato: la
                    // regola sta in un posto solo (helpers), così qui il badge
                    // non può divergere dal filtro Da pagare / Pagate.
                    $expenseStatus = expense_status_badge($expense);
                    ?>
                    <tr>
                        <td class="ip-bulk-col ip-hide-on-mobile">
                            <label class="ip-bulk-hit">
                                <input type="checkbox" class="form-check-input ip-bulk-check" form="ip-bulk-form"
                                       name="ids[]" value="<?= (int) $expense['expense_id'] ?>"
                                       aria-label="<?= esc(trans('bulk_select_row_expenses', '', 'Seleziona la spesa') . ' ' . ($expense['expense_number'] ?: '#' . $expense['expense_id']), 'attr') ?>">
                            </label>
                        </td>
                        <?php
                        // data-cell: su telefono la riga diventa la stessa scheda
                        // compatta della lista fatture (numero e totale, fornitore
                        // e azioni, data e residuo) invece di otto righe
                        // etichettate per spesa. Categoria e imponibile restano
                        // solo nella vista tabellare.
                        ?>
                        <td data-cell="due" data-label="<?= esc(trans('date', '', 'Data')) ?>"><?= esc(date_from_mysql($expense['expense_date'])) ?></td>
                        <td data-cell="number" data-label="<?= esc(trans('number', '', 'Numero')) ?>">
                            <span class="ip-num"><?= esc($expense['expense_number'] ?: '—') ?></span>
                            <?php if (($expense['expense_source'] ?? '') === 'xml'): ?>
                                <?php /* Da fattura elettronica: utile sapere che i dati
                                         non sono stati digitati a mano. */ ?>
                                <span class="badge ip-status text-bg-info ip-num-tag" title="<?= esc($expense['expense_file'] ?? '', 'attr') ?>">XML</span>
                            <?php endif; ?>
                        </td>
                        <td data-cell="status" data-label="<?= esc(trans('status', '', 'Stato')) ?>">
                            <span class="badge ip-status <?= esc($expenseStatus['class'], 'attr') ?>"><?= esc($expenseStatus['label']) ?></span>
                        </td>
                        <td data-cell="client" data-label="<?= esc(trans('supplier', '', 'Fornitore')) ?>">
                            <?= esc($expense['supplier_name']) ?>
                            <?php if (!empty($expense['supplier_vat'])): ?>
                                <br><small class="text-muted"><?= esc($expense['supplier_vat']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="ip-hide-on-mobile ip-col-secondary" data-label="<?= esc(trans('category', '', 'Categoria')) ?>"><?= esc($expense['expense_category_name'] ?? '—') ?></td>
                        <td class="amount ip-hide-on-mobile ip-col-secondary" data-label="<?= esc(trans('subtotal', '', 'Imponibile')) ?>"><?= format_currency($expense['expense_amount']) ?></td>
                        <td class="amount d-none d-xxl-table-cell" data-label="<?= esc(trans('vat', '', 'IVA')) ?>"><?= format_currency($expense['expense_tax']) ?></td>
                        <td class="amount" data-cell="amount" data-label="<?= esc(trans('total', '', 'Totale')) ?>"><?= format_currency($expense['expense_total']) ?></td>
                        <?php
                        // Il residuo si mostra con il proprio segno: su una
                        // nota di credito ricevuta è negativo (rimborso
                        // ancora da incassare) e con il vecchio "$open > 0"
                        // spariva dietro un trattino, come se fosse saldata.
                        ?>
                        <td class="amount" data-cell="balance" data-label="<?= esc(trans('balance', '', 'Da pagare')) ?>"><?= round($open, 2) != 0.0 ? format_currency($open) : '—' ?></td>
                        <td data-cell="actions" data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <?php if (($expense['expense_source'] ?? '') === 'xml'): ?>
                                        <li><a href="<?= site_url('expenses/preview_xml/' . (int) $expense['expense_id']) ?>">
                                            <i class="fa fa-regular fa-file-code fa-margin" aria-hidden="true"></i> <?= trans('xml_preview', '', 'Anteprima fattura elettronica') ?></a></li>
                                    <?php endif; ?>
                                    <li><a href="<?= site_url('expenses/form/' . (int) $expense['expense_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?></a></li>
                                    <li>
                                        <form action="<?= site_url('expenses/delete/' . (int) $expense['expense_id']) ?>" method="POST">
                                            <?= csrf_field() ?>
                                            <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                    data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                    data-confirm-body="<?= esc(trans('delete_record_warning', '', 'Eliminare la spesa?'), 'attr') ?>"
                                                    data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($expenses)): ?>
                    <!-- Stessa forma delle altre liste (tr.empty-row): icona,
                         testo e chiamata all'azione — prima era solo una riga
                         di testo muto, senza via d'uscita. -->
                    <tr class="empty-row">
                        <td colspan="11">
                            <i class="fa fa-solid fa-shopping-cart empty-icon" aria-hidden="true"></i>
                            <?= trans('no_expenses', '', 'Nessuna spesa nel periodo.') ?>
                            <div class="empty-actions">
                                <a href="<?= site_url('expenses/form') ?>" class="empty-cta">
                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_expense', '', 'Nuova spesa') ?>
                                </a>
                                <a href="<?= site_url('expenses/import') ?>" class="empty-cta">
                                    <i class="fa fa-solid fa-upload" aria-hidden="true"></i> <?= trans('import_xml', '', 'Importa XML') ?>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>

                <?php
                /* Stesso piede della lista fatture: con l'elenco vuoto non
                   stampa nulla (il partial esce quando count è 0). */
                ?>
                <?= $this->include('layout/list_totals', [
                    'totals'        => $period_totals ?? [],
                    // Selezione, data, numero, stato, fornitore, categoria.
                    'label_colspan' => 6,
                    // "Categoria" (dentro le 6) e "Imponibile" sono colonne
                    // secondarie: il piede deve sparire con loro.
                    'secondary_before' => 1,
                    'net_secondary'    => true,
                ]) ?>
            </table>
        </div>
        </div>

        <?= $this->include('layout/list_export_bar', [
            'empty' => empty($expenses),
            'links' => [
                [
                    'url'   => site_url('exports/expenses') . $periodBase,
                    'label' => trans('export_expenses_xls', '', 'Spese in XLS'),
                    'icon'  => 'fa-solid fa-file-excel',
                ],
            ],
        ]) ?>

        <?php /* Paginazione come fatture e clienti: il partial non stampa
                 nulla quando c'e' una sola pagina. */ ?>
        <?= $this->include('layout/pager_footer', ['group' => 'ip_expenses']) ?>
    </div>
</div>

<?= $this->endSection() ?>
