<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Configurazione dell'integrazione MCP.
 *
 * Pagina dedicata (fuori da Impostazioni): la chiave del server /mcp e' il
 * segreto che gli agenti AI esterni usano come bearer token, e l'azienda
 * selezionata fissa il perimetro dei dati su cui possono operare. Il form e'
 * volutamente minimale: la chiave si rigenera via ajax, l'URL deriva da
 * site_url('mcp'), resta solo l'azienda da scegliere.
 *
 * Ottimizzazioni UI rispetto all'originale:
 *  - badge di stato live dell'endpoint (attivo / non configurato);
 *  - snippet di configurazione client gia' pronto da copiare;
 *  - conferma prima di rigenerare la chiave (azione distruttiva);
 *  - feedback toast alla copia dell'endpoint.
 */

$this->setVar('pageTitle', trans('mcp_settings', '', 'Integrazione MCP'));

$mcpKey       = (string) get_setting('mcp_key', '');
$mcpCompanyId = (string) get_setting('mcp_company_id', '0');
$mcpActive    = $mcpKey !== '';
$mcpUrl       = site_url('mcp');
?>

<form method="post" action="<?= site_url('mcp/settings') ?>" id="form-mcp-settings">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', [
        'title'   => trans('mcp_settings', '', 'Integrazione MCP'),
        'actions' => header_actions(['hide_cancel_button' => true]),
    ]) ?>

    <div id="content">
        <div class="ip-form-pane">
            <div class="ip-account-layout ip-account-layout--wide">

                <div class="ip-account-layout__main">

                    <!-- Stato dell'endpoint: dà subito conferma che l'integrazione è operativa. -->
                    <div class="ip-mcp-status alert <?= $mcpActive ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center mb-3" role="status">
                        <span class="badge text-bg-<?= $mcpActive ? 'success' : 'warning' ?> me-2">
                            <?= $mcpActive ? trans('mcp_active') : trans('mcp_inactive') ?>
                        </span>
                        <span class="ip-mcp-status__text">
                            <?= $mcpActive ? trans('mcp_status_active_help') : trans('mcp_status_inactive_help') ?>
                        </span>
                    </div>

                    <section class="ip-doc-section">
                        <div class="ip-doc-section__head">
                            <span class="ip-doc-step">1</span>
                            <h2 class="ip-doc-section__title"><?= trans('mcp_key') ?></h2>
                        </div>
                        <div class="ip-doc-section__body">
                            <div class="ip-doc-grid">
                                <div class="ip-doc-field ip-doc-field--wide">
                                    <label class="ip-doc-label" for="mcp_key">
                                        <?= trans('mcp_key') ?>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" name="mcp_key" id="mcp_key" class="form-control"
                                               readonly value="<?= esc($mcpKey) ?>">
                                        <button id="btn_generate_mcp_key" type="button" class="btn btn-warning"
                                                title="<?= esc(trans('generate'), 'attr') ?>">
                                            <i class="fa fa-solid fa-recycle fa-margin" aria-hidden="true"></i> <?= trans('generate') ?>
                                        </button>
                                    </div>
                                    <p class="ip-field-help"><?= trans('mcp_key_help') ?></p>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="mcp_company_id">
                                        <?= trans('mcp_company') ?>
                                    </label>
                                    <select name="mcp_company_id" id="mcp_company_id" class="form-control simple-select">
                                        <option value="0" <?= check_select($mcpCompanyId, '0') ?>>
                                            <?= trans('mcp_company_default', '', 'Predefinita') ?>
                                        </option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?= (int) $company['company_id'] ?>"
                                                <?= check_select($mcpCompanyId, (string) $company['company_id']) ?>>
                                                <?= esc($company['company_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="ip-doc-section">
                        <div class="ip-doc-section__head">
                            <span class="ip-doc-step">2</span>
                            <h2 class="ip-doc-section__title"><?= trans('mcp_url') ?></h2>
                        </div>
                        <div class="ip-doc-section__body">
                            <div class="ip-doc-grid">
                                <div class="ip-doc-field ip-doc-field--wide">
                                    <label class="ip-doc-label" for="mcp_url">
                                        <?= trans('mcp_url') ?>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" id="mcp_url" class="form-control" readonly
                                               value="<?= esc($mcpUrl) ?>">
                                        <button id="btn_copy_mcp_url" type="button" class="btn btn-secondary"
                                                title="<?= esc(trans('copy', '', 'Copia'), 'attr') ?>">
                                            <i class="fa fa-solid fa-copy" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <p class="ip-field-help"><?= trans('mcp_help') ?></p>
                                </div>
                            </div>

                            <div class="ip-doc-field ip-doc-field--wide mt-2">
                                <label class="ip-doc-label">
                                    <?= trans('mcp_client_config') ?>
                                </label>
                                <p class="ip-field-help"><?= trans('mcp_client_config_help') ?></p>
                                <pre class="ip-mcp-snippet"><code>{
  "mcpServers": {
    "invoiceplane": {
      "url": "<?= esc($mcpUrl) ?>",
      "headers": {
        "Authorization": "Bearer <?= esc($mcpKey ?: 'LA_TUA_CHIAVE') ?>"
      }
    }
  }
}</code></pre>
                            </div>
                        </div>
                    </section>

                </div>

            </div>
        </div>
    </div>

</form>

<script>
    $(function () {
        var tokenName   = '<?= csrf_token() ?>';
        var tokenInput  = document.querySelector('input[name="' + tokenName + '"]');
        var tokenField  = document.getElementById('mcp_key');
        var generateBtn = document.getElementById('btn_generate_mcp_key');
        var copyBtn     = document.getElementById('btn_copy_mcp_url');
        var urlInput    = document.getElementById('mcp_url');

        // Toast volante: riusa il container e lo stile dei flashdata (layout/alerts.php).
        function showToast(message, variant) {
            variant = variant || 'success';
            var container = document.getElementById('ip-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'ip-toast-container';
                container.className = 'ip-toast-container';
                container.setAttribute('aria-live', 'polite');
                container.setAttribute('aria-atomic', 'true');
                document.body.appendChild(container);
            }
            var el = document.createElement('div');
            el.className = 'toast fade ip-toast ip-toast--' + variant;
            el.setAttribute('role', 'alert');
            el.setAttribute('aria-live', 'assertive');
            el.setAttribute('aria-atomic', 'true');
            el.setAttribute('data-bs-delay', '3000');
            el.innerHTML = '<div class="toast-body">'
                + '<i class="fa fa-solid fa-check-circle ip-toast-icon" aria-hidden="true"></i>'
                + '<div class="ip-toast-message">' + message + '</div></div>';
            container.appendChild(el);
            if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
                var t = new bootstrap.Toast(el, {delay: 3000});
                t.show();
                el.addEventListener('hidden.bs.toast', function () { el.remove(); });
            }
        }

        if (!tokenInput || !tokenField || !generateBtn) {
            return;
        }

        generateBtn.addEventListener('click', function () {
            // Azione distruttiva: rigenerare invalida subito i client già configurati.
            if (!window.confirm('<?= esc(trans('mcp_regenerate_confirm'), 'js') ?>')) {
                return;
            }

            var fd = new FormData();
            fd.append(tokenName, tokenInput.value);

            fetch('<?= site_url('mcp/ajax/get_key') ?>', {method: 'POST', body: fd})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.token) {
                        tokenInput.value = data.token;
                    }
                    if (data && data.key) {
                        tokenField.value = data.key;
                        // Aggiorna lo snippet client così resta coerente con la nuova chiave.
                        var snippet = document.querySelector('.ip-mcp-snippet code');
                        if (snippet) {
                            snippet.innerHTML = snippet.innerHTML.replace(
                                /Bearer .*$/m,
                                'Bearer ' + data.key
                            );
                        }
                        showToast('<?= esc(trans('mcp_key'), 'js') ?>: <?= esc(trans('generate'), 'js') ?>', 'success');
                    }
                });
        });

        if (copyBtn && urlInput) {
            copyBtn.addEventListener('click', function () {
                urlInput.select();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(urlInput.value).then(function () {
                        showToast('<?= esc(trans('mcp_copied'), 'js') ?>', 'success');
                    });
                }
            });
        }
    });
</script>

<?= $this->endSection() ?>
