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

// La chiave è write-only: il valore memorizzato è il ciphertext e serve solo
// a sapere se l'integrazione è configurata, non va mai stampato. Il campo
// parte vuoto e si riempie solo con la risposta di mcp/ajax/get_key.
$mcpKeyStored = (string) get_setting('mcp_key', '');
$mcpCompanyId = (string) get_setting('mcp_company_id', '0');
$mcpActive    = $mcpKeyStored !== '';
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
                        <span class="badge ip-status text-bg-<?= $mcpActive ? 'success' : 'warning' ?> me-2">
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
                                <?= $this->include('mcp/_key_field', ['mcpKeyStored' => $mcpKeyStored]) ?>

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
        "Authorization": "Bearer LA_TUA_CHIAVE"
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
        var copyKeyBtn  = document.getElementById('btn_copy_mcp_key');
        var copyBtn     = document.getElementById('btn_copy_mcp_url');
        var urlInput    = document.getElementById('mcp_url');

        // Toast volante: riusa il container e lo stile dei flashdata (layout/alerts.php).
        function showToast(message, variant) {
            variant = variant || 'success';
            var icons = {
                success: 'check-circle',
                info: 'circle-info',
                warning: 'circle-exclamation',
                danger: 'triangle-exclamation'
            };
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

            var body = document.createElement('div');
            body.className = 'toast-body';

            var icon = document.createElement('i');
            icon.className = 'fa fa-solid fa-' + (icons[variant] || icons.success) + ' ip-toast-icon';
            icon.setAttribute('aria-hidden', 'true');

            var text = document.createElement('div');
            text.className = 'ip-toast-message';
            // textContent, non innerHTML: i messaggi non devono interpretare
            // markup e la chiave non deve mai passare dal DOM come HTML.
            text.textContent = message;

            body.appendChild(icon);
            body.appendChild(text);
            el.appendChild(body);
            container.appendChild(el);

            if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
                var t = new bootstrap.Toast(el, {delay: 3000});
                t.show();
                el.addEventListener('hidden.bs.toast', function () { el.remove(); });
            }
        }

        // Copia negli appunti con fallback al comando del browser: se l'API
        // Clipboard è negata (http, iframe) il campo è comunque selezionato e
        // document.execCommand('copy') recupera il caso più comune.
        function copyText(value, onSuccess, onFailure) {
            function fallback() {
                var copied = false;
                try {
                    copied = document.execCommand('copy');
                } catch (e) {
                    copied = false;
                }
                if (copied) {
                    onSuccess();
                } else {
                    onFailure();
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(onSuccess, fallback);
                return;
            }
            fallback();
        }

        function copyKeyField() {
            tokenField.select();
            copyText(tokenField.value,
                function () {
                    showToast('<?= esc(trans('mcp_key_copied', '', 'Chiave copiata negli appunti'), 'js') ?>', 'success');
                },
                function () {
                    showToast('<?= esc(trans('mcp_copy_failed', '', 'Copia non riuscita: la chiave è selezionata, premi Ctrl+C'), 'js') ?>', 'warning');
                });
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
                        // Il CSRF è monouso: il token della pagina va rinnovato
                        // anche quando la generazione fallisce.
                        tokenInput.value = data.token;
                    }
                    if (!data || !data.key) {
                        return;
                    }
                    // Il server salva già la chiave cifrata e non la rimanda
                    // nell'HTML: questa è l'unica occasione per vederla in
                    // chiaro. La si mette nel campo, la si seleziona e la si copia.
                    tokenField.value = data.key;
                    tokenField.select();
                    copyKeyField();
                    // Aggiorna lo snippet client così resta coerente con la
                    // nuova chiave. textContent: la chiave non deve mai
                    // passare da innerHTML.
                    var snippet = document.querySelector('.ip-mcp-snippet code');
                    if (snippet) {
                        snippet.textContent = snippet.textContent.replace(
                            /Bearer .*$/m,
                            function () { return 'Bearer ' + data.key; }
                        );
                    }
                });
        });

        if (copyKeyBtn) {
            copyKeyBtn.addEventListener('click', function () {
                if (!tokenField.value) {
                    // La chiave memorizzata è write-only: senza una generazione
                    // in questa pagina non c'è nulla da copiare.
                    showToast('<?= esc(trans('mcp_key_not_generated', '', 'Genera prima una chiave: quella esistente non è più visibile'), 'js') ?>', 'warning');
                    return;
                }
                copyKeyField();
            });
        }

        if (copyBtn && urlInput) {
            copyBtn.addEventListener('click', function () {
                urlInput.select();
                copyText(urlInput.value,
                    function () {
                        showToast('<?= esc(trans('mcp_copied'), 'js') ?>', 'success');
                    },
                    function () {
                        showToast('<?= esc(trans('mcp_url_copy_failed', '', 'Copia non riuscita: l\'endpoint è selezionato, premi Ctrl+C'), 'js') ?>', 'warning');
                    });
            });
        }
    });
</script>

<?= $this->endSection() ?>
