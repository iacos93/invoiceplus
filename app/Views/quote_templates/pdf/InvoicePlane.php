<?php
/**
 * Preventivo — stesso impianto visivo delle fatture (partial_document.php),
 * cosi' un documento della stessa azienda ha la stessa faccia. Lo stile viene
 * da layout/pdf_styles e i blocchi anagrafici da layout/pdf_party: qui si
 * scelgono solo i valori del preventivo.
 *
 * Rispetto alla fattura non ci sono saldo e incasso, ma c'e' la scadenza del
 * preventivo e l'elenco delle proprie imposte (quote_tax_rates). Riceve dal
 * controller: $quote, $items, $quote_tax_rates, $client, $user, $amounts,
 * $show_item_discounts.
 */
$cols = $show_item_discounts ? 5 : 4;

// Identita' di chi emette: la ragione sociale dell'azienda del documento, non
// il nome dell'account (stessa logica di partial_document.php).
$company_name = trim((string) ($user['emitter_name'] ?? ''));
$person_name  = trim((string) ($user['user_name'] ?? ''));

$cityLine = static function ($city, $zip, $state): string {
    // Ordine italiano: CAP, citta', provincia.
    return trim(implode(' ', array_filter([$zip, $city, $state])));
};

$clientName = trim(($client['client_name'] ?? '') . ' ' . ($client['client_surname'] ?? ''));

// Tinta del numero/preventivo coerente con la fattura (pdf_styles accent):
// lo stato positivo (approvato) verde, quello negativo (rifiutato/annullato)
// rosso, il resto neutro. La fattura fa altrettanto con pagata/scaduta.
$accent = 'neutral';
$quoteStatusId = (int) ($quote['quote_status_id'] ?? 0);
if ($quoteStatusId === 4) {
    $accent = 'paid';
} elseif ($quoteStatusId === 5 || $quoteStatusId === 6) {
    $accent = 'overdue';
}
?>
<!DOCTYPE html>
<html lang="<?= trans('cldr') ?>">
<head>
    <meta charset="utf-8">
    <title><?= trans('quote') ?> <?= htmlsc((string) ($quote['quote_number'] ?? '')) ?></title>
    <?php /* debug => false: senza, in sviluppo la toolbar di debug avvolge il
             foglio in <!-- DEBUG-VIEW ... --> e, dentro un <style>, quel testo
             non è un commento CSS ma un preludio di selettore che si mangia
             la regola @page (margini 1,2 cm invece di 15 mm). */ ?>
    <style><?= $this->include('layout/pdf_styles', ['accent' => $accent, 'debug' => false]) ?></style>
</head>
<body>

<table class="layout doc-head">
    <tr>
        <td class="emitter">
            <div class="logo"><?= invoice_logo_pdf() ?></div>
            <?= $this->include('layout/pdf_party', [
                'name'    => $company_name !== '' ? $company_name : $person_name,
                'subname' => $company_name !== '' ? $person_name : '',
                'ids'     => [
                    !empty($user['emitter_vat_id']) ? trans('vat_id_short') . ': ' . $user['emitter_vat_id'] : '',
                    !empty($user['emitter_tax_code']) ? trans('tax_code_short') . ': ' . $user['emitter_tax_code'] : '',
                ],
                'lines'   => [
                    $user['emitter_address_1'] ?? '',
                    $user['user_address_2'] ?? '',
                    $cityLine($user['emitter_city'] ?? '', $user['emitter_zip'] ?? '', $user['emitter_state'] ?? ''),
                    !empty($user['emitter_country']) ? get_country_name(trans('cldr'), $user['emitter_country']) : '',
                ],
                'contacts' => [
                    !empty($user['emitter_phone']) ? trans('phone_abbr') . ': ' . $user['emitter_phone'] : '',
                    !empty($user['user_fax']) ? trans('fax_abbr') . ': ' . $user['user_fax'] : '',
                ],
            ]) ?>
        </td>
        <td class="recipient">
            <?= $this->include('layout/pdf_party', [
                'label'   => trans('quote_to'),
                'boxed'   => true,
                'name'    => $clientName,
                'ids'     => [
                    !empty($client['client_vat_id']) ? trans('vat_id_short') . ': ' . $client['client_vat_id'] : '',
                    !empty($client['client_tax_code']) ? trans('tax_code_short') . ': ' . $client['client_tax_code'] : '',
                ],
                'lines'   => [
                    $client['client_address_1'] ?? '',
                    $client['client_address_2'] ?? '',
                    $cityLine($client['client_city'] ?? '', $client['client_zip'] ?? '', $client['client_state'] ?? ''),
                    !empty($client['client_country']) ? get_country_name(trans('cldr'), $client['client_country']) : '',
                ],
                'contacts' => [
                    !empty($client['client_phone']) ? trans('phone_abbr') . ': ' . $client['client_phone'] : '',
                ],
            ]) ?>
        </td>
    </tr>
</table>

<h1 class="doc-title">
    <?= trans('quote') ?>
    <span class="doc-number"><?= htmlsc((string) ($quote['quote_number'] ?? '')) ?></span>
</h1>

<table class="layout doc-meta">
    <tr>
        <td><div class="k"><?= trans('quote_date', '', 'Data') ?></div></td>
        <td><div class="k"><?= trans('expires', '', 'Scadenza') ?></div></td>
        <?php /* Larghezza esplicita: con tre celle la tabella a larghezze fisse
                 distribuiva il 25% avanzato e la seconda colonna finiva a 33%,
                 mentre nella fattura (quattro celle) sta a 25%. */ ?>
        <td class="text-end doc-meta-amount"><div class="k"><?= trans('total') ?></div></td>
    </tr>
    <tr>
        <td><div class="v"><?= date_from_mysql($quote['quote_date_created'] ?? '', true) ?></div></td>
        <td><div class="v"><?= date_from_mysql($quote['quote_date_expires'] ?? '', true) ?></div></td>
        <td class="text-end doc-meta-amount"><div class="v is-accent"><?= format_currency($amounts['quote_total'] ?? 0) ?></div></td>
    </tr>
