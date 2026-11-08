<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('companies', '', 'Aziende'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('companies/form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new_company', '', 'Nuova azienda') . '</a>';
$active = active_company_id();
$companyForms = \App\Services\FinanceService::companyForms();
$deleteModals = [];
?>
<?= $this->include('layout/headerbar', ['title' => trans('companies', '', 'Aziende'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-building" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('companies', '', 'Aziende')) ?></span>
                <span class="badge text-bg-secondary"><?= count($companies) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse">
                <thead><tr>
                    <th scope="col"><?= trans('company', '', 'Azienda') ?></th>
                    <th scope="col"><?= trans('vat_id', '', 'Partita IVA') ?></th>
                    <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                    <th scope="col" class="text-end"><?= trans('options', '', 'Opzioni') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($companies as $c): ?>
                    <?php $isActive = (int) $c['company_id'] === $active; ?>
                    <tr>
                        <td data-cell="number">
                            <strong><?= esc($c['company_name']) ?></strong>
                            <?php
                            $formLabel = $companyForms[$c['company_form']]['label'] ?? ($c['company_form'] ?: '');
                            if ($formLabel): ?>
                                <br><small class="text-muted"><?= esc($formLabel) ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-cell="client"><span class="ip-num"><?= esc($c['company_vat_id'] ?: '—') ?></span></td>
                        <td data-cell="status">
                            <?php if ($isActive): ?>
                                <span class="badge ip-status text-bg-success"><?= trans('active_company', '', 'In uso') ?></span>
                            <?php endif; ?>
                            <?php if (db_bool($c['company_is_default'])): ?>
                                <span class="badge ip-status text-bg-info"><?= trans('default', '', 'Predefinita') ?></span>
                            <?php endif; ?>
                            <?php if (!db_bool($c['company_is_active'])): ?>
                                <span class="badge ip-status text-bg-warning"><?= trans('suspended', '', 'Sospesa') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ip-hide-on-mobile">
                            <div class="ip-doc-actions justify-content-end">
                                <?php if (!$isActive): ?>
                                    <form method="POST" action="<?= site_url('companies/switch') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="company_id" value="<?= (int) $c['company_id'] ?>">
                                        <input type="hidden" name="back" value="<?= esc(current_url(true), 'attr') ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary">
                                            <i class="fa fa-solid fa-right-left" aria-hidden="true"></i> <?= trans('switch_to', '', 'Passa a questa') ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-secondary" href="<?= site_url('companies/form/' . (int) $c['company_id']) ?>">
                                    <i class="fa fa-regular fa-edit" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?>
                                </a>
                                <?php if (!$isActive): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-secondary dropdown-toggle" type="button"
                                                data-bs-toggle="dropdown" aria-expanded="false"
                                                aria-label="<?= esc(trans('more_actions', '', 'Altre azioni'), 'attr') ?>">
                                            <i class="fa fa-solid fa-ellipsis-h" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <form method="POST" action="<?= site_url('companies/toggle/' . (int) $c['company_id']) ?>">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="dropdown-item <?= db_bool($c['company_is_active']) ? 'text-warning' : 'text-success' ?>">
                                                        <i class="fa <?= db_bool($c['company_is_active']) ? 'fa-solid fa-pause' : 'fa-solid fa-play' ?>" aria-hidden="true"></i>
                                                        <?= db_bool($c['company_is_active']) ? trans('suspend', '', 'Sospendi') : trans('activate', '', 'Attiva') ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <?php if (!db_bool($c['company_is_default'])): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger"
                                                            data-bs-toggle="modal" data-bs-target="#deleteCompany<?= (int) $c['company_id'] ?>">
                                                        <i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                                    </button>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                    <?php if (!db_bool($c['company_is_default'])): $deleteModals[] = $c; endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php foreach ($deleteModals as $c): ?>
    <div class="modal fade" id="deleteCompany<?= (int) $c['company_id'] ?>" tabindex="-1"
         aria-labelledby="deleteCompanyLabel<?= (int) $c['company_id'] ?>" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteCompanyLabel<?= (int) $c['company_id'] ?>">
                        <?= trans('confirm_delete', '', 'Conferma eliminazione') ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= trans('close', '', 'Chiudi') ?>"></button>
                </div>
                <div class="modal-body">
                    <?= trans('confirm_delete_company', '', 'Vuoi davvero eliminare questa azienda? L\'operazione è irreversibile.') ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= trans('cancel', '', 'Annulla') ?></button>
                    <form method="POST" action="<?= site_url('companies/delete/' . (int) $c['company_id']) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger">
                            <i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?= $this->endSection() ?>
