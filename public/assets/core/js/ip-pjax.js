/**
 * InvoicePlane CI4 — Navigazione parziale (ip-pjax.js)
 *
 * Le pagine sono documenti completi renderizzati dal server. Questo script
 * intercetta i click sui link interni (GET, stessa origine), scarica la pagina
 * e ne sostituisce solo #main-content: topbar e sidebar restano gli stessi nodi
 * del DOM, quindi non si ricostruiscono a ogni click. Titolo, cronologia,
 * focus e stato attivo della sidebar vengono aggiornati di conseguenza.
 *
 * Cosa resta a carico completo (niente pjax):
 *  - form e submit (POST/GET): non vengono intercettati, quindi il browser fa
 *    la navigazione normale — serve per CSRF, redirect e upload;
 *  - download (PDF, XML, CSV...), link esterni, target/download;
 *  - errori, risposte non HTML e redirect al login: si torna a location.href,
 *    così l'errore o il login si vedono davvero.
 *
 * Gli script dentro #main-content vengono rieseguiti (i browser non eseguono
 * gli <script> inseriti via innerHTML). Per non accumulare listener su
 * document/window, le pagine li registrano con ipOn() (definita in
 * layout/base.php): sono legati a un AbortSignal che qui viene annullato prima
 * di montare la pagina nuova. Il resto dello stato di pagina (timer, pannelli
 * montati in <body>) viene ripulito in abortPage().
 */
