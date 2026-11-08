<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Form del conto di saldo.
 *
 * Accanto ai campi sta il foglio d'anteprima: il conto esiste per finire
 * stampato nel riquadro pagamento del documento (e nel nodo <IBAN> della
 * fattura elettronica), quindi mentre si compila si vede il blocco come sara'
 * stampato, con l'IBAN a gruppi di quattro come sull'estratto conto. Serve
 * soprattutto a ricontrollare le cifre: un IBAN sbagliato torna indietro come
 * scarto dello SdI giorni dopo, a fattura gia' numerata.
 */
use App\Models\BankAccountModel;

$this->setVar('pageTitle', trans('bank_account_form', '', 'Conto di saldo'));

$v = static fn (string $key) => (string) old($key, $model->formValue($key));
?>

<form method="post" action="<?= site_url('bank_accounts/form/' . ($bank_account_id ?? '')) ?>" class="ip-doc-form">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', [
        'title'   => trans('bank_account_form', '', 'Conto di saldo'),
        'actions' => header_actions(),
    ]) ?>

    <div id="content">
        <div class="ip-form-pane">
            <div class="ip-account-layout">

                <div class="ip-account-layout__main">

                    <section class="ip-doc-section">
                        <div class="ip-doc-section__head">
                            <span class="ip-doc-step">1</span>
                            <h2 class="ip-doc-section__title"><?= trans('bank_account', '', 'Il conto') ?></h2>
                        </div>
                        <div class="ip-doc-section__body">
                            <div class="ip-doc-grid">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="bank_account_name"><?= trans('name', '', 'Nome') ?></label>
                                    <input type="text" name="bank_account_name" id="bank_account_name" class="form-control"
                                           value="<?= esc($v('bank_account_name')) ?>"
                                           placeholder="<?= esc(trans('bank_account_name_placeholder', '', 'Es. Conto principale'), 'attr') ?>"
                                           maxlength="100" required>
                                    <p class="ip-field-help"><?= trans('bank_account_name_help', '', 'Come lo riconosci tu nella select della fattura. Non viene stampato.') ?></p>
                                    <?php if (!empty($errors['bank_account_name'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['bank_account_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="bank_account_bank"><?= trans('bank', '', 'Banca') ?></label>
                                    <input type="text" name="bank_account_bank" id="bank_account_bank" class="form-control"
                                           value="<?= esc($v('bank_account_bank')) ?>" maxlength="150"
                                           placeholder="<?= esc(trans('bank_account_bank_placeholder', '', 'Es. Intesa Sanpaolo'), 'attr') ?>">
                                </div>
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="bank_account_holder"><?= trans('holder', '', 'Intestatario') ?></label>
                                    <input type="text" name="bank_account_holder" id="bank_account_holder" class="form-control"
                                           value="<?= esc($v('bank_account_holder')) ?>" maxlength="150">
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="ip-doc-section">
                        <div class="ip-doc-section__head">
                            <span class="ip-doc-step">2</span>
                            <h2 class="ip-doc-section__title"><?= trans('bank_details', '', 'Coordinate') ?></h2>
                            <span class="ip-doc-section__hint"><?= trans('bank_details_hint', '', 'Vanno in fattura e nell\'XML') ?></span>
                        </div>
                        <div class="ip-doc-section__body">
                            <div class="ip-doc-grid">
                                <div class="ip-doc-field ip-doc-field--wide">
                                    <label class="ip-doc-label" for="bank_account_iban"><?= trans('iban', '', 'IBAN') ?></label>
                                    <input type="text" name="bank_account_iban" id="bank_account_iban" class="form-control ip-num"
                                           value="<?= esc(BankAccountModel::formatIban($v('bank_account_iban'))) ?>"
                                           placeholder="IT60 X054 2811 1010 0000 0123 456"
                                           autocomplete="off" spellcheck="false" inputmode="latin">
                                    <p class="ip-field-help"><?= trans('iban_help', '', 'Incollalo pure con gli spazi: vengono tolti al salvataggio, come vuole il tracciato.') ?></p>
                                    <?php if (!empty($errors['bank_account_iban'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['bank_account_iban']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="bank_account_bic"><?= trans('bic', '', 'BIC / SWIFT') ?></label>
                                    <input type="text" name="bank_account_bic" id="bank_account_bic" class="form-control"
                                           value="<?= esc($v('bank_account_bic')) ?>" maxlength="11"
                                           autocomplete="off" spellcheck="false" style="text-transform:uppercase">
                                    <p class="ip-field-help"><?= trans('bic_help', '', 'Serve per i bonifici dall\'estero.') ?></p>
                                    <?php if (!empty($errors['bank_account_bic'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['bank_account_bic']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php /* Il SIA sta sul conto e non sull'azienda: lo assegna
                                         la banca dentro il rapporto, quindi chi presenta
                                         RiBa su due banche ne ha due diversi. */ ?>
                                <div class="ip-doc-field ip-doc-field--wide">
                                    <label class="ip-doc-label" for="bank_account_sia_code"><?= trans('sia_code', '', 'Codice SIA') ?></label>
                                    <input type="text" name="bank_account_sia_code" id="bank_account_sia_code" class="form-control ip-input-short"
                                           maxlength="5" value="<?= esc($v('bank_account_sia_code')) ?>"
                                           autocomplete="off" spellcheck="false" style="text-transform:uppercase">
                                    <p class="ip-field-help">
                                        <?= trans('sia_help', '', 'Cinque caratteri assegnati dalla banca: identificano l\'azienda come creditore nei flussi RiBa. Serve solo se presenti ricevute bancarie su questo conto.') ?>
                                    </p>
                                    <?php if (!empty($errors['bank_account_sia_code'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['bank_account_sia_code']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="ip-doc-section">
                        <div class="ip-doc-section__head">
                            <span class="ip-doc-step">3</span>
                            <h2 class="ip-doc-section__title"><?= trans('on_documents', '', 'Sui documenti') ?></h2>
                        </div>
                        <div class="ip-doc-section__body">
                            <div class="form-check">
                                <input type="hidden" name="bank_account_is_default" value="0">
                                <input type="checkbox" class="form-check-input" name="bank_account_is_default"
                                       id="bank_account_is_default" value="1"
                                       <?= $v('bank_account_is_default') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bank_account_is_default">
                                    <?= trans('bank_account_is_default', '', 'Usa come conto predefinito sui documenti') ?>
                                </label>
                                <p class="ip-field-help">
                                    <?= trans('bank_account_is_default_help', '', 'Viene stampato sulle fatture che non indicano un conto diverso. Il predefinito è uno solo: impostandolo qui, l\'altro decade.') ?>
                                </p>
                            </div>

                            <div class="ip-doc-field" style="margin-top:16px">
                                <label class="ip-doc-label" for="bank_account_note"><?= trans('note', '', 'Nota') ?></label>
                                <textarea name="bank_account_note" id="bank_account_note" rows="2"
                                          class="form-control"
                                          placeholder="<?= esc(trans('bank_account_note_placeholder', '', 'Es. Indicare il numero fattura nella causale.'), 'attr') ?>"><?= esc($v('bank_account_note')) ?></textarea>
                                <p class="ip-field-help">
                                    <?= trans('bank_account_note_help', '', 'Testo aggiuntivo riportato sul documento sotto le coordinate.') ?>
                                </p>
                            </div>
                        </div>
                    </section>

                </div>

                <?php /* Anteprima: aggiornata dal vivo da ip-bank-account-form.js. Il
                         markup parte gia' compilato lato server, cosi' chi apre un conto
                         esistente lo vede anche prima che parta lo script. */ ?>
                <aside class="ip-account-layout__side">
                    <div class="ip-paysheet" id="ip-paysheet">
                        <span class="ip-paysheet__label"><?= trans('on_the_document', '', 'Sul documento') ?></span>
                        <div class="ip-paysheet__paper">
                            <p class="ip-paysheet__title" data-paysheet="heading"><?= trans('payment_details', '', 'Coordinate per il pagamento') ?></p>
                            <p class="ip-paysheet__line" data-paysheet="bank"><?= esc($v('bank_account_bank')) ?></p>
                            <p class="ip-paysheet__line" data-paysheet="holder"><?= esc($v('bank_account_holder')) ?></p>
                            <p class="ip-paysheet__line ip-paysheet__iban" data-paysheet="iban"><?= esc(BankAccountModel::formatIban($v('bank_account_iban'))) ?></p>
                            <p class="ip-paysheet__line" data-paysheet="bic"><?= $v('bank_account_bic') ? 'BIC ' . esc($v('bank_account_bic')) : '' ?></p>
                            <p class="ip-paysheet__empty" data-paysheet="empty" <?= $v('bank_account_iban') ? 'hidden' : '' ?>>
                                <?= trans('paysheet_empty', '', 'Compila l\'IBAN per vedere il blocco che verrà stampato.') ?>
                            </p>
                            <p class="ip-paysheet__note" data-paysheet="note" <?= $v('bank_account_note') ? '' : 'hidden' ?>><?= esc($v('bank_account_note')) ?></p>
                        </div>
                    </div>
                </aside>

            </div>
        </div>
    </div>

</form>

<script defer src="<?php _core_asset('js/ip-bank-account-form.js'); ?>"></script>

<?= $this->endSection() ?>
