<div style="margin:16px 0;padding:12px;background:var(--ip-bg);border:1px solid var(--ip-border);border-radius:var(--ip-radius-sm);max-width:620px">
    <h3 style="margin-top:0"><?= trans('email_template_tags') ?></h3>
    <p style="font-size:12px;color:var(--ip-text-muted)"><?= trans('email_template_tags_instructions') ?></p>

    <h4 style="margin-bottom:4px"><?= trans('client') ?></h4>
    <p style="margin:0 0 10px;font-size:13px;color:var(--ip-text)">
        <code>{{client_name}}</code> - <?= trans('client_name') ?><br>
        <code>{{client_surname}}</code> - <?= trans('client_surname') ?><br>
        <code>{{client_fullname}}</code> - Nome e cognome completi<br>
        <code>{{client_address_1}}</code> - <?= trans('street_address') ?><br>
        <code>{{client_address_2}}</code> - <?= trans('street_address_2') ?><br>
        <code>{{client_city}}</code> - <?= trans('city') ?><br>
        <code>{{client_state}}</code> - <?= trans('state') ?><br>
        <code>{{client_zip}}</code> - <?= trans('zip') ?><br>
        <code>{{client_country}}</code> - <?= trans('country') ?><br>
        <code>{{client_phone}}</code> - <?= trans('phone') ?><br>
        <code>{{client_fax}}</code> - <?= trans('fax') ?><br>
        <code>{{client_mobile}}</code> - <?= trans('mobile') ?><br>
        <code>{{client_email}}</code> - <?= trans('email') ?><br>
        <code>{{client_web}}</code> - <?= trans('web_address') ?><br>
        <code>{{client_vat_id}}</code> - <?= trans('vat_id') ?><br>
        <code>{{client_tax_code}}</code> - <?= trans('tax_code') ?><br>
    </p>

    <h4 style="margin-bottom:4px"><?= trans('user') ?></h4>
    <p style="margin:0 0 10px;font-size:13px;color:var(--ip-text)">
        <code>{{user_name}}</code> - Nome utente<br>
        <code>{{user_company}}</code> - Azienda utente<br>
        <code>{{user_address_1}}</code> - <?= trans('street_address') ?><br>
        <code>{{user_address_2}}</code> - <?= trans('street_address_2') ?><br>
        <code>{{user_city}}</code> - <?= trans('city') ?><br>
        <code>{{user_state}}</code> - <?= trans('state') ?><br>
        <code>{{user_zip}}</code> - <?= trans('zip') ?><br>
        <code>{{user_country}}</code> - <?= trans('country') ?><br>
        <code>{{user_phone}}</code> - <?= trans('phone') ?><br>
        <code>{{user_fax}}</code> - <?= trans('fax') ?><br>
        <code>{{user_mobile}}</code> - <?= trans('mobile') ?><br>
        <code>{{user_email}}</code> - Email utente<br>
        <code>{{user_web}}</code> - <?= trans('web_address') ?><br>
        <code>{{user_iban}}</code> - <?= trans('user_iban') ?><br>
    </p>

    <h4 style="margin-bottom:4px"><?= trans('invoices') ?></h4>
    <p style="margin:0 0 10px;font-size:13px;color:var(--ip-text)">
        <code>{{invoice_number}}</code> - Numero fattura<br>
        <code>{{invoice_date_created}}</code> - Data fattura<br>
        <code>{{invoice_date_due}}</code> - Scadenza fattura<br>
        <code>{{invoice_status}}</code> - Stato fattura<br>
        <code>{{invoice_item_subtotal}}</code> - <?= trans('subtotal') ?><br>
        <code>{{invoice_item_tax_total}}</code> - <?= trans('invoice_tax') ?><br>
        <code>{{invoice_tax_total}}</code> - Imposta totale<br>
        <code>{{invoice_total}}</code> - Totale fattura<br>
        <code>{{invoice_paid}}</code> - <?= trans('total_paid') ?><br>
        <code>{{invoice_balance}}</code> - Saldo fattura<br>
        <code>{{invoice_terms}}</code> - <?= trans('invoice_terms') ?><br>
        <code>{{invoice_guest_url}}</code> - Link di visualizzazione guest
    </p>

    <h4 style="margin-bottom:4px"><?= trans('quotes') ?></h4>
    <p style="margin:0 0 10px;font-size:13px;color:var(--ip-text)">
        <code>{{quote_number}}</code> - Numero preventivo<br>
        <code>{{quote_date_created}}</code> - Data preventivo<br>
        <code>{{quote_date_expires}}</code> - Scadenza preventivo<br>
        <code>{{quote_item_subtotal}}</code> - <?= trans('subtotal') ?><br>
        <code>{{quote_item_tax_total}}</code> - Imposta righe<br>
        <code>{{quote_tax_total}}</code> - Imposta totale<br>
        <code>{{quote_total}}</code> - Totale preventivo<br>
        <code>{{quote_guest_url}}</code> - Link di visualizzazione guest
    </p>

    <h4 style="margin-bottom:4px"><?= trans('custom_fields') ?></h4>
    <p style="margin:0;font-size:13px;color:var(--ip-text)">
        <code>{{ip_cf_&lt;id&gt;}}</code> - Campo personalizzato: sostituisci <code>&lt;id&gt;</code> con l'ID mostrato nella pagina dei campi personalizzati
    </p>
</div>