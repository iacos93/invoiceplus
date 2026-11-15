"use strict";

/*
 * Comportamenti del guscio (topbar, sidebar, tema, conferme).
 *
 * Stavano in blocchi <script> dentro layout/base.php: ~9 KB di codice identico
 * su ogni pagina, spedito con l'HTML e mai messo in cache, quindi riscaricato a
 * ogni navigazione completa. Qui viaggiano dentro ip-app.min.js, che il browser
 * tiene in cache (e il service worker serve anche offline).
 *
 * Restano inline nel layout le tre cose che devono girare *durante* il parsing,
 * altrimenti si vedono: il ripristino dello scroll della colonna (dopo il
 * markup, prima del primo disegno), la misura dell'altezza della topbar e lo
 * stato chiuso/aperto delle sezioni. Spostarle qui vorrebbe dire disegnare la
 * pagina con i valori sbagliati e correggerla dopo, con uno scatto visibile.
 */

// Tooltip di Bootstrap 5: inizializza gli elementi .tip e quelli con
// data-bs-toggle="tooltip". Gira a documento pronto: gli elementi del
// contenuto esistono, compresi quelli arrivati con la navigazione parziale.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tip, [data-bs-toggle="tooltip"]').forEach(function (el) {
        if (window.bootstrap) { new bootstrap.Tooltip(el); }
    });
});

// Cambio scheda: riporta la lettura all'inizio del pannello. Senza, cambiando
// scheda da fondo pagina si atterrava in mezzo al nuovo pannello, senza intro
// né contesto. Due insidie, entrambe gestite: Bootstrap 5.3 imposta
// html { scroll-behavior: smooth } (scroll animati) e il plugin Tab aggiorna
// l'hash della URL dopo ogni cambio scheda — il browser fa uno scroll di
// frammento verso l'ancora #pannello, che per un pannello più alto del viewport
// finisce allineato al bordo basso: l'intro sparisce sotto lo stack sticky
// (navbar + headerbar + barra schede). Il metodo del plugin che imposta l'hash è
// inlined nel bundle minificato, quindi non si può togliere alla radice: si
// lascia partire lo scroll e lo si azzera non appena si muove dal bersaglio
// esplicito (posizione del pannello meno l'altezza dello stack) con uno
// scrollTo istantaneo.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', function () {
            var pane = document.querySelector(this.getAttribute('href'));
            if (!pane) { return; }
            var y = pane.getBoundingClientRect().top + window.scrollY;
            var stack = 0;
            var headerbar = document.getElementById('headerbar');
            if (headerbar) { stack += headerbar.offsetHeight; }
            var tabs = document.querySelector('.ip-tabs');
            if (tabs) { stack += tabs.offsetHeight + 10; }
            var navbar = parseInt(document.documentElement.style.getPropertyValue('--ip-navbar-height'), 10) || 56;
            var target = y - navbar - stack - 12;
            var cancel = function () {
                if (Math.abs(window.scrollY - target) < 4) { return; }
                window.scrollTo({ top: target, behavior: 'instant' });
            };
            window.scrollTo({ top: target, behavior: 'instant' });
            document.addEventListener('scroll', cancel);
            setTimeout(function () {
                document.removeEventListener('scroll', cancel);
                cancel();
            }, 600);
        });
    });
});

// Sidebar off-canvas su mobile: toggle con aria-expanded, backdrop, chiusura su
// Escape o click sul backdrop.
(function () {
    var toggle = document.getElementById('ip-sidebar-toggle');
    var sidebar = document.getElementById('ip-sidebar');
    var backdrop = document.getElementById('ip-sidebar-backdrop');
    if (!toggle || !sidebar || !backdrop) { return; }

    function setOpen(open) {
        document.body.classList.toggle('ip-sidebar-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        backdrop.hidden = !open;
        if (open) { toggle.blur(); }
    }

    toggle.addEventListener('click', function () {
        setOpen(!document.body.classList.contains('ip-sidebar-open'));
    });
    backdrop.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('ip-sidebar-open')) {
            setOpen(false);
        }
    });
})();

// Toggle tema chiaro/scuro — vedi layout/theme_head.php per l'applicazione
// anticipata e il fallback su prefers-color-scheme.
(function () {
    var btn = document.getElementById('ip-theme-toggle');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var root = document.documentElement;
        var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
        root.dataset.theme = next;
        root.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('ip-theme', next); } catch (e) {}
        // Transizione morbida: abilita le transizioni solo per la durata del
        // cambio, poi leva la classe cosi' il resto dell'app resta senza
        // transizioni (il caricamento iniziale resta istantaneo).
        // La guardia prefers-reduced-motion in custom.css la neutralizza.
        root.classList.add('ip-theme-animating');
        if (window.__ipThemeAnim) { clearTimeout(window.__ipThemeAnim); }
        window.__ipThemeAnim = setTimeout(function () {
            root.classList.remove('ip-theme-animating');
        }, 300);
    });
})();

