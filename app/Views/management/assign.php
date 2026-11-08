<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Assegnazione in blocco delle voci di ricavo/costo.
 *
 * Per le fatture l'assegnazione è per riga (una fattura può contenere voci
 * diverse), per le spese è per documento. Compaiono solo i documenti che
 * hanno ancora qualcosa da assegnare: un elenco di documenti già completi
 * renderebbe la pagina inutilmente lunga e nasconderebbe quelli da fare.
 */
$this->setVar('pageTitle', trans('management_assign', '', 'Assegna voci'));

$yearLabel = trans('management_assign', '', 'Assegna voci') . ' · ' . $year;

$hasRevenue = $revenue_accounts !== [];
$hasCosts   = $cost_accounts !== [];

$selectOptions = static function (array $options, string $empty): string {
    $html = '<option value="">' . esc($empty) . '</option>';
    foreach ($options as $id => $label) {
        $html .= '<option value="' . (int) $id . '">' . esc($label) . '</option>';
    }
    return $html;
};
?>

<?= $this->include('layout/headerbar', ['title' => $yearLabel]) ?>

<div id="content">
    <?= $this->include('management/_tabs', ['active' => 'assign']) ?>

    <?php if (!$hasRevenue && !$hasCosts): ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-sitemap" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_empty_title', '', 'Prima il piano dei conti') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <p><?= trans('management_budget_needs_plan', '', 'Il budget si assegna alle voci del piano dei conti: importane uno o crea le voci che usi.') ?></p>
                <a class="btn btn-primary" href="<?= site_url('management/accounts') ?>">
                    <i class="fa fa-solid fa-sitemap" aria-hidden="true"></i>
                    <?= trans('management_plan', '', 'Piano dei conti') ?>
                </a>
            </div>
        </section>
    <?php else: ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_assign_invoices', '', 'Fatture con righe da assegnare') ?></h2>
                <span class="ip-doc-section__hint"><?= count($invoices) ?></span>
            </div>
            <div class="ip-doc-section__body">
                <?php if ($invoices === []): ?>
                    <p class="text-muted mb-0">
                        <i class="fa fa-solid fa-check-circle" aria-hidden="true"></i>
                        <?= trans('management_assign_invoices_done', '', 'Tutte le righe delle fatture dell\'anno hanno una voce di ricavo.') ?>
                    </p>
                <?php elseif (!$hasRevenue): ?>
                    <p class="text-muted mb-0"><?= trans('management_no_revenue_accounts', '', 'Crea almeno una voce di ricavo nel piano dei conti.') ?></p>
                <?php else: ?>
                    <form method="post" action="<?= site_url('management/assign') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target" value="invoices">

                        <?php /* Barra di assegnazione sopra l'elenco: voce, opzione e
                                 pulsante stanno insieme. Con il pulsante in fondo
                                 bisognava scegliere la voce qui, spuntare e poi
                                 scorrere oltre tutte le righe per applicarla. */ ?>
                        <div class="ip-assign-bar" data-assign-bar="invoices">
                            <div class="ip-assign-bar__field">
                                <label class="ip-doc-label" for="invoice_account"><?= trans('management_revenue_account', '', 'Voce di ricavo') ?></label>
                                <select name="account_id" id="invoice_account" class="form-control simple-select" required>
                                    <?= $selectOptions($revenue_accounts, trans('management_choose', '', 'Scegli…')) ?>
                                </select>
                            </div>
                            <div class="form-check ip-assign-bar__check">
                                <input type="checkbox" name="overwrite" value="1" id="invoice_overwrite" class="form-check-input">
                                <label class="form-check-label" for="invoice_overwrite">
                                    <?= trans('management_overwrite', '', 'Sovrascrivi anche le righe già assegnate') ?>
                                </label>
                            </div>
                            <span class="ip-assign-bar__count" data-selected-count="invoices" aria-live="polite"></span>
                            <button type="submit" class="btn btn-sm btn-primary" data-assign-submit="invoices" disabled>
                                <i class="fa fa-solid fa-link" aria-hidden="true"></i> <?= trans('management_assign_selected', '', 'Assegna alle fatture selezionate') ?>
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover ip-bilancino ip-bilancino--assign">
                                <thead>
                                <tr>
                                    <th scope="col" class="ip-bilancino__check">
                                        <input type="checkbox" class="form-check-input" data-check-all="invoices"
                                               aria-label="<?= esc(trans('select_all', '', 'Seleziona tutto'), 'attr') ?>">
                                    </th>
                                    <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                                    <th scope="col"><?= trans('date', '', 'Data') ?></th>
                                    <th scope="col"><?= trans('client', '', 'Cliente') ?></th>
                                    <th scope="col" class="text-end"><?= trans('management_missing_lines', '', 'Righe da assegnare') ?></th>
                                    <th scope="col" class="text-end"><?= trans('management_missing_amount', '', 'Imponibile da assegnare') ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td><input type="checkbox" class="form-check-input" name="document_ids[]"
                                                   value="<?= (int) $invoice['invoice_id'] ?>" data-check-group="invoices"
                                                   aria-label="<?= esc(trans('select_invoice', $invoice['invoice_number']), 'attr') ?>"></td>
                                        <td><a href="<?= site_url('invoices/view/' . (int) $invoice['invoice_id']) ?>"><?= esc($invoice['invoice_number']) ?></a></td>
                                        <td><?= esc(date_from_mysql($invoice['invoice_date_created'])) ?></td>
                                        <td><?= esc($invoice['client_name']) ?></td>
                                        <td class="text-end"><span class="badge text-bg-warning"><?= (int) $invoice['missing_lines'] ?></span></td>
                                        <td class="text-end"><?= format_currency((float) $invoice['missing_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-shopping-cart" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_assign_expenses', '', 'Spese da assegnare') ?></h2>
                <span class="ip-doc-section__hint"><?= count($expenses) ?></span>
            </div>
            <div class="ip-doc-section__body">
                <?php if ($expenses === []): ?>
                    <p class="text-muted mb-0">
                        <i class="fa fa-solid fa-check-circle" aria-hidden="true"></i>
                        <?= trans('management_assign_expenses_done', '', 'Tutte le spese dell\'anno hanno una voce di costo.') ?>
                    </p>
                <?php elseif (!$hasCosts): ?>
                    <p class="text-muted mb-0"><?= trans('management_no_cost_accounts', '', 'Crea almeno una voce di costo nel piano dei conti.') ?></p>
                <?php else: ?>
                    <form method="post" action="<?= site_url('management/assign') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target" value="expenses">

                        <?php /* Barra di assegnazione sopra l'elenco: voce, opzione e
                                 pulsante stanno insieme. Con il pulsante in fondo
                                 bisognava scegliere la voce qui, spuntare e poi
                                 scorrere oltre tutte le righe per applicarla. */ ?>
                        <div class="ip-assign-bar" data-assign-bar="expenses">
                            <div class="ip-assign-bar__field">
                                <label class="ip-doc-label" for="expense_account"><?= trans('management_cost_account', '', 'Voce di costo') ?></label>
                                <select name="account_id" id="expense_account" class="form-control simple-select" required>
                                    <?= $selectOptions($cost_accounts, trans('management_choose', '', 'Scegli…')) ?>
                                </select>
                            </div>
                            <div class="form-check ip-assign-bar__check">
                                <input type="checkbox" name="overwrite" value="1" id="expense_overwrite" class="form-check-input">
                                <label class="form-check-label" for="expense_overwrite">
                                    <?= trans('management_overwrite', '', 'Sovrascrivi anche le righe già assegnate') ?>
                                </label>
                            </div>
                            <span class="ip-assign-bar__count" data-selected-count="expenses" aria-live="polite"></span>
                            <button type="submit" class="btn btn-sm btn-primary" data-assign-submit="expenses" disabled>
                                <i class="fa fa-solid fa-link" aria-hidden="true"></i> <?= trans('management_assign_selected_expenses', '', 'Assegna alle spese selezionate') ?>
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover ip-bilancino ip-bilancino--assign">
                                <thead>
                                <tr>
                                    <th scope="col" class="ip-bilancino__check">
                                        <input type="checkbox" class="form-check-input" data-check-all="expenses"
                                               aria-label="<?= esc(trans('select_all', '', 'Seleziona tutto'), 'attr') ?>">
                                    </th>
                                    <th scope="col"><?= trans('date', '', 'Data') ?></th>
                                    <th scope="col"><?= trans('number', '', 'Numero') ?></th>
                                    <th scope="col"><?= trans('supplier', '', 'Fornitore') ?></th>
                                    <th scope="col" class="text-end"><?= trans('total', '', 'Totale') ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($expenses as $expense): ?>
                                    <tr>
                                        <td><input type="checkbox" class="form-check-input" name="document_ids[]"
                                                   value="<?= (int) $expense['expense_id'] ?>" data-check-group="expenses"
                                                   aria-label="<?= esc(trans('expense', '', 'Spesa') . ' ' . $expense['expense_number'], 'attr') ?>"></td>
                                        <td><?= esc(date_from_mysql($expense['expense_date'])) ?></td>
                                        <td><a href="<?= site_url('expenses/form/' . (int) $expense['expense_id']) ?>"><?= esc($expense['expense_number'] ?: '—') ?></a></td>
                                        <td><?= esc($expense['supplier_name']) ?></td>
                                        <td class="text-end"><?= format_currency((float) $expense['expense_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
/* Per ciascuna delle due tabelle: "seleziona tutto", conteggio delle righe
 * spuntate accanto al pulsante (che resta disabilitato finché non c'è nulla
 * da assegnare) e clic su tutta la riga, non solo sulla casella da 16px:
 * con cinquanta fatture da assegnare la mira conta. */
(function () {
    "use strict";

    document.querySelectorAll("[data-check-all]").forEach(function (master) {
        var group  = master.dataset.checkAll;
        var boxes  = Array.prototype.slice.call(document.querySelectorAll('[data-check-group="' + group + '"]'));
        var count  = document.querySelector('[data-selected-count="' + group + '"]');
        var submit = document.querySelector('[data-assign-submit="' + group + '"]');

        function refresh() {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            master.checked       = n > 0 && n === boxes.length;
            master.indeterminate = n > 0 && n < boxes.length;
            if (count) { count.textContent = n > 0 ? n + " <?= esc(trans('management_selected', '', 'selezionate'), 'js') ?>" : ""; }
            if (submit) { submit.disabled = n === 0; }
        }

        master.addEventListener("change", function () {
            boxes.forEach(function (box) { box.checked = master.checked; });
            refresh();
        });
        boxes.forEach(function (box) {
            box.addEventListener("change", refresh);
            var row = box.closest("tr");
            row.classList.add("is-selectable");
            row.addEventListener("click", function (e) {
                if (e.target.closest("a, input, label, button")) { return; }
                box.checked = !box.checked;
                refresh();
            });
        });
        refresh();
    });
})();
</script>

<?= $this->endSection() ?>
