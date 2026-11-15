<?php
/**
 * Campo della chiave MCP, in sola lettura.
 *
 * Il valore memorizzato (cifrato, "enc2:...") serve soltanto a scegliere il
 * testo di aiuto: non viene mai stampato nell'HTML, né in attributi né in
 * variabili JavaScript. La chiave in chiaro arriva solo per ajax — quando la
 * si genera (mcp/ajax/get_key) o quando la si copia (mcp/ajax/reveal_key) —
 * così aprire la pagina, o guardare un backup del suo sorgente, non basta a
 * leggerla.
 *
 */
// Lo stato si calcola qui invece di farselo passare dalla pagina: il parziale
// è l'unico posto in cui il valore serve, e passarlo voleva dire due verità
// possibili (badge "attivo", campo "nessuna chiave configurata"). Conta la
// chiave effettivamente in uso, non solo quella nel database: con mcp.key in
// .env l'integrazione è attiva.
$mcpKeySet = mcp_key_configured();
?>
<div class="ip-doc-field ip-doc-field--wide">
    <label class="ip-doc-label" for="mcp_key">
        <?= trans('mcp_key') ?>
    </label>
    <?php /* Stessa coppia campo + pulsanti delle Impostazioni (chiave cron, URL
             del feed): ip-secret-group dà ai pulsanti il corpo del campo e lo
             stile secondario, il valore in monospazio perché è una stringa da
             copiare. «Genera» non è più un blocco d'avviso arancione: qui
             l'azione della pagina è «Salva», e un pulsante acceso accanto a un
             campo vuoto lo faceva sembrare l'unica cosa da fare. */ ?>
    <div class="input-group ip-secret-group">
        <input type="text" name="mcp_key" id="mcp_key" class="form-control"
               value="" readonly autocomplete="off" spellcheck="false"
               placeholder="<?= esc(mcp_key_placeholder($mcpKeySet), 'attr') ?>">
        <button id="btn_copy_mcp_key" type="button" class="btn btn-secondary ip-secret-copy"
                title="<?= esc(trans('copy', '', 'Copia'), 'attr') ?>"
                aria-label="<?= esc(trans('copy', '', 'Copia'), 'attr') ?>">
            <i class="fa fa-solid fa-copy" aria-hidden="true"></i>
        </button>
        <button id="btn_generate_mcp_key" type="button" class="btn btn-secondary">
            <i class="fa fa-solid fa-rotate" aria-hidden="true"></i> <?= trans('generate') ?>
        </button>
    </div>
    <p class="ip-field-help"><?= trans('mcp_key_help') ?></p>
</div>
