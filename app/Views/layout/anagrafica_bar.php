<?php
/**
 * Partial: barra di intestazione delle anagrafiche (clienti e fornitori).
 *
 * Clienti e fornitori sono la stessa schermata con due elenchi diversi, ma il
 * markup era scritto due volte — schede, filtro di stato, esporta/importa e
 * pulsante "nuovo" — e le due copie avevano già iniziato a divergere (una sola
 * aveva il sottomenu mobile compilato in modo identico, e le etichette di
 * esportazione non coincidevano). Qui la barra nasce da un solo posto, e il
 * parametro $entity decide solo destinazioni, etichette e icona.
 *
 * Emette headerbar + sottomenu mobile: la view che lo include non deve
 * aggiungere altro chrome, solo la tabella.
 *
 * Parametri:
 *   entity 'clients' | 'suppliers'
 *   status 'active' | 'inactive' | 'all'  (default 'active')
 */
$_ab = (array) ($this->renderVars['options'] ?? []);

$entity     = ($_ab['entity'] ?? 'clients') === 'suppliers' ? 'suppliers' : 'clients';
$status     = (string) ($_ab['status'] ?? 'active');
$isSupplier = $entity === 'suppliers';
$base       = $isSupplier ? 'suppliers' : 'clients';
$otherBase  = $isSupplier ? 'clients' : 'suppliers';

$labelSelf  = $isSupplier ? trans('suppliers', '', 'Fornitori') : trans('clients');
$labelOther = $isSupplier ? trans('clients') : trans('suppliers', '', 'Fornitori');
$iconSelf   = $isSupplier ? 'fa-solid fa-shopping-basket' : 'fa-solid fa-users';
$iconOther  = $isSupplier ? 'fa-solid fa-users' : 'fa-solid fa-shopping-basket';

// Etichette di esportazione: il tracciato è lo stesso nelle due anagrafiche,
// cambia solo il soggetto. Un fallback unico evita le due diciture diverse che
// c'erano nei due file.
$exportLabel = sprintf(
    $isSupplier ? trans('export_suppliers', '', 'Esporta %s CSV') : trans('export_clients', '', 'Esporta %s CSV'),
    mb_strtolower($labelSelf)
);
$importLabel = sprintf(
    $isSupplier ? trans('import_suppliers', '', 'Importa %s CSV') : trans('import_clients', '', 'Importa %s CSV'),
    mb_strtolower($labelSelf)
);
// "Nuovo cliente" e non "Aggiungi cliente": la stessa azione si chiamava in
// due modi (il fornitore era gia' "Nuovo") e la chiave add_client resta
// legata al suo altro senso, cioe' assegnare un cliente a un utente.
$addLabel = $isSupplier
    ? trans('add_supplier', '', 'Nuovo fornitore')
    : trans('new_client', '', 'Nuovo cliente');
$addOtherLabel = $isSupplier
    ? trans('new_client', '', 'Nuovo cliente')
    : trans('add_supplier', '', 'Nuovo fornitore');

$statusLabels = [
    'active'   => trans('active'),
    'inactive' => trans('inactive'),
    'all'      => trans('all'),
];
// Uno stato fuori elenco (URL digitato a mano) non deve accendere nessuna
// linguetta: il filtro "attivo" sarebbe una bugia su ciò che si sta vedendo.
$status = array_key_exists($status, $statusLabels) ? $status : '';

$statusUrl = static fn (string $key): string => site_url($base . '/status/' . $key);

$typeSwitch = '<div class="btn-group btn-group-sm me-2" role="group" aria-label="'
    . esc(trans('anagrafiche', '', 'Anagrafiche'), 'attr') . '">'
    . '<a href="' . site_url('clients') . '" class="btn ' . ($isSupplier ? 'btn-secondary' : 'btn-primary') . '">'
    . '<i class="fa fa-solid fa-users me-1" aria-hidden="true"></i> ' . esc(trans('clients')) . '</a>'
    . '<a href="' . site_url('suppliers') . '" class="btn ' . ($isSupplier ? 'btn-primary' : 'btn-secondary') . '">'
    . '<i class="fa fa-solid fa-shopping-basket me-1" aria-hidden="true"></i> '
    . esc(trans('suppliers', '', 'Fornitori')) . '</a>'
    . '</div>';

$statusButtons = '';
foreach ($statusLabels as $key => $label) {
    $statusButtons .= '<a href="' . $statusUrl($key) . '" class="btn '
        . ($status === $key ? 'btn-primary' : 'btn-secondary') . '"'
        . ($status === $key ? ' aria-current="page"' : '') . '>' . esc($label) . '</a>';
}

