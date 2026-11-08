/**
 * InvoicePlane CI4 — Skeleton di caricamento (ip-skeleton.js)
 *
 * Tre integrazioni, nessuna dipendenza da jQuery:
 *
 * 1. Navigazione tra pagine: al click su un link o alla submit di un form
 *    (stessa origine) la pagina corrente resta a schermo; l'overlay
 *    #ip-page-skeleton compare solo se la pagina successiva non è ancora
 *    partita dopo SHOW_DELAY_MS. Prima compariva subito, e su una pagina
 *    veloce il lampo della finta headerbar+tabella sembrava un doppio
 *    caricamento a ogni click. La nuova pagina arriva già con l'overlay
 *    nascosto, quindi non serve alcuna pulizia esplicita: in caso di ritorno
 *    dalla cache (bfcache) pageshow lo nasconde di nuovo. I link e le form
 *    che restituiscono un file (allegati, XML, PDF, CSV) sono esclusi: il
 *    download non cambia pagina, quindi l'overlay non verrebbe mai tolto.
 *    Per gli altri casi in cui la navigazione non parte resta il timeout di
 *    sicurezza (SAFETY_MS).
 *
 * 2. Ricerca live sulle liste (moduli elenco con .ip-live-search[data-action]):
 *    durante la chiamata AJAX al partial filtrato, nel target della tabella
 *    entra una tabella skeleton; sparisce quando il partial sostituisce
 *    il contenuto (MutationObserver sul target).
 *
 * 3. Ricerca globale (topbar): righe skeleton nel pannello dei risultati
 *    mentre la risposta JSON è in volo.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
       1. Overlay di navigazione
       ------------------------------------------------------------------ */
    var NAV_FLAG = 'ip-nav-loading';

    // Quanto si aspetta prima di coprire il contenuto. L'attesa è deliberata:
    // finché il browser non ha la pagina nuova tiene a schermo quella vecchia,
    // che è già un feedback; l'overlay serve solo se il caricamento tarda.
    // Mostrarlo subito, con la finta headerbar e la tabella, faceva sembrare
    // che ogni click ricaricasse la pagina due volte.
    var SHOW_DELAY_MS = 400;
    var navShowTimer = null;

    // Rete di sicurezza: se la navigazione non parte davvero (download,
    // handler che annulla il click, richiesta mai completata) la pagina
    // resta questa e nessun evento rimuoverebbe l'overlay. Dopo questo
    // tempo lo togliamo comunque: meglio un skeleton che sparisce troppo
    // presto di uno che copre il contenuto finché non si ricarica.
    var SAFETY_MS = 12000;
    var navSafety = null;

    // Rotte che rispondono con Content-Disposition: attachment (allegati,
    // XML FatturaPA, PDF generati, CSV di esportazione, flussi RiBa). Il
    // browser scarica il file *senza* lasciare la pagina: non c'è nessuna
    // navigazione da coprire, e l'overlay resterebbe lì per sempre.
    var DOWNLOAD_PATHS = [
        /\/download(\/|$)/i,            // f24/download/12
        /\/download_xml(\/|$)/i,        // expenses/download_xml/7
        /\/get_file(\/|$)/i,            // upload/get_file/nome
        /\/generate_[a-z_]*(xml|pdf)(\/|$)/i, // invoices/generate_xml, generate_sumex_pdf, ...
        /\/export(\/|$)/i,              // finance/export/csv
        /\/exports\/run$/i,
        /\/riba\/generate$/i
    ];
    var DOWNLOAD_EXT = /\.(pdf|xml|p7m|csv|txt|zip|xlsx?|ics)$/i;

    function isDownloadUrl(url) {
        var u;
        try {
            u = new URL(url, window.location.href);
        } catch (e) {
            return false;
        }
        if (DOWNLOAD_EXT.test(u.pathname)) { return true; }
        // invoices/archive?download=file.pdf
        if (u.searchParams.get('download')) { return true; }
        for (var i = 0; i < DOWNLOAD_PATHS.length; i++) {
            if (DOWNLOAD_PATHS[i].test(u.pathname)) { return true; }
        }
        return false;
    }

    function pageOverlay() {
        return document.getElementById('ip-page-skeleton');
    }

    function setMainBusy(busy) {
        var main = document.getElementById('main-content');
        if (!main) { return; }
        if (busy) {
            main.setAttribute('aria-busy', 'true');
        } else {
            main.removeAttribute('aria-busy');
        }
    }

    // Marca la navigazione: alla prossima pagina il focus va sul contenuto
    // principale (vedi focusAfterNavigation). Va fatto subito, senza aspettare
    // l'overlay: il salto di focus serve anche quando la pagina è veloce.
    function markNavigation() {
        try { sessionStorage.setItem(NAV_FLAG, '1'); } catch (e) { /* storage off */ }
    }

    // Programma l'overlay, non lo mostra: se la pagina nuova arriva prima
    // dello scadere del tempo il timer muore con il documento e non si vede
    // nulla. Un nuovo click riprogramma l'attesa (vince l'ultima navigazione).
    function schedulePageSkeleton() {
        clearTimeout(navShowTimer);
        navShowTimer = setTimeout(function () {
            navShowTimer = null;
            showPageSkeleton();
        }, SHOW_DELAY_MS);
    }

    function cancelPageSkeleton() {
        clearTimeout(navShowTimer);
        navShowTimer = null;
    }

    function showPageSkeleton() {
        var el = pageOverlay();
        if (el) { el.hidden = false; }
        setMainBusy(true);
        clearTimeout(navSafety);
        navSafety = setTimeout(hidePageSkeleton, SAFETY_MS);
    }

    function hidePageSkeleton() {
        var el = pageOverlay();
        if (el) { el.hidden = true; }
        setMainBusy(false);
        clearTimeout(navSafety);
        navSafety = null;
    }

    // Dopo una navigazione lanciata dall'app, sposta il focus su
    // <main> (tabindex=-1): chi usa tastiera o screen reader riparte dal
    // contenuto, non dall'inizio della pagina.
    function focusAfterNavigation() {
        try {
            if (sessionStorage.getItem(NAV_FLAG) !== '1') { return; }
            sessionStorage.removeItem(NAV_FLAG);
        } catch (e) {
            return;
        }
        var main = document.getElementById('main-content');
        if (main) { main.focus(); }
    }

    function isSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    // Link "piani": stessa origine, senza modificatori (apri in scheda/nuova
    // finestra), senza target/_blank o download, non ancore o javascript:.
    function isPlainLink(a) {
        if (a.getAttribute('target') || a.hasAttribute('download')) { return false; }
        if (a.hasAttribute('data-no-skeleton')) { return false; }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) { return false; }
        if (!isSameOrigin(href)) { return false; }
        // Scaricare un allegato non cambia pagina: niente overlay.
        return !isDownloadUrl(href);
    }

    // Un giro a vuoto (setTimeout 0) prima di fidarsi: anche un handler
    // delegato può annullare il click, e un'azione annullata non deve
    // programmare né overlay né focus sulla pagina successiva.
    function afterClickIsConfirmed(e, next) {
        setTimeout(function () {
            if (e.defaultPrevented) { return; }
            markNavigation();
            next();
        }, 0);
    }

    // Bubble: gira dopo i handler della pagina; eventuali bersagli gestiti
    // altrove (conferma eliminazione) vanno esclusi a monte.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0) { return; }
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var a = e.target.closest('a');
        if (!a) { return; }
        // I link di conferma vengono preventDefault-ati da scripts.min.js
        // per aprire la modale: niente navigazione, niente skeleton.
        if (a.closest('[data-bs-toggle="confirm"]')) { return; }
        if (!isPlainLink(a)) { return; }
        afterClickIsConfirmed(e, schedulePageSkeleton);
    });

    // submit non fa bubble: serve la capture sul document.
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) { return; }
        var form = e.target;
        if (!form) { return; }
        if (form.hasAttribute('data-no-skeleton') || form.getAttribute('target')) { return; }
        var action = form.getAttribute('action');
        if (!action || action.charAt(0) === '#' || /^javascript:/i.test(action)) { return; }
        if (!isSameOrigin(action)) { return; }
        // Anche le form che restituiscono un file (esportazioni, flussi RiBa)
        // lasciano la pagina dov'è: nessuna navigazione da coprire.
        if (isDownloadUrl(action)) { return; }
        afterClickIsConfirmed(e, schedulePageSkeleton);
    }, true);

    // Ritorno dalla bfcache: la pagina era "viva" con l'overlay mostrato.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            cancelPageSkeleton();
            hidePageSkeleton();
        } else {
            // Nuova pagina (o primo caricamento): eventuale focus sul
            // contenuto e stato busy ripuliti.
            focusAfterNavigation();
        }
    });
    // Se la navigazione è stata annullata (es. submit gestito in AJAX), il
    // load della pagina corrente annulla l'attesa e rimuove un overlay rimasto.
    window.addEventListener('load', function () {
        cancelPageSkeleton();
        hidePageSkeleton();
    });

    /* ------------------------------------------------------------------
       Primitiva: tabella skeleton
       ------------------------------------------------------------------ */
    function skeletonBar(widthPct) {
        var s = document.createElement('span');
        s.className = 'ip-skeleton';
        s.style.setProperty('--w', widthPct + '%');
        return s;
    }

    // Testo di stato per screen reader: i placeholder sono decorativi, la
    // regione role=status annuncia solo questo.
    function statusText() {
        var t = document.createElement('span');
        t.className = 'visually-hidden';
        t.textContent = 'Caricamento…';
        return t;
    }

    function makeTableSkeleton(cols, rows) {
        var wrap = document.createElement('div');
        wrap.className = 'ip-skeleton-table ip-skeleton-table--standalone';
        wrap.setAttribute('role', 'status');
        wrap.appendChild(statusText());

        var head = document.createElement('div');
        head.className = 'ip-skeleton-table__head';
        for (var c = 0; c < cols; c++) { head.appendChild(skeletonBar(14)); }
        wrap.appendChild(head);

        for (var r = 0; r < rows; r++) {
            var row = document.createElement('div');
            row.className = 'ip-skeleton-table__row';
            // Larghezze deterministiche per cella: rotte diverse ma stabili.
            for (var c2 = 0; c2 < cols; c2++) {
                row.appendChild(skeletonBar(30 + ((c2 * 13 + r * 7) % 45)));
            }
            wrap.appendChild(row);
        }
        return wrap;
    }

    /* ------------------------------------------------------------------
       2. Ricerca live (filtri AJAX sulle liste)
       ------------------------------------------------------------------ */
    function bindLiveSearchSkeletons() {
        document.querySelectorAll('.ip-live-search[data-action]').forEach(function (box) {
            if (box.dataset.ipSkeletonBound) { return; }
            box.dataset.ipSkeletonBound = '1';

            var input = box.querySelector('input[name="filter_query"]');
            var targetSel = box.getAttribute('data-target');
            var target = targetSel ? document.querySelector(targetSel) : null;
            if (!input || !target) { return; }

            // Il campo di ricerca controlla la regione che aggiorna.
            if (targetSel && targetSel.charAt(0) === '#') {
                input.setAttribute('aria-controls', targetSel.slice(1));
            }

            var timer = null;
            var skeleton = null;
            var observer = null;
            var safety = null;

            function clearSkeleton() {
                if (skeleton && skeleton.parentNode) { skeleton.parentNode.removeChild(skeleton); }
                skeleton = null;
                if (safety) { clearTimeout(safety); safety = null; }
                target.removeAttribute('aria-busy');
            }

            // L'osservatore guarda solo la presenza del nostro skeleton: il
            // partial che arriva sostituisce innerHTML del target, quindi lo
            // skeleton esce dal DOM da solo.
            function ensureObserver() {
                if (observer) { return; }
                observer = new MutationObserver(function () {
                    if (skeleton && !target.contains(skeleton)) { clearSkeleton(); }
                });
                observer.observe(target, { childList: true, subtree: true });
            }

            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    if (input.value.trim() === '' || skeleton) { return; }
                    // aria-busy: la regione sta per essere aggiornata dalla
                    // risposta AJAX; rimosso quando il partial arriva.
                    target.setAttribute('aria-busy', 'true');
                    skeleton = makeTableSkeleton(6, 6);
                    target.insertBefore(skeleton, target.firstChild);
                    ensureObserver();
                    // Se il partial non arriva (errore di rete), lo skeleton
                    // non resta appeso per sempre.
                    safety = setTimeout(clearSkeleton, 8000);
                }, 300);
            });
        });
    }

    /* ------------------------------------------------------------------
       3. Ricerca globale (topbar)
       ------------------------------------------------------------------ */
    function bindGlobalSearchSkeleton() {
        var panel = document.getElementById('ip-global-search-results');
        var input = document.getElementById('ip-global-search-input');
        if (!panel || !input) { return; }

        var timer = null;
        var skeleton = null;
        var observer = null;
        var safety = null;

        function clearSkeleton() {
            if (skeleton && skeleton.parentNode) { skeleton.parentNode.removeChild(skeleton); }
            skeleton = null;
            if (safety) { clearTimeout(safety); safety = null; }
            input.removeAttribute('aria-busy');
        }

        function ensureObserver() {
            if (observer) { return; }
            observer = new MutationObserver(function () {
                if (skeleton && !panel.contains(skeleton)) { clearSkeleton(); }
            });
            observer.observe(panel, { childList: true, subtree: true });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (input.value.trim().length < 2 || skeleton) { return; }
                // aria-busy sul combobox mentre la risposta JSON è in volo.
                input.setAttribute('aria-busy', 'true');
                skeleton = document.createElement('div');
                skeleton.className = 'ip-skeleton--panel';
                skeleton.setAttribute('role', 'status');
                skeleton.appendChild(statusText());
                for (var i = 0; i < 3; i++) {
                    skeleton.appendChild(skeletonBar(45 + i * 12));
                }
                panel.appendChild(skeleton);
                panel.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                ensureObserver();
                safety = setTimeout(clearSkeleton, 8000);
            }, 220);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindLiveSearchSkeletons();
            bindGlobalSearchSkeleton();
        });
    } else {
        bindLiveSearchSkeletons();
        bindGlobalSearchSkeleton();
    }

    // Le liste rimontate dalla navigazione parziale hanno caselle nuove;
    // il dataset su ogni box evita il doppio binding su quelle già legate.
    document.addEventListener('ip:page-ready', bindLiveSearchSkeletons);

    // La navigazione parziale (ip-pjax.js) usa la stessa regola per non
    // intercettare un link che scarica un file invece di cambiare pagina.
    window.ipIsDownloadUrl = isDownloadUrl;
})();