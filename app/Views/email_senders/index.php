<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
// Unica sezione per tutto cio' che riguarda l'invio email: impostazioni
// generali in alto, caselle mittente (ognuna con il proprio server SMTP) sotto.
// Prima la stessa configurazione stava anche in una scheda Email delle
// Impostazioni, con un blocco SMTP che scriveva su una sola casella: due posti
// per lo stesso dato, e non era chiaro quale valesse.
$this->setVar('pageTitle', trans('email_settings_section', '', 'Email e mittenti'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('email_senders/form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new_sender', '', 'Nuovo mittente') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('email_settings_section', '', 'Email e mittenti'), 'actions' => $actions]) ?>

<?php /* #content semplice e non .table-content: la pagina apre con un form a
         sezioni, non con la tabella, e senza padding verticale la sezione
         restava incollata all'headerbar. La card dei mittenti sotto porta già
         il proprio distacco. */ ?>
<div id="content">

    <p class="ip-settings-intro"><?= trans('email_section_intro', '', 'Tutto l\'invio delle email in un posto: in alto la casella usata per le email di sistema e l\'allegato PDF, sotto le caselle mittente, ciascuna con il proprio server SMTP.') ?></p>

    <form method="post" action="<?= site_url('email_senders') ?>" class="ip-email-config-form">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('email_config', '', 'Configurazione email') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="row">
                    <div class="col-12 col-md-6">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="settings[email_pdf_attachment]">
                                <?= trans('email_pdf_attachment') ?>
                            </label>
                            <div class="form-check">
                                <input type="hidden" name="settings[email_pdf_attachment]" value="0">
                                <input type="checkbox" class="form-check-input" name="settings[email_pdf_attachment]" value="1"
                                       id="settings[email_pdf_attachment]"
                                    <?= check_select(get_setting('email_pdf_attachment'), '1', '==', true) ?>>
                                <label class="form-check-label" for="settings[email_pdf_attachment]"><?= trans('yes') ?></label>
                            </div>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="settings[company_system_sender_id]">
                                <?= trans('system_email_sender') ?>
                            </label>
                            <select name="settings[company_system_sender_id]" id="settings[company_system_sender_id]"
                                    class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                <option value="0" <?= check_select((int) company_setting('company_system_sender_id', 0), 0) ?>>
                                    <?= trans('system_email_sender_none', '', 'Nessuna (PHP mail())') ?>
                                </option>
                                <?php foreach ($senders as $es): ?>
                                    <option value="<?= (int) $es['sender_id'] ?>" <?= check_select((int) company_setting('company_system_sender_id', 0), (int) $es['sender_id']) ?>>
                                        <?= esc($es['sender_name'] . ' &lt;' . $es['sender_email'] . '&gt;' . (!empty($es['sender_smtp_host']) ? ' — ' . $es['sender_smtp_host'] : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><?= trans('system_email_sender_help', '', 'Casella usata per le email di sistema (reset password, notifiche cron, ricevute SdI di ripiego).') ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="ip-doc-actions">
            <button type="submit" class="btn btn-sm btn-success">
                <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('save', '', 'Salva') ?>
            </button>
        </div>
    </form>

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-envelope" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('email_senders', '', 'Mittenti email')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_email_senders') : count($senders) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse">
                <thead><tr>
                    <th scope="col"><?= trans('name', '', 'Nome') ?></th>
                    <th scope="col"><?= trans('email', '', 'Indirizzo') ?></th>
                    <th scope="col"><?= trans('smtp', '', 'SMTP') ?></th>
                    <th scope="col"><?= trans('default', '', 'Predefinito') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($senders as $s): ?>
                    <tr>
                        <td data-label="<?= esc(trans('name', '', 'Nome')) ?>"><?= esc($s['sender_name']) ?></td>
                        <td data-label="<?= esc(trans('email', '', 'Indirizzo')) ?>"><span class="ip-num"><?= esc($s['sender_email']) ?></span></td>
                        <td data-label="<?= esc(trans('smtp', '', 'SMTP')) ?>">
                            <?php if (!empty($s['sender_smtp_host'])): ?>
                                <?= esc($s['sender_smtp_host']) ?><small class="text-muted">:<?= (int) $s['sender_smtp_port'] ?></small>
                            <?php else: ?>
                                <span class="text-muted"><?= trans('smtp_none_php_mail', '', 'nessuno: mail() di PHP') ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= esc(trans('default', '', 'Predefinito')) ?>">
                            <?php if (db_bool($s['sender_is_default'])): ?>
                                <span class="badge ip-status text-bg-success"><?= trans('default', '', 'Predefinito') ?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= site_url('email_senders/form/' . (int) $s['sender_id']) ?>"><i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?></a></li>
                                    <li><form action="<?= site_url('email_senders/delete/' . (int) $s['sender_id']) ?>" method="POST"><?= csrf_field() ?>
                                        <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                data-confirm-body="<?= esc(trans('delete_record_warning', '', 'Eliminare il mittente?'), 'attr') ?>"
                                                data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?></button></form></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($senders)): ?>
                    <tr><td colspan="5" class="ip-items-table__empty">
                        <?= trans('no_senders', '', 'Nessun mittente configurato: crea un mittente con "Nuovo mittente" per iniziare a spedire email.') ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= $this->include('layout/pager_footer', ['group' => 'ip_email_senders']) ?>
    </div>
</div>
<?= $this->endSection() ?>
