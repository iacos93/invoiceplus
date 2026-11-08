<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Piano dei conti del Bilancino.
 *
 * L'albero è mostrato per tipo (ricavi, costi variabili, costi fissi) e non in
 * un unico elenco ordinato per codice: il tipo è ciò che determina il posto di
 * una voce nel report, e vederlo prima del codice evita di chiedersi perché
 * una voce di costo non compare fra i ricavi.
 */
$this->setVar('pageTitle', trans('management_plan', '', 'Piano dei conti'));

$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('management/accounts/form') . '">'
    . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('management_new_account', '', 'Nuova voce') . '</a>';

$typeLabels = \App\Models\CostingAccountModel::TYPES;

// Render ricorsivo con rientro visivo. Il conteggio dei figli serve a mostrare
// il totale del padre come somma, non come importo digitato a parte.
$renderRows = static function (array $nodes, int $level = 0) use (&$renderRows, $typeLabels, $budget, $year): string {
    $html = '';

    foreach ($nodes as $node) {
        $id       = (int) $node['account_id'];
        $children = (array) ($node['children'] ?? []);
        $annual   = (float) ($budget[$id] ?? 0);
        $inactive = (int) $node['account_active'] !== 1;

        $html .= '<tr class="' . ($inactive ? 'is-inactive' : '') . '">';
        $html .= '<td class="ip-bilancino__name" style="--ip-level: ' . $level . '">'
            . '<span class="ip-bilancino__indent" aria-hidden="true"></span>'
            . ($children !== [] ? '<i class="fa fa-solid fa-caret-down ip-bilancino__caret" aria-hidden="true"></i> ' : '')
            . '<span class="ip-plan-code">' . esc($node['account_code']) . '</span> '
            . esc($node['account_name'])
            . ($inactive ? ' <span class="badge text-bg-secondary">' . esc(trans('inactive', '', 'Disattivata')) . '</span>' : '')
            . ($node['account_note'] ? '<span class="ip-table-cell-note">' . esc($node['account_note']) . '</span>' : '')
            . '</td>';
        $html .= '<td><span class="badge ' . esc(match ((string) $node['account_type']) {
            'ricavo'          => 'text-bg-success',
            'costo_variabile' => 'text-bg-warning',
            default           => 'text-bg-secondary',
        }) . '">' . esc($typeLabels[$node['account_type']] ?? $node['account_type']) . '</span></td>';
        $html .= '<td class="text-end">' . ($annual != 0.0 ? format_currency($annual) : '<span class="text-muted">—</span>') . '</td>';
        $html .= '<td class="text-end">';
        $html .= '<div class="btn-group btn-group-sm">';
        $html .= '<a class="btn btn-secondary" href="' . site_url('management/accounts/form/' . $id) . '" title="' . esc(trans('edit', '', 'Modifica'), 'attr') . '">'
            . '<i class="fa fa-regular fa-edit" aria-hidden="true"></i><span class="visually-hidden">' . esc(trans('edit', '', 'Modifica')) . '</span></a>';
        $html .= '<form method="post" action="' . site_url('management/accounts/delete/' . $id) . '" class="d-inline">'
            . csrf_field()
            . '<button type="button" class="btn btn-secondary" data-bs-toggle="confirm"'
            . ' data-confirm-title="' . esc(trans('confirm', '', 'Conferma'), 'attr') . '"'
            . ' data-confirm-body="' . esc(trans('management_delete_account_warning', '', 'Eliminare questa voce? Se è usata da righe o spese non verrà eliminata: disattivala.'), 'attr') . '"'
            . ' data-confirm-ok="' . esc(trans('delete', '', 'Elimina'), 'attr') . '" data-confirm-danger="1">'
            . '<i class="fa fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden">' . esc(trans('delete', '', 'Elimina')) . '</span></button>'
            . '</form>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr>';

        if ($children !== []) {
            $html .= $renderRows($children, $level + 1);
        }
    }

    return $html;
};
?>

<?= $this->include('layout/headerbar', ['title' => trans('management_plan', '', 'Piano dei conti') . ' · ' . $year, 'actions' => $actions]) ?>

<?php /* #content semplice come le altre quattro schede del modulo, non
         .table-content: quel contenitore azzera il padding verticale perché
         la tabella aderisca all'headerbar, ma qui in testa c'è la subnav e la
         barra si incollava sotto il titolo, facendo sembrare la pagina più
         stretta e compressa delle schede accanto. */ ?>
<div id="content">

    <?= $this->include('management/_tabs', ['active' => 'accounts']) ?>

    <?php if (!$has_plan): ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-sitemap" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_empty_title', '', 'Prima il piano dei conti') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <p><?= trans('management_empty_body', '', 'Il Bilancino legge i ricavi e i costi in base alle voci del piano dei conti. Puoi partire dal piano standard — venti voci già divise fra ricavi, costi variabili e costi fissi — e personalizzarlo dopo.') ?></p>
                <div class="ip-bilancino-actions">
                    <form method="post" action="<?= site_url('management/accounts/import_standard') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-solid fa-download" aria-hidden="true"></i>
                            <?= trans('management_import_standard', '', 'Importa piano standard') ?>
                        </button>
                    </form>
                    <a class="btn btn-secondary" href="<?= site_url('management/accounts/form') ?>">
                        <i class="fa fa-solid fa-plus" aria-hidden="true"></i>
                        <?= trans('management_new_account', '', 'Crea una voce') ?>
                    </a>
                </div>
            </div>
        </section>
    <?php else: ?>
        <div class="ip-management-tools">
            <form method="post" action="<?= site_url('management/accounts/import_standard') ?>" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-secondary">
                    <i class="fa fa-solid fa-download" aria-hidden="true"></i>
                    <?= trans('management_import_standard', '', 'Importa piano standard') ?>
                </button>
            </form>
        </div>

        <div class="card ip-table-card">
            <div class="card-header ip-table-card-header">
                <div class="ip-table-card-title">
                    <span class="card-header-icon"><i class="fa fa-solid fa-sitemap" aria-hidden="true"></i></span>
                    <span class="ip-table-card-label"><?= trans('management_plan', '', 'Piano dei conti') ?></span>
                    <span class="badge text-bg-secondary"><?= count($accounts) ?></span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover ip-bilancino ip-bilancino--plan">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('management_item', '', 'Voce') ?></th>
                        <th scope="col"><?= trans('management_type', '', 'Tipo') ?></th>
                        <th scope="col" class="text-end"><?= trans('management_budget_year', '', 'Budget') . ' ' . $year ?></th>
                        <th scope="col" class="text-end"><?= trans('options', '', 'Opzioni') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (['ricavo', 'costo_variabile', 'costo_fisso'] as $type): ?>
                        <?php $group = array_values(array_filter($tree, static fn(array $n): bool => $n['account_type'] === $type)); ?>
                        <tr class="ip-bilancino__section">
                            <th scope="colgroup" colspan="4"><span class="ip-bilancino__band"><?= esc($typeLabels[$type]) ?></span></th>
                        </tr>
                        <?php if ($group === []): ?>
                            <tr>
                                <td colspan="4" class="ip-bilancino__empty">
                                    <?= trans('management_no_accounts_type', '', 'Nessuna voce di questo tipo: aggiungine una dal piano dei conti.') ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?= $renderRows($group) ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
