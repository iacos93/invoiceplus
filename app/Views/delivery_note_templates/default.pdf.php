<?php
/**
 * Documento di trasporto (DDT) — template PDF.
 *
 * Rispetto alla versione precedente (CSS scritto a mano nel file) il foglio
 * adotta lo stesso sistema dei documenti fiscali: lo stile sta in
 * layout/pdf_styles (condiviso con fatture e preventivi) e le anagrafiche in
 * layout/pdf_party. Cosi' il DDT ha la stessa faccia di fattura e preventivo
 * della stessa azienda, e l'anteprima a schermo (iframe) e il PDF dompdf
 * coincidono (foglio A4 simulato su sfondo "scrivania" via @media screen).
 *
 * Dal controller riceve: $note (riga ip_delivery_notes + join clienti/fattura),
 * $items (righe merce), $company (azienda attiva, chi emette).
 */

$cityLine = static function ($city, $zip, $state): string {
    return trim(implode(' ', array_filter([$zip, $city, $state])));
};

$emitterIds = [];
if (!empty($company['company_vat_id'])) {
    $emitterIds[] = trans('vat_id_short') . ': ' . $company['company_vat_id'];
}
if (!empty($company['company_tax_code'])) {
    $emitterIds[] = trans('tax_code_short') . ': ' . $company['company_tax_code'];
}

$emitterLines = array_filter([
    $company['company_address_1'] ?? '',
    $cityLine($company['company_city'] ?? '', $company['company_zip'] ?? '', $company['company_state'] ?? ''),
    !empty($company['company_country']) ? get_country_name(trans('cldr'), $company['company_country']) : '',
]);

$emitterContacts = array_filter([
    !empty($company['company_phone']) ? trans('phone_abbr') . ': ' . $company['company_phone'] : '',
    !empty($company['company_email']) ? trans('email_abbr') . ': ' . $company['company_email'] : '',
]);

$clientIds = [];
if (!empty($note['client_vat_id'])) {
    $clientIds[] = trans('vat_id_short') . ': ' . $note['client_vat_id'];
}
if (!empty($note['client_tax_code'])) {
    $clientIds[] = trans('tax_code_short') . ': ' . $note['client_tax_code'];
}

$clientLines = array_filter([
    $note['client_address_1'] ?? '',
    $cityLine($note['client_city'] ?? '', $note['client_zip'] ?? '', $note['client_state'] ?? ''),
    !empty($note['client_country']) ? get_country_name(trans('cldr'), $note['client_country']) : '',
]);

$clientContacts = array_filter([
    !empty($note['client_phone']) ? trans('phone_abbr') . ': ' . $note['client_phone'] : '',
]);

$invoiced = !empty($note['invoice_id']);
$badge = $invoiced
    ? ['text' => trans('invoiced', '', 'Fatturato'), 'color' => '#2f7a31']
    : ['text' => trans('to_invoice', '', 'Da fatturare'), 'color' => '#9a6a00'];
?>
<!DOCTYPE html>
<html lang="<?= trans('cldr') ?>">
<head>
    <meta charset="utf-8">
    <title><?= trans('delivery_note', '', 'DDT') ?> <?= htmlsc((string) ($note['delivery_note_number'] ?? '')) ?></title>
    <?php /* debug => false: in sviluppo i marcatori <!-- DEBUG-VIEW --> della
             toolbar finiscono dentro il <style> e si mangiano la regola @page. */ ?>
    <style><?= $this->include('layout/pdf_styles', ['accent' => 'neutral', 'debug' => false]) ?></style>
    <style>
        /* Larghezze colonne specifiche del DDT (niente prezzi): la descrizione
           dei beni occupa la parte larga, quantita' e unita' di misura stanno
           a destra. Le altre regole del documento vengono da layout/pdf_styles. */
        table.items td.c-goods { width: 64%; }
        table.items td.c-qty   { width: 18%; }
        table.items td.c-unit  { width: 18%; }

        /* Dati di trasporto su una riga sola, stessa impostazione della
           doc-meta delle fatture: etichette brevi, valori brevi. */
        .ddt-meta td { padding-right: 18px; vertical-align: top; }
        .ddt-meta .k {
            font-size: 7pt; font-weight: bold; letter-spacing: 0.06em;
            text-transform: uppercase; color: #6b6b6b;
        }
        .ddt-meta .v { font-size: 9.5pt; }

        .signatures { width: 100%; margin-top: 26px; border-collapse: collapse; }
        .signatures td {
            width: 33%; padding-top: 28px; border-top: 0.5pt solid #9a9a9a;
            font-size: 8pt; text-align: center; color: #6b6b6b;
        }
    </style>
</head>
<body>

