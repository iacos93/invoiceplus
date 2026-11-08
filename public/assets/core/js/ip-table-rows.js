/*
 * Righe delle tabelle elenco cliccabili.
 *
 * Un click su una riga (al di fuori di link, pulsanti, form e menu a
 * tendina) porta alla pagina di dettaglio/modifica del record. L'URL è
 * ricavato, in ordine di priorità:
 *   1. dall'attributo data-row-url sulla <tr> (esplicito, se serve un
 *      bersaglio diverso dal primo link);
 *   2. dal primo <a> della riga che NON sta nel menu a tendina;
 *   3. dal primo <a> "vedi/modifica" nel menu a tendina (fallback per le
 *      tabelle — es. spese — i cui unici link stanno nel menu Opzioni).
 *
 * Così una sola regola copre tutte le tabelle ip-table-card-collapse senza
 * dover toccare ogni singola vista; il comportamento degrada con grazia
 * (riga non cliccabile) dove non si trova alcun URL valido.
 */
(function () {
    "use strict";

    function rowUrl(tr) {
        if (!tr) { return null; }

        if (tr.dataset && tr.dataset.rowUrl) {
            var u = tr.dataset.rowUrl;
            return (u && u !== "#") ? u : null;
        }

        // 2) primo link fuori dal menu a tendina
        var links = tr.querySelectorAll("td a, th a");
        for (var i = 0; i < links.length; i++) {
            var a = links[i];
            if (a.closest(".dropdown-menu")) { continue; }
            if (a.hasAttribute("data-no-row-click")) { continue; }
            var h = a.getAttribute("href");
            if (h && h !== "#" && h.indexOf("javascript:") !== 0) {
                return h;
            }
        }

        // 3) fallback: un link "vedi/modifica" nel menu a tendina
        var dd = tr.querySelectorAll(".dropdown-menu a");
        var fallback = null;
        for (var j = 0; j < dd.length; j++) {
            var d = dd[j];
            var dh = d.getAttribute("href");
            if (!dh || dh === "#" || dh.indexOf("javascript:") === 0) { continue; }
            if (/form\/|\/view\//.test(dh)) { return dh; }
            if (!fallback) { fallback = dh; }
        }
        return fallback;
    }

    function isInteractive(target) {
        return !!target.closest("a, button, form, input, select, textarea, [data-no-row-click]");
    }

    function markRows(root) {
        root = root || document;
        var rows = root.querySelectorAll(
            "table.ip-table-card-collapse tbody tr, table.ip-clickable-rows tbody tr"
        );
        rows.forEach(function (tr) {
            if (tr.classList.contains("empty-row")) { return; }
            if (rowUrl(tr)) {
                tr.classList.add("ip-clickable");
            } else {
                tr.classList.remove("ip-clickable");
            }
        });
    }

    document.addEventListener("click", function (e) {
        // Solo il click sinistro, senza modificatori (così Cmd/Ctrl+click
        // apre il link nel nuovo tab come da sempre).
        if (e.which !== 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        if (isInteractive(e.target)) { return; }

        var tr = e.target.closest("tr");
        if (!tr || !tr.classList.contains("ip-clickable")) { return; }

        var url = rowUrl(tr);
        if (url) {
            e.preventDefault();
            if (window.ipNavigate) {
                // La riga non è un <a>: la navigazione parziale va chiesta a mano.
                window.ipNavigate(url);
            } else {
                window.location.href = url;
            }
        }
    });

    document.addEventListener("DOMContentLoaded", function () {
        markRows(document);

        // Le righe filtrate via AJAX (ip-live-search) venivano ridisegnate
        // senza la classe: un MutationObserver le ri-marka non appena
        // ricompaiono nel DOM, così restano cliccabili anche dopo la ricerca.
        if (window.MutationObserver) {
            var observer = new MutationObserver(function (mutations) {
                var dirty = false;
                mutations.forEach(function (m) {
                    if (m.addedNodes && m.addedNodes.length) { dirty = true; }
                });
                if (dirty) { markRows(document); }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
})();
