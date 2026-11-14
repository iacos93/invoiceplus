<?php
/**
 * Campo della chiave MCP, in sola lettura e write-only.
 *
 * Il valore memorizzato (cifrato, "enc2:...") serve soltanto a scegliere il
 * testo di aiuto: non viene mai stampato nell'HTML, né in attributi né in
 * variabili JavaScript. La chiave in chiaro esiste esclusivamente nella
 * risposta ajax che la genera, così chi apre la pagina non può leggerla da
 * un backup o dal sorgente della pagina.
 *
 * @var string $mcpKeyStored valore letto da get_setting('mcp_key'), mai mostrato
 */
$mcpKeyStored = (string) ($mcpKeyStored ?? '');
$mcpKeySet    = $mcpKeyStored !== '';
?>
<div class="ip-doc-field ip-doc-field--wide">
    <label class="ip-doc-label" for="mcp_key">
        <?= trans('mcp_key') ?>
    </label>
    <div class="input-group">
        <input type="text" name="mcp_key" id="mcp_key" class="form-control"
               value="" readonly autocomplete="off" spellcheck="false"
               placeholder="<?= $mcpKeySet
                   ? esc(trans('mcp_key_placeholder', '', 'La chiave non viene più mostrata: rigenerala per vederne una nuova'), 'attr')
                   : esc(trans('mcp_key_placeholder_empty', '', 'Nessuna chiave configurata: generala per attivare l\'integrazione'), 'attr') ?>">
        <button id="btn_copy_mcp_key" type="button" class="btn btn-secondary"
                title="<?= esc(trans('copy', '', 'Copia'), 'attr') ?>">
            <i class="fa fa-solid fa-copy" aria-hidden="true"></i>
        </button>
        <button id="btn_generate_mcp_key" type="button" class="btn btn-warning"
                title="<?= esc(trans('generate'), 'attr') ?>">
            <i class="fa fa-solid fa-recycle fa-margin" aria-hidden="true"></i> <?= trans('generate') ?>
        </button>
    </div>
    <p class="ip-field-help"><?= trans('mcp_key_help') ?></p>
</div>
