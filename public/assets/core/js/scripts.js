"use strict";

// Insert text into textarea at Caret Position
function insert_at_caret(areaId, text) {
    var txtarea = document.getElementById(areaId),
        scrollPos = txtarea.scrollTop,
        strPos = 0,
        br = ((txtarea.selectionStart || txtarea.selectionStart === '0') ?
            "ff" : (document.selection ? "ie" : false)),
        range;

    if (br === "ie") {
        txtarea.focus();
        range = document.selection.createRange();
        range.moveStart('character', -txtarea.value.length);
        strPos = range.text.length;
    } else if (br === "ff") {
        strPos = txtarea.selectionStart;
    }

    var front = (txtarea.value).substring(0, strPos),
        back = (txtarea.value).substring(strPos, txtarea.value.length);

    txtarea.value = front + text + back;
    strPos = strPos + text.length;
    if (br === "ie") {
        txtarea.focus();
        range = document.selection.createRange();
        range.moveStart('character', -txtarea.value.length);
        range.moveStart('character', strPos);
        range.moveEnd('character', 0);
        range.select();
    } else if (br === "ff") {
        txtarea.selectionStart = strPos;
        txtarea.selectionEnd = strPos;
        txtarea.focus();
    }
    txtarea.scrollTop = scrollPos;
}

// takes mdl_email_template row as JSON, array with names to use in form fields.
function inject_email_template(template_fields, email_template) {
    $.each(email_template, function (key, val) {
        // remove prefix from key
        key = key.replace("email_template_", "");
        // if key is in template_fields, apply value to form field
        if (val && template_fields.indexOf(key) > -1) {
            if (key === 'body') {
                $("#" + key).html(val);
            } else if (key === 'pdf_template') {
                $("#" + key).val(val).trigger('change');
            } else {
                $("#" + key).val(val);
            }
        }
    });
}

function update_email_template_preview() {
    $('#email-template-preview').contents().find("body").html($('.email-template-body').val());
}

// Insert HTML tags into textarea
function insert_html_tag(tag_type, destination_id) {
    var text, sel, text_area, selectedText, startPos, endPos, replace, replaceText, len;
    switch (tag_type) {
        case 'text-bold':
            text = ['<b>', '</b>'];
            break;
        case 'text-italic':
            text = ['<em>', '</em>'];
            break;
        case 'text-paragraph':
            text = ['<p>', '</p>'];
            break;
        case 'text-linebreak':
            text = ['<br>', ''];
            break;

        case 'text-h1':
            text = ['<h1>', '</h1>'];
            break;
        case 'text-h2':
            text = ['<h2>', '</h2>'];
            break;
        case 'text-h3':
            text = ['<h3>', '</h3>'];
            break;
        case 'text-h4':
            text = ['<h4>', '</h4>'];
            break;

        case 'text-code':
            text = ['<code>', '</code>'];
            break;
        case 'text-hr':
            text = ['<hr/>', ''];
            break;
        case 'text-css':
            text = ['<style></style>', ''];
            break;
    }

    // Get the selected text
    text_area = document.getElementById(destination_id);
    if (document.selection !== undefined) {
        text_area.focus();
        sel = document.selection.createRange();
        selectedText = sel.text;
    }
    else if (text_area.selectionStart !== '') {
        startPos = text_area.selectionStart;
        endPos = text_area.selectionEnd;
        selectedText = text_area.value.substring(startPos, endPos);
    }

    // Check if <style> should be added
    if (tag_type === 'text-css') {
        replace = text[0] + '\n\r' + text_area.value;
        $(text_area).val(replace);
        update_email_template_preview();
        return true;
    }

    // Check if there is only one HTML tag
    if (text[1].length === 0) {
        insert_at_caret(destination_id, text[0]);
        update_email_template_preview();
        return true;
    }

    // Check if text is selected, replace it or just insert the tag at cursor position
    if (!selectedText || !selectedText.length) {
        text = text[0] + text[1];
        insert_at_caret(destination_id, text);
        update_email_template_preview();
    } else {
        replaceText = text[0] + selectedText + text[1];
        len = text_area.value.length;
        replace = text_area.value.substring(0, startPos) + replaceText + text_area.value.substring(endPos, len);
        $(text_area).val(replace);
        update_email_template_preview();
    }
}

