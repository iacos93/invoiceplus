<?php
/**
 * Piè di pagina dei documenti (fatture, preventivi, DDT).
 *
 * Era copiato identico in tre modelli, e ogni ritocco di impaginazione voleva
 * dire farlo tre volte. Parametri:
 *   $footerKey  'pdf_invoice_footer' (fatture e DDT) o 'pdf_quote_footer'
 *   $document   il documento che si stampa (decide l'azienda: logo e testo)
 *
 * Una sola riga, in tre colonne: il segno a sinistra, il testo dell'azienda
 * al centro, il numero di pagina a destra. Prima il testo stava su una riga
 * propria sopra e logo e pagina su un'altra sotto: il piè di pagina costava
 * due righe fisse in ogni foglio, sottratte al corpo del documento, e il
 * testo — centrato — non si allineava con niente. Su una riga sola il fondo
 * pagina è più basso e, con il testo allineato a sinistra come il corpo, si
 * legge come la prosecuzione del documento invece che come un'appendice.
 *
 * Le colonne di logo e pagina prendono solo lo spazio che serve (width 1%): se
 * il logo manca la cella resta vuota e il testo parte dal bordo.
 */
// Come in pdf_styles e pdf_party: i parametri di $this->include() arrivano
// nelle "render options" e non come variabili.
$_o         = (array) ($this->renderVars['options'] ?? []);
$footerKey  = $_o['footerKey'] ?? ($footerKey ?? 'pdf_invoice_footer');
$document   = $_o['document'] ?? ($document ?? null);
$logo = invoice_logo_footer_pdf($document);
$note = trim(document_footer($footerKey, $document));
?>
<div class="doc-footer">
    <table class="layout doc-footer__row">
        <tr>
            <?php if ($logo !== ''): ?>
                <td class="doc-footer__logo"><?= $logo ?></td>
            <?php endif; ?>
            <td class="doc-footer__note"><?= $note ?></td>
            <?php /* Il totale delle pagine non è noto finché dompdf non ha
                     impaginato: pdf_create() fa un primo passaggio per
                     contarle e sostituisce il segnaposto. Il "numero /
                     totale" lo compone il foglio (counter(page) nel CSS).
                     Nell'anteprima a schermo il blocco è nascosto perché
                     lì non c'è paginazione. */ ?>
            <td class="doc-footer__pages"><span class="pages">%%PAGINE%%</span></td>
        </tr>
    </table>
</div>
