<?php
/**
 * Tracciati del segno di Invoice+, da includere dentro un
 * <svg class="ip-brand-mark" viewBox="0 0 24 24">.
 *
 * Il segno compare in cinque punti (barra dell'area admin e del portale,
 * pannello di accesso, due piè di pagina): prima i tracciati erano copiati in
 * ciascuno, e rifare il logo voleva dire ritoccare cinque copie sperando di
 * non dimenticarne una. Qui c'è solo il disegno; l'<svg> resta alla vista,
 * che ne decide classe e dimensione.
 *
 * Il disegno: una piastrella con le righe di una fattura, l'ultima delle
 * quali finisce in un "+" — la voce in più, cioè il "plus" del nome. I colori
 * stanno in custom.css (.ip-mark-*), gli stessi di favicon.svg e logo.svg.
 */
?>
<rect class="ip-mark-tile" x="1" y="1" width="22" height="22" rx="6"/>
<rect class="ip-mark-line" x="5.4" y="5.6" width="13.2" height="2.5" rx="1.25"/>
<rect class="ip-mark-line ip-mark-line--2" x="5.4" y="10.2" width="7.4" height="2.5" rx="1.25"/>
<rect class="ip-mark-line ip-mark-line--3" x="5.4" y="14.8" width="4.2" height="2.5" rx="1.25"/>
<path class="ip-mark-plus" d="M14.5 11.6h2.6v3.1h3.1v2.6h-3.1v3.1h-2.6v-3.1h-3.1v-2.6h3.1Z"/>
