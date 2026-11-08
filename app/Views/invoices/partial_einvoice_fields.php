<?php
/**
 * Corpo della sezione "Fattura elettronica" (logica stile FattureinCloud).
 *
 * I due campi essenziali (tipo documento, esigibilita' IVA) restano sempre in
 * vista; il resto e' raggruppato in pannelli collassabili "opzionali" che si
 * aprono da soli quando contengono gia' valori (cosi' in modifica non sparisce
 * cio' che era stato compilato) e mostrano quanti campi sono valorizzati.
 *
 * Usato sia dal form di creazione (partial_create_einvoice.php) sia dalla
 * scheda di modifica (invoices/edit.php). $get e' la funzione che restituisce
 * il valore corrente di un campo: old() in creazione, $invoice[...] in modifica.
 */
$get = $get ?? static fn(string $n) => '';

$isFilled = static function (string ...$names) use ($get): int {
    $count = 0;
    foreach ($names as $name) {
        if (trim((string) ($get($name) ?? '')) !== '') {
            $count++;
        }
    }
    return $count;
};

$field = static function (string $name, string $label, string $type = 'text', array $extra = []) use ($get): void {
    $value = (string) ($get($name) ?? '');
    echo '<div class="ip-doc-field">';
    echo '<label class="ip-doc-label" for="' . $name . '">' . esc($label) . '</label>';

    if ($type === 'select') {
        echo '<select name="' . $name . '" id="' . $name . '" class="form-control simple-select">';
        foreach ($extra as $val => $text) {
            $selected = $value === (string) $val ? ' selected' : '';
            echo '<option value="' . esc($val, 'attr') . '"' . $selected . '>' . esc($text) . '</option>';
        }
        echo '</select>';
    } else {
        $step = $type === 'number' ? ' step="0.01" min="0"' : '';
        $max  = !empty($extra['maxlength']) ? ' maxlength="' . (int) $extra['maxlength'] . '"' : '';
        echo '<input type="' . $type . '"' . $step . $max . ' name="' . $name . '" id="' . $name . '"'
           . ' class="form-control" value="' . esc($value, 'attr') . '">';
    }

    if (!empty($extra['help'])) {
        echo '<p class="ip-number-help">' . esc($extra['help']) . '</p>';
    }

    echo '</div>';
};

$group = static function (string $id, string $title, array $fieldNames, string $inner) use ($isFilled): void {
    $count   = $isFilled(...$fieldNames);
    $open    = $count > 0;
    $toggleId = $id . '-toggle';
    echo '<div class="ip-einv-group">';
    echo '<button type="button" class="ip-einv-group__toggle" id="' . $toggleId . '" '
       . 'data-bs-toggle="collapse" data-bs-target="#' . $id . '" '
       . 'aria-controls="' . $id . '" aria-expanded="' . ($open ? 'true' : 'false') . '">';
    echo '<span class="ip-einv-group__title">' . esc($title) . '</span>';
    if ($count > 0) {
        echo '<span class="ip-einv-group__tag">' . $count . ' valorizzat' . ($count === 1 ? 'o' : 'i') . '</span>';
    } else {
        echo '<span class="ip-einv-group__tag">Opzionale</span>';
    }
    echo '<i class="fa fa-solid fa-chevron-down ip-einv-group__chevron" aria-hidden="true"></i>';
    echo '</button>';
    echo '<div id="' . $id . '" class="ip-einv-group__body collapse' . ($open ? ' show' : '') . '" '
       . 'role="region" aria-labelledby="' . $toggleId . '">';
    echo '<div class="ip-doc-grid">';
    echo $inner;
    echo '</div>';
    echo '</div>';
    echo '</div>';
};

