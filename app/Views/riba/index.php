<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('riba', '', 'Ricevute bancarie')); ?>
<?php
// Gli account passati dal controller sono già verificati: IBAN e codice SIA
// sono presenti, quindi la select contiene solo conti configurati per RiBa.
$ip_riba_accounts_options = '';
if ($accounts === []) {
    $ip_riba_accounts_options = '<option value="">'
        . esc(trans('riba_no_eligible_account_short', '', 'Nessun conto RiBa configurato'))
        . '</option>';
}
foreach ($accounts as $a) {
    $ip_riba_accounts_options .= '<option value="' . (int) $a['bank_account_id'] . '"'
        . '>' . esc($a['bank_account_name']) . '</option>';
}
$actions = '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('bank_account', '', 'Conto di accredito') . '</span>'
    . '<select name="bank_account_id" form="riba_form" class="form-control input-sm simple-select ip-riba-account-select"'
    . ' aria-label="' . esc(trans('bank_account', '', 'Conto di accredito'), 'attr') . '"'
    . (empty($accounts) ? ' disabled' : '') . '>' . $ip_riba_accounts_options . '</select>'
    . '</div>'
    . '<button type="submit" form="riba_form" class="btn btn-sm btn-success" ' . (empty($invoices) || empty($accounts) ? 'disabled' : '') . '><i class="fa fa-solid fa-download" aria-hidden="true"></i> ' . trans('generate_flow', '', 'Genera flusso') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('riba', '', 'Ricevute bancarie (RiBa)'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form">
    <?php if (!$has_riba_account): ?>
        <div class="alert alert-warning" role="status">
            <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
            <?= trans('riba_no_eligible_account', '', 'Nessun conto abilitato per RiBa. In Impostazioni → Conti di saldo completa IBAN e codice SIA del conto da usare.') ?>
        </div>
    <?php endif; ?>

    <?php if (!$has_riba_method): ?>
        <?php // Senza un metodo con codice MP12 nessuna fattura risulta emessa
              // come RiBa, quindi qui non c'è nulla da presentare. ?>
        <div class="alert alert-info small">
            <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
            <?= trans('riba_no_method_only', '', 'Nessun metodo di pagamento con modalità MP12 (RIBA): qui compaiono solo le fatture emesse con pagamento RiBa. Creane uno in Impostazioni → Metodi di pagamento e assegnalo alle fatture da presentare.') ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info small">
        <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
        <?= trans('riba_bank_check', '', 'Il tracciato segue lo standard CBI, ma le banche adottano varianti: fai verificare il primo flusso dalla tua banca prima di usarlo in produzione.') ?>
    </div>

    <?php if (empty($invoices)): ?>
        <?php // Senza fatture la tabella mostrava solo intestazioni e una casella
              // "seleziona tutte" che non seleziona nulla: lo stato vuoto delle
              // altre liste dice la stessa cosa senza colonne inutili. ?>
        <div class="empty-state card">
            <div class="card-body empty-body">
                <i class="fa fa-solid fa-check-circle empty-state-icon" aria-hidden="true"></i>
                <p class="empty-state-text">
                    <?= trans('riba_none_only', '', 'Nessuna fattura da presentare: servono fatture emesse con pagamento RiBa, con saldo aperto e non già inserite in una distinta.') ?>
                </p>
            </div>
        </div>
    <?php else: ?>
    <form method="POST" action="<?= site_url('riba/generate') ?>" id="riba_form">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('select_invoices', '', 'Fatture da presentare') ?></h2>
                <span class="ip-doc-section__hint">
                    <?= count($invoices) ?> <?= trans('with_riba_method', '', 'con metodo RiBa') ?>
                </span>
            </div>

            <div class="ip-doc-items">
                <table class="ip-items-table">
                    <thead>
                    <tr>
                        <th scope="col" class="ip-items-table__actions">
                            <input type="checkbox" id="riba_all"
                                   aria-label="<?= esc(trans('select_all', '', 'Seleziona tutte le fatture')) ?>">
                        </th>
                        <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                        <th scope="col"><?= trans('client', '', 'Cliente') ?></th>
                        <th scope="col"><?= trans('iban', '', 'IBAN debitore') ?></th>
                        <th scope="col" class="ip-items-table__num"><?= trans('due_date', '', 'Scadenza') ?></th>
                        <th scope="col" class="ip-items-table__total"><?= trans('balance', '', 'Da incassare') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <?php $hasIban = !empty($inv['client_iban']); ?>
                        <tr class="ip-item-row is-riba">
                            <td class="ip-items-table__actions">
                                <?php // Preselezionate solo quelle domiciliabili: senza IBAN del
                                      // debitore la ricevuta entra nel flusso ma non si domicilia. ?>
                                <input type="checkbox" name="invoice_ids[]" value="<?= (int) $inv['invoice_id'] ?>"
                                       class="riba-check" <?= $hasIban ? 'checked' : '' ?>
                                       aria-label="<?= esc(sprintf(trans('select_invoice', '', 'Seleziona fattura %s'), $inv['invoice_number'] ?: ('#' . $inv['invoice_id']))) ?>">
                            </td>
                            <td>
                                <span class="ip-num"><?= esc($inv['invoice_number'] ?: ('#' . $inv['invoice_id'])) ?></span>
                            </td>
                            <td><?= esc(format_client($inv)) ?></td>
                            <td>
                                <?php if (!empty($inv['client_iban'])): ?>
                                    <span class="ip-num"><?= esc($inv['client_iban']) ?></span>
                                <?php else: ?>
                                    <span class="text-danger" title="<?= esc(trans('riba_no_iban', '', 'Senza IBAN la ricevuta non è domiciliabile'), 'attr') ?>">
                                        <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> <?= trans('missing', '', 'mancante') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="ip-items-table__num"><?= esc(date_from_mysql($inv['invoice_date_due'])) ?></td>
                            <td class="ip-items-table__total"><?= format_currency($inv['invoice_balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </form>
    <?php endif; ?>
</div>

<script>
(function () {
    var all = document.getElementById('riba_all');
    if (!all) { return; }

    var rows = document.querySelectorAll('.riba-check');

    all.addEventListener('change', function () {
        rows.forEach(function (c) { c.checked = all.checked; });
    });

    // La pagina arriva con le fatture a metodo RiBa gia' spuntate: la casella
    // in testata deve dire com'e' la selezione (nessuna / alcune / tutte),
    // altrimenti sembra che non sia selezionato niente.
    function syncAll() {
        var checked = 0;
        rows.forEach(function (c) { if (c.checked) { checked++; } });
        all.checked = checked === rows.length && rows.length > 0;
        all.indeterminate = checked > 0 && checked < rows.length;
    }

    rows.forEach(function (c) { c.addEventListener('change', syncAll); });
    syncAll();
})();

</script>
<?= $this->endSection() ?>