(function () {
    'use strict';

    if (!window.fetch || !window.history || !window.history.pushState || !window.DOMParser) {
        return;
    }

    var MAIN_ID = 'main-content';
    var navToken = 0;
    var fetchController = null;
    var started = false;
    // Ultimo indirizzo effettivamente montato: serve a "annullare" il tasto
    // Indietro quando la pagina corrente ha modifiche non salvate.
    var lastUrl = window.location.href;

    function isSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function isDownload(url) {
        if (typeof window.ipIsDownloadUrl === 'function') {
            return window.ipIsDownloadUrl(url);
        }

        try {
            var u = new URL(url, window.location.href);
            if (/\.(pdf|xml|p7m|csv|txt|zip|xlsx?|ics)$/i.test(u.pathname)) {
                return true;
            }
            return !!u.searchParams.get('download');
        } catch (e) {
            return false;
        }
    }

    function isPlainLink(a) {
        if (!a || a.getAttribute('target') || a.hasAttribute('download')) {
            return false;
        }
        if (a.hasAttribute('data-no-pjax')) {
            return false;
        }
        // I link .ajax-loader mostrano il loader a tutta pagina di scripts.js
        // e si aspettano una navigazione completa che lo smonti.
        if (a.classList.contains('ajax-loader')) {
            return false;
        }

        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) {
            return false;
        }
        if (!isSameOrigin(href) || isDownload(href)) {
            return false;
        }

        var u = new URL(href, window.location.href);
        // Asset e upload non sono pagine: il pjax non li deve scaricare per
        // poi accorgersi che non c'è #main-content. Le rotte di sessione
        // (logout in primis) cambiano lo stato di autenticazione: meglio la
        // navigazione completa, che porta subito al login.
        if (/\/(assets|uploads)\//.test(u.pathname) || u.pathname.indexOf('/sessions/') === 0) {
            return false;
        }

        return true;
    }

    function isSamePage(url) {
        var u = new URL(url, window.location.href);
        return u.pathname === window.location.pathname && u.search === window.location.search;
    }

    function full(url) {
        window.location.href = url;
    }

    /* ------------------------------------------------------------------
       Stato di pagina: listener globali, pannelli montati in <body>, modali
       ------------------------------------------------------------------ */

    function abortPage() {
        if (window.__ipPageAbort) {
            try { window.__ipPageAbort.abort(); } catch (e) { /* già annullato */ }
        }
        window.__ipPageAbort = window.AbortController ? new AbortController() : null;
        window.ipPageSignal = window.__ipPageAbort ? window.__ipPageAbort.signal : undefined;
        window.ipBeforeNavigate = null;

        // Elementi che le pagine montano fuori da #main-content e che non
        // sparirebbero con il contenuto: popover del calendario e toast MCP.
        Array.prototype.forEach.call(document.querySelectorAll('.ip-calendar-popover'), function (el) {
            el.remove();
        });
        var ghostToast = document.querySelector('body > #ip-toast-container');
        if (ghostToast) {
            ghostToast.remove();
        }

        closeModals();

        // La ricerca globale vive in topbar: se era aperta, non deve restare
        // sopra la pagina nuova.
        var searchPanel = document.getElementById('ip-global-search-results');
        if (searchPanel) {
            searchPanel.hidden = true;
        }
        var searchInput = document.getElementById('ip-global-search-input');
        if (searchInput) {
            searchInput.setAttribute('aria-expanded', 'false');
        }
    }

    // IPSelect e IPDatepicker montano i pannelli in <body> (portalRoot). Quelli
    // del contenuto appena smontato resterebbero lì per sempre: si rimuovono
    // dopo il replace, quando i loro controlli non ci sono più. I pannelli dei
    // select della sidebar restano perché il controllo esiste ancora.
    function cleanupPortals() {
        Array.prototype.forEach.call(document.querySelectorAll('body > .ip-select-panel'), function (panel) {
            if (!panel.id || !document.querySelector('[aria-controls="' + panel.id + '"]')) {
                panel.remove();
            }
        });
        Array.prototype.forEach.call(document.querySelectorAll('body > .ip-dp'), function (panel) {
            panel.remove();
        });
    }

    function closeModals() {        Array.prototype.forEach.call(document.querySelectorAll('.modal.show'), function (el) {
            var instance = window.bootstrap && window.bootstrap.Modal
                ? window.bootstrap.Modal.getInstance(el)
                : null;
            if (instance) {
                instance.hide();
                // La pagina nuova è già pronta: non si aspetta la transizione
                // di chiusura, che lascerebbe la modale sopra il contenuto.
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                return;
            }
            el.classList.remove('show');
            el.style.display = 'none';
            el.setAttribute('aria-hidden', 'true');
        });

        Array.prototype.forEach.call(document.querySelectorAll('.modal-backdrop'), function (el) {
            el.remove();
        });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');

        // La barra delle azioni di gruppo vive nel contenuto: se si arriva da
        // una lista con selezioni, la classe che allunga il fondo pagina non
        // deve restare anche sulla pagina nuova.
        document.body.classList.remove('ip-bulk-active');
        document.documentElement.style.removeProperty('--ip-bulk-bar-h');

        var placeholder = document.getElementById('modal-placeholder');
        if (placeholder) {
            placeholder.innerHTML = '';
        }
    }

    /* ------------------------------------------------------------------
       Sidebar: stessa regola di nav_item_state() (ip_helpers.php)
       ------------------------------------------------------------------ */

    function segments(path) {
        return path.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
    }

    // Le URL possono avere o non avere index.php a seconda del rewrite. La
    // regola del server (getRoutePath) lo toglie; qui lo si toglie da entrambi
    // i lati del confronto.
    function routePath(path) {
        return path.replace(/\/index\.php(?=\/|$)/, '');
    }

    function matches(current, route, exact) {
        var c = segments(current);
        var r = segments(route);

        if (exact) {
            return c.length === r.length && r.every(function (seg, i) { return c[i] === seg; });
        }
        if (r.length > c.length) {
            return false;
        }
        return r.every(function (seg, i) { return c[i] === seg; });
    }

    function readClosedNav() {
        try {
            return JSON.parse(window.localStorage.getItem('ip-nav-closed') || '[]');
        } catch (e) {
            return [];
        }
    }

    function scrollToCurrentNav() {
        var scroller = document.querySelector('#ip-sidebar .ip-sidebar-scroll');
        if (!scroller) {
            return;
        }
        var current = scroller.querySelector('.ip-nav-link.is-current, .ip-nav-sub-link.active');
        if (!current) {
            return;
        }
        var rect = current.getBoundingClientRect();
        var view = scroller.getBoundingClientRect();
        if (rect.top < view.top || rect.bottom > view.bottom) {
            scroller.scrollTop += rect.top - view.top - 80;
        }
    }

    function updateSidebar(url) {
        var current = routePath(new URL(url, window.location.href).pathname);
        var closed = readClosedNav();

        Array.prototype.forEach.call(document.querySelectorAll('#ip-sidebar .ip-nav-item'), function (item) {
            var link = item.querySelector('.ip-nav-link');
            if (!link) {
                return;
            }

            var parentPath = routePath(new URL(link.href, window.location.href).pathname);
            var panel = item.querySelector('.ip-nav-sub');
            var toggle = item.querySelector('[data-nav-toggle]');
            var labelEl = link.querySelector('span');
            var label = labelEl ? labelEl.textContent.trim() : '';

            var subs = [];
            if (panel) {
                Array.prototype.forEach.call(panel.querySelectorAll('.ip-nav-sub-link'), function (a) {
                    subs.push({
                        el: a,
                        path: routePath(new URL(a.href, window.location.href).pathname),
                    });
                });
            }

            var open = matches(current, parentPath, false);
            var active = null;
            var depth = -1;

            subs.forEach(function (c) {
                if (!matches(current, c.path, false)) {
                    return;
                }
                open = true;
                var d = segments(c.path).length;
                if (d > depth) {
                    depth = d;
                    active = c;
                }
            });

            var here = open && active === null;

            item.classList.toggle('active', open);
            link.classList.toggle('is-current', here);
            if (here) {
                link.setAttribute('aria-current', matches(current, parentPath, true) ? 'page' : 'true');
            } else {
                link.removeAttribute('aria-current');
            }

            subs.forEach(function (c) {
                var on = active !== null && c.el === active.el;
                c.el.classList.toggle('active', on);
                if (on) {
                    c.el.setAttribute('aria-current', matches(current, c.path, true) ? 'page' : 'true');
                } else {
                    c.el.removeAttribute('aria-current');
                }
            });

            if (panel && toggle) {
                // Una sezione chiusa a mano resta chiusa anche se è quella
                // corrente: è la stessa scelta del caricamento completo.
                var manuallyClosed = label !== '' && closed.indexOf(label) !== -1;
                var show = open && !manuallyClosed;
                panel.hidden = !show;
                toggle.setAttribute('aria-expanded', show ? 'true' : 'false');

                if (manuallyClosed && active !== null) {
                    // La sotto-voce attiva è nascosta: il "sei qui" resta sulla
                    // voce madre, come fa il toggle al caricamento completo.
                    link.classList.add('is-current');
                    link.setAttribute('aria-current', 'true');
                }
            }
        });

        scrollToCurrentNav();
    }

    /* ------------------------------------------------------------------
       Script della pagina
       ------------------------------------------------------------------ */

    function runScript(old) {
        if (old.hasAttribute('data-pjax-skip') || old.closest('template')) {
            old.remove();
            return Promise.resolve();
        }

        var script = document.createElement('script');
        for (var i = 0; i < old.attributes.length; i++) {
            script.setAttribute(old.attributes[i].name, old.attributes[i].value);
        }
        script.text = old.textContent;

        if (old.src) {
            // Uno script esterno va caricato prima di eseguire gli inline che
            // potrebbero dipenderne; l'ordine dei <script> nella pagina è
            // conservato dalla catena. Il tag si toglie dopo l'esecuzione:
            // lasciarlo in <head> lo accumulerebbe a ogni visita.
            return new Promise(function (resolve) {
                function done() {
                    if (script.parentNode) {
                        script.remove();
                    }
                    resolve();
                }
                script.onload = done;
                script.onerror = done;
                document.head.appendChild(script);
                old.remove();
            });
        }

        // Un <script> creato e inserito nel documento viene eseguito subito.
        if (old.parentNode) {
            old.parentNode.replaceChild(script, old);
        } else {
            document.body.appendChild(script);
        }
        return Promise.resolve();
    }

    function runScripts(container) {
        var scripts = Array.prototype.slice.call(container.querySelectorAll('script'));
        var chain = Promise.resolve();

        scripts.forEach(function (old) {
            chain = chain.then(function () { return runScript(old); });
        });

        return chain;
    }

    /* ------------------------------------------------------------------
       Componenti e layout dopo il cambio di pagina
       ------------------------------------------------------------------ */

    function initTooltips() {
        if (!window.bootstrap || !window.bootstrap.Tooltip) {
            return;
        }
        Array.prototype.forEach.call(
            document.querySelectorAll('#main-content .tip, #main-content [data-bs-toggle="tooltip"]'),
            function (el) {
                if (!window.bootstrap.Tooltip.getInstance(el)) {
                    new window.bootstrap.Tooltip(el);
                }
            }
        );
    }

    // La base misura l'altezza reale di topbar/headerbar su window.resize: un
    // evento dopo lo swap riallinea --ip-headerbar-height (thead sticky).
    function syncLayout() {
        window.dispatchEvent(new Event('resize'));
    }

    // I due form della sidebar (azienda e anno) hanno un campo "back" per
    // tornare alla pagina da cui si è cambiato contesto. Sono fuori da
    // #main-content, quindi il server non li ridisegna: senza questo, dopo una
    // navigazione parziale il "back" punterebbe ancora alla pagina precedente.
    function syncSidebarContext(url) {
        Array.prototype.forEach.call(
            document.querySelectorAll('#ip-sidebar input[name="back"]'),
            function (input) {
                input.value = url;
            }
        );
    }

    function scrollAfterRender(saved) {        if (typeof saved === 'number') {
            window.scrollTo(0, saved);
            return;
        }

        var hash = window.location.hash;
        if (hash.length > 1) {
            var target = document.getElementById(hash.slice(1));
            if (target) {
                target.scrollIntoView();
                return;
            }
        }
        window.scrollTo(0, 0);
    }

    /* ------------------------------------------------------------------
       Navigazione
       ------------------------------------------------------------------ */

    function setBusy(busy) {
        var main = document.getElementById(MAIN_ID);
        if (!main) {
            return;
        }
        if (busy) {
            main.setAttribute('aria-busy', 'true');
        } else {
            main.removeAttribute('aria-busy');
        }
    }

    function swap(doc, finalUrl, opts) {
        var oldMain = document.getElementById(MAIN_ID);
        var newMain = doc.getElementById(MAIN_ID);

        if (!oldMain || !newMain) {
            full(finalUrl);
            return;
        }

        if (opts.push) {
            // Salva lo scroll della pagina che si sta lasciando: al ritorno
            // con il tasto Indietro si torna dove si era.
            history.replaceState(
                { pjax: true, scroll: window.scrollY },
                '',
                window.location.href
            );
        }

        abortPage();

        document.title = doc.title.trim();
        if (doc.documentElement.lang) {
            document.documentElement.lang = doc.documentElement.lang;
        }

        var imported = document.importNode(newMain, true);
        oldMain.parentNode.replaceChild(imported, oldMain);
        cleanupPortals();

        updateSidebar(finalUrl);
        initTooltips();

        if (opts.push) {
            // Un link che il server reindirizza alla pagina corrente (es.
            // "Clienti" su /clients/status/active) non deve creare una voce
            // di cronologia in più con lo stesso indirizzo.
            var currentUrl = new URL(window.location.href);
            var nextUrl    = new URL(finalUrl, window.location.href);
            var sameUrl    = currentUrl.pathname === nextUrl.pathname
                && currentUrl.search === nextUrl.search
                && currentUrl.hash === nextUrl.hash;

            if (!sameUrl) {
                history.pushState({ pjax: true, scroll: 0 }, '', finalUrl);
            }
        }

        syncSidebarContext(window.location.href);
        lastUrl = window.location.href;
        setBusy(false);

        runScripts(imported).then(function () {
            var main = document.getElementById(MAIN_ID);
            if (main) {
                try {
                    main.focus({ preventScroll: true });
                } catch (e) {
                    main.focus();
                }
            }

            scrollAfterRender(opts.scroll);
            syncLayout();
            initTooltips();
            document.dispatchEvent(new CustomEvent('ip:page-ready', { detail: { url: finalUrl } }));
        });
    }

    function navigate(url, opts) {
        opts = opts || {};

        var token = ++navToken;
        if (fetchController) {
            fetchController.abort();
        }
        fetchController = window.AbortController ? new AbortController() : null;
        var signal = fetchController ? fetchController.signal : undefined;

        setBusy(true);

        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
            signal: signal,
        })
            .then(function (response) {
                if (token !== navToken) {
                    return null;
                }

                var type = response.headers.get('content-type') || '';
                if (!response.ok || type.indexOf('text/html') === -1) {
                    full(url);
                    return null;
                }

                var finalUrl = response.url || url;
                if (!isSameOrigin(finalUrl)) {
                    full(finalUrl);
                    return null;
                }

                // fetch non manda il frammento al server e response.url non lo
                // riporta: senza questo, /settings#calendar_feed_url arrivava
                // come /settings e lo scroll all'ancora si perdeva.
                var requestedHash = new URL(url, window.location.href).hash;
                if (requestedHash && !new URL(finalUrl, window.location.href).hash) {
                    finalUrl += requestedHash;
                }

                return response.text().then(function (html) {
                    return token === navToken ? { html: html, url: finalUrl } : null;
                });
            })
            .then(function (page) {
                if (!page) {
                    return;
                }

                var doc = new DOMParser().parseFromString(page.html, 'text/html');
                // Login scaduto, errore o layout diverso: niente shell interna.
                if (!doc.querySelector('#main-content') || !doc.querySelector('#ip-sidebar')) {
                    full(page.url);
                    return;
                }
                if (token !== navToken) {
                    return;
                }

                swap(doc, page.url, opts);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                setBusy(false);
                full(url);
            });
    }

    /* ------------------------------------------------------------------
       Ingresso
       ------------------------------------------------------------------ */

    function initHistory() {
        lastUrl = window.location.href;

        if (!history.state || !history.state.pjax) {
            history.replaceState({ pjax: true, scroll: window.scrollY }, '', window.location.href);
        }

        window.addEventListener('popstate', function (event) {
            // Le voci create prima del pjax o da un'ancora non hanno il nostro
            // stato: per quelle il browser fa il suo comportamento normale.
            if (!event.state || !event.state.pjax) {
                return;
            }

            // Indietro non emette beforeunload: la stessa domanda di
            // ipBeforeNavigate vale anche qui. Se si annulla, si rimette
            // l'indirizzo della pagina che si stava lasciando (il contenuto
            // è ancora quello, non è stato smontato).
            if (typeof window.ipBeforeNavigate === 'function' && window.ipBeforeNavigate() === false) {
                history.pushState({ pjax: true, scroll: window.scrollY }, '', lastUrl);
                return;
            }

            navigate(window.location.href, { push: false, scroll: event.state.scroll });
        });
    }

    function init() {
        if (started) {
            return;
        }
        started = true;

        initHistory();

        // Anche un'ancora nativa cambia l'indirizzo senza passare da swap():
        // il "back" dei form di contesto deve seguirla.
        window.addEventListener('hashchange', function () {
            lastUrl = window.location.href;
            syncSidebarContext(window.location.href);
        });

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0) {
                return;
            }
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var link = event.target.closest ? event.target.closest('a') : null;
            if (!link || !isPlainLink(link)) {
                return;
            }
            // I link di conferma aprono la modale e non navigano.
            if (link.closest('[data-bs-toggle="confirm"]')) {
                return;
            }

            var href = link.href;
            if (isSamePage(href)) {
                // Stessa pagina: un'ancora diversa mantiene lo scroll nativo;
                // se è proprio questa pagina (es. click sulla voce attiva del
                // menu) non si ricarica nulla. Il preventDefault serve anche a
                // dire a ip-skeleton.js che non c'è una navigazione da coprire.
                event.preventDefault();

                var targetHash = new URL(href, window.location.href).hash;
                if (targetHash && targetHash !== window.location.hash) {
                    window.location.hash = targetHash;
                }
                return;
            }

            event.preventDefault();

            // Modifiche non salvate (Impostazioni): la pagina può fermare la
            // navigazione. È il corrispettivo pjax di beforeunload.
            if (typeof window.ipBeforeNavigate === 'function' && window.ipBeforeNavigate() === false) {
                return;
            }

            navigate(href, { push: true });
        });

        // Form GET (filtri, ricerche): stessa navigazione parziale, senza
        // toccare i POST — quelli restano al browser perché hanno CSRF,
        // redirect e upload da gestire. Il submit fa bubble, quindi qui si è
        // già visto l'eventuale preventDefault di un handler di pagina.
        document.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }

            var form = event.target;
            if (!form || (form.method || 'get').toLowerCase() !== 'get') {
                return;
            }
            if (form.hasAttribute('data-no-pjax') || form.getAttribute('target')) {
                return;
            }
            if ((form.getAttribute('enctype') || '').indexOf('multipart') !== -1) {
                return;
            }

            var action = form.getAttribute('action') || window.location.href;
            if (!isSameOrigin(action) || isDownload(action)) {
                return;
            }

            event.preventDefault();

            if (typeof window.ipBeforeNavigate === 'function' && window.ipBeforeNavigate() === false) {
                return;
            }

            var url = new URL(action, window.location.href);
            var data;
            try {
                data = event.submitter ? new FormData(form, event.submitter) : new FormData(form);
            } catch (e) {
                data = new FormData(form);
            }

            var params = new URLSearchParams();
            data.forEach(function (value, key) {
                if (typeof value === 'string') {
                    params.append(key, value);
                }
            });
            url.search = params.toString();

            navigate(url.href, { push: true });
        });
    }

    // API per le pagine che navigano da codice (es. righe cliccabili della
    // bacheca) invece che da un <a>.
    window.ipNavigate = function (url) {
        if (!isSameOrigin(url) || isDownload(url)) {
            full(url);
            return;
        }
        if (typeof window.ipBeforeNavigate === 'function' && window.ipBeforeNavigate() === false) {
            return;
        }
        navigate(url, { push: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
