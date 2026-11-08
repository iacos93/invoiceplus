<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('reconciliation', '', 'Riconciliazione bancaria'));
$btn = function ($key, $label) use ($state) {
    $cls = ($state === $key) ? 'btn-primary' : 'btn-secondary';
    return '<a href="' . site_url('reconciliation') . ($key ? '?state=' . $key : '') . '" class="btn ' . $cls . '">' . $label . '</a>';
};
$filters = '<div class="btn-group btn-group-sm index-options">'
         . $btn('open', trans('to_reconcile', '', 'Da abbinare'))
         . $btn('done', trans('reconciled', '', 'Abbinati'))
         . $btn('',     trans('all', '', 'Tutti'))
         . '</div>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('reconciliation', '', 'Riconciliazione bancaria'), 'filters' => $filters]) ?>

<div id="content" class="ip-doc-form">

    <?php
    // Riepilogo del perimetro mostrato: quante righe restano da chiudere e
    // quanto denaro entra ed esce. Calcolato sui movimenti già caricati per la
    // vista, quindi segue il filtro di stato e l'anno selezionato.
    $sumIn = $sumOut = 0.0;
    $open  = 0;
    foreach ($transactions as $t) {
        $a = (float) $t['transaction_amount'];
        $a >= 0 ? $sumIn += $a : $sumOut += $a;
        if (!db_bool($t['is_reconciled'])) {
            $open++;
        }
    }
    ?>
    <?= view('layout/summary_strip', ['items' => [
        ['label' => trans('transactions', '', 'Movimenti'), 'value' => (string) count($transactions)],
        ['label' => trans('to_reconcile', '', 'Da abbinare'), 'value' => (string) $open, 'tone' => $open ? 'warning' : 'default'],
        ['label' => 'Entrate', 'value' => format_currency($sumIn)],
        ['label' => 'Uscite', 'value' => format_currency($sumOut), 'tone' => $sumOut < 0 ? 'danger' : 'default'],
    ]]) ?>


    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step"><i class="fa fa-solid fa-upload" aria-hidden="true"></i></span>
            <h2 class="ip-doc-section__title"><?= trans('import_statement', '', 'Importa estratto conto') ?></h2>
            <span class="ip-doc-section__hint"><?= trans('statement_formats', '', 'CSV con intestazione oppure CAMT.053') ?></span>
        </div>
        <div class="ip-doc-section__body">
            <?php /* Il caricamento non è una riga da aggiungere a una tabella:
                     è l'azione principale della sezione. Usa quindi la stessa
                     drop-zone dell'import (ip-import-drop), con il conto in
                     colonna accanto — prima un IPSelect da 120px stretto fra
                     file e pulsante non lasciava capire cosa fosse obbligatorio. */ ?>
            <form method="POST" action="<?= site_url('reconciliation/import') ?>"
                  enctype="multipart/form-data" class="ip-statement-upload" id="statement-upload">
                <?= csrf_field() ?>

                <label class="ip-import-drop" for="statement_file">
                    <span class="ip-import-drop__icon"><i class="fa fa-solid fa-cloud-upload" aria-hidden="true"></i></span>
                    <span class="ip-import-drop__text">
                        <span class="ip-import-drop__title">Trascina qui l’estratto conto o <u>sfoglia</u></span>
                        <span class="ip-import-drop__sub">CSV con intestazione oppure CAMT.053 (.xml) · un file per volta</span>
                    </span>
                    <input type="file" name="statement" id="statement_file"
                           accept=".csv,.xml,.txt,text/csv,application/xml" required>
                </label>

                <div class="ip-statement-upload__side">
                    <label class="ip-doc-label" for="bank_account_id"><?= trans('bank_account', '', 'Conto') ?></label>
                    <select name="bank_account_id" id="bank_account_id" class="form-control input-sm simple-select"
                            data-placeholder="<?= esc(trans('no_account_selected', '', 'Nessun conto specifico'), 'attr') ?>">
                        <option value=""><?= trans('no_account_selected', '', 'Nessun conto specifico') ?></option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int) $account['bank_account_id'] ?>"><?= esc($account['bank_account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <p class="ip-statement-file" id="statement_chosen" hidden>
                        <i class="fa fa-regular fa-file-lines" aria-hidden="true"></i>
                        <span></span>
                    </p>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa fa-solid fa-upload" aria-hidden="true"></i> <?= trans('import_statement', '', 'Importa estratto conto') ?>
                    </button>
                </div>
            </form>

            <?php
            // Stato del collegamento automatico: se nessun conto è collegato lo
            // si dice apertamente, invece di lasciare intendere che i movimenti
            // arrivino da soli.
            $linked = array_filter($accounts, static fn($a) => !empty($a['sync_provider']));
            ?>
            <p class="ip-number-help ip-statement-note">
                <?php if ($linked): ?>
                    <i class="fa fa-solid fa-link" aria-hidden="true"></i>
                    <?= count($linked) ?> <?= trans('accounts_linked', '', 'conti collegati alla banca.') ?>
                <?php else: ?>
                    <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                    <?= trans('no_sync_hint', '', 'Nessun conto collegato alla banca: i movimenti si caricano da file. La sincronizzazione automatica richiede un aggregatore PSD2 — vedi Impostazioni → Conti di saldo.') ?>
                <?php endif; ?>
            </p>
        </div>
    </section>

    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step"><i class="fa fa-solid fa-right-left" aria-hidden="true"></i></span>
            <h2 class="ip-doc-section__title"><?= trans('transactions', '', 'Movimenti') ?></h2>
            <span class="ip-doc-section__hint"><?= count($transactions) ?></span>
        </div>

        <?php /* Prima era una tabella a quattro colonne in cui l'azione
                 (abbinare) stava compressa nell'ultima cella accanto a una
                 causale bancaria spesso lunghissima. Ogni movimento diventa
                 una riga a tre zone — data, causale con direzione, importo e
                 abbinamento — così il verso del denaro si legge dall'icona e
                 dal colore prima ancora che dalla cifra, e la proposta di
                 abbinamento ha lo spazio per mostrare il perché. */ ?>
        <ul class="ip-recon-list">
            <?php $mesi = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic']; ?>
            <?php foreach ($transactions as $t): ?>
                <?php
                $amount = (float) $t['transaction_amount'];
                $isIn   = $amount >= 0;
                $done   = db_bool($t['is_reconciled']);
                $ts     = strtotime((string) $t['transaction_date']);
                ?>
                <li class="ip-recon-row<?= $done ? ' is-done' : '' ?>">
                    <span class="ip-recon-date" aria-label="<?= esc(date_from_mysql($t['transaction_date']), 'attr') ?>">
                        <span class="ip-recon-date__day"><?= $ts ? date('d', $ts) : '' ?></span>
                        <span class="ip-recon-date__month"><?= $ts ? $mesi[(int) date('n', $ts) - 1] . ' ' . date('y', $ts) : '' ?></span>
                    </span>

                    <span class="ip-recon-dir <?= $isIn ? 'is-in' : 'is-out' ?>"
                          title="<?= $isIn ? 'Entrata' : 'Uscita' ?>">
                        <i class="fa fa-solid <?= $isIn ? 'fa-arrow-down' : 'fa-arrow-up' ?>" aria-hidden="true"></i>
                    </span>

                    <span class="ip-recon-desc">
                        <span class="ip-recon-desc__text"><?= esc($t['transaction_description']) ?></span>
                        <?php if (!empty($t['bank_account_name'])): ?>
                            <span class="ip-recon-desc__meta"><i class="fa fa-solid fa-building-columns" aria-hidden="true"></i> <?= esc($t['bank_account_name']) ?></span>
                        <?php endif; ?>
                    </span>

                    <span class="ip-recon-amount <?= $isIn ? 'is-in' : 'is-out' ?>"><?= format_currency($amount) ?></span>

                    <div class="ip-recon-action">
                        <?php if ($done): ?>
                            <span class="ip-recon-matched">
                                <i class="fa fa-solid fa-circle-check" aria-hidden="true"></i>
                                <?php if (!empty($t['invoice_id'])): ?>
                                    <a href="<?= site_url('invoices/view/' . (int) $t['invoice_id']) ?>"><?= trans('invoice', '', 'Fattura') ?></a>
                                <?php elseif (!empty($t['expense_id'])): ?>
                                    <a href="<?= site_url('expenses/form/' . (int) $t['expense_id']) ?>"><?= trans('expense', '', 'Spesa') ?></a>
                                <?php else: ?>
                                    <?= trans('reconciled', '', 'Abbinato') ?>
                                <?php endif; ?>
                            </span>
                            <form method="POST" action="<?= site_url('reconciliation/unmatch/' . (int) $t['transaction_id']) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-link ip-recon-undo"
                                        title="<?= esc(trans('unmatch', '', 'Annulla abbinamento'), 'attr') ?>">
                                    <i class="fa fa-solid fa-rotate-left" aria-hidden="true"></i> <?= trans('unmatch', '', 'Annulla abbinamento') ?>
                                </button>
                            </form>
                        <?php elseif (empty($t['candidates'])): ?>
                            <span class="ip-recon-none">
                                <i class="fa fa-regular fa-circle-question" aria-hidden="true"></i>
                                <?= trans('no_candidates', '', 'Nessun documento corrispondente') ?>
                            </span>
                        <?php else: ?>
                            <?php $best = $t['candidates'][0]; ?>
                            <form method="POST" action="<?= site_url('reconciliation/match') ?>" class="ip-recon-match">
                                <?= csrf_field() ?>
                                <input type="hidden" name="transaction_id" value="<?= (int) $t['transaction_id'] ?>">
                                <?php if (count($t['candidates']) === 1): ?>
                                    <?php /* Un solo candidato: una tendina con
                                             una voce sola è rumore, la proposta
                                             si mostra come testo leggibile. */ ?>
                                    <input type="hidden" name="target" value="<?= esc($best['type'] . ':' . $best['id'], 'attr') ?>">
                                    <span class="ip-recon-suggest">
                                        <span class="ip-recon-suggest__label"><?= esc($best['label']) ?> · <?= format_currency($best['amount']) ?></span>
                                        <span class="ip-recon-suggest__why"><?= esc(implode(', ', $best['reasons'])) ?></span>
                                    </span>
                                <?php else: ?>
                                    <select name="target" class="form-control input-sm simple-select"
                                            aria-label="<?= esc(trans('match_target', '', 'Documento da abbinare'), 'attr') ?>">
                                        <?php foreach ($t['candidates'] as $c): ?>
                                            <option value="<?= esc($c['type'] . ':' . $c['id'], 'attr') ?>">
                                                <?= esc($c['label']) ?> — <?= format_currency($c['amount']) ?>
                                                (<?= esc(implode(', ', $c['reasons'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('match', '', 'Abbina') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if (empty($transactions)): ?>
                <li class="ip-recon-empty">
                    <i class="fa fa-solid fa-inbox" aria-hidden="true"></i>
                    <?= trans('no_transactions', '', 'Nessun movimento. Importa un estratto conto per iniziare.') ?>
                </li>
            <?php endif; ?>
        </ul>
    </section>
</div>

<?php /* Il nome del file scelto va mostrato: la drop-zone da sola non dice
         cosa verrà caricato, e con il trascinamento non c'è nessun campo che
         lo mostri. Lo script sta dentro il contenuto sostituito da pjax, così
         viene rieseguito a ogni navigazione. */ ?>
<script>
(function () {
    "use strict";
    var form = document.getElementById('statement-upload');
    if (!form) { return; }

    var input = form.querySelector('input[type="file"]');
    var drop  = input ? input.closest('.ip-import-drop') : null;
    var row   = document.getElementById('statement_chosen');
    var name  = row ? row.querySelector('span') : null;
    if (!input || !drop || !row || !name) { return; }

    function show() {
        var file = input.files && input.files[0];
        if (!file) {
            row.hidden = true;
            name.textContent = '';
            return;
        }
        name.textContent = file.name + ' · ' + (file.size / 1024).toFixed(1).replace('.', ',') + ' KB';
        row.hidden = false;
    }

    input.addEventListener('change', show);

    ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
            e.preventDefault();
            if (ev === 'drop' && e.dataTransfer && e.dataTransfer.files[0]) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            setTimeout(function () { drop.classList.remove('is-dragover'); }, 80);
        });
    });
})();
</script>

<?= $this->endSection() ?>