/* Campi essenziali: sempre in vista. */
?>
<div class="ip-doc-grid">
    <?php $field('invoice_doc_type', trans('document_type', '', 'Tipo documento'), 'select', [
        ''     => trans('default', '', 'Predefinito (TD01)'),
        'TD01' => 'TD01 — Fattura',
        'TD02' => 'TD02 — Acconto/anticipo su fattura',
        'TD04' => 'TD04 — Nota di credito',
        'TD05' => 'TD05 — Nota di debito',
        'TD06' => 'TD06 — Parcella',
        'TD24' => 'TD24 — Fattura differita',
        'TD25' => 'TD25 — Fattura differita (art. 21 c.4)',
        'TD26' => 'TD26 — Cessione di beni ammortizzabili',
    ]); ?>
    <?php $field('invoice_vat_exigibility', trans('vat_exigibility', '', 'Esigibilità IVA'), 'select', [
        ''  => trans('immediate', '', 'Immediata (predefinita)'),
        'I' => 'I — Immediata',
        'D' => 'D — Differita',
        'S' => 'S — Scissione dei pagamenti (split payment)',
    ]); ?>
</div>

<?php
/* Dati Pubblica Amministrazione. */
$paInner = '';
ob_start();
// CIG o CUP senza alcun numero di riferimento: il tracciato li mette in un
// DatiOrdineAcquisto con IdDocumento "ND" (EinvoiceReferences), che lo SdI
// accetta ma l'ufficio che liquida può respingere. Lo si dice qui, accanto
// ai campi, invece di lasciarlo scoprire dal rifiuto.
$refProbe = [];
foreach (array_merge(['invoice_cig', 'invoice_cup', 'creditinvoice_parent_id'], \App\Services\EinvoiceReferences::NUMBER_FIELDS) as $probeField) {
    $refProbe[$probeField] = $get($probeField);
}
if (\App\Services\EinvoiceReferences::missingOrderReference($refProbe)) {
    echo '<div class="alert alert-warning ip-doc-alert" role="status" id="einv-missing-order-ref">'
       . '<i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> '
       . esc(trans('einvoice_missing_order_ref', '', 'CIG/CUP indicati senza numero d\'ordine, determina o contratto: nell\'XML il riferimento uscirà come "ND". Indica il numero in "Riferimenti documento" se l\'ente lo richiede.'))
       . '</div>';
}
$field('invoice_cig', trans('cig', '', 'CIG'), 'text', ['help' => 'Codice Identificativo Gara', 'maxlength' => 15]);
$field('invoice_cup', trans('cup', '', 'CUP'), 'text', ['help' => 'Codice Unico di Progetto', 'maxlength' => 15]);
$field('invoice_admin_reference', trans('admin_reference', '', 'Rif. amministrazione'), 'text', ['help' => 'Codice con cui l\'ente identifica il fornitore']);
$paInner = ob_get_clean();
$group('einv-pa', trans('public_contract', '', 'Commessa pubblica (PA)'),
    ['invoice_cig', 'invoice_cup', 'invoice_admin_reference'], $paInner);
?>

<?php
/* Riferimenti a documenti precedenti. */
$refInner = '';
ob_start();
$field('invoice_order_number', trans('order_or_resolution_number', '', 'Numero ordine / determina'), 'text', [
    'help'      => 'Atto con cui la PA ha disposto l\'acquisto (ordine, determina, buono d\'ordine): è il riferimento in cui viaggiano CIG e CUP',
    'maxlength' => 20,
]);
$field('invoice_order_date', trans('order_date', '', 'Data ordine'), 'date');
$field('invoice_order_line', trans('order_line', '', 'Riga ordine'));
$field('invoice_contract_number', trans('contract_number', '', 'Numero contratto'));
$field('invoice_contract_date', trans('contract_date', '', 'Data contratto'), 'date');
$field('invoice_convention_number', trans('convention_number', '', 'Numero convenzione'));
$field('invoice_convention_date', trans('convention_date', '', 'Data convenzione'), 'date');
$field('invoice_receipt_number', trans('receipt_number', '', 'Numero ricezione'));
$field('invoice_receipt_date', trans('receipt_date', '', 'Data ricezione'), 'date');
$refInner = ob_get_clean();
$group('einv-ref', trans('document_references', '', 'Riferimenti documento'),
    ['invoice_order_number', 'invoice_order_date', 'invoice_order_line', 'invoice_contract_number',
     'invoice_contract_date', 'invoice_convention_number', 'invoice_convention_date',
     'invoice_receipt_number', 'invoice_receipt_date'], $refInner);
