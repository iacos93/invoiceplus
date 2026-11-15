<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('sdi_settings', '', 'Fattura elettronica (SdI)')); ?>

<script>
    ipReady(function () {
        var channelSelect = document.querySelector('select[name="settings[sdi_channel]"]');

        // Mostra solo i gruppi di campi coerenti col canale SdI scelto:
        // evita di mostrare inutilmente le credenziali PEC, API o IMAP.
        function toggle_sdi_settings() {
            var channel = channelSelect ? channelSelect.value : '';

            document.querySelectorAll('.sdi-group').forEach(function (group) {
                var matched = String(group.getAttribute('data-channels') || '').split(',').indexOf(channel) !== -1;
                group.hidden = !matched;
                // I controlli nascosti vanno disabilitati: cosi' il salvataggio
                // non ne azzera i valori gia' memorizzati (es. password).
                group.querySelectorAll('input, select, textarea').forEach(function (field) {
                    field.disabled = !matched;
                });
            });
        }

        toggle_sdi_settings();

        if (channelSelect) {
            channelSelect.addEventListener('change', toggle_sdi_settings);
        }
    });
</script>

<form method="POST" action="<?= site_url('sdi/settings') ?>" id="form-sdi-settings">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('sdi_settings', '', 'Fattura elettronica (SdI)'), 'actions' => header_actions()]) ?>

    <div id="content">
        <div class="container-xl py-3">
            <?= $this->include('settings/partial_sdi') ?>
        </div>
    </div>

</form>

<?= $this->endSection() ?>
