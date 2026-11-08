<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', $account_id
    ? trans('management_edit_account', '', 'Modifica voce')
    : trans('management_new_account', '', 'Nuova voce'));

$value = static function (string $key, $default = '') use ($model): string {
    $v = $model->formValues[$key] ?? $default;

    return (string) ($v ?? '');
};

?>

<?= $this->include('layout/headerbar', ['title' => $account_id
    ? trans('management_edit_account', '', 'Modifica voce')
    : trans('management_new_account', '', 'Nuova voce')]) ?>

<div id="content">
    <form method="post" action="<?= site_url('management/accounts/form' . ($account_id ? '/' . (int) $account_id : '')) ?>">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('management_account_data', '', 'Dati della voce') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="account_code"><?= trans('management_code', '', 'Codice') ?></label>
                        <input type="text" name="account_code" id="account_code" class="form-control" maxlength="20"
                               required value="<?= esc($value('account_code')) ?>">
                        <?php if (!empty($errors['account_code'])): ?>
                            <span class="ip-field-error"><?= esc($errors['account_code']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="account_name"><?= trans('management_name', '', 'Nome') ?></label>
                        <input type="text" name="account_name" id="account_name" class="form-control" maxlength="150"
                               required value="<?= esc($value('account_name')) ?>">
                        <?php if (!empty($errors['account_name'])): ?>
                            <span class="ip-field-error"><?= esc($errors['account_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="account_type"><?= trans('management_type', '', 'Tipo') ?></label>
                        <select name="account_type" id="account_type" class="form-control simple-select" required>
                            <option value=""><?= trans('management_choose', '', 'Scegli…') ?></option>
                            <?php foreach ($types as $key => $label): ?>
                                <option value="<?= esc($key, 'attr') ?>" <?= $value('account_type') === $key ? 'selected' : '' ?>>
                                    <?= esc($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="ip-doc-section__hint">
                            <?= trans('management_type_hint', '', 'Ricavo entra nei ricavi; costo variabile e fisso si distinguono per il margine di contribuzione.') ?>
                        </span>
                        <?php if (!empty($errors['account_type'])): ?>
                            <span class="ip-field-error"><?= esc($errors['account_type']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="parent_id"><?= trans('management_parent', '', 'Voce padre') ?></label>
                        <select name="parent_id" id="parent_id" class="form-control simple-select"
                                data-placeholder="<?= esc(trans('management_no_parent', '', 'Nessuna — voce di primo livello'), 'attr') ?>"
                                data-allow-clear="true">
                            <option value=""><?= trans('management_no_parent', '', 'Nessuna — voce di primo livello') ?></option>
                            <?php foreach ($parents as $parentId => $parentLabel): ?>
                                <?php if ($account_id !== null && (int) $parentId === (int) $account_id) { continue; } ?>
                                <option value="<?= (int) $parentId ?>" <?= $value('parent_id') === (string) $parentId ? 'selected' : '' ?>>
                                    <?= esc($parentLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['parent_id'])): ?>
                            <span class="ip-field-error"><?= esc($errors['parent_id']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="account_order"><?= trans('management_order', '', 'Ordine') ?></label>
                        <input type="number" name="account_order" id="account_order" class="form-control" step="1"
                               value="<?= esc($value('account_order', '0')) ?>">
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="account_active"><?= trans('status', '', 'Stato') ?></label>
                        <div class="form-check">
                            <input type="checkbox" name="account_active" id="account_active" value="1" class="form-check-input"
                                <?= $value('account_active', '1') === '0' ? '' : 'checked' ?>>
                            <label class="form-check-label" for="account_active">
                                <?= trans('management_active_hint', '', 'Attiva (compare nelle select di assegnazione)') ?>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="ip-doc-grid">
                    <div class="ip-doc-field ip-doc-field--wide">
                        <label class="ip-doc-label" for="account_note"><?= trans('notes', '', 'Note') ?></label>
                        <textarea name="account_note" id="account_note" class="form-control" rows="3"><?= esc($value('account_note')) ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <div class="ip-doc-actions">
            <button type="submit" class="btn btn-sm btn-success">
                <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('save', '', 'Salva') ?>
            </button>
            <a class="btn btn-sm btn-secondary" href="<?= site_url('management/accounts') ?>">
                <?= trans('cancel', '', 'Annulla') ?>
            </a>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
