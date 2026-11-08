<?php
/**
 * Partial: riga di stato vuoto di una tabella.
 *
 * Prima ogni lista se la scriveva da sola: chi usava <tr class="empty-row">
 * con icona e pulsante (clienti, fornitori), chi un <td colspan> con una frase
 * e basta (DDT, F24, prodotti). Nella stessa applicazione il vuoto aveva
 * quindi due aspetti, e quelli senza pulsante costringevano a cercare la voce
 * di menu per creare il primo documento.
 *
 * Va incluso dentro <tbody>: emette una <tr> completa.
 *
 * Parametri:
 *   colspan   int     numero di colonne della tabella (obbligatorio)
 *   message   string  testo mostrato
 *   icon      string  classe Font Awesome già presente nel subset
 *   cta_url   string  destinazione del pulsante (opzionale)
 *   cta_label string  etichetta del pulsante (opzionale, richiede cta_url)
 *   cta_icon  string  icona del pulsante (default: fa-solid fa-plus)
 *
 * La classe .empty-row non è decorativa: il CSS nasconde l'intestazione della
 * tabella quando l'unica riga è questa, e la ricerca live la riaccende quando
 * si svuota il filtro.
 */
$_er = (array) ($this->renderVars['options'] ?? []);

$erColspan  = max(1, (int) ($_er['colspan'] ?? 1));
$erMessage  = (string) ($_er['message'] ?? '');
$erIcon     = (string) ($_er['icon'] ?? 'fa-solid fa-info-circle');
$erCtaUrl   = (string) ($_er['cta_url'] ?? '');
$erCtaLabel = (string) ($_er['cta_label'] ?? '');
$erCtaIcon  = (string) ($_er['cta_icon'] ?? 'fa-solid fa-plus');
?>
<tr class="empty-row">
    <td colspan="<?= $erColspan ?>">
        <i class="fa <?= esc($erIcon, 'attr') ?> empty-icon" aria-hidden="true"></i>
        <?= esc($erMessage) ?>
        <?php if ($erCtaUrl !== '' && $erCtaLabel !== ''): ?>
            <a href="<?= esc($erCtaUrl, 'attr') ?>" class="empty-cta">
                <i class="fa <?= esc($erCtaIcon, 'attr') ?>" aria-hidden="true"></i> <?= esc($erCtaLabel) ?>
            </a>
        <?php endif; ?>
    </td>
</tr>