?>

<?php
/* Bollo, ritenute e cassa previdenziale. */
$advInner = '';
ob_start();
?>
    <div class="ip-doc-subgroup"><?= trans('stamp_duty', '', 'Bollo') ?></div>
    <?php $field('invoice_stamp_mode', trans('stamp_mode', '', 'Applicazione'), 'select', [
        ''   => trans('stamp_auto', '', 'Automatico (oltre 77,47 € esenti)'),
        'si' => trans('stamp_yes', '', 'Sempre applicato'),
        'no' => trans('stamp_no', '', 'Mai applicato'),
    ]); ?>
    <?php $field('invoice_stamp_amount', trans('stamp_amount', '', 'Importo bollo (€)'), 'number'); ?>

    <div class="ip-doc-subgroup"><?= trans('withholding', '', 'Ritenuta d\'acconto') ?></div>
    <?php $field('invoice_withholding_type', trans('withholding_type', '', 'Tipo ritenuta'), 'select', [
        ''     => trans('none', '', 'Nessuna'),
        'RT01' => 'RT01 — Persone fisiche',
        'RT02' => 'RT02 — Persone giuridiche',
        'RT03' => 'RT03 — Contributo INPS',
        'RT04' => 'RT04 — Contributo ENASARCO',
        'RT05' => 'RT05 — Contributo ENPAM',
        'RT06' => 'RT06 — Altro contributo previdenziale',
    ]); ?>
    <?php $field('invoice_withholding_rate', trans('withholding_rate', '', 'Aliquota (%)'), 'number'); ?>
    <?php $field('invoice_withholding_reason', trans('withholding_reason', '', 'Causale pagamento'), 'select', [
        'A' => 'A — Prestazioni di lavoro autonomo',
        'B' => 'B — Utilizzazione economica di opere dell\'ingegno',
        'C' => 'C — Utili da contratti di associazione in partecipazione',
        'G' => 'G — Indennità di cessazione di rapporti di agenzia',
        'L' => 'L — Redditi da utilizzazione di opere dell\'ingegno (autore)',
        'M' => 'M — Prestazioni di lavoro autonomo non abituale',
        'O' => 'O — Lavoro autonomo non abituale, senza obbligo INPS',
        'V' => 'V — Provvigioni per intermediazione',
    ]); ?>

    <div class="ip-doc-subgroup"><?= trans('social_fund', '', 'Cassa previdenziale') ?></div>
    <?php $field('invoice_fund_type', trans('fund_type', '', 'Tipo cassa'), 'select', [
        ''     => trans('none', '', 'Nessuna'),
        'TC01' => 'TC01 — Cassa nazionale previdenza e assistenza avvocati e procuratori',
        'TC02' => 'TC02 — Cassa previdenza dottori commercialisti',
        'TC03' => 'TC03 — Cassa previdenza e assistenza geometri',
        'TC04' => 'TC04 — Cassa nazionale previdenza e assistenza ingegneri e architetti',
        'TC05' => 'TC05 — Cassa nazionale del notariato',
        'TC06' => 'TC06 — Cassa nazionale previdenza e assistenza ragionieri',
        'TC07' => 'TC07 — ENASARCO',
        'TC08' => 'TC08 — ENPACL (consulenti del lavoro)',
        'TC09' => 'TC09 — ENPAM (medici)',
        'TC10' => 'TC10 — ENPAF (farmacisti)',
        'TC11' => 'TC11 — ENPAV (veterinari)',
        'TC12' => 'TC12 — ENPAIA (agrotecnici e periti agrari)',
        'TC13' => 'TC13 — Fondo previdenza impiegati imprese di spedizione',
        'TC14' => 'TC14 — INPGI (giornalisti)',
        'TC15' => 'TC15 — ONAOSI',
        'TC16' => 'TC16 — CASAGIT',
        'TC17' => 'TC17 — EPPI (periti industriali)',
        'TC18' => 'TC18 — EPAP (pluricategoriale)',
        'TC19' => 'TC19 — ENPAB (biologi)',
        'TC20' => 'TC20 — ENPAPI (infermieri)',
        'TC21' => 'TC21 — ENPAP (psicologi)',
        'TC22' => 'TC22 — INPS',
    ]); ?>
    <?php $field('invoice_fund_rate', trans('fund_rate', '', 'Aliquota (%)'), 'number'); ?>
