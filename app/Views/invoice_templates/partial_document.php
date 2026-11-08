<?php
/**
 * Corpo del documento fattura, condiviso dai tre template.
 *
 * default / paid / overdue erano tre file quasi identici, e "quasi" era il
 * problema: le versioni pagata e scaduta si erano fermate a una revisione
 * precedente e avevano perso gli estremi di pagamento — proprio la fattura
 * scaduta, quella che si rimanda al cliente per farsi pagare, usciva senza
 * IBAN. Ora il contenuto e' qui e i tre file scelgono solo lo stato.
 *
 * Riceve dai template: $accent ('neutral' | 'paid' | 'overdue') e $badge
 * (testo dello stato accanto al numero, vuoto per il documento neutro).
 * Dal controller: $invoice, $items, $invoice_tax_rates, $payment_method,
 * $show_item_discounts, $bank_account.
 */
// $accent/$badge arrivano dalle "render options" di $this->include(), non
// da extract() diretto: vanno letti da renderVars['options'] (vedi anche
// layout/headerbar.php). Senza questo, restavano silenziosamente ai
// default 'neutral'/'' — niente errore, ma le PDF "pagata"/"scaduta"
// uscivano sempre col colore e il badge del documento neutro.
$_pd    = (array) ($this->renderVars['options'] ?? []);
$accent = $_pd['accent'] ?? ($accent ?? 'neutral');
$badge  = $_pd['badge']  ?? ($badge  ?? '');

$cols = $show_item_discounts ? 5 : 4;

// Identita' di chi emette: la ragione sociale dell'azienda del documento, non
// il nome dell'account. Chi riceve la fattura deve leggere "Novatech Srl", non
// "Admin"; la persona resta sotto, e solo se aggiunge informazione.
$company_name = trim((string) ($invoice->emitter_name ?? ''));
$person_name  = trim((string) ($invoice->user_name ?? ''));

$cityLine = static function ($city, $zip, $state): string {
    // Ordine italiano: CAP, citta', provincia. Prima usciva "Milano MI 20100".
    return trim(implode(' ', array_filter([$zip, $city, $state])));
};
?>
<!DOCTYPE html>
<html lang="<?= trans('cldr') ?>">
<head>
    <meta charset="utf-8">
    <title><?= trans('invoice') ?> <?= htmlsc((string) $invoice->invoice_number) ?></title>
    <?php /* debug => false: in sviluppo la toolbar di debug avvolge ogni view
             inclusa in <!-- DEBUG-VIEW ... -->. Dentro un <style> quei
             marcatori non sono commenti CSS ma preludi di selettore, e la
             regola @page che segue veniva inghiottita: in sviluppo il PDF
             usciva con i margini di default di dompdf (1,2 cm) invece dei
             15 mm dichiarati, quindi diverso da quello di produzione. */ ?>
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
                    !empty($invoice->emitter_vat_id) ? trans('vat_id_short') . ': ' . $invoice->emitter_vat_id : '',
                    !empty($invoice->emitter_tax_code) ? trans('tax_code_short') . ': ' . $invoice->emitter_tax_code : '',
                ],
                'lines'   => [
                    $invoice->emitter_address_1 ?? '',
                    $invoice->user_address_2 ?? '',
                    $cityLine($invoice->emitter_city ?? '', $invoice->emitter_zip ?? '', $invoice->emitter_state ?? ''),
                    !empty($invoice->emitter_country) ? get_country_name(trans('cldr'), $invoice->emitter_country) : '',
                ],
                'contacts' => [
                    !empty($invoice->emitter_phone) ? trans('phone_abbr') . ': ' . $invoice->emitter_phone : '',
                    $invoice->user_fax ? trans('fax_abbr') . ': ' . $invoice->user_fax : '',
                ],
            ]) ?>
        </td>
        <td class="recipient">
            <?= $this->include('layout/pdf_party', [
                'label'   => trans('bill_to'),
                'boxed'   => true,
                'name'    => format_client($invoice),
                'ids'     => [
                    $invoice->client_vat_id ? trans('vat_id_short') . ': ' . $invoice->client_vat_id : '',
                    $invoice->client_tax_code ? trans('tax_code_short') . ': ' . $invoice->client_tax_code : '',
                ],
                'lines'   => [
                    $invoice->client_address_1 ?? '',
                    $invoice->client_address_2 ?? '',
                    $cityLine($invoice->client_city ?? '', $invoice->client_zip ?? '', $invoice->client_state ?? ''),
                    $invoice->client_country ? get_country_name(trans('cldr'), $invoice->client_country) : '',
                ],
                'contacts' => [
                    $invoice->client_phone ? trans('phone_abbr') . ': ' . $invoice->client_phone : '',
                ],
            ]) ?>
        </td>
    </tr>