// Prefiltro CSRF e loader fullpage: agiscono sull'intera document, una volta
// sola. La navigazione parziale non li riesegue.
$(document).ready(function () {

    // Automatical CSRF protection for all jQuery POST/PUT/PATCH/DELETE requests.
    //
    // CI4 cookie-mode protection (see app/Config/Security.php): the token equals
    // the raw value of the "csrf_cookie_name" cookie (tokenRandomize=false) and
    // the framework accepts it either as a POST field named "csrf_test_name" or
    // via the "X-CSRF-TOKEN" header. Set the header rather than mutating the
    // body, so FormData/JSON payloads are not corrupted. With regenerate=true
    // the server rotates the cookie on every successful POST and document.cookie
    // always reflects the latest value, so reading it here on every request is
    // correct. No external JS cookie library (js-cookie) is loaded on the page,
    // so parse document.cookie directly.
    function ipGetCsrfToken() {
        var m = document.cookie.match('(^|; )csrf_cookie_name=([^;]*)');
        return m ? decodeURIComponent(m[2]) : '';
    }

    $.ajaxPrefilter(function (options) {
        var t = (options.type || '').toUpperCase();
        if (t !== 'POST' && t !== 'PUT' && t !== 'PATCH' && t !== 'DELETE') {
            return;
        }
        options.headers = options.headers || {};
        if (!options.headers['X-CSRF-TOKEN']) {
            options.headers['X-CSRF-TOKEN'] = ipGetCsrfToken();
        }
    });

    // Correct the height of the content area
    var $content = $('#content'),
        $html = $('html');

    var documentHeight = $html.outerHeight(),
        navbarHeight = $('.navbar').outerHeight(),
        headerbarHeight = $('#headerbar').outerHeight(),
        submenuHeight = $('#submenu').outerHeight(),
        contentHeight = documentHeight - navbarHeight - headerbarHeight - submenuHeight;
    if ($content.outerHeight() < contentHeight) {
        $content.outerHeight(contentHeight);
    }

    // Tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Fullpage loader
    $(document).on('click', '.ajax-loader', function () {
        $('#fullpage-loader').fadeIn(200);
        window.fullpageloaderTimeout = window.setTimeout(function () {
            $('#loader-error').fadeIn(200);
            $('#loader-icon').removeClass('fa-spin').addClass('text-danger');
        }, 10000);
    });

    $(document).on('click', '.fullpage-loader-close', function () {
        $('#fullpage-loader').fadeOut(200);
        $('#loader-error').hide();
        $('#loader-icon').addClass('fa-spin').removeClass('text-danger');
        clearTimeout(window.fullpageloaderTimeout);
    });
});

