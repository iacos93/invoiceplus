<?php
/**
 * Viewer condiviso FatturaPA/XML.
 * Tiene separati: contesto documento, modalità di lettura, contenuto e
 * metadati tecnici. Usato da fatture emesse e ricevute.
 */
$_p      = (array) ($this->renderVars['options'] ?? []);
$result  = $_p['result']  ?? ($result  ?? []);
$styles  = $_p['styles']  ?? ($styles  ?? []);
$style   = $_p['style']   ?? ($style   ?? '');
$baseUrl = $_p['baseUrl'] ?? ($baseUrl ?? '');
$summary = $_p['summary'] ?? ($summary ?? []);
$rawXml  = $_p['rawXml']  ?? ($rawXml  ?? '');

$hasRaw = trim((string) $rawXml) !== '';
$renderOk = !empty($result['ok']) && !empty($result['html']);
$xmlSize = $hasRaw ? strlen($rawXml) : 0;
$xmlLines = $hasRaw ? substr_count($rawXml, "\n") + 1 : 0;
$format = $summary['formato'] ?? '';
$progressivo = $summary['progressivo'] ?? '';
?>

<div class="ip-xml-viewer" data-xml-viewer>
    <section class="ip-xml-viewer__main">        <header class="ip-xml-toolbar">
            <div class="ip-xml-document">
                <span class="ip-xml-document__icon" aria-hidden="true"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i></span>
                <div class="ip-xml-document__copy">
                    <strong><?= esc($summary['tipo'] ?? '') ?: esc(trans('xml_preview', '', 'Fattura elettronica')) ?></strong>
                    <?php if (!empty($summary['numero']) || !empty($summary['data'])): ?>
                        <span><?php if (!empty($summary['numero'])): ?><span class="ip-xml-num">N. <?= esc($summary['numero']) ?></span><?php endif; ?><?php if (!empty($summary['data'])): ?> <span class="ip-xml-dot" aria-hidden="true">·</span> <span class="ip-xml-date"><?= esc($summary['data']) ?></span><?php endif; ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($hasRaw): ?>
                <div class="ip-xml-toolbar__center">
                    <div class="ip-xml-switch" role="tablist" aria-label="<?= esc(trans('xml_preview_view_mode', '', 'Modalità visualizzazione')) ?>">
                        <button type="button" id="btn-view-rendered" class="ip-xml-switch__btn is-active<?= !$renderOk ? ' has-warning' : '' ?>" role="tab" aria-selected="true" tabindex="0" data-view="rendered" aria-controls="xml-panel-rendered" title="<?= esc($renderOk ? trans('xml_preview_rendered', '', 'Resa') . ' (1)' : ($result['error'] ?? trans('xml_preview_failed', '', 'Anteprima non disponibile')), 'attr') ?>">
                            <i class="fa fa-solid fa-eye" aria-hidden="true"></i><span><?= trans('xml_preview_rendered', '', 'Resa') ?></span>
                            <?php if (!$renderOk): ?><span class="ip-xml-warn-dot" aria-hidden="true"></span><span class="visually-hidden"><?= trans('xml_preview_failed', '', 'Anteprima non disponibile') ?></span><?php endif; ?>
                        </button>
                        <button type="button" id="btn-view-raw" class="ip-xml-switch__btn" role="tab" aria-selected="false" tabindex="-1" data-view="raw" aria-controls="xml-panel-raw" title="<?= esc(trans('xml_preview_raw', '', 'XML grezzo'), 'attr') ?> (2)">
                            <i class="fa fa-solid fa-code" aria-hidden="true"></i><span><?= trans('xml_preview_raw', '', 'XML grezzo') ?></span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="ip-xml-toolbar__actions">
                <?php if ($hasRaw): ?>
                    <a class="btn btn-sm btn-primary ip-xml-download" href="<?= esc($baseUrl) ?>?download=1" aria-label="<?= esc(trans('download_xml', '', 'Scarica XML'), 'attr') ?>" title="<?= esc(trans('download_xml', '', 'Scarica XML'), 'attr') ?> (⌘S)">
                        <i class="fa fa-solid fa-download" aria-hidden="true"></i><span><?= trans('download', '', 'Scarica') ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <div class="ip-xml-panels">
            <section id="xml-panel-rendered" class="ip-xml-panel" role="tabpanel" aria-labelledby="btn-view-rendered" tabindex="0">
                <?php if ($renderOk): ?>
                    <?php /* L'HTML lo produce un foglio di stile XSLT di terze parti a partire
                             da un XML che puo' arrivare da un fornitore: niente allow-scripts,
                             quindi il contenuto non puo' eseguire codice ne' navigare fuori
                             dall'iframe. allow-same-origin serve solo a leggere l'altezza del
                             documento reso (iframe.contentDocument, vedi script sotto) per
                             adattare l'iframe al contenuto invece di ritagliarlo a un'altezza
                             fissa. */ ?>
                    <iframe class="ip-xml-frame" sandbox="allow-same-origin" title="<?= esc(trans('xml_preview', '', 'Anteprima fattura elettronica'), 'attr') ?>" srcdoc="<?= esc($result['html'], 'attr') ?>"></iframe>
                <?php else: ?>
                    <div class="ip-xml-empty">
                        <div class="ip-xml-empty__icon" aria-hidden="true"><i class="fa fa-regular fa-file-code" aria-hidden="true"></i></div>
                        <h2><?= trans('xml_preview_failed', '', 'Anteprima non disponibile') ?></h2>
                        <p><?= esc($result['error'] ?? 'Il documento non può essere trasformato con il foglio di stile selezionato.') ?></p>
                    </div>
                <?php endif; ?>
            </section>
            <?php if ($hasRaw): ?>
                <section id="xml-panel-raw" class="ip-xml-panel" role="tabpanel" aria-labelledby="btn-view-raw" tabindex="0" hidden>
                    <div class="ip-xml-rawbar">
                        <div><strong><?= trans('xml_preview_raw', '', 'XML grezzo') ?></strong><span><?= number_format($xmlSize) ?> byte · <?= $xmlLines ?> <?= trans('xml_preview_lines', '', 'righe') ?></span></div>
                    </div>
                    <pre class="ip-xml-code" tabindex="0"><code><?= esc($rawXml) ?></code></pre>
                </section>
            <?php endif; ?>
        </div>
    </section>

    <aside class="ip-xml-sidebar">
        <?php if (!empty($summary)): ?>
            <section class="ip-xml-card">
                <header><?= trans('xml_preview_summary', '', 'Dati documento') ?></header>
                <div class="ip-xml-card__body">
                    <?php if (!empty($summary['cedente'])): ?>
                        <div class="ip-xml-field ip-xml-field--stacked"><span><?= trans('issuer', '', 'Emittente') ?></span><strong><?= esc($summary['cedente']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($summary['cessionario'])): ?>
                        <div class="ip-xml-field ip-xml-field--stacked"><span><?= trans('recipient', '', 'Destinatario') ?></span><strong><?= esc($summary['cessionario']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($summary['imponibile']) || !empty($summary['imposta'])): ?>
                        <div class="ip-xml-subtotals">
                            <?php if (!empty($summary['imponibile'])): ?><div class="ip-xml-field"><span><?= trans('taxable', '', 'Imponibile') ?></span><strong><?= esc($summary['imponibile']) ?> €</strong></div><?php endif; ?>
                            <?php if (!empty($summary['imposta'])): ?><div class="ip-xml-field"><span><?= trans('vat', '', 'IVA') ?></span><strong><?= esc($summary['imposta']) ?> €</strong></div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($summary['totale'])): ?>
                        <div class="ip-xml-total">
                            <span><?= trans('total', '', 'Totale') ?></span>
                            <strong><?= esc($summary['totale']) ?> €</strong>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($format || $progressivo): ?>
                    <div class="ip-xml-card__meta">
                        <?php if ($format): ?><span><?= esc($format) ?></span><?php endif; ?>
                        <?php if ($format && $progressivo): ?><span class="ip-xml-dot" aria-hidden="true">·</span><?php endif; ?>
                        <?php if ($progressivo): ?><span class="ip-xml-mono"><?= esc($progressivo) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="ip-xml-card">
            <header><?= trans('xml_preview_style', '', 'Foglio di stile') ?></header>
            <div class="ip-xml-card__body">
                <div class="ip-xml-styles">
                    <?php foreach ($styles as $key => $item): ?>
                        <?php $active = $key === $style; ?>
                        <a class="ip-xml-style<?= $active ? ' is-active' : '' ?><?= !$item['installed'] ? ' is-missing' : '' ?>"
                           href="<?= esc($baseUrl . '?style=' . $key) ?>"
                           title="<?= esc($item['desc'] ?? ($item['installed'] ? 'Installato' : 'Non installato'), 'attr') ?>"
                           aria-current="<?= $active ? 'true' : 'false' ?>"
                           aria-label="<?= esc($item['label'] . ($item['installed'] ? ($active ? ' - Selezionato' : '') : ' - Non installato'), 'attr') ?>">
                            <span class="ip-xml-style__name">
                                <?= esc($item['label']) ?>
                                <?php if (!empty($item['badge'])): ?><span class="ip-xml-badge"><?= esc($item['badge']) ?></span><?php endif; ?>
                            </span>
                            <span class="ip-xml-style__state">
                                <?php if ($active): ?>
                                    <i class="fa fa-solid fa-check-circle" aria-hidden="true"></i>
                                <?php elseif ($item['installed']): ?>
                                    <i class="fa fa-solid fa-angle-right" aria-hidden="true"></i>
                                <?php else: ?>
                                    <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php $missing = array_filter($styles, static fn($item) => !$item['installed']); ?>
                <?php if ($missing !== []): ?>
                    <div class="ip-xml-help"><i class="fa fa-solid fa-info-circle" aria-hidden="true"></i><span><?= trans('xml_preview_install_help', '', 'Per aggiungere un foglio, installa il file XSL nella cartella') ?> <code>app/Libraries/fatturapa/xsl/</code>.</span></div>
                <?php endif; ?>
            </div>
        </section>
    </aside>
