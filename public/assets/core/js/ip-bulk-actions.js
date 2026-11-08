/*
 * Selezione multipla nelle liste documenti.
 *
 * La barra delle azioni compare solo quando c'è almeno una riga selezionata:
 * a riposo non occupa spazio e non offre comandi che non si possono usare.
 *
 * Il JavaScript qui fa solo tre cose — casella "tutte", conteggio, e
 * abilitazione della barra. Gli id viaggiano nel POST perché le caselle
 * dichiarano form="ip-bulk-form" nel markup: se questo script non parte, la
 * selezione funziona comunque (barra sempre visibile, invio corretto), si
 * perde solo il conteggio. Nessuna raccolta di id via JS, nessuna richiesta
 * asincrona.
 */
(function () {
    "use strict";

    var FORM = "ip-bulk-form";

    function boxes() {
        return Array.prototype.slice.call(document.querySelectorAll(".ip-bulk-check"));
    }

    function sync() {
        var all     = boxes();
        var checked = all.filter(function (c) { return c.checked; });
        var bar     = document.getElementById("ip-bulk-bar");
        var count   = document.getElementById("ip-bulk-count");
        var master  = document.getElementById("ip-bulk-all");

        if (bar) {
            bar.hidden = checked.length === 0;
            // La barra flotta sopra il fondo pagina: finché è visibile il
            // contenuto si allunga della sua altezza (--ip-bulk-bar-h, letta
            // da custom.css su body.ip-bulk-active) così pager e ultima riga
            // restano raggiungibili invece che coperti. L'altezza va misurata:
            // i bottoni vanno a capo e la barra non ha un'altezza fissa.
            document.body.classList.toggle("ip-bulk-active", checked.length > 0);
            if (checked.length > 0) {
                document.documentElement.style.setProperty("--ip-bulk-bar-h", bar.offsetHeight + "px");
            }
        }

        if (count) {
            // Il testo completo sta nel markup tradotto (data-template) così
            // la stringa resta nei file di lingua e non qui dentro.
            var tpl  = count.getAttribute("data-template") || "%s";
            var next = tpl.replace("%s", String(checked.length));

            // Si scrive solo se cambia davvero. Scrivere textContent sostituisce
            // il nodo di testo, cioè è una mutazione childList: con l'observer
            // qui sotto agganciato, una riscrittura incondizionata si
            // richiamava da sola a ogni giro e bloccava la pagina.
            if (count.textContent !== next) {
                count.textContent = next;
            }
        }

        if (master) {
            master.checked = all.length > 0 && checked.length === all.length;
            // Selezione parziale: lo stato indeterminato è l'unico modo per
            // dire "alcune sì, altre no" senza mentire in nessuna direzione.
            master.indeterminate = checked.length > 0 && checked.length < all.length;
        }
    }

    function init(root) {
        root = root || document;

        var master = root.querySelector("#ip-bulk-all");
        if (master && !master.dataset.ipReady) {
            master.dataset.ipReady = "1";
            master.addEventListener("change", function () {
                boxes().forEach(function (c) { c.checked = master.checked; });
                sync();
            });
        }

        root.querySelectorAll(".ip-bulk-check").forEach(function (c) {
            if (c.dataset.ipReady) { return; }
            c.dataset.ipReady = "1";
            if (!c.getAttribute("form")) { c.setAttribute("form", FORM); }
            c.addEventListener("change", sync);
        });

        var clear = root.querySelector("#ip-bulk-clear");
        if (clear && !clear.dataset.ipReady) {
            clear.dataset.ipReady = "1";
            clear.addEventListener("click", function () {
                boxes().forEach(function (c) { c.checked = false; });
                sync();
            });
        }

        sync();
    }

    var observedResults = null;

    function observeResults() {
        var results = document.getElementById("filter_results") || document.querySelector(".ip-table-card");
        if (!window.MutationObserver || !results || results === observedResults) { return; }
        observedResults = results;

        // Le righe filtrate dalla ricerca live vengono ridisegnate: le nuove
        // caselle vanno agganciate, e il conteggio rifatto perché la
        // selezione precedente può essere sparita dal DOM.
        //
        // L'observer guarda il contenitore dei risultati, non tutto il body:
        // deve reagire alle righe ridisegnate, non a ogni pannello che un
        // altro componente monta da qualche parte nella pagina.
        new MutationObserver(function (mutations) {
            var dirty = false;
            mutations.forEach(function (m) {
                if (m.addedNodes && m.addedNodes.length) { dirty = true; }
            });
            if (dirty) { init(document); }
        }).observe(results, { childList: true, subtree: true });
    }

    function boot() {
        if (!document.getElementById(FORM)) { return; }
        init(document);
        observeResults();
    }

    document.addEventListener("DOMContentLoaded", function () {
        boot();

        // Al ridimensionamento i bottoni ri-wrappano: l'altezza pubblicata
        // va riallineata, ma solo mentre la barra è effettivamente visibile.
        window.addEventListener("resize", function () {
            var bar = document.getElementById("ip-bulk-bar");
            if (bar && !bar.hidden) {
                document.documentElement.style.setProperty("--ip-bulk-bar-h", bar.offsetHeight + "px");
            }
        });
    });

    // Le liste rimontate dalla navigazione parziale hanno caselle nuove.
    document.addEventListener("ip:page-ready", boot);
})();
