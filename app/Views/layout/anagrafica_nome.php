<?php
/**
 * Partial: cella "nome" di un elenco di anagrafiche (clienti e fornitori).
 *
 * Le due liste mostravano lo stesso dato in due modi: i fornitori con la
 * iniziale in un cerchio e il timbro "Non attivo" accanto al nome, i clienti
 * con il nome nudo e una colonna "Attivo" a sé con Sì/No. La stessa
 * informazione — chi è attivo e chi no — aveva quindi due letture, e una delle
 * due occupava una colonna intera. Qui il nome è uno solo: iniziale, link e,
 * quando serve, i timbri.
 *
 * Emette una <td>: va inclusa dentro una <tr> già aperta, una volta per riga.
 *
 * Parametri:
 *   label   string  intestazione della colonna, per il data-label delle schede
 *                   su mobile (obbligatorio: senza, la card mobile perde il
 *                   nome del campo)
 *   url     string  destinazione del link (scheda dell'anagrafica)
 *   name    string  nome mostrato
 *   active  bool    false aggiunge il timbro "Non attivo"
 *   meta    string  HTML già sicuro da inserire dopo il nome (es. il timbro
 *                   del tipo cliente): passarlo grezzo è voluto, arriva da
 *                   client_type_badge() che fa escaping dei suoi testi
 *   cell    string  ruolo della cella nella scheda mobile (data-cell). Con
 *                   "number" il nome finisce in alto a sinistra, come il
 *                   numero di un documento: senza, su telefono il nome era
 *                   una riga etichettata come tutte le altre
 */
$_an = (array) ($this->renderVars['options'] ?? []);

$anLabel  = (string) ($_an['label'] ?? '');
$anUrl    = (string) ($_an['url'] ?? '');
$anName   = (string) ($_an['name'] ?? '');
$anActive = (bool) ($_an['active'] ?? true);
$anMeta   = (string) ($_an['meta'] ?? '');
$anCell   = (string) ($_an['cell'] ?? '');
?>
<td<?= $anCell !== '' ? ' data-cell="' . esc($anCell, 'attr') . '"' : '' ?> data-label="<?= esc($anLabel, 'attr') ?>">
    <a href="<?= esc($anUrl, 'attr') ?>">
        <span class="ip-avatar-sm" aria-hidden="true"><?= esc(mb_strtoupper(mb_substr($anName, 0, 1))) ?></span><?= esc($anName) ?>
    </a>
    <?php if (!$anActive): ?>
        <span class="badge ip-status text-bg-secondary"><?= trans('inactive', '', 'Non attivo') ?></span>
    <?php endif; ?>
    <?php if ($anMeta !== ''): ?>
        <?php // Il tipo va su una riga sua, sotto il nome: accodato sulla stessa
              // riga si leggeva come parte della ragione sociale ("Anna Di Capua
              // Persona fisica"), e il nome perdeva il suo confine. ?>
        <div class="ip-anagrafica-meta"><?= $anMeta ?></div>
    <?php endif; ?>
</td>
