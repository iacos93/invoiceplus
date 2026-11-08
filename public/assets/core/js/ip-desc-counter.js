/*
 * Contatore caratteri per il campo descrizione delle righe fattura/preventivo.
 *
 * Il cap è 1000 perché la Descrizione della FatturaPA è un String1000Type:
 * superato quel limite l'XML viene scartato dallo SdI. Meglio mostrare il
 * conteggio mentre si digita (e bloccare l'invio oltre il limite via
 * maxlength) anziché scoprirlo al momento della trasmissione.
 *
 * Funziona sia sulle righe già presenti sia su quelle clonate
 * dinamicamente (il template di document_create_form e le righe aggiunte
 * via AJAX), grazie a un MutationObserver che inizializza i nuovi campi
 * non appena compaiono nel DOM.
 */
(function () {
    "use strict";

    var DEFAULT_MAX = 1000;

    function fieldMax(el) {
        var m = parseInt(el.getAttribute("maxlength"), 10);
        return (isNaN(m) || m <= 0) ? DEFAULT_MAX : m;
    }

    function ensureCounter(el) {
        if (el.dataset.descCounter === "1") { return; }
        el.dataset.descCounter = "1";

        // Avvolge il campo in uno span così il contatore resta sempre sotto il
        // campo, anche quando il campo è figlio di un flex (la riga "aggiungi
        // riga"): senza il wrapper, il <small> diverrebbe a sua volta un flex
        // item affiancato anziché andare a capo.
        var wrap = document.createElement("span");
        wrap.className = "ip-desc-wrap";
        el.parentNode.insertBefore(wrap, el);
        wrap.appendChild(el);

        var counter = document.createElement("small");
        counter.className = "ip-desc-counter";
        wrap.appendChild(counter);

        function update() {
            var len = el.value.length;
            var max = fieldMax(el);
            counter.textContent = len + " / " + max;
            counter.classList.toggle("ip-desc-counter--full", len >= max);
        }

        el.addEventListener("input", update);
        update();
    }

    function init(root) {
        root = root || document;
        root.querySelectorAll(".ip-desc-counter-field").forEach(ensureCounter);
    }

    document.addEventListener("DOMContentLoaded", function () {
        init(document);

        if (window.MutationObserver) {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType !== 1) { return; }
                        if (node.matches && node.matches(".ip-desc-counter-field")) {
                            ensureCounter(node);
                        }
                        if (node.querySelectorAll) {
                            node.querySelectorAll(".ip-desc-counter-field").forEach(ensureCounter);
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
})();
