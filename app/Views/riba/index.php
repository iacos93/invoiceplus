<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('riba', '', 'Ricevute bancarie')); ?>
<?php
// L'avviso sul SIA riguarda il conto scelto, non l'azienda: ogni opzione
// porta con se' se il proprio codice c'e', e il JS in fondo alla pagina
// mostra l'avviso solo quando manca a quello selezionato.
$ip_riba_accounts_options = '';
foreach ($accounts as $a) {
    $ip_riba_accounts_options .= '<option value="' . (int) $a['bank_account_id'] . '"'
        . ' data-sia="' . (empty($a['bank_account_sia_code']) ? '0' : '1') . '">'
        . esc($a['bank_account_name']) . '</option>';
}
$actions = '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('bank_account', '', 'Conto di accredito') . '</span>'
    . '<select name="bank_account_id" form="riba_form" class="form-control input-sm simple-select"'
    . ' aria-label="' . esc(trans('bank_account', '', 'Conto di accredito'), 'attr') . '"'
    . ' style="min-width:200px">' . $ip_riba_accounts_options . '</select>'
    . '</div>'
    . '<button type="submit" form="riba_form" class="btn btn-sm btn-success" ' . (empty($invoices) ? 'disabled' : '') . '><i class="fa fa-solid fa-download" aria-hidden="true"></i> ' . trans('generate_flow', '', 'Genera flusso') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('riba', '', 'Ricevute bancarie (RiBa)'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form">
    <div class="alert alert-warning" id="riba_no_sia" hidden>
        <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
        <?= trans('riba_no_sia', '', 'Il conto di accredito scelto non ha il codice SIA: la banca rifiuterebbe il flusso. Impostalo in Impostazioni → Conti di saldo.') ?>
    </div>

    <?php if (!$has_riba_method): ?>
        <?php // Senza un metodo con codice MP12 la pagina non sa quali fatture
              // erano destinate alla RiBa e le elenca tutte, come prima. ?>
        <div class="alert alert-info small">
            <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
            <?= trans('riba_no_method', '', 'Nessun metodo di pagamento con modalità MP12 (RIBA): le fatture non possono essere distinte per metodo. Impostane uno in Impostazioni → Metodi di pagamento per trovarle già selezionate qui.') ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info small">
        <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
        <?= trans('riba_bank_check', '', 'Il tracciato segue lo standard CBI, ma le banche adottano varianti: fai verificare il primo flusso dalla tua banca prima di usarlo in produzione.') ?>
    </div>

    <form method="POST" action="<?= site_url('riba/generate') ?>" id="riba_form">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('select_invoices', '', 'Fatture da presentare') ?></h2>
                <span class="ip-doc-section__hint">
                    <?php if ($riba_count > 0): ?>
                        <?= $riba_count ?> <?= trans('with_riba_method', '', 'con metodo RiBa') ?>
                        <?php if (count($invoices) > $riba_count): ?>
                            · <?= count($invoices) - $riba_count ?> <?= trans('other_open', '', 'altre aperte') ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= count($invoices) ?>
                    <?php endif; ?>
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
                    <?php $ip_riba_divider = false; ?>
                    <?php foreach ($invoices as $inv): ?>
                        <?php
                        $isRiba = !empty($inv['is_riba']);
                        $hasIban = !empty($inv['client_iban']);
                        // Il separatore compare una volta sola, e solo se sopra
                        // c'e' davvero un blocco RiBa da separare.
                        if (!$isRiba && !$ip_riba_divider && $riba_count > 0):
                            $ip_riba_divider = true; ?>
                            <tr class="ip-riba-divider">
                                <td colspan="6">
                                    <?= trans('riba_other_invoices', '', 'Altre fatture aperte — senza metodo RiBa, presentabili comunque') ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr class="ip-item-row<?= $isRiba ? ' is-riba' : '' ?>">
                            <td class="ip-items-table__actions">
                                <?php // Preselezionate solo quelle domiciliabili: senza IBAN del
                                      // debitore la ricevuta entra nel flusso ma non si domicilia. ?>
                                <input type="checkbox" name="invoice_ids[]" value="<?= (int) $inv['invoice_id'] ?>"
                                       class="riba-check" <?= $isRiba && $hasIban ? 'checked' : '' ?>
                                       aria-label="<?= esc(sprintf(trans('select_invoice', '', 'Seleziona fattura %s'), $inv['invoice_number'] ?: ('#' . $inv['invoice_id']))) ?>">
                            </td>
                            <td>
                                <span class="ip-num"><?= esc($inv['invoice_number'] ?: ('#' . $inv['invoice_id'])) ?></span>
                                <?php if ($isRiba): ?>
                                    <span class="badge text-bg-secondary">RiBa</span>
                                <?php endif; ?>
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
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="6" class="ip-items-table__empty">
                            <?= trans('riba_none', '', 'Nessuna fattura da presentare: servono documenti emessi, con saldo aperto e non già inseriti in una distinta.') ?>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </form>
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

(function () {
    // Il codice SIA appartiene al conto: cambiando conto di accredito cambia
    // anche se l'avviso si applica.
    var select = document.querySelector('select[name="bank_account_id"]');
    var alert  = document.getElementById('riba_no_sia');

    if (!select || !alert) { return; }

    function apply() {
        var option = select.options[select.selectedIndex];
        alert.hidden = !option || option.dataset.sia === '1';
    }

    select.addEventListener('change', apply);
    apply();
})();
</script>
<?= $this->endSection() ?>
