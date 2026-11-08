<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Form del metodo di pagamento.
 *
 * Le due opzioni sotto il nome decidono cosa esce sul documento: la modalita'
 * MP01…MP23 finisce nel blocco DatiPagamento della fattura elettronica, il
 * flag "coordinate" fa comparire accanto l'IBAN del conto di saldo. Il foglio
 * a lato le mostra applicate al conto predefinito, cosi' la conseguenza si
 * legge qui invece di scoprirla sul PDF.
 */
use App\Models\BankAccountModel;
use App\Models\PaymentMethodModel;

$this->setVar('pageTitle', trans('payment_method_form'));

$requiresBankAccount = old('payment_method_requires_bank_account');
if ($requiresBankAccount === null) {
    // Nuovo metodo (nessun id, nessun repost): default spuntato, coerente
    // col default 1 della colonna. In modifica/repost formValue()/old()
    // riportano il valore reale gia' salvato.
    $requiresBankAccount = $payment_method_id
        ? $model->formValue('payment_method_requires_bank_account')
        : 1;
}

// db_bool() e non il confronto booleano PHP: da PostgreSQL il valore salvato
// arriva come 't'/'f', e 'f' e' una stringa non vuota, quindi "vera" — la
// casella risultava sempre spuntata e il salvataggio successivo rimetteva
// l'IBAN anche sui metodi che non lo prevedono. db_bool() riconosce anche
// '1'/'0' del repost e l'1 del default.
$requiresBankAccount = db_bool($requiresBankAccount);

$currentCode = (string) old('payment_method_sdi_code', $model->formValue('payment_method_sdi_code'));
$account     = $default_account ?? null;
?>

<form method="post" action="<?= site_url('payment_methods/form/' . ($payment_method_id ?? '')) ?>" class="ip-doc-form">

    <?= csrf_field() ?>
    <?php // Serve alla regola is_unique del nome, che deve escludere la riga in modifica. ?>
    <input type="hidden" name="payment_method_id" value="<?= (int) ($payment_method_id ?? 0) ?>">

    <?= $this->include('layout/headerbar', ['title' => trans('payment_method_form'), 'actions' => header_actions()]) ?>

    <div id="content">
        <div class="ip-form-pane">
            <div class="ip-account-layout">

                <div class="ip-account-layout__main">

                    <section class="ip-doc-section">
                        <div class="ip-doc-section__head">
                            <h2 class="ip-doc-section__title"><?= trans('payment_method', '', 'Metodo di pagamento') ?></h2>
                        </div>
                        <div class="ip-doc-section__body">

                            <div class="ip-doc-grid">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="payment_method_name">
                                        <?= trans('name', '', 'Nome') ?>
                                    </label>
                                    <input type="text" name="payment_method_name" id="payment_method_name" class="form-control"
                                           value="<?= esc(old('payment_method_name', $model->formValue('payment_method_name'))) ?>"
                                           maxlength="35" required>
                                    <p class="ip-field-help"><?= trans('payment_method_name_help', '', 'Come compare nella select della fattura e nel report incassi.') ?></p>
                                    <?php if (!empty($errors['payment_method_name'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['payment_method_name']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="payment_method_sdi_code">
                                        <?= trans('sdi_payment_mode', '', 'Modalità SdI') ?>
                                    </label>
                                    <select name="payment_method_sdi_code" id="payment_method_sdi_code" class="form-control simple-select"
                                            data-placeholder="<?= esc(trans('not_set', '', 'Non impostata'), 'attr') ?>">
                                        <option value=""><?= trans('not_set', '', 'Non impostata') ?></option>
                                        <?php foreach (PaymentMethodModel::SDI_CODES as $code => $label): ?>
                                            <option value="<?= esc($code, 'attr') ?>" <?= $currentCode === $code ? 'selected' : '' ?>>
                                                <?= esc($code . ' · ' . $label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="ip-field-help">
                                        <?= trans('sdi_payment_mode_help', '', 'Il codice ModalitaPagamento della fattura elettronica. Senza, il documento parte privo del blocco dati di pagamento.') ?>
                                    </p>
                                    <?php if (!empty($errors['payment_method_sdi_code'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['payment_method_sdi_code']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php // Fuori dalla griglia: con il paragrafo di spiegazione
                                  // sbilanciava la riga lasciando mezzo schermo vuoto a destra. ?>
                            <div class="form-check" style="margin-top:16px">
                                <input type="hidden" name="payment_method_requires_bank_account" value="0">
                                <input type="checkbox" class="form-check-input" name="payment_method_requires_bank_account"
                                       id="payment_method_requires_bank_account" value="1"
                                       <?= $requiresBankAccount ? 'checked' : '' ?>>
                                <label class="form-check-label" for="payment_method_requires_bank_account">
                                    <?= trans('payment_method_requires_bank_account', '', 'Richiede un conto di saldo (IBAN) sul documento') ?>
                                </label>
                                <p class="ip-field-help">
                                    <?= trans('payment_method_requires_bank_account_hint', '', 'Lascia spuntato per i metodi bancari (bonifico, RiBa, SDD): sul documento compaiono anche le coordinate del conto, e l\'IBAN entra nella fattura elettronica. Togli la spunta per contanti o assegno: il campo "Conto di saldo" sparisce dal form fattura.') ?>
                                </p>
                            </div>

                        </div>
                    </section>

                </div>

                <?php /* Stesso foglio del form conto di saldo: la coppia di pagine
                         descrive lo stesso riquadro del documento da due lati. */ ?>
                <aside class="ip-account-layout__side">
                    <div class="ip-paysheet" id="ip-paysheet">
                        <span class="ip-paysheet__label"><?= trans('on_the_document', '', 'Sul documento') ?></span>
                        <div class="ip-paysheet__paper">
                            <p class="ip-paysheet__title"><?= trans('payment_details', '', 'Coordinate per il pagamento') ?></p>
                            <p class="ip-paysheet__line" data-paysheet="method"></p>
                            <p class="ip-paysheet__line" data-paysheet="mode"></p>
                            <?php if ($account): ?>
                                <p class="ip-paysheet__line" data-paysheet="account"><?= esc(implode(' · ', array_filter([$account['bank_account_bank'] ?? '', $account['bank_account_holder'] ?? '']))) ?></p>
                                <p class="ip-paysheet__line ip-paysheet__iban" data-paysheet="iban"><?= esc(BankAccountModel::formatIban($account['bank_account_iban'] ?? '')) ?></p>
                            <?php else: ?>
                                <p class="ip-paysheet__line" data-paysheet="account"></p>
                                <p class="ip-paysheet__line ip-paysheet__iban" data-paysheet="iban"></p>
                                <p class="ip-paysheet__empty" data-paysheet="no-account">
                                    <?= trans('paysheet_no_account', '', 'Nessun conto di saldo configurato: le coordinate non verranno stampate.') ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if ($account): ?>
                            <p class="ip-paysheet__label" style="margin:8px 0 0; text-transform:none; letter-spacing:0; font-weight:400">
                                <?= trans('paysheet_account_source', '', 'Coordinate del conto predefinito') ?>:
                                <?= esc($account['bank_account_name']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </aside>

            </div>
        </div>
    </div>

</form>

<script defer src="<?php _core_asset('js/ip-payment-method-form.js'); ?>"></script>

<?= $this->endSection() ?>
