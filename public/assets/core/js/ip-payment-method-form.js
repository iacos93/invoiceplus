/**
 * InvoicePlane CI4 — Form del metodo di pagamento (ip-payment-method-form.js)
 *
 * Aggiorna il foglio d'anteprima con le due scelte che hanno un effetto fuori
 * da questa pagina: la modalita' MP che finisce nell'XML e la presenza delle
 * coordinate bancarie sul documento. Le coordinate mostrate sono quelle del
 * conto predefinito, gia' scritte nel markup dal server: qui si accendono e si
 * spengono, non si vanno a cercare.
 */
(function () {
    'use strict';

    var sheet = document.getElementById('ip-paysheet');
    if (!sheet) { return; }

    var nameInput = document.getElementById('payment_method_name');
    var modeSelect = document.getElementById('payment_method_sdi_code');
    var ibanCheck = document.getElementById('payment_method_requires_bank_account');

    function out(name) {
        return sheet.querySelector('[data-paysheet="' + name + '"]');
    }

    function setLine(name, text) {
        var el = out(name);
        if (!el) { return; }
        el.textContent = text;
        el.hidden = text === '';
    }

    // Le righe delle coordinate esistono nel markup con il loro contenuto:
    // qui si decide solo se il metodo le porta sul documento.
    function toggleAccount(show) {
        ['account', 'iban', 'no-account'].forEach(function (name) {
            var el = out(name);
            if (!el) { return; }
            el.hidden = !show || el.textContent.trim() === '';
        });
    }

    function render() {
        var name = nameInput ? nameInput.value.trim() : '';
        var mode = modeSelect ? modeSelect.value : '';
        var modeLabel = '';

        if (mode && modeSelect.selectedIndex > -1) {
            modeLabel = modeSelect.options[modeSelect.selectedIndex].textContent.trim();
        }

        setLine('method', name ? 'Pagamento: ' + name : '');
        setLine('mode', modeLabel
            ? 'Modalità fattura elettronica: ' + modeLabel
            : 'Nessuna modalità: la fattura elettronica parte senza dati di pagamento.');
        toggleAccount(!ibanCheck || ibanCheck.checked);
    }

    if (nameInput) { nameInput.addEventListener('input', render); }
    // IPSelect scrive nel <select> nativo e vi emette 'change': ascoltare qui
    // copre sia il componente sia il campo nudo.
    if (modeSelect) { modeSelect.addEventListener('change', render); }
    if (ibanCheck) { ibanCheck.addEventListener('change', render); }

    render();
})();
