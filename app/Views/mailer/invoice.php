<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('email_invoice')); ?>
<?php $actions = '<button type="button" onclick="window.history.back()" class="btn btn-danger"><i class="fa fa-solid fa-times" aria-hidden="true"></i> ' . trans('cancel') . '</button>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('email_invoice'), 'actions' => $actions]) ?>

<div id="content">
    <form method="POST" action="<?= site_url('mailer/send_invoice/' . $invoice_id) ?>" id="mailer_form">
        <?= csrf_field() ?>
        <?php if (!empty($is_reminder)): ?>
            <input type="hidden" name="is_reminder" value="1">
        <?php endif; ?>

        <div class="row">
            <div class="col-12 col-md-8">
                <div class="card">
                    <div class="card-header"><?= !empty($is_reminder) ? trans('payment_reminder', '', 'Sollecito di pagamento') : trans('email_invoice') ?></div>
                    <div class="card-body">
                        <?php if (!empty($is_reminder)): ?>
                            <div class="alert alert-warning small">
                                <?= esc(sprintf(
                                    trans('reminder_hint', '', 'Fattura scaduta dal %s, saldo da incassare %s. Il testo qui sotto è un sollecito: controllalo prima di inviare.'),
                                    date_from_mysql($invoice['invoice_date_due'], true),
                                    format_currency($invoice['invoice_balance'])
                                )) ?>
                                <?php if (!empty($invoice['invoice_last_reminder_sent'])): ?>
                                    <?= esc(sprintf(trans('reminder_last_sent', '', 'Ultimo sollecito inviato il %s.'), date_from_mysql($invoice['invoice_last_reminder_sent'], true))) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="to_email"><?= trans('to_email') ?></label>
                            <input type="email" name="to_email" id="to_email" class="form-control" required value="<?= esc($invoice['client_email'] ?? '') ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template"><?= trans('email_template') ?></label>
                            <select name="email_template" id="email_template" class="form-control simple-select">
                                <option value=""><?= trans('none') ?></option>
                                <?php foreach ($email_templates as $template_option): ?>
                                    <option value="<?= esc($template_option['email_template_id']) ?>"
                                        <?= (int) $selected_email_template === (int) $template_option['email_template_id'] ? 'selected' : '' ?>>
                                        <?= esc($template_option['email_template_title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!empty($senders)): ?>
                            <div class="ip-doc-field">
                                <label class="ip-doc-label" for="sender_id"><?= trans('send_from', '', 'Invia da') ?></label>
                                <select name="sender_id" id="sender_id" class="form-control simple-select">
                                    <?php foreach ($senders as $sender): ?>
                                        <option value="<?= (int) $sender['sender_id'] ?>"
                                            <?= db_bool($sender['sender_is_default']) ? 'selected' : '' ?>>
                                            <?= esc($sender['sender_name']) ?> &lt;<?= esc($sender['sender_email']) ?>&gt;
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php /* Scegliendo un mittente configurato, nome e indirizzo qui
                                         sotto vengono ignorati: e' la casella che autentica
                                         l'invio, e un From diverso fa scartare il messaggio. */ ?>
                                <p class="ip-field-help">
                                    <?= trans('send_from_help', '', 'Il messaggio parte da questa casella; se ha un SMTP proprio viene usato quello.') ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="from_name"><?= trans('from_name') ?></label>
                            <input type="text" name="from_name" id="from_name" class="form-control" value="<?= esc($invoice['user_name'] ?? '') ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="from_email"><?= trans('from_email') ?></label>
                            <input type="email" name="from_email" id="from_email" class="form-control" value="<?= esc($invoice['user_email'] ?? '') ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="cc"><?= trans('cc') ?></label>
                            <input type="text" name="cc" id="cc" class="form-control" value="">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="bcc"><?= trans('bcc') ?></label>
                            <input type="text" name="bcc" id="bcc" class="form-control" value="">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="subject"><?= trans('subject') ?></label>
                            <input type="text" name="subject" id="subject" class="form-control"
                                   value="<?= esc(trans('invoice')) ?> #<?= esc($invoice['invoice_number'] ?? '') ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="pdf_template"><?= trans('pdf_template') ?></label>
                            <select name="pdf_template" id="pdf_template" class="form-control simple-select">
                                <option value=""><?= trans('none') ?></option>
                                <?php foreach ($pdf_templates as $pdf_template): ?>
                                    <option value="<?= esc($pdf_template) ?>"
                                        <?= (string) $selected_pdf_template === (string) $pdf_template ? 'selected' : '' ?>>
                                        <?= esc($pdf_template) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="body"><?= trans('body') ?></label>
                            <textarea name="body" id="body" rows="12" class="form-control"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-check form-check-inline">
                                <input type="checkbox" name="invoice_pdf_attachment" value="1" checked>
                                <?= trans('email_pdf_attachment') ?>
                            </label>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="guest_url"><?= trans('guest_url') ?></label>
                            <input type="text" id="guest_url" class="form-control" readonly value="<?= esc(site_url('guest/view/invoice/' . ($invoice['invoice_url_key'] ?? ''))) ?>">
                        </div>

                        <?= view('email_templates/template_tags') ?>

                        <button type="submit" name="btn_send" value="1" class="btn btn-success float-end"><i class="fa fa-solid fa-paper-plane" aria-hidden="true"></i> <?= trans('send') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    (function () {
        var template_fields = ["body", "subject", "from_name", "from_email", "cc", "bcc", "pdf_template"];
        var field_map = {
            body: "email_template_body",
            subject: "email_template_subject",
            from_name: "email_template_from_name",
            from_email: "email_template_from_email",
            cc: "email_template_cc",
            bcc: "email_template_bcc",
            pdf_template: "email_template_pdf_template"
        };

        function inject_email_template(template) {
            for (var i = 0; i < template_fields.length; i++) {
                var key = template_fields[i];
                var el = document.getElementById(key);
                if (el && template[field_map[key]] !== undefined) {
                    el.value = template[field_map[key]] || '';
                }
            }
        }

        var selected_email_template = <?= $email_template ?: '{}' ?>;
        if (selected_email_template && typeof selected_email_template === 'object') {
            inject_email_template(selected_email_template);
        }

        var templateSelect = document.getElementById('email_template');
        if (templateSelect) {
            templateSelect.addEventListener('change', function () {
                var id = this.value;
                if (id === '') return;

                fetch('<?= site_url('email_templates/ajax/get_content') ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: 'email_template_id=' + encodeURIComponent(id) +
                        '&<?= csrf_token() ?>=' + encodeURIComponent('<?= csrf_hash() ?>')
                }).then(function (r) { return r.json(); })
                  .then(inject_email_template)
                  .catch(function () {});
            });
        }
    })();
</script>

<?= $this->endSection() ?>