/*
 * Drag & drop per riordinare le righe di fatture e preventivi.
 *
 * Ogni riga ha un pulsante "maniglia" (classe .ip-item-row__handle): solo da
 * lì parte il trascinamento (la riga diventa draggable al mousedown sulla
 * maniglia, così si continua a poter selezionare il testo nelle textarea
 * senza innescare un drag). Durante il trascinamento la riga si sposta in
 * tempo reale nel DOM; al rilascio, se il <tbody> dichiara data-reorder-url,
 * si invia l'elenco ordinato degli item_id al server che riscrive item_order.
 *
 * Nei form di creazione (document_create_form) il <tbody> non ha data-reorder-url:
 * le righe non sono ancora salvate, ma l'ordine nel DOM è quello che il salvataggio
 * usa, quindi basta riordinare visivamente — nessun POST.
 *
 * I listener sono delegati sul <tbody>, così funzionano anche sulle righe
 * aggiunte in un secondo momento (clone del template, righe ricaricate).
 */
(function () {
    "use strict";

    var dragRow = null;

    // Il gruppo (?:...) non cattura: il valore del cookie è m[1], non m[2].
    // Leggendo m[2] si otteneva undefined, e decodeURIComponent(undefined)
    // restituisce la stringa "undefined": ogni riordino partiva con un token
    // fasullo, veniva respinto dal filtro CSRF e l'ordine non si salvava mai.
    // Il fallimento era invisibile perché fetch non rigetta sugli status HTTP
    // (vedi persistOrder, che ora controlla response.ok).
    function csrfToken() {
        var m = document.cookie.match(/(?:^|;\s*)csrf_cookie_name=([^;]*)/);
        return m ? decodeURIComponent(m[1]) : "";
    }

    // Prima riga il cui centro è sotto il cursore: lì inseriremo quella trascinata.
    function rowAfter(tbody, y) {
        var rows = Array.prototype.slice.call(
            tbody.querySelectorAll("tr.ip-item-row:not(.ip-dragging)")
        );
        for (var i = 0; i < rows.length; i++) {
            var box = rows[i].getBoundingClientRect();
            if (y < box.top + box.height / 2) {
                return rows[i];
            }
        }
        return null;
    }

    function persistOrder(tbody) {
        var url = tbody.dataset.reorderUrl;
        if (!url) { return; }

        var ids = [];
        tbody.querySelectorAll("tr.ip-item-row").forEach(function (r) {
            if (r.dataset.itemId) { ids.push(r.dataset.itemId); }
        });
        if (!ids.length) { return; }

        var key = url.indexOf("/quotes/") !== -1 ? "quote_id" : "invoice_id";
        var body = new URLSearchParams();
        ids.forEach(function (id) { body.append("item_ids[]", id); });
        body.set(key, tbody.dataset.documentId || "");

        fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "X-CSRF-TOKEN": csrfToken(),
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: body.toString()
        }).then(function (res) {
            // fetch rigetta solo sugli errori di rete: senza questo controllo un
            // 403 (CSRF) o un 404 passavano per successo e l'ordine sullo schermo
            // restava diverso da quello salvato, senza che nulla lo dicesse.
            if (!res.ok) {
                throw new Error("riordino non salvato: HTTP " + res.status);
            }
        }).catch(function (err) {
            console.error("[ip-items-reorder]", err);
        });
    }

    function bindTbody(tbody) {
        if (tbody.dataset.reorderBound === "1") { return; }
        tbody.dataset.reorderBound = "1";

        tbody.addEventListener("mousedown", function (e) {
            var handle = e.target.closest(".ip-item-row__handle");
            if (!handle) { return; }
            var row = handle.closest("tr.ip-item-row");
            if (row) { row.setAttribute("draggable", "true"); }
        });

        // Rilascio della pressione sulla maniglia senza drag: la riga non deve
        // restare draggable (altrimenti un successivo trascinamento del testo
        // nella textarea partirebbe dal <tr>).
        tbody.addEventListener("mouseup", function (e) {
            var handle = e.target.closest(".ip-item-row__handle");
            if (!handle) { return; }
            var row = handle.closest("tr.ip-item-row");
            if (row) { row.removeAttribute("draggable"); }
        });

        tbody.addEventListener("dragstart", function (e) {
            var row = e.target.closest("tr.ip-item-row");
            if (!row) { return; }
            dragRow = row;
            row.classList.add("ip-dragging");
            e.dataTransfer.effectAllowed = "move";
            try { e.dataTransfer.setData("text/plain", row.dataset.itemId || ""); } catch (err) {}
        });

        tbody.addEventListener("dragover", function (e) {
            if (!dragRow) { return; }
            e.preventDefault();
            e.dataTransfer.dropEffect = "move";
            var after = rowAfter(tbody, e.clientY);
            if (after === null) {
                tbody.appendChild(dragRow);
            } else if (after !== dragRow) {
                tbody.insertBefore(dragRow, after);
            }
        });

        tbody.addEventListener("dragend", function (e) {
            var row = e.target.closest("tr.ip-item-row");
            if (row) {
                row.classList.remove("ip-dragging");
                row.removeAttribute("draggable");
            }
            var moved = dragRow;
            dragRow = null;
            if (moved) { persistOrder(tbody); }
        });

        // Pulsanti su/giù: riordino accessibile da tastiera e funzionante al
        // touch (il drag nativo HTML5 non parte su dispositivi senza mouse).
        tbody.addEventListener("click", function (e) {
            var btn = e.target.closest(".ip-item-row__move");
            if (!btn) { return; }
            e.preventDefault();
            var row = btn.closest("tr.ip-item-row");
            if (!row) { return; }
            var dir = btn.dataset.dir;
            var target = dir === "up" ? row.previousElementSibling : row.nextElementSibling;
            while (target && (!target.classList || !target.classList.contains("ip-item-row"))) {
                target = dir === "up" ? target.previousElementSibling : target.nextElementSibling;
            }
            if (!target) { return; }
            if (dir === "up") {
                tbody.insertBefore(row, target);
            } else {
                tbody.insertBefore(target, row);
            }
            refreshMoveButtons(tbody);
            persistOrder(tbody);
        });

        refreshMoveButtons(tbody);
    }

    // Disattiva su/giù agli estremi così l'utente capisce subito che non c'è
    // altro verso cui spostare la riga.
    function refreshMoveButtons(tbody) {
        var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr.ip-item-row"));
        rows.forEach(function (r, i) {
            var up = r.querySelector('.ip-item-row__move[data-dir="up"]');
            var down = r.querySelector('.ip-item-row__move[data-dir="down"]');
            if (up) { up.disabled = (i === 0); }
            if (down) { down.disabled = (i === rows.length - 1); }
        });
    }

    function init(root) {
        root = root || document;
        root.querySelectorAll(".ip-items-table tbody").forEach(bindTbody);
    }

    document.addEventListener("DOMContentLoaded", function () {
        init(document);

        if (window.MutationObserver) {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType !== 1) { return; }
                        if (node.matches && node.matches(".ip-items-table tbody")) {
                            bindTbody(node);
                            refreshMoveButtons(node);
                        }
                        if (node.querySelectorAll) {
                            node.querySelectorAll(".ip-items-table tbody").forEach(function (tb) {
                                bindTbody(tb);
                                refreshMoveButtons(tb);
                            });
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
})();