// Widget legati agli elementi della pagina corrente. La navigazione parziale
// (ip-pjax.js) sostituisce #main-content: i nodi nuovi non hanno i listener
// legati al primo caricamento, quindi questa funzione viene richiamata
// sull'evento ip:page-ready. I .off('.ipPage') prima dei .on rendono innocuo un
// secondo giro sugli stessi elementi.
function initPageWidgets() {
    // I select con ricerca e i datepicker sono inizializzati da
    // ip-components.js (componenti propri, senza librerie esterne).

    // Enable clipboard toggles. ClipboardJS è un vendor che non tutte le
    // pagine caricano: senza la guardia il ready lanciava ReferenceError e
    // fermava tutte le inizializzazioni successive.
    if (window.ClipboardJS) {
        if (window.__ipClipboard) {
            try { window.__ipClipboard.destroy(); } catch (e) { /* già distrutto */ }
        }
        window.__ipClipboard = new ClipboardJS('.to-clipboard');
    }

    // Keep track of the last "taggable" input/textarea
    $('.taggable').off('focus.ipPage').on('focus.ipPage', function () {
        window.lastTaggableClicked = this;
    });

    // Template Tag handling
    $('.tag-select').off('change.ipPage').on('change.ipPage', function (event) {
        var select = $(event.currentTarget);
        // Add the tag to the field
        if (typeof window.lastTaggableClicked !== 'undefined') {
            insert_at_caret(window.lastTaggableClicked.id, select.val());
        }

        // Reset the select and exit
        select.val([]);
        return false;
    });

    // HTML tags to email templates textarea
    $('.html-tag').off('click.ipPage').on('click.ipPage', function () {
        var tag_type = $(this).data('tagType');
        var body_id = $('.email-template-body').attr('id');
        insert_html_tag(tag_type, body_id);
    });

    // Email Template Preview handling
    var email_template_body_id = $('.email-template-body').attr('id');
    if (email_template_body_id) {
        var bodySelector = email_template_body_id.charAt(0) === '#'
            ? email_template_body_id
            : '#' + email_template_body_id;
        $(bodySelector).off('input.ipPage propertychange.ipPage')
            .on('input.ipPage propertychange.ipPage', update_email_template_preview);
    }

    $('#email-template-preview-reload').off('click.ipPage')
        .on('click.ipPage', update_email_template_preview);

    update_email_template_preview();

    // Password strength meter
    $('.passwordmeter-input').off('input.ipPage').on('input.ipPage', function () {
        if (typeof zxcvbn !== 'function') {
            return;
        }
        var strength = zxcvbn($(this).val());

        $('.passmeter-2, .passmeter-3').hide();
        if (strength.score === 4) {
            $('.passmeter-2, .passmeter-3').show();
        } else if (strength.score === 3) {
            $('.passmeter-2').show();
        }
    });
}

window.ipInitPageWidgets = initPageWidgets;
$(document).ready(initPageWidgets);
document.addEventListener('ip:page-ready', initPageWidgets);


// Ricerca live sulle liste. Un solo componente .ip-live-search con due modalita':
//  - con [data-action]: interroga il backend filter/filter_* (FilterAjax, gia'
//    esistente lato server ma prima privo di qualsiasi input in UI) e sostituisce
//    l'HTML del [data-target]. Usato per clienti/fatture/preventivi/pagamenti.
//  - senza [data-action]: filtro client-side sulle righe della tabella indicata
//    da [data-target] (niente round-trip: adatto alle liste di configurazione,
//    tipicamente corte — prodotti, utenti, aliquote, unita', ecc.).
// La modalita' AJAX interroga in GET: la ricerca non modifica stato, e un POST
// richiederebbe il token CSRF che il JS non puo' leggere (cookie httponly).
(function () {
    function bindAjaxSearch(box, input, target, url) {
        var original = target.innerHTML;
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            timer = setTimeout(function () {
                if (q === '') { target.innerHTML = original; return; }
                var sep = url.indexOf('?') === -1 ? '?' : '&';
                fetch(url + sep + 'filter_query=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) { target.innerHTML = html; })
                    .catch(function () { /* silenzioso: la lista resta quella corrente */ });
            }, 300);
        });
    }

    function bindClientFilter(box, input, table) {
        var tbody = table.tagName === 'TABLE' ? table.querySelector('tbody') : table;
        if (!tbody) { return; }
        var noRow = null;

        function normalize(s) { return (s || '').toLowerCase(); }

        input.addEventListener('input', function () {
            var q = normalize(input.value.trim());
            var rows = tbody.querySelectorAll('tr');
            var visible = 0;

            rows.forEach(function (row) {
                if (row === noRow) { return; }
                if (row.classList.contains('empty-row')) {
                    row.style.display = q === '' ? '' : 'none';
                    return;
                }
                var match = q === '' || normalize(row.textContent).indexOf(q) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) { visible++; }
            });

            if (!noRow) {
                var cols = (table.querySelectorAll('thead th').length) || 1;
                noRow = document.createElement('tr');
                noRow.className = 'ip-filter-empty-row';
                var td = document.createElement('td');
                td.colSpan = cols;
                td.className = 'text-muted text-center';
                td.style.padding = '32px 16px';
                td.textContent = box.getAttribute('data-empty-text') || 'Nessun risultato per la ricerca.';
                noRow.appendChild(td);
                tbody.appendChild(noRow);
            }
            noRow.style.display = (q !== '' && visible === 0) ? '' : 'none';
        });
    }

    function initLiveSearch() {
        document.querySelectorAll('.ip-live-search').forEach(function (box) {
            if (box.dataset.liveSearchBound) { return; }
            box.dataset.liveSearchBound = '1';

            var input = box.querySelector('input[name="filter_query"]');
            var targetSel = box.getAttribute('data-target');
            var url = box.getAttribute('data-action');
            var target = targetSel ? document.querySelector(targetSel) : null;
            if (!input || !target) { return; }

            if (url) {
                bindAjaxSearch(box, input, target, url);
            } else {
                bindClientFilter(box, input, target);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLiveSearch);
    } else {
        initLiveSearch();
    }
    // Le liste rimontate dalla navigazione parziale hanno caselle nuove:
    // initLiveSearch salta quelle già legate (dataset.liveSearchBound).
    document.addEventListener('ip:page-ready', initLiveSearch);
})();

