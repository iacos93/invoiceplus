<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('sdi_settings', '', 'Fattura elettronica (SdI)')); ?>

<script>
    $(function () {
        // Mostra solo i gruppi di campi coerenti col canale SdI scelto:
        // evita di mostrare inutilmente le credenziali PEC, API o IMAP.
        toggle_sdi_settings();

        $('select[name="settings[sdi_channel]"]').change(function () {
            toggle_sdi_settings();
        });

        function toggle_sdi_settings() {
            var channel = $('select[name="settings[sdi_channel]"]').val();

            $('.sdi-group').each(function () {
                var matched = String($(this).data('channels')).split(',').indexOf(channel) !== -1;
                $(this).toggle(matched);
                // I controlli nascosti vanno disabilitati: cosi' il salvataggio
                // non ne azzera i valori gia' memorizzati (es. password).
                $(this).find('input, select, textarea').prop('disabled', !matched);
            });
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
