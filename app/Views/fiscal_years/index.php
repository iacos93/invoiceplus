<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('fiscal_years', '', 'Parametri fiscali'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('fiscal_years/form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new_year', '', 'Nuovo anno') . '</a>';

$sel_year = (int) selected_year();

/* IRPEF: la tabella aveva solo IRES/IRAP e forfettario, nascondendo l'imposta
   personale. Per ogni anno alleghiamo gli scaglioni e ne ricaviamo l'aliquota
   marginale massima e una mini-scala (la stessa "firma a gradini" del form). */
$irpef = static function (array $y): array {
    $b      = $y['brackets'] ?? [];
    $rates  = array_column($b, 1);
    $top    = $rates ? max($rates) : 0;
    $maxFor = $top ?: 1;
    return [$b, $top, count($b), $maxFor];
};

/* Profilo aliquote: ogni anno diventa una "impronta" a barre sovrapposte,
   così a colpo d'occhio si vedono le differenze di struttura tra gli anni
   (prima serviva leggere quattro colonne di numeri quasi sempre uguali). */
$mix = static function (array $y): array {
    $parts = [
        'ires' => [(float) $y['fiscal_ires_aliquota'], trans('ires', '', 'IRES'), '--ip-primary'],
        'irap' => [(float) $y['fiscal_irap_aliquota'], trans('irap', '', 'IRAP'), '--ip-info'],
        'add'  => [(float) $y['fiscal_add_regionale'] + (float) $y['fiscal_add_comunale'], trans('surcharges', '', 'Addizionali'), '--ip-violet'],
        'forf' => [(float) $y['fiscal_forf_ordinaria'], trans('flat_rate_scheme', '', 'Forfettario'), '--ip-success'],
    ];
    $sum = array_sum(array_column($parts, 0)) ?: 1;
    return array_map(static function (array $p) use ($sum): array {
        return [$p[0], $p[1], $p[2], round($p[0] / $sum * 100, 1)];
    }, $parts);
};
?>
<?= $this->include('layout/headerbar', ['title' => trans('fiscal_years', '', 'Parametri fiscali'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-percent" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('fiscal_years', '', 'Parametri fiscali')) ?></span>
                <span class="badge text-bg-secondary"><?= count($years) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse">
                <thead><tr>
                    <th scope="col"><?= trans('year', '', 'Anno') ?></th>
                    <th scope="col" class="ip-fyear-irpef-col"><?= trans('irpef', '', 'IRPEF') ?></th>
                    <th scope="col"><?= trans('ires_irap', '', 'IRES / IRAP') ?></th>
                    <th scope="col"><?= trans('surcharges', '', 'Addizionali') ?></th>
                    <th scope="col"><?= trans('flat_rate_scheme', '', 'Forfettario') ?></th>
                    <th scope="col" class="ip-fyear-profile-col"><?= trans('rate_profile', '', 'Profilo aliquote') ?></th>
                    <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($years as $y): ?>
                    <tr class="<?= (int) $y['fiscal_year'] === $sel_year ? 'ip-row-current' : '' ?>">
                        <td data-label="<?= esc(trans('year', '', 'Anno')) ?>">
                            <strong class="ip-num"><?= (int) $y['fiscal_year'] ?></strong>
                            <?php if ((int) $y['fiscal_year'] === $sel_year): ?>
                                <span class="ip-row-current-badge"><?= trans('selected', '', 'selezionato') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ip-fyear-irpef-col" data-label="<?= esc(trans('irpef', '', 'IRPEF')) ?>">
                            <?php [$brackets, $top, $count, $maxFor] = $irpef($y); ?>
                            <div class="ip-fyear-irpef">
                                <div class="ip-fyear-irpef__ladder" role="img"
                                     aria-label="<?= esc(trans('irpef_brackets_aria', '', 'Scala IRPEF: ') .
                                         $count . ' scaglioni, aliquota marginale massima ' . format_amount($top) . '%', 'attr') ?>">
                                    <?php foreach ($brackets as [$limit, $rate]): ?>
                                        <span class="ip-fyear-irpef__bar"
                                              style="height:<?= round((float) $rate / $maxFor * 100, 1) ?>%;opacity:<?= $top > 0 ? (0.4 + 0.6 * (float) $rate / $maxFor) : 0.15 ?>"
                                              title="<?= esc(($limit === null ? 'oltre ' : 'fino a ' . format_amount($limit) . ' → ') . format_amount($rate) . '%', 'attr') ?>"></span>
                                    <?php endforeach; ?>
                                </div>
                                <span class="ip-fyear-irpef__meta">
                                    <span class="ip-fyear-irpef__top"><?= $top > 0 ? format_amount($top) . '%' : '—' ?></span>
                                    <span class="ip-fyear-irpef__count"><?= $count ?> <?= trans('brackets_short', '', 'scagl.') ?></span>
                                </span>
                            </div>
                        </td>
                        <td data-label="<?= esc(trans('ires_irap', '', 'IRES / IRAP')) ?>">
                            <span class="ip-num"><?= format_amount($y['fiscal_ires_aliquota']) ?>% / <?= format_amount($y['fiscal_irap_aliquota']) ?>%</span>
                        </td>
                        <td data-label="<?= esc(trans('surcharges', '', 'Addizionali')) ?>">
                            <span class="ip-num"><?= format_amount((float) $y['fiscal_add_regionale'] + (float) $y['fiscal_add_comunale']) ?>%</span>
                        </td>
                        <td data-label="<?= esc(trans('flat_rate_scheme', '', 'Forfettario')) ?>">
                            <span class="ip-num"><?= format_amount($y['fiscal_forf_ordinaria']) ?>% / <?= format_amount($y['fiscal_forf_startup']) ?>%</span>
                        </td>
                        <td class="ip-fyear-profile-col" data-label="<?= esc(trans('rate_profile', '', 'Profilo aliquote')) ?>">
                            <div class="ip-fyear-bar" role="img"
                                 aria-label="<?= esc(trans('rate_profile_aria', '', 'Composizione delle aliquote: ') .
                                     'IRES ' . format_amount($y['fiscal_ires_aliquota']) . '%, ' .
                                     'IRAP ' . format_amount($y['fiscal_irap_aliquota']) . '%, ' .
                                     'addizionali ' . format_amount((float) $y['fiscal_add_regionale'] + (float) $y['fiscal_add_comunale']) . '%, ' .
                                     'forfettario ' . format_amount($y['fiscal_forf_ordinaria']) . '%', 'attr') ?>">
                                <?php foreach ($mix($y) as [$val, $label, $color, $pct]): ?>
                                    <span class="ip-fyear-seg" style="width:<?= $pct ?>%;background:var(<?= $color ?>)"
                                          title="<?= esc($label . ' ' . format_amount($val) . '%', 'attr') ?>"></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td data-label="<?= esc(trans('status', '', 'Stato')) ?>">
                            <?php if (db_bool($y['fiscal_is_projection'])): ?>
                                <span class="badge ip-status text-bg-warning"><?= trans('projection', '', 'Proiezione') ?></span>
                            <?php else: ?>
                                <span class="badge ip-status text-bg-success"><?= trans('in_force', '', 'In vigore') ?></span>
                            <?php endif; ?>
                            <?php /* Riga condivisa fra le aziende: modificarla ne crea una copia
                                     di questa azienda (FiscalYears::form), e non si cancella. */ ?>
                            <?php if (($y['company_id'] ?? null) === null): ?>
                                <span class="badge ip-status text-bg-secondary" title="<?= esc(trans('fiscal_year_shared_hint', '', 'Parametri condivisi fra le aziende: modificandoli se ne crea una copia per l\'azienda attiva'), 'attr') ?>"><?= trans('shared', '', 'Condiviso') ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= site_url('fiscal_years/form/' . (int) $y['fiscal_id']) ?>"><i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?></a></li>
                                    <li><form action="<?= site_url('fiscal_years/delete/' . (int) $y['fiscal_id']) ?>" method="POST"><?= csrf_field() ?>
                                        <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                data-confirm-body="<?= esc(trans('delete_fiscal_year_warning', '', 'Eliminando l\'anno, le stime che lo usavano ripiegheranno sull\'anno coperto più vicino. Procedere?'), 'attr') ?>"
                                                data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?></button></form></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($years)): ?>
                    <tr><td colspan="8" class="ip-items-table__empty">
                        <?= trans('no_fiscal_years', '', 'Nessun anno configurato. Finché la tabella è vuota la stima usa i valori di riferimento inclusi nell\'applicazione.') ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= $this->include('layout/pager_footer', ['group' => 'ip_fiscal_years']) ?>
    </div>
</div>
<?= $this->endSection() ?>
