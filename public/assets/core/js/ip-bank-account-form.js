/**
 * InvoicePlane CI4 — Form del conto di saldo (ip-bank-account-form.js)
 *
 * Tiene aggiornato il foglio d'anteprima mentre si compila: il conto serve a
 * essere stampato sul documento, e vederlo nella forma stampata mentre si
 * digita e' il modo piu' diretto per accorgersi di un IBAN incollato male.
 *
 * L'IBAN nel campo viene raggruppato a quattro cifre quando si esce dal campo:
 * si rilegge come sull'estratto conto. Gli spazi non danno fastidio, il
 * salvataggio li toglie (BankAccountModel::normalizePost) perche' il tracciato
 * FatturaPA vuole la stringa continua.
 *
 * Nessuna dipendenza da jQuery.
 */
(function () {
    'use strict';

    var sheet = document.getElementById('ip-paysheet');
    if (!sheet) { return; }

    var fields = {
        bank:   document.getElementById('bank_account_bank'),
        holder: document.getElementById('bank_account_holder'),
        iban:   document.getElementById('bank_account_iban'),
        bic:    document.getElementById('bank_account_bic'),
        note:   document.getElementById('bank_account_note')
    };

    function out(name) {
        return sheet.querySelector('[data-paysheet="' + name + '"]');
    }

    function cleanIban(value) {
        return (value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    }

    function groupIban(value) {
        var clean = cleanIban(value);
        return clean ? clean.replace(/(.{4})/g, '$1 ').trim() : '';
    }

    // Riga vuota nascosta invece che lasciata a spazio bianco: sul foglio le
    // righe assenti non devono aprire buchi fra quelle presenti.
    function setLine(name, text) {
        var el = out(name);
        if (!el) { return; }
        el.textContent = text;
        el.hidden = text === '';
    }

    function render() {
        var iban = fields.iban ? cleanIban(fields.iban.value) : '';
        var bic  = fields.bic ? cleanIban(fields.bic.value) : '';
        var note = fields.note ? fields.note.value.trim() : '';

        setLine('bank', fields.bank ? fields.bank.value.trim() : '');
        setLine('holder', fields.holder ? fields.holder.value.trim() : '');
        setLine('iban', groupIban(iban));
        setLine('bic', bic ? 'BIC ' + bic : '');
        setLine('note', note);

        var empty = out('empty');
        if (empty) { empty.hidden = iban !== ''; }
    }

    Object.keys(fields).forEach(function (key) {
        if (fields[key]) { fields[key].addEventListener('input', render); }
    });

    if (fields.iban) {
        fields.iban.addEventListener('blur', function () {
            fields.iban.value = groupIban(fields.iban.value);
        });
    }

    render();
})();