<?php
$advInner = ob_get_clean();
$group('einv-adv', trans('stamp_withholding_fund', '', 'Bollo, ritenute e cassa'),
    ['invoice_stamp_mode', 'invoice_stamp_amount', 'invoice_withholding_type', 'invoice_withholding_rate',
     'invoice_withholding_reason', 'invoice_fund_type', 'invoice_fund_rate'], $advInner);
?>

<?php
/* Carta del Docente: checkbox inline che rivela il codice buono. */
// db_bool(): in modifica il valore arriva da PostgreSQL come 't'/'f', e il
// vecchio confronto con '1' lasciava la casella sempre vuota — e il Salva
// successivo spegneva il flag. In creazione old() riporta '1'/'0'.
$isCarta = db_bool($get('invoice_carta_docente') ?? false);
?>
<div class="ip-doc-field ip-doc-field--full" style="margin-top:12px">
    <input type="hidden" name="invoice_carta_docente" value="0">
    <label class="form-check ip-doc-check">
        <input type="checkbox" class="form-check-input" name="invoice_carta_docente" id="invoice_carta_docente" value="1"
               <?= $isCarta ? 'checked' : '' ?>>
        <span class="form-check-label">
            <?= trans('carta_docente_flag', '', 'Fattura per incasso buono Carta del Docente') ?>
        </span>
    </label>
    <p class="ip-number-help">
        <?= trans('carta_docente_flag_help', '', 'L\'XML FatturaPA esce intestato al Ministero (formato FPA12, ufficio QGGT71) con il codice buono e la descrizione "Pagamento Buono", fuori campo IVA: è il documento che serve per la liquidazione del buono da parte dello Stato.') ?>
    </p>
</div>
<div class="ip-doc-field" id="ip-voucher-field" <?= $isCarta ? '' : 'hidden' ?>>
    <label class="ip-doc-label" for="invoice_voucher_code">
        <?= trans('voucher_code', '', 'Codice buono (voucher)') ?>
    </label>
    <input type="text" name="invoice_voucher_code" id="invoice_voucher_code" class="form-control"
           maxlength="20" value="<?= esc((string) ($get('invoice_voucher_code') ?? ''), 'attr') ?>">
    <p class="ip-number-help">
        <?= trans('voucher_code_help', '', '8 caratteri dal portale Carta del Docente, attenzione a maiuscole e minuscole: è il codice con cui lo Stato riconosce il buono e lo liquida.') ?>
    </p>
</div>
<script>
/* Codice buono Carta del Docente: segue il flag, così non si compila per
   errore una fattura ordinaria. Risolto qui perché la sezione e-invoice è
   condivisa tra creazione e modifica. */
(function () {
    var flag = document.getElementById('invoice_carta_docente');
    var field = document.getElementById('ip-voucher-field');
    if (!flag || !field) { return; }
    function toggle() { field.hidden = !flag.checked; }
    flag.addEventListener('change', toggle);
    toggle();
})();
</script>
