<?php
/**
 * Blocco anagrafico di un documento PDF (chi emette / a chi e' intestato).
 *
 * Nei template questo blocco era una catena di venti `if` ripetuta quattro
 * volte — due per file, per emittente e destinatario — e ogni copia aveva
 * derive sue: il preventivo stampava il codice ISO del paese invece del nome,
 * la fattura pagata aveva perso la distinzione fra ragione sociale e persona.
 * Qui la forma e' una sola e i template passano solo i valori.
 *
 * Uso:
 *   <?= $this->include('layout/pdf_party', [
 *       'label'   => trans('bill_to'),
 *       'name'    => 'Aurora Bianchi',
 *       'subname' => '',                       // opzionale: persona sotto la ragione sociale
 *       'ids'     => ['P.IVA: 01234567890'],   // identificativi fiscali
 *       'lines'   => ['Via Roma 1', '20100 Milano MI', 'Italia'],
 *       'contacts'=> ['Tel: 02 1234567'],
 *       'boxed'   => true,                     // riquadro (destinatario)
 *   ]) ?>
 */
$_pp      = (array) ($this->renderVars['options'] ?? []);
$label    = (string) ($_pp['label']   ?? ($label   ?? ''));
$name     = (string) ($_pp['name']    ?? ($name    ?? ''));
$subname  = (string) ($_pp['subname'] ?? ($subname ?? ''));
$ids      = array_filter((array) ($_pp['ids']      ?? ($ids      ?? [])));
$lines    = array_filter((array) ($_pp['lines']    ?? ($lines    ?? [])));
$contacts = array_filter((array) ($_pp['contacts'] ?? ($contacts ?? [])));
$boxed    = (bool) ($_pp['boxed'] ?? ($boxed ?? false));
?>
<?php if ($label !== ''): ?>
    <div class="party-label"><?= htmlsc($label) ?></div>
<?php endif; ?>
<div class="<?= $boxed ? 'party-box' : '' ?>">
    <div class="party-name"><?= htmlsc($name) ?></div>
    <?php if ($subname !== '' && $subname !== $name): ?>
        <div class="party-line"><?= htmlsc($subname) ?></div>
    <?php endif; ?>
    <?php foreach ($ids as $id): ?>
        <div class="party-ids"><?= htmlsc($id) ?></div>
    <?php endforeach; ?>
    <?php foreach ($lines as $line): ?>
        <div class="party-line"><?= htmlsc($line) ?></div>
    <?php endforeach; ?>
    <?php foreach ($contacts as $contact): ?>
        <div class="party-ids"><?= htmlsc($contact) ?></div>
    <?php endforeach; ?>
</div>