<table class="layout doc-head">
    <tr>
        <td class="emitter">
            <div class="logo"><?= invoice_logo_pdf() ?></div>
            <?= $this->include('layout/pdf_party', [
                'name'     => $company['company_name'] ?? '',
                'ids'      => $emitterIds,
                'lines'    => $emitterLines,
                'contacts' => $emitterContacts,
            ]) ?>
        </td>
        <td class="recipient">
            <?= $this->include('layout/pdf_party', [
                'label'    => trans('client', '', 'Destinatario'),
                'boxed'    => true,
                'name'     => format_client($note),
                'ids'      => $clientIds,
                'lines'    => $clientLines,
                'contacts' => $clientContacts,
            ]) ?>
        </td>
    </tr>
</table>

<h1 class="doc-title">
    <?= trans('delivery_note', '', 'Documento di trasporto') ?>
    <span class="doc-number"><?= htmlsc((string) ($note['delivery_note_number'] ?: ('#' . $note['delivery_note_id']))) ?></span>
    <span class="doc-badge" style="border-color:<?= $badge['color'] ?>; color:<?= $badge['color'] ?>;">
        <?= htmlsc($badge['text']) ?>
    </span>
</h1>

<table class="layout doc-meta ddt-meta">
    <tr>
        <td><div class="k"><?= trans('date') ?></div><div class="v"><?= date_from_mysql($note['delivery_note_date'], true) ?></div></td>
        <td><div class="k"><?= trans('transport_reason', '', 'Causale') ?></div><div class="v"><?= htmlsc($note['transport_reason'] ?? '') ?></div></td>
        <td><div class="k"><?= trans('packages', '', 'Colli') ?></div><div class="v"><?= htmlsc($note['packages'] ?: '—') ?></div></td>
        <td><div class="k"><?= trans('weight', '', 'Peso') ?></div><div class="v"><?= htmlsc($note['weight'] ?: '—') ?></div></td>
    </tr>
    <tr>
        <td><div class="k"><?= trans('carrier', '', 'Vettore') ?></div><div class="v"><?= htmlsc($note['carrier'] ?: '—') ?></div></td>
        <td><div class="k"><?= trans('transport_charge', '', 'Porto') ?></div><div class="v"><?= htmlsc($note['transport_charge'] ?? '') ?></div></td>
        <td><div class="k"><?= trans('goods_appearance', '', 'Aspetto beni') ?></div><div class="v"><?= htmlsc($note['goods_appearance'] ?: '—') ?></div></td>
        <td><div class="k"><?= trans('transport_start', '', 'Inizio trasporto') ?></div>
            <div class="v"><?= htmlsc($note['transport_datetime'] ? date('d/m/Y H:i', strtotime($note['transport_datetime'])) : '—') ?></div></td>
    </tr>
</table>

<?php if (!empty($note['shipping_address'])): ?>
    <div class="block">
        <div class="block-title"><?= trans('shipping_address', '', 'Luogo di destinazione') ?></div>
        <div class="block-body"><?= nl2br(htmlsc($note['shipping_address'])) ?></div>
    </div>
<?php endif; ?>

<table class="items">
    <thead>
    <tr>
        <th scope="col" class="c-goods"><?= trans('goods', '', 'Descrizione dei beni') ?></th>
        <th scope="col" class="c-qty num"><?= trans('qty') ?></th>
        <th scope="col" class="c-unit"><?= trans('unit', '', 'Unità') ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td class="c-goods item-name">
                <?= htmlsc($item['item_name'] ?? '') ?>
                <?php if (!empty($item['item_description'])): ?>
                    <div class="party-line"><?= nl2br(htmlsc($item['item_description'])) ?></div>
                <?php endif; ?>
            </td>
            <td class="c-qty num"><?= format_amount($item['item_quantity'] ?? 0) ?></td>
            <td class="c-unit"><?= htmlsc($item['item_unit'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($items)): ?>
        <tr><td colspan="3"><?= trans('no_goods', '', 'Nessun bene indicato.') ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php if (!empty($note['delivery_note_notes'])): ?>
    <div class="block">
        <div class="block-title"><?= trans('notes') ?></div>
        <div class="block-body"><?= nl2br(htmlsc($note['delivery_note_notes'])) ?></div>
    </div>
<?php endif; ?>

<table class="signatures">
    <tr>
        <td><?= trans('sender_signature', '', 'Firma del mittente') ?></td>
        <td><?= trans('carrier_signature', '', 'Firma del vettore') ?></td>
        <td><?= trans('recipient_signature', '', 'Firma del destinatario') ?></td>
    </tr>
</table>

<div class="doc-footer">
    <?php $docFooter = trim((string) get_setting('pdf_invoice_footer')); ?>
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