</table>

<h1 class="doc-title">
    <?= trans('invoice') ?>
    <span class="doc-number"><?= htmlsc((string) $invoice->invoice_number) ?></span>
    <?php if ($badge !== ''): ?>
        <span class="doc-badge"><?= htmlsc($badge) ?></span>
    <?php endif; ?>
</h1>

<table class="layout doc-meta">
    <tr>
        <td><div class="k"><?= trans('invoice_date') ?></div></td>
        <td><div class="k"><?= trans('due_date') ?></div></td>
        <?php if ($payment_method): ?>
            <td><div class="k"><?= trans('payment_method') ?></div></td>
        <?php endif; ?>
        <td class="text-end"><div class="k"><?= trans('amount_due') ?></div></td>
    </tr>
    <tr>
        <td><div class="v"><?= date_from_mysql($invoice->invoice_date_created, true) ?></div></td>
        <td><div class="v"><?= date_from_mysql($invoice->invoice_date_due, true) ?></div></td>
        <?php if ($payment_method): ?>
            <td><div class="v"><?= htmlsc($payment_method->payment_method_name) ?></div></td>
        <?php endif; ?>
        <td class="text-end"><div class="v is-accent"><?= format_currency($invoice->invoice_balance) ?></div></td>
    </tr>
</table>

<?php
/* Codice della riga: quello scritto sulla riga (codice MEPA o articolo
   concordato) se c'è, altrimenti il codice del prodotto in catalogo. La
   colonna compare solo se almeno una riga ha un codice, altrimenti sarebbe
   una colonna vuota che ruba spazio alla descrizione. */