// Ricerca globale della topbar: interroga search/global (GET, JSON raggruppato)
// e mostra i risultati in un pannello navigabile da tastiera. "/" mette il fuoco
// sul campo, Esc lo toglie, frecce e Invio scorrono e aprono.
(function () {
    var box = document.getElementById('ip-global-search');
    if (!box) { return; }

    var input  = document.getElementById('ip-global-search-input');
    var panel  = document.getElementById('ip-global-search-results');
    var url    = box.getAttribute('data-action');
    var timer  = null;
    var items  = [];
    var cursor = -1;
    var seq    = 0;

    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : text;
        return div.innerHTML;
    }

    function close() {
        panel.hidden = true;
        panel.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        items = [];
        cursor = -1;
    }

    function open() {
        panel.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function highlight(index) {
        if (!items.length) { return; }
        // Ciclico: da fondo lista si torna in cima, cosi' la freccia giu' non
        // "sbatte" contro l'ultimo risultato.
        cursor = (index + items.length) % items.length;
        items.forEach(function (el, i) { el.classList.toggle('is-active', i === cursor); });
        items[cursor].scrollIntoView({ block: 'nearest' });
    }

    function render(data) {
        if (!data.groups.length) {
            panel.innerHTML = '<div class="ip-global-search__empty">'
                + 'Nessun risultato per “' + esc(data.query) + '”.</div>';
            items = [];
            cursor = -1;
            open();
            return;
        }

        var html = '';
        data.groups.forEach(function (group) {
            html += '<div class="ip-global-search__group-label">'
                 + '<i class="fa ' + esc(group.icon) + '" aria-hidden="true"></i>'
                 + esc(group.label) + '</div>';
            group.items.forEach(function (item) {
                html += '<a class="ip-global-search__item" role="option" href="' + esc(item.url) + '">'
                     + '<span class="ip-global-search__item-main">'
                     + '<span class="ip-global-search__item-title">' + esc(item.title) + '</span>'
                     + (item.subtitle
                        ? '<span class="ip-global-search__item-sub">' + esc(item.subtitle) + '</span>'
                        : '')
                     + '</span>'
                     + (item.meta
                        ? '<span class="ip-global-search__item-meta">' + esc(item.meta) + '</span>'
                        : '')
                     + '</a>';
            });
        });

        panel.innerHTML = html;
        items = Array.prototype.slice.call(panel.querySelectorAll('.ip-global-search__item'));
        cursor = -1;
        open();
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        box.classList.toggle('is-filled', q !== '');
        clearTimeout(timer);

        if (q.length < 2) { close(); return; }

        timer = setTimeout(function () {
            // La risposta piu' lenta di una richiesta precedente non deve
            // sovrascrivere quella della digitazione corrente.
            var mine = ++seq;
            fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (mine === seq) { render(data); } })
                .catch(function () { if (mine === seq) { close(); } });
        }, 220);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); input.blur(); return; }
        if (panel.hidden || !items.length) { return; }

        if (e.key === 'ArrowDown')      { e.preventDefault(); highlight(cursor + 1); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); highlight(cursor - 1); }
        else if (e.key === 'Enter' && cursor >= 0) { e.preventDefault(); items[cursor].click(); }
    });

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target)) { close(); }
    });

    // Il suggerimento nel campo mostra il tasto giusto per la piattaforma.
    var kbdMod = box.querySelector('.ip-kbd-mod');
    if (kbdMod && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {
        kbdMod.textContent = '⌘';
    }

    document.addEventListener('keydown', function (e) {
        // Ctrl+K / Cmd+K: la convenzione delle command palette. A differenza
        // di "/" funziona anche da dentro un campo, perché la combinazione
        // non produce testo e quindi non c'è nulla da rubare a chi scrive.
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            input.focus();
            input.select();
            return;
        }
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
        var el = document.activeElement;
        // Non rubare "/" a chi sta scrivendo (compresi i campi di ricerca delle liste).
        if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable)) {
            return;
        }
        e.preventDefault();
        input.focus();
        input.select();
    });
})();