</div>

<script>
(function () {
    const root = document.querySelector('[data-xml-viewer]');
    if (!root) return;
    const panels = root.querySelectorAll('.ip-xml-panel');
    const switchEl = root.querySelector('.ip-xml-switch');
    const iframe = root.querySelector('.ip-xml-frame');
    let current = 'rendered';

    function highlight() {
        const code = root.querySelector('.ip-xml-code code');
        if (!code || code.dataset.highlighted) return;
        const escaped = code.textContent.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        code.innerHTML = escaped
            .replace(/(&lt;!--[\s\S]*?--&gt;)/g, '<span class="xml-comment">$1</span>')
            .replace(/(&lt;\?xml[\s\S]*?\?&gt;)/gi, '<span class="xml-prolog">$1</span>')
            .replace(/(&lt;\/?[A-Za-z_:][^&]*?)(\s+[A-Za-z_:][\w:.-]*)(=)(&quot;[^&]*?&quot;)/g, '<span class="xml-tag">$1</span><span class="xml-attr">$2</span>$3<span class="xml-value">$4</span>')
            .replace(/(&lt;\/?[A-Za-z_:][\w:.-]*)(?=[\s&gt;])/g, '<span class="xml-tag">$1</span>');
        code.dataset.highlighted = '1';
    }

    function show(view) {
        current = view;
        panels.forEach(panel => { panel.hidden = panel.id !== 'xml-panel-' + view; });
        if (switchEl) {
            const tabs = switchEl.querySelectorAll('[data-view]');
            tabs.forEach(btn => {
                const active = btn.dataset.view === view;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
                btn.setAttribute('tabindex', active ? '0' : '-1');
            });
        }
        if (view === 'raw') highlight();
    }

    if (switchEl) {
        switchEl.addEventListener('click', event => {
            const btn = event.target.closest('[data-view]');
            if (btn) show(btn.dataset.view);
        });

        // Navigazione da tastiera dei tab WAI-ARIA (Freccia sinistra / Freccia destra)
        switchEl.addEventListener('keydown', event => {
            const tabs = Array.from(switchEl.querySelectorAll('[data-view]'));
            const idx = tabs.indexOf(document.activeElement);
            if (idx === -1) return;

            let nextIdx = null;
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                nextIdx = (idx + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                nextIdx = (idx - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                nextIdx = 0;
            } else if (event.key === 'End') {
                nextIdx = tabs.length - 1;
            }

            if (nextIdx !== null) {
                event.preventDefault();
                tabs[nextIdx].focus();
                show(tabs[nextIdx].dataset.view);
            }
        });
    }

    if (iframe) {
        let isFitting = false;
        const fit = () => {
            if (isFitting) return;
            isFitting = true;
            try {
                const doc = iframe.contentDocument || iframe.contentWindow?.document;
                if (!doc || !doc.body) { isFitting = false; return; }
                const container = doc.getElementById('fattura-container')
                    || doc.getElementById('fattura-elettronica')
                    || doc.body;
                const h = Math.max(container.scrollHeight, container.offsetHeight, doc.documentElement?.scrollHeight || 0);
                if (h > 50) {
                    iframe.style.height = (h + 24) + 'px';
                }
            } catch (error) { /* documento sandboxed: contentDocument puo' non essere leggibile, si rinuncia silenziosamente */ }
            finally {
                isFitting = false;
            }
        };
        iframe.addEventListener('load', () => {
            fit();
            setTimeout(fit, 100);
            setTimeout(fit, 300);
        });
        window.addEventListener('resize', fit, { passive: true });
    }

    document.addEventListener('keydown', event => {
        const typing = /INPUT|TEXTAREA|SELECT/.test(event.target?.tagName || '');
        if (!typing && event.key === '1') show('rendered');
        if (!typing && event.key === '2' && switchEl) show('raw');
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            const download = root.querySelector('.ip-xml-toolbar__actions a');
            if (download) { event.preventDefault(); download.click(); }
        }
    });

    show('rendered');
})();
</script>