$itemCode = static function ($item): string {
    $code = trim((string) ($item->item_code ?? ''));

    return $code !== '' ? $code : trim((string) ($item->product_sku ?? ''));
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
                <div class="item-name"><?= htmlsc($item->item_name) ?></div>
                <?php if (trim((string) $item->item_description) !== ''): ?>
                    <div class="item-desc"><?= nl2br(htmlsc((string) $item->item_description)) ?></div>
                <?php endif; ?>
            </td>
            <td class="num">
                <?= format_amount($item->item_quantity) ?>
                <?php if ($item->item_product_unit): ?>
                    <span class="item-unit"><?= htmlsc($item->item_product_unit) ?></span>
                <?php endif; ?>
            </td>
            <td class="num"><?= format_currency($item->item_price) ?></td>
            <?php if ($show_item_discounts): ?>
                <td class="num"><?= format_currency($item->item_discount) ?></td>
            <?php endif; ?>
            <td class="num"><?= format_currency($item->item_total) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<table class="layout doc-close<?= empty($bank_account) ? ' no-aside' : '' ?>">
    <tr>
        <td class="aside">
            <?php if (!empty($bank_account)): ?>
                <?php /* Coordinate di pagamento: senza, il cliente riceve l'importo
                         da versare ma non dove versarlo. Accanto ai totali e non
                         in fondo alla pagina, perche' si leggono insieme al saldo. */ ?>
                <div class="block">
                    <div class="block-title"><?= trans('payment_details', '', 'Estremi di pagamento') ?></div>
                    <div class="block-body">
                        <?php if (!empty($bank_account['bank_account_bank'])): ?>
                            <div><?= htmlsc($bank_account['bank_account_bank']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($bank_account['bank_account_holder'])): ?>
                            <div><span class="kv-k"><?= trans('holder', '', 'Intestatario') ?>:</span>
                                <?= htmlsc($bank_account['bank_account_holder']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($bank_account['bank_account_iban'])): ?>
                            <div><span class="kv-k">IBAN:</span>
                                <span class="mono"><?= htmlsc($bank_account['bank_account_iban']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($bank_account['bank_account_bic'])): ?>
                            <div><span class="kv-k">BIC/SWIFT:</span>
                                <span class="mono"><?= htmlsc($bank_account['bank_account_bic']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($bank_account['bank_account_note'])): ?>
                            <div><?= nl2br(htmlsc($bank_account['bank_account_note'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </td>
        <td class="sums">
            <table class="sums">
                <tr>
                    <td class="k"><?= trans('subtotal') ?></td>
                    <td class="v"><?= format_currency($invoice->invoice_item_subtotal) ?></td>
                </tr>

                <?php
                // Sconto di documento e contributo cassa prima dell'IVA: è lì
                // che agiscono (InvoiceMath::documentSummary), e solo in
                // quest'ordine le righe del riepilogo si sommano al totale.
                // Lo sconto mostra l'importo effettivo; la percentuale resta
                // nell'etichetta per dire da dove viene.
                ?>
                <?php if ((float) ($invoice->invoice_discount_total ?? 0) != 0): ?>
                    <tr>
                        <td class="k"><?= trans('invoice_discount', '', 'Sconto sul documento') ?><?= (float) $invoice->invoice_discount_percent != 0 ? ' (' . format_amount($invoice->invoice_discount_percent) . '%)' : '' ?></td>
                        <td class="v">&minus;<?= format_currency($invoice->invoice_discount_total) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ((float) ($invoice->invoice_fund_amount ?? 0) != 0): ?>
                    <tr>
                        <td class="k"><?= trans('fund_contribution', '', 'Contributo cassa') ?> <?= htmlsc($invoice->invoice_fund_type ?? '') ?> (<?= format_amount($invoice->invoice_fund_rate) ?>%)</td>
                        <td class="v"><?= format_currency($invoice->invoice_fund_amount) ?></td>
                    </tr>
                <?php endif; ?>

                <?php if ($invoice->invoice_item_tax_total > 0): ?>
                    <tr>
                        <td class="k"><?= trans('item_tax') ?></td>
                        <td class="v"><?= format_currency($invoice->invoice_item_tax_total) ?></td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($invoice_tax_rates as $rate): ?>
                    <tr>
                        <td class="k"><?= htmlsc($rate->invoice_tax_rate_name) ?>
                            (<?= format_amount($rate->invoice_tax_rate_percent) ?>%)</td>
                        <td class="v"><?= format_currency($rate->invoice_tax_rate_amount) ?></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="is-total">
                    <td class="k"><?= trans('total') ?></td>
                    <td class="v"><?= format_currency($invoice->invoice_total) ?></td>
                </tr>
                <?php if ((float) ($invoice->invoice_withholding_amount ?? 0) != 0): ?>
                    <tr>
                        <td class="k"><?= trans('withholding_tax', '', 'Ritenuta d\'acconto') ?></td>
                        <td class="v">&minus;<?= format_currency($invoice->invoice_withholding_amount) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="k"><?= trans('paid') ?></td>
                    <td class="v"><?= format_currency($invoice->invoice_paid) ?></td>
                </tr>
                <tr class="is-balance">
                    <td><?= trans('balance') ?></td>
                    <td class="v"><?= format_currency($invoice->invoice_balance) ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<?php if ($invoice->invoice_terms): ?>
    <div class="block">
        <div class="block-title"><?= trans('terms') ?></div>
        <div class="block-body"><?= nl2br(htmlsc($invoice->invoice_terms)) ?></div>
    </div>
<?php endif; ?>

<div class="doc-footer">
    <?php $docFooter = trim((string) get_setting('pdf_invoice_footer')); ?>
    <?php if ($docFooter !== ''): ?>
        <div class="doc-footer-note"><?= $docFooter ?></div>
    <?php endif; ?>
    <table class="layout">
        <tr>
            <td><?= invoice_logo_footer_pdf() ?></td>
            <?php /* Il totale delle pagine non è noto finché dompdf non ha
                     impaginato: pdf_create() fa un primo passaggio per
                     contarle e sostituisce il segnaposto. Il "numero /
                     totale" lo compone il foglio (counter(page) nel CSS).
                     Nell'anteprima a schermo il blocco è nascosto perché
                     lì non c'è paginazione. */ ?>
            <td class="text-end"><span class="pages">%%PAGINE%%</span></td>
        </tr>
    </table>
</div>

</body>
</html>
