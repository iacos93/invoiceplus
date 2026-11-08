<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Elenco dei conti di saldo.
 *
 * La colonna che conta è "Sui documenti": queste righe esistono per decidere
 * quale IBAN viene stampato in fattura e finisce nel nodo <IBAN> dell'XML,
 * quindi l'elenco dice per ogni conto se ci arriva da solo (predefinito) o
 * solo su richiesta della singola fattura. Il conteggio d'uso accanto serve
 * prima di eliminare: un conto collegato a documenti non è cancellabile.
 */
$this->setVar('pageTitle', trans('bank_accounts', '', 'Conti di saldo'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('bank_accounts/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new', '', 'Nuovo') . '</a>';

$usage ??= [];

// Riferimenti al conto, scritti per esteso: "3 fatture · 1 spesa".
$usageText = static function (array $counts): string {
    $labels = [
        'invoices'     => ['fattura', 'fatture'],
        'expenses'     => ['spesa', 'spese'],
        'transactions' => ['movimento', 'movimenti'],
        'f24'          => ['F24', 'F24'],
    ];
    $parts = [];

    foreach ($labels as $key => [$one, $many]) {
        $n = (int) ($counts[$key] ?? 0);

        if ($n > 0) {
            $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
        }
    }

    return implode(' · ', $parts);
};
?>
<?= $this->include('layout/headerbar', ['title' => trans('bank_accounts', '', 'Conti di saldo'), 'actions' => $actions]) ?>

<div id="content" class="table-content">

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-university" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('bank_accounts', '', 'Conti di saldo')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_bank_accounts') : count($bank_accounts) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#bank-accounts-list-table"
                 data-empty-text="<?= esc(trans('no_search_results', '', 'Nessun conto corrisponde alla ricerca.'), 'attr') ?>">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca conto, banca o IBAN…'), 'attr') ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="bank-accounts-list-table">
                <thead>
                <tr>
                    <th scope="col"><?= trans('bank_account', '', 'Conto') ?></th>
                    <th scope="col"><?= trans('bank_details', '', 'Coordinate') ?></th>
                    <th scope="col"><?= trans('on_documents', '', 'Sui documenti') ?></th>
                    <th scope="col"><?= trans('usage', '', 'Uso') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($bank_accounts)): ?>
                    <tr class="empty-row">
                        <td colspan="5">
                            <i class="fa fa-solid fa-university empty-icon" aria-hidden="true"></i>
                            <?= trans('no_bank_accounts', '', 'Nessun conto di saldo.') ?>
                            <a href="<?= site_url('bank_accounts/form') ?>" class="empty-cta">
                                <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('bank_account_add_first', '', 'Aggiungi il primo conto') ?>
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($bank_accounts as $account): ?>
                    <?php
                    $accountId = (int) $account['bank_account_id'];
                    $isDefault = !empty($account['bank_account_is_default']);
                    $used      = $usageText($usage[$accountId] ?? []);
                    ?>
                    <tr>
                        <td data-label="<?= esc(trans('bank_account', '', 'Conto')) ?>">
                            <a href="<?= site_url('bank_accounts/form/' . $accountId) ?>"><?= esc($account['bank_account_name']) ?></a>
                            <?php if ($account['bank_account_bank'] || $account['bank_account_holder']): ?>
                                <div class="ip-cell-sub">
                                    <?= esc(implode(' · ', array_filter([$account['bank_account_bank'], $account['bank_account_holder']]))) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td data-label="<?= esc(trans('bank_details', '', 'Coordinate')) ?>">
                            <?php if ($account['bank_account_iban']): ?>
                                <span class="ip-num ip-num--wrap"><?= esc(\App\Models\BankAccountModel::formatIban($account['bank_account_iban'])) ?></span>
                                <div class="ip-cell-sub">
                                    <?php if ($account['bank_account_bic']): ?>
                                        BIC <?= esc($account['bank_account_bic']) ?>
                                    <?php endif; ?>
                                    <?php if ($account['bank_account_sia_code']): ?>
                                        <?= $account['bank_account_bic'] ? ' · ' : '' ?>SIA <?= esc($account['bank_account_sia_code']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted"><?= trans('bank_account_no_iban', '', 'IBAN non indicato') ?></span>
                            <?php endif; ?>
                        </td>

                        <td data-label="<?= esc(trans('on_documents', '', 'Sui documenti')) ?>">
                            <?php if ($isDefault): ?>
                                <span class="badge text-bg-success"><?= trans('default', '', 'Predefinito') ?></span>
                                <div class="ip-cell-sub"><?= trans('bank_account_default_hint', '', 'Stampato quando la fattura non ne indica un altro') ?></div>
                            <?php else: ?>
                                <span class="text-muted"><?= trans('bank_account_on_request', '', 'Solo se scelto sulla fattura') ?></span>
                            <?php endif; ?>
                        </td>

                        <td data-label="<?= esc(trans('usage', '', 'Uso')) ?>">
                            <?php if ($used !== ''): ?>
                                <span class="ip-cell-sub"><?= esc($used) ?></span>
                            <?php else: ?>
                                <span class="text-muted"><?= trans('never_used', '', 'Mai usato') ?></span>
                            <?php endif; ?>
                        </td>

                        <td data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a href="<?= site_url('bank_accounts/form/' . $accountId) ?>">
                                            <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?>
                                        </a>
                                    </li>
                                    <li>
                                        <?php if ($used !== ''): ?>
                                            <?php // Cancellarlo lascerebbe quei documenti con un IBAN muto:
                                                  // la voce resta visibile ma spenta, con il motivo accanto. ?>
                                            <span class="dropdown-button is-disabled"
                                                  title="<?= esc(trans('bank_account_in_use', '', 'Collegato a: ') . $used, 'attr') ?>">
                                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i>
                                                <span><?= trans('delete', '', 'Elimina') ?><em class="ip-cell-sub"><?= esc($used) ?></em></span>
                                            </span>
                                        <?php else: ?>
                                            <form action="<?= site_url('bank_accounts/delete/' . $accountId) ?>" method="POST">
                                                <?= csrf_field() ?>
                                                <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                        data-confirm-body="<?= esc(trans('delete_record_warning', '', 'Eliminare il conto?'), 'attr') ?>"
                                                        data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                                    <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
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
        <?= $this->include('layout/pager_footer', ['group' => 'ip_bank_accounts']) ?>
    </div>

    <?php endif; ?>
</div>

<?= $this->endSection() ?>
