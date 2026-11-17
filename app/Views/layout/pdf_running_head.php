<?php
/**
 * Carta intestata delle pagine successive alla prima (fatture, preventivi, DDT).
 *
 * Su un documento lungo dalla seconda pagina in poi restavano solo le righe e
 * il piè di pagina: un foglio staccato non diceva più di chi fosse. La prima
 * pagina ha già logo ed emittente per esteso, quindi la fascia serve solo
 * dopo.
 *
 * Non è HTML impaginato: dompdf ripete un elemento `position: fixed` su ogni
 * pagina a partire da quella in cui compare, prima compresa, e non c'è modo di
 * saltarla. Qui si lascia un marcatore con i dati e la fascia la disegna
 * pdf_create() sulle sole pagine dopo la prima (vedi pdf_running_head_draw()).
 * Nell'anteprima a schermo il commento non si vede.
 *
 * Parametri:
 *   $document  il documento che si stampa (decide l'azienda e quindi il logo)
 *   $name      ragione sociale (o nome) dell'emittente
 *   $details   righe brevi: partita IVA, indirizzo; le vuote si scartano
 */
$_o       = (array) ($this->renderVars['options'] ?? []);
$document = $_o['document'] ?? ($document ?? null);
$name     = trim((string) ($_o['name'] ?? ($name ?? '')));
$details  = array_values(array_filter(array_map(
    static fn ($v): string => trim((string) $v),
    (array) ($_o['details'] ?? ($details ?? []))
)));

echo pdf_running_head_marker([
    'logo'    => (string) logo_path(document_logo('invoice_logo', $document)),
    'name'    => $name,
    'details' => implode(' · ', $details),
]);

// La stessa fascia per l'anteprima a schermo, dove i fogli li compone
// layout/preview_sheet.php e non dompdf: il blocco è nascosto ovunque tranne
// che dentro un .doc-page dopo il primo, quindi nel PDF non compare (lì la
// disegna pdf_create()) e non si duplica.
$_logoSrc = logo_data_uri(document_logo('invoice_logo', $document));
?>
<div class="doc-running-head">
    <table class="layout doc-running-head__row">
        <tr>
            <td class="doc-running-head__logo"><?php if ($_logoSrc !== ''): ?><img src="<?= $_logoSrc ?>" alt=""><?php endif; ?></td>
            <td class="doc-running-head__text">
                <?php if ($name !== ''): ?><div class="doc-running-head__name"><?= htmlsc($name) ?></div><?php endif; ?>
                <?php if ($details !== []): ?><div class="doc-running-head__details"><?= htmlsc(implode(' · ', $details)) ?></div><?php endif; ?>
            </td>
        </tr>
    </table>
</div>