// Delegato conferma — un solo listener per tutti i bottoni che chiedono
// conferma. Il bottone dichiara cosa mostrare e cosa fare con attributi data-:
//   data-bs-toggle="confirm"
//   data-confirm-title="Elimina la fattura"
//   data-confirm-body="L'operazione non si può annullare."
//   data-confirm-ok="Elimina"
//   data-confirm-danger="1"        (bottone rosso, icona di avviso)
//   data-confirm-callback="fnName" (funzione globale da chiamare al posto del submit)
//   data-confirm-action="url"      (invia la form a questo indirizzo invece che
//                                   al suo action: cosi' una sola <form> per riga
//                                   serve piu' azioni — preferito, elimina, SdI)
(function () {
    var modalEl = document.getElementById('ip-confirm-modal');
    var modal = null;
    var pendingEl = null;

    function ensureModal() {
        if (modal || !modalEl) return modal;
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
        modal = new bootstrap.Modal(modalEl);
        document.getElementById('ip-confirm-ok').addEventListener('click', onOk);
        return modal;
    }

    function show(el) {
        pendingEl = el;
        var m = ensureModal();
        if (!m) { return; }

        var title = (el.dataset.confirmTitle || 'Conferma');
        var body  = (el.dataset.confirmBody  || '');
        var ok    = (el.dataset.confirmOk     || 'Conferma');
        var danger = (el.dataset.confirmDanger === '1' || el.dataset.confirmDanger === 'true');

        document.getElementById('ip-confirm-modal-title').textContent = title;
        document.getElementById('ip-confirm-modal-body').textContent = body;
        var okBtn = document.getElementById('ip-confirm-ok');
        okBtn.querySelector('.ip-confirm-ok-label').textContent = ok;
        okBtn.className = danger ? 'btn btn-danger' : 'btn btn-primary';
        okBtn.querySelector('i').className = danger ? 'fa fa-exclamation-triangle' : 'fa fa-check';
        var modalIcon = document.querySelector('#ip-confirm-modal .confirm-modal-icon');
        if (modalIcon) {
            modalIcon.className = 'fa ' + (danger ? 'fa-exclamation-triangle confirm-modal-icon confirm-modal-icon-danger'
                                                    : 'fa-question-circle confirm-modal-icon');
        }
        m.show();
    }

    // Un submitter valido per requestSubmit() e' solo un input type=submit/image
    // o un <button> il cui type e' "submit" (default se l'attributo manca). Ogni
    // bottone "Elimina" dell'app e' invece type="button" apposta, per non
    // inviare il form al primo click e lasciare il tempo alla conferma:
    // passato come submitter, il browser lo rifiuta e requestSubmit lancia un
    // TypeError, mai catturato. Il form quindi non partiva mai: nessuna conferma
    // di eliminazione, in nessuna schermata dell'app, arrivava a effetto.
    function isSubmitButton(el) {
        if (!el) { return false; }
        if (el.tagName === 'BUTTON') { return (el.getAttribute('type') || 'submit').toLowerCase() === 'submit'; }
        if (el.tagName === 'INPUT') {
            var t = (el.getAttribute('type') || '').toLowerCase();
            return t === 'submit' || t === 'image';
        }
        return false;
    }

    function onOk() {
        if (!pendingEl) return;
        var el = pendingEl;
        pendingEl = null;
        var cb = el.dataset.confirmCallback;
        if (cb && typeof window[cb] === 'function') { window[cb](el); return; }
        if (el.tagName === 'A' && el.getAttribute('href') && el.getAttribute('href') !== '#') {
            window.location.href = el.getAttribute('href'); return;
        }
        // el.form (non solo el.closest('form')): un bottone puo' puntare con
        // l'attributo HTML5 form="..." a un <form> altrove nel DOM, non a un
        // antenato — closest('form') non lo troverebbe mai, quindi il click su
        // "conferma" non invierebbe nulla. .form risolve anche quel caso.
        var form = el.form || el.closest('form');
        if (!form) { return; }
        // Un'azione diversa da quella della form (data-confirm-action): le liste
        // hanno una sola <form> per riga e i bottoni che non sono il preferito
        // puntano qui. L'indirizzo va nel formaction di un submitter creato al
        // volo, NON nell'action della form: quella deve restare l'azione di
        // partenza, altrimenti dopo un «Elimina» annullato il click successivo
        // sul preferito scriverebbe nell'indirizzo rimasto appiccicato
        // (eliminazione al posto del preferito).
        var action = el.dataset.confirmAction;
        if (action && form.requestSubmit) {
            // Se un invio precedente non è arrivato (annullato o fermato da uno
            // script) il suo bottone è ancora lì: via prima di aggiungerne uno.
            form.querySelectorAll('.ip-confirm-submit').forEach(function (b) { b.remove(); });
            var submitter = document.createElement('button');
            submitter.type = 'submit';
            submitter.className = 'ip-confirm-submit';
            submitter.hidden = true;
            submitter.setAttribute('formaction', action);
            form.appendChild(submitter);
            form.requestSubmit(submitter);
            return;
        }
        if (action) { form.setAttribute('action', action); }
        if (!form.requestSubmit) { form.submit(); return; }
        // requestSubmit(el) solo se el e' un submitter valido: rispetta
        // l'eventuale formaction diversa dall'action del form (i bottoni
        // "Elimina riga", type=submit). Per tutti gli altri (type=button) si
        // invia senza submitter — niente eccezione, e formaction non c'e'
        // comunque su quei bottoni.
        if (isSubmitButton(el)) { form.requestSubmit(el); } else { form.requestSubmit(); }
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-bs-toggle="confirm"]');
        if (!el) return;
        e.preventDefault();
        e.stopPropagation();
        show(el);
    });
})();
