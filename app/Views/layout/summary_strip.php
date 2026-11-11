<?php
/**
 * Partial: striscia di riepilogo in testa a una schermata (conteggi e totali
 * del perimetro mostrato sotto).
 *
 * Nasce per unificare tre trattamenti diversi della stessa idea: le card KPI
 * della bacheca, la riga di testo muto in alto a destra nelle liste documenti
 * e la striscia dello scadenzario. Le card KPI restano alla bacheca — lì i
 * numeri SONO il contenuto della pagina; ovunque siano invece l'intestazione
 * di un elenco si usa questa striscia, che è la forma più leggibile delle tre
 * (etichetta piccola sopra, cifra grande sotto) ed era già il componente più
 * usato: .ip-summary esisteva per progetti e attività, le altre schermate se
 * n'erano fatte una copia a testa.
 *
 * Parametri attesi:
 *   $items array  elenco di ['label' => string, 'value' => string,
 *                            'tone' => 'default'|'danger'|'warning'|'success',
 *                            'hint' => string]
 *                 Le voci con value === null vengono saltate, così il
 *                 chiamante può comporre l'elenco senza if annidati.
 *                 `hint` è il testo del tooltip sull'etichetta, per i numeri
 *                 che non si spiegano da soli (es. "Quota primi 3").
 */
$_ss   = (array) ($this->renderVars['options'] ?? []);
$items = $_ss['items'] ?? ($items ?? []);

$items = array_values(array_filter($items, static fn ($i) => ($i['value'] ?? null) !== null));

if ($items === []) {
    return;
}
?>
<div class="ip-summary">
    <?php foreach ($items as $item): ?>
        <?php $tone = $item['tone'] ?? 'default'; ?>
        <span class="ip-summary-item">
            <span class="ip-summary-label"<?= !empty($item['hint']) ? ' title="' . esc($item['hint'], 'attr') . '"' : '' ?>><?= esc($item['label']) ?></span>
            <span class="ip-summary-value<?= $tone !== 'default' ? ' is-' . esc($tone, 'attr') : '' ?>">
                <?= esc($item['value']) ?>
            </span>
        </span>
    <?php endforeach; ?>
</div>