$filters = '<div class="d-flex align-items-center flex-wrap gap-2">'
    . $typeSwitch
    . '<div class="btn-group btn-group-sm index-options" role="group" aria-label="'
    . esc(trans('status', '', 'Stato'), 'attr') . '">' . $statusButtons . '</div>'
    . '</div>';

$actions = '<button type="button" class="btn btn-secondary btn-sm submenu-toggle d-lg-none"'
    . ' data-bs-toggle="collapse" data-bs-target="#ip-submenu-collapse"'
    . ' aria-controls="ip-submenu-collapse" aria-expanded="false">'
    . '<i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . esc(trans('filter', '', 'Filtra')) . '</button>'
    . '<div class="btn-group btn-group-sm me-1">'
    . '<a class="btn btn-secondary" href="' . site_url($base . '/export') . '" title="' . esc($exportLabel, 'attr') . '">'
    . '<i class="fa fa-solid fa-download" aria-hidden="true"></i> ' . esc(trans('export', '', 'Esporta')) . '</a>'
    . '<button type="button" class="btn btn-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">'
    . '<span class="visually-hidden">' . esc(trans('options', '', 'Opzioni')) . '</span></button>'
    . '<ul class="dropdown-menu dropdown-menu-end">'
    . '<li><a class="dropdown-item" href="' . site_url($base . '/export') . '">'
    . '<i class="fa fa-solid fa-download fa-margin" aria-hidden="true"></i> ' . esc($exportLabel) . '</a></li>'
    . '<li><a class="dropdown-item" href="' . site_url($base . '/export_template') . '">'
    . '<i class="fa fa-regular fa-file-text fa-margin" aria-hidden="true"></i> ' . esc(trans('download_template', '', 'Scarica template CSV')) . '</a></li>'
    . '<li><hr class="dropdown-divider"></li>'
    . '<li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#import' . ($isSupplier ? 'Suppliers' : 'Clients') . 'Modal">'
    . '<i class="fa fa-solid fa-upload fa-margin" aria-hidden="true"></i> ' . esc($importLabel) . '</a></li>'
    . '</ul>'
    . '</div>'
    . '<div class="btn-group btn-group-sm">'
    . '<a class="btn btn-primary" href="' . site_url($base . '/form') . '">'
    . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . esc($addLabel) . '</a>'
    . '<button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">'
    . '<span class="visually-hidden">' . esc(trans('options', '', 'Opzioni')) . '</span></button>'
    . '<ul class="dropdown-menu dropdown-menu-end">'
    . '<li><a class="dropdown-item" href="' . site_url($base . '/form') . '">'
    . '<i class="fa fa-solid fa-plus-circle fa-margin" aria-hidden="true"></i> ' . esc($addLabel) . '</a></li>'
    . '<li><a class="dropdown-item" href="' . site_url($otherBase . '/form') . '">'
    . '<i class="fa fa-solid fa-user-plus fa-margin" aria-hidden="true"></i> ' . esc($addOtherLabel) . '</a></li>'
    . '</ul>'
    . '</div>';
?>
<?= $this->include('layout/headerbar', [
    'title'   => $labelSelf,
    'filters' => $filters,
    'actions' => $actions,
]) ?>

<div id="submenu">
    <div class="collapse clearfix" id="ip-submenu-collapse">

        <div class="submenu-row">
            <div class="btn-group btn-group-sm w-100 mb-2" role="group" aria-label="<?= esc(trans('anagrafiche', '', 'Anagrafiche'), 'attr') ?>">
                <a href="<?= site_url('clients') ?>" class="btn <?= $isSupplier ? 'btn-secondary' : 'btn-primary' ?>">
                    <i class="fa fa-solid fa-users me-1" aria-hidden="true"></i> <?= trans('clients') ?></a>
                <a href="<?= site_url('suppliers') ?>" class="btn <?= $isSupplier ? 'btn-primary' : 'btn-secondary' ?>">
                    <i class="fa fa-solid fa-shopping-basket me-1" aria-hidden="true"></i> <?= trans('suppliers', '', 'Fornitori') ?></a>
            </div>
            <div class="btn-group btn-group-sm index-options w-100" role="group" aria-label="<?= esc(trans('status', '', 'Stato'), 'attr') ?>">
                <?php foreach ($statusLabels as $key => $label): ?>
                    <a href="<?= $statusUrl($key) ?>"
                       class="btn <?= $status === $key ? 'btn-primary' : 'btn-secondary' ?>"
                       <?= $status === $key ? 'aria-current="page"' : '' ?>>
                        <?= esc($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>