</table>

<?php
/* Codice della riga: quello scritto sulla riga (codice MEPA o articolo
   concordato) se c'è, altrimenti il codice del prodotto in catalogo. La
   colonna compare solo se almeno una riga ha un codice. */
/* Le righe arrivano da DocumentRow, che risponde sia a $item['campo'] sia a
   $item->campo: qui serve la sintassi array, quindi nessun tipo stretto. */
$itemCode = static function ($item): string {
    $code = trim((string) ($item['item_code'] ?? ''));

    return $code !== '' ? $code : trim((string) ($item['product_sku'] ?? ''));
};

$has_item_code = array_filter(array_map($itemCode, $items)) !== [];
?>
<table class="items items-<?= $cols ?><?= $has_item_code ? ' has-code' : '' ?>">
    <thead>
    <tr>
        <?php if ($has_item_code): ?>
            <th scope="col" class="c-code"><?= trans('item_code', '', 'Codice') ?></th>
        <?php endif; ?>
        <th scope="col" class="c-desc"><?= trans('item', '', 'Articolo') ?> / <?= trans('description', '', 'Descrizione') ?></th>
        <th scope="col" class="c-qty num"><?= trans('qty') ?></th>
        <th scope="col" class="c-price num"><?= trans('price') ?></th>
        <?php if ($show_item_discounts): ?>
            <th scope="col" class="c-discount num"><?= trans('discount') ?></th>
        <?php endif; ?>
        <th scope="col" class="c-total num"><?= trans('total') ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <?php if ($has_item_code): ?>
                <td class="item-code"><?= htmlsc($itemCode($item)) ?></td>
            <?php endif; ?>
            <td>
                <div class="item-name"><?= htmlsc($item['item_name'] ?? '') ?></div>
                <?php if (trim((string) ($item['item_description'] ?? '')) !== ''): ?>
                    <div class="item-desc"><?= nl2br(htmlsc((string) ($item['item_description'] ?? ''))) ?></div>
                <?php endif; ?>
            </td>
            <td class="num">
                <?= format_amount($item['item_quantity'] ?? 0) ?>
                <?php if (!empty($item['item_product_unit'])): ?>
                    <span class="item-unit"><?= htmlsc($item['item_product_unit']) ?></span>
                <?php endif; ?>
            </td>
            <td class="num"><?= format_currency($item['item_price'] ?? 0) ?></td>
            <?php if ($show_item_discounts): ?>
                <td class="num"><?= format_currency($item['item_discount'] ?? 0) ?></td>
            <?php endif; ?>
            <td class="num"><?= format_currency($item['item_total'] ?? 0) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php /* Cella sinistra vuota come nella fattura: senza, i totali occupavano
         tutta la larghezza e l'etichetta finiva sul bordo opposto alla cifra,
         mentre la fattura li tiene in un blocco stretto a destra. */ ?>
<table class="layout doc-close no-aside">
    <tr>
        <td class="aside"></td>
        <td class="sums">
            <table class="sums">
                <tr>
                    <td class="k"><?= trans('subtotal') ?></td>
                    <td class="v"><?= format_currency($amounts['quote_item_subtotal'] ?? 0) ?></td>
                </tr>

                <?php if ((float) ($amounts['quote_item_tax_total'] ?? 0) > 0): ?>
                    <tr>
                        <td class="k"><?= trans('item_tax') ?></td>
                        <td class="v"><?= format_currency($amounts['quote_item_tax_total']) ?></td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($quote_tax_rates as $rate): ?>
                    <tr>
                        <td class="k"><?= htmlsc($rate['quote_tax_rate_name'] ?? '') ?>
                            (<?= format_amount($rate['quote_tax_rate_percent'] ?? 0) ?>%)</td>
                        <td class="v"><?= format_currency($rate['quote_tax_rate_amount'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if ((float) ($quote['quote_discount_percent'] ?? 0) != 0.00): ?>
                    <tr>
                        <td class="k"><?= trans('discount') ?></td>
                        <td class="v">&minus;<?= format_amount($quote['quote_discount_percent']) ?>%</td>
                    </tr>
                <?php endif; ?>
                <?php if ((float) ($quote['quote_discount_amount'] ?? 0) != 0.00): ?>
                    <tr>
                        <td class="k"><?= trans('discount') ?></td>
                        <td class="v">&minus;<?= format_currency($quote['quote_discount_amount']) ?></td>
                    </tr>
                <?php endif; ?>

                <tr class="is-total">
                    <td class="k"><?= trans('total') ?></td>
                    <td class="v"><?= format_currency($amounts['quote_total'] ?? 0) ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<?php if (!empty($quote['notes'])): ?>
    <div class="block">
        <div class="block-title"><?= trans('notes') ?></div>
        <div class="block-body"><?= nl2br(htmlsc($quote['notes'])) ?></div>
    </div>
<?php endif; ?>

<div class="doc-footer">
    <?php $docFooter = trim((string) get_setting('pdf_quote_footer')); ?>
    <?php if ($docFooter !== ''): ?>
        <div class="doc-footer-note"><?= $docFooter ?></div>
    <?php endif; ?>
    <table class="layout">
        <tr>
            <td><?= invoice_logo_footer_pdf() ?></td>
            <td class="text-end"><span class="pages">%%PAGINE%%</span></td>
        </tr>
    </table>
</div>

</body>
</html>
