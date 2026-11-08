<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', 'F24'); ?>
<form method="post" action="<?= site_url('f24/form/' . ($f24_id ?? '')) ?>" enctype="multipart/form-data" class="ip-doc-form">
    <?= csrf_field() ?>
    <?= $this->include('layout/headerbar', ['title' => 'F24', 'actions' => header_actions()]) ?>
    <div id="content">
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('f24_data', '', 'Dati della delega') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="f24_due_date"><?= trans('due_date', '', 'Scadenza') ?></label>
                        <input type="text" name="f24_due_date" id="f24_due_date" class="form-control datepicker"
                               value="<?= esc($model->formValue('f24_due_date') ? date_from_mysql($model->formValue('f24_due_date')) : date(date_format_setting())) ?>">
                        <?php if (!empty($errors['f24_due_date'])): ?><span class="ip-field-error"><?= esc($errors['f24_due_date']) ?></span><?php endif; ?>
                    </div>
                    <div class="ip-doc-field">
                        <?php /* Etichette dedicate, non le chiavi generiche: 'period',
                                 'bank_account' e 'file' significano altro altrove
                                 ("Periodo", "Conto", "File") e vincevano sul testo
                                 di riserva, quindi il campo si presentava senza
                                 dire quale file fosse né di che periodo. */ ?>
                        <label class="ip-doc-label" for="f24_period"><?= trans('f24_period_label', '', 'Periodo di riferimento') ?></label>
                        <input type="text" name="f24_period" id="f24_period" class="form-control"
                               placeholder="<?= esc(trans('f24_period_hint', '', 'Es. 2° trimestre 2026'), 'attr') ?>"
                               value="<?= esc(old('f24_period', $model->formValue('f24_period'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="f24_amount"><?= trans('amount', '', 'Importo') ?></label>
                        <input type="text" name="f24_amount" id="f24_amount" class="form-control amount"
                               value="<?= esc(format_amount($model->formValue('f24_amount'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="f24_paid_date"><?= trans('paid_on', '', 'Pagato il') ?></label>
                        <input type="text" name="f24_paid_date" id="f24_paid_date" class="form-control datepicker"
                               value="<?= esc($model->formValue('f24_paid_date') ? date_from_mysql($model->formValue('f24_paid_date')) : '') ?>">
                    </div>
                    <?php if (!empty($bank_accounts)): ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="bank_account_id"><?= trans('f24_bank_account_label', '', 'Conto di addebito') ?></label>
                            <select name="bank_account_id" id="bank_account_id" class="form-control simple-select">
                                <option value=""><?= trans('none', '', 'Nessuno') ?></option>
                                <?php foreach ($bank_accounts as $a): ?>
                                    <option value="<?= (int) $a['bank_account_id'] ?>" <?= (int) $model->formValue('bank_account_id') === (int) $a['bank_account_id'] ? 'selected' : '' ?>>
                                        <?= esc($a['bank_account_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="f24_file"><?= trans('f24_file_label', '', 'Modello (PDF)') ?></label>
                        <?php
                        /* Stesso componente degli altri caricamenti dell'app
                           (Impostazioni, import XML): area trascina-e-rilascia,
                           "Scegli/Sostituisci", rimozione e testo di aiuto.
                           Era un input file nativo, l'unico rimasto: qui il
                           file è la delega che si conserva, quindi merita un
                           controllo che dica cosa si è caricato. */
                        ?>
                        <input type="file" name="f24_file" id="f24_file" class="ip-file-input"
                               accept=".pdf,.jpg,.png,application/pdf,image/jpeg,image/png"
                               data-help="<?= esc(trans('f24_file_help', '', 'PDF, JPG o PNG · un file alla volta. Caricandone un altro sostituisci quello allegato.'), 'attr') ?>">
                        <?php if ($model->formValue('f24_file')): ?>
                            <p class="ip-number-help">
                                <i class="fa fa-solid fa-paperclip" aria-hidden="true"></i>
                                <?= trans('file_present', '', 'File già allegato: caricandone un altro lo sostituisci.') ?>
                                <?php if (!empty($f24_id)): ?>
                                    <a href="<?= site_url('f24/download/' . (int) $f24_id) ?>"><?= trans('download', '', 'Download') ?></a>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="f24_receipt_file"><?= trans('f24_receipt', '', 'Quietanza (PDF)') ?></label>
                        <input type="file" name="f24_receipt_file" id="f24_receipt_file" class="ip-file-input"
                               accept=".pdf,.jpg,.png,application/pdf,image/jpeg,image/png"
                               data-help="<?= esc(trans('f24_receipt_help', '', 'La ricevuta del versamento. Per caricarne molte insieme usa Importa quietanze.'), 'attr') ?>">
                        <?php if ($model->formValue('f24_receipt_file') && !empty($f24_id)): ?>
                            <p class="ip-number-help">
                                <i class="fa fa-solid fa-receipt" aria-hidden="true"></i>
                                <?= esc($model->formValue('f24_receipt_protocol') ?: trans('file_present', '', 'Quietanza allegata')) ?>
                                <a href="<?= site_url('f24/receipt/' . (int) $f24_id) ?>"><?= trans('download', '', 'Download') ?></a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ip-doc-field" style="margin-top:16px">
                    <label class="ip-doc-label" for="f24_note"><?= trans('note', '', 'Nota') ?></label>
                    <textarea name="f24_note" id="f24_note" rows="2" class="form-control"><?= esc($model->formValue('f24_note')) ?></textarea>
                </div>
            </div>
        </section>
    </div>
</form>
<?= $this->endSection() ?>
