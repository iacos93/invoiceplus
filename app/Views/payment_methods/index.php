<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Elenco dei metodi di pagamento.
 *
 * Le colonne dicono la conseguenza sul documento, non il valore grezzo del
 * campo: un metodo qui dentro decide la modalità (MP01…MP23) scritta nel
 * blocco DatiPagamento della fattura elettronica e se accanto compaiono le
 * coordinate del conto di saldo. Un metodo senza codice SdI non produce
 * nessun blocco: va detto nell'elenco, non scoperto a fattura scartata.
 */
use App\Models\PaymentMethodModel;

$this->setVar('pageTitle', trans('payment_methods'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('payment_methods/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';

$usage ??= [];

$usageText = static function (array $counts): string {
    $parts = [];
    $inv   = (int) ($counts['invoices'] ?? 0);
    $pay   = (int) ($counts['payments'] ?? 0);

    if ($inv > 0) {
        $parts[] = $inv . ' ' . ($inv === 1 ? 'fattura' : 'fatture');
    }
    if ($pay > 0) {
        $parts[] = $pay . ' ' . ($pay === 1 ? 'pagamento' : 'pagamenti');
    }

    return implode(' · ', $parts);
};
?>
<?= $this->include('layout/headerbar', ['title' => trans('payment_methods'), 'actions' => $actions]) ?>

<div id="content" class="table-content">

    <?php if (empty($payment_methods)): ?>

        <div class="empty-state card">
            <div class="card-body empty-body">
                <i class="fa fa-regular fa-credit-card empty-state-icon" aria-hidden="true"></i>
                <p class="empty-state-text">
                    <?= trans('no_payment_methods', '', 'Nessun metodo di pagamento. Aggiungi quelli che usi davvero (bonifico, contanti, RiBa): compaiono nella select della fattura e ne determinano la modalità di pagamento.') ?>
                </p>
                <a class="btn btn-primary" href="<?= site_url('payment_methods/form') ?>">
                    <i class="fa fa-solid fa-plus fa-margin" aria-hidden="true"></i> <?= trans('payment_method_add_first', '', 'Aggiungi il primo metodo') ?>
                </a>
            </div>
        </div>

    <?php else: ?>

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-credit-card" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('payment_methods')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_payment_methods') : count($payment_methods) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#payment-methods-list-table"
                 data-empty-text="<?= esc(trans('no_payment_methods_found'), 'attr') ?>">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca metodo di pagamento…'), 'attr') ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="payment-methods-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('payment_method') ?></th>
                <th scope="col"><?= trans('on_the_document', '', 'Sul documento') ?></th>
                <th scope="col"><?= trans('sdi_payment_mode', '', 'Modalità SdI') ?></th>
                <th scope="col"><?= trans('usage', '', 'Uso') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($payment_methods as $payment_method): ?>
                <?php
                $methodId  = (int) $payment_method['payment_method_id'];
                // db_bool(): PostgreSQL restituisce il booleano come 't'/'f', e
                // (int) 't' vale 0 — ogni metodo risultava senza IBAN.
                $withIban  = db_bool($payment_method['payment_method_requires_bank_account']);
                $sdiCode   = PaymentMethodModel::sdiCodeFor($payment_method);
                $used      = $usageText($usage[$methodId] ?? []);
                ?>
                <tr>
                    <td data-label="<?= esc(trans('payment_method')) ?>">
                        <a href="<?= site_url('payment_methods/form/' . $methodId) ?>"><?= esc($payment_method['payment_method_name']) ?></a>
                    </td>

                    <td data-label="<?= esc(trans('on_the_document', '', 'Sul documento')) ?>">
                        <?php if ($withIban): ?>
                            <i class="fa fa-solid fa-university fa-margin text-muted" aria-hidden="true"></i>
                            <?= trans('with_bank_details', '', 'Con le coordinate del conto') ?>
                        <?php else: ?>
                            <span class="text-muted"><?= trans('without_bank_details', '', 'Senza coordinate') ?></span>
                        <?php endif; ?>
                    </td>

                    <td data-label="<?= esc(trans('sdi_payment_mode', '', 'Modalità SdI')) ?>">
                        <?php if ($sdiCode): ?>
                            <span class="ip-num"><?= esc($sdiCode) ?></span>
                            <div class="ip-cell-sub"><?= esc(PaymentMethodModel::SDI_CODES[$sdiCode] ?? '') ?></div>
                        <?php else: ?>
                            <span class="badge text-bg-warning"><?= trans('not_set', '', 'Non impostata') ?></span>
                            <div class="ip-cell-sub"><?= trans('sdi_payment_mode_missing', '', 'La fattura elettronica parte senza dati di pagamento') ?></div>
                        <?php endif; ?>
                    </td>

                    <td data-label="<?= esc(trans('usage', '', 'Uso')) ?>">
                        <?php if ($used !== ''): ?>
                            <span class="ip-cell-sub"><?= esc($used) ?></span>
                        <?php else: ?>
                            <span class="text-muted"><?= trans('never_used', '', 'Mai usato') ?></span>
                        <?php endif; ?>
                    </td>

                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle"
                               data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i>
                                <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('payment_methods/form/' . $methodId) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i>
                                        <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <?php if ($used !== ''): ?>
                                        <?php // Fatture e pagamenti conservano solo l'id: eliminarlo
                                              // lascerebbe lo storico senza il metodo con cui è stato
                                              // incassato. Voce spenta, con il motivo accanto. ?>
                                        <span class="dropdown-button is-disabled"
                                              title="<?= esc(trans('payment_method_in_use', '', 'Usato in: ') . $used, 'attr') ?>">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i>
                                            <span><?= trans('delete') ?><em class="ip-cell-sub"><?= esc($used) ?></em></span>
                                        </span>
                                    <?php else: ?>
                                        <form action="<?= site_url('payment_methods/delete/' . $methodId) ?>" method="POST">
                                            <?= csrf_field() ?>
                                            <button type="button" class="dropdown-button"
                                                    data-bs-toggle="confirm"
                                                    data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                    data-confirm-body="<?= esc(trans('delete_record_warning'), 'attr') ?>"
                                                    data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>

        </table>
    </div>
    <?= $this->include('layout/pager_footer', ['group' => 'ip_payment_methods']) ?>
    </div>

    <?php endif; ?>
</div>

<?= $this->endSection() ?>