// Tipologia cliente (azienda / persona fisica / PA). Cambia le etichette e
// mostra i campi che hanno senso solo per un tipo: il cognome esiste per le
// persone fisiche, e il codice destinatario si chiama Codice Univoco Ufficio
// quando il cliente e' una pubblica amministrazione.
(function () {
    function initClientType() {
        var group = document.getElementById('ip-client-type');
        if (!group || group.dataset.ipClientTypeBound) { return; }
        group.dataset.ipClientTypeBound = '1';

        var help = document.getElementById('ip-sdi-help');
        var HELP = {
            company: "Sette caratteri assegnati dal gestore del cliente. Se non lo conosci lascia vuoto: verra\u2019 usato 0000000 e la fattura restera\u2019 nel cassetto fiscale.",
            person:  "Sette caratteri. Per un privato senza canale telematico lascia vuoto (0000000) oppure indica la PEC.",
            pa:      "Sei caratteri dell\u2019indice IPA. \u00c8 obbligatorio: senza, lo SdI scarta la fattura."
        };

        function current() {
            var checked = group.querySelector('input[name="client_type"]:checked');
            return checked ? checked.value : 'company';
        }

        function apply() {
            var type = current();

            group.querySelectorAll('.ip-type-choice__option').forEach(function (option) {
                option.classList.toggle('is-active', option.querySelector('input').checked);
            });

            // Etichette che cambiano nome secondo il tipo.
            document.querySelectorAll('[data-label-' + type + ']').forEach(function (label) {
                var text = label.getAttribute('data-label-' + type);
                if (text) { label.textContent = text; }
            });

            // Campi esclusivi di uno o piu' tipi: l'attributo vale anche come
            // lista "company,pa" (es. la Partita IVA esiste sia per le aziende
            // sia per le PA), quindi si confronta il tipo con ogni elemento
            // della lista e non con la stringa intera.
            document.querySelectorAll('[data-client-type-only]').forEach(function (block) {
                var allowed = (block.getAttribute('data-client-type-only') || '').split(',');
                block.classList.toggle('is-visible', allowed.indexOf(type) !== -1);
            });

            var code = document.getElementById('client_sdi_code');
            if (code) { code.maxLength = type === 'pa' ? 6 : 7; }
            if (help) { help.textContent = HELP[type] || ''; }
        }

        group.addEventListener('change', apply);
        apply();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClientType);
    } else {
        initClientType();
    }
    document.addEventListener('ip:page-ready', initClientType);
})();
