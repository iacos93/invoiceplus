/*
 * Motore righe documento — fatture e preventivi, creazione e modifica.
 *
 * Nasce per chiudere la divergenza che rendeva la stessa operazione due cose
 * diverse: in creazione le righe erano client-side (clone di un <template>,
 * serializzate in JSON in un campo nascosto, un solo submit), in modifica ogni
 * riga aveva il proprio <form> e ogni salvataggio ricaricava tutta la pagina.
 * Aggiungere cinque righe a una fattura esistente significava cinque
 * ricaricamenti, e ogni submit buttava via quello che era stato digitato nelle
 * altre sezioni senza dirlo. Qui il comportamento e' uno solo, guidato da
 * attributi data-*: la view mette il markup, questo file lo fa funzionare.
 *
 * Contratto (tutto opzionale tranne body/template):
 *   [data-doc-items]            radice, porta il formato valuta:
 *                               data-decimal / data-thousands / data-symbol /
 *                               data-placement (stessi valori di format_currency())
 *   [data-doc-items-body]       <tbody> che ospita le righe
 *   [data-doc-items-template]   <template> della riga (campi con data-field)
 *   [data-doc-items-add]        pulsante "Aggiungi riga"
 *   [data-doc-items-json]       input nascosto: riceve le righe in JSON al submit
 *   [data-doc-items-deleted]    input nascosto: id delle righe rimosse (modifica)
 *   [data-doc-total="..."]      subtotal | discount | tax | total
 *
 * Le righe non hanno attributo name: al submit vengono serializzate nel campo
 * JSON. Cosi' aggiungere o togliere righe non richiede indici nei name, e il
 * POST e' identico per i due controller e per le due modalita'.
 *
 * In modifica le righe gia' salvate arrivano dal server con data-item-id
 * valorizzato: il controller aggiorna quelle, inserisce quelle senza id ed
 * elimina quelle il cui id compare in [data-doc-items-deleted].
 */
(function () {
    "use strict";

    function init(root) {
        if (root.dataset.docItemsReady === "1") { return; }
        root.dataset.docItemsReady = "1";

        // Creazione e modifica rendono la stessa tabella
        // (layout/document_item_table.php), quindi questi elementi stanno tutti
        // dentro la radice. Il ripiego sul documento serve a una view che
        // dovesse tenerne uno fuori dalla radice (es. il <template> in fondo
        // alla pagina): c'e' sempre una sola tabella righe per pagina, quindi
        // non e' ambiguo.
        function pick(sel) {
            return root.querySelector(sel) || document.querySelector(sel);
        }

        var body     = pick("[data-doc-items-body]");
        var template = pick("[data-doc-items-template]");
        if (!body || !template) { return; }

        var addBtn       = pick("[data-doc-items-add]");
        var jsonField    = pick("[data-doc-items-json]");
        var deletedField = pick("[data-doc-items-deleted]");
        var form         = root.closest("form") || (jsonField && jsonField.form);

        var DEC   = root.dataset.decimal;
        var THOU  = root.dataset.thousands;
        var SYM   = root.dataset.symbol;
        var PLACE = root.dataset.placement;

        // Id delle righe gia' salvate che l'utente ha tolto: si comunicano al
        // server al submit. Tenerli qui (e non cancellare subito via POST) e'
        // cio' che rende la modifica annullabile: finche' non si salva, il
        // documento sul server non e' stato toccato.
        var deletedIds = [];

        // Modifiche non ancora salvate. Nella schermata di modifica la testata
        // non e' l'unico form: "Cambia cliente" e "Aggiungi imposta" hanno il
        // proprio, e inviarli ricarica la pagina — le righe digitate e non
        // ancora salvate sparirebbero senza che nulla lo dica, che e' il modo in
        // cui il vecchio salvataggio riga per riga perdeva il lavoro.
        var dirty = false;

        // L'inizializzazione popola le righe e IPSelect si aggancia ai select
        // subito dopo, emettendo un 'change' che non e' opera dell'utente:
        // contarlo marcherebbe la pagina come "sporca" al solo caricamento e la
        // conferma comparirebbe a ogni navigazione. Si inizia ad ascoltare al
        // tick successivo, quando l'avvio e' concluso.
        var listening = false;
        setTimeout(function () { listening = true; }, 0);

        // Testi della conferma di uscita: arrivano tradotti dal partial, con un
        // fallback italiano per le pagine che non li passano.
        var LEAVE_TITLE = root.dataset.leaveTitle || "Modifiche non salvate";
        var LEAVE_BODY  = root.dataset.leaveBody  || "Le modifiche a questo documento non sono state salvate: uscendo andranno perse.";
        var LEAVE_OK    = root.dataset.leaveOk    || "Esci senza salvare";

        // Ultima destinazione chiesta con un clic. Serve perché la navigazione
        // parziale chiama ipBeforeNavigate() senza dirgli dove si sta andando:
        // l'indirizzo lo ricorda il clic, e la conferma lo riprende.
        var leaveUrl = null;

        /* La conferma è una modale dell'app (#ip-confirm-modal, la stessa dei
           "Elimina") al posto di window.confirm: quella blocca il thread e
           sulla navigazione parziale arriva quando la pagina è già cambiata.
           Senza la modale nel layout si lascia uscire senza chiedere: meglio
           un'uscita silenziosa che una pagina che non risponde più. */
        function askLeave(onLeave) {
            var modalEl = document.getElementById("ip-confirm-modal");

            if (!modalEl || typeof bootstrap === "undefined" || !bootstrap.Modal) {
                onLeave();
                return;
            }

            var title = modalEl.querySelector(".modal-title");
            var body  = modalEl.querySelector("#ip-confirm-modal-body");
            var ok    = modalEl.querySelector("#ip-confirm-ok");
            var icon  = modalEl.querySelector(".confirm-modal-icon");

            if (title) { title.textContent = LEAVE_TITLE; }
            if (body)  { body.textContent  = LEAVE_BODY; }
            if (ok) {
                var label = ok.querySelector(".ip-confirm-ok-label");
                var glyph = ok.querySelector("i");
                if (label) { label.textContent = LEAVE_OK; }
                // Rosso come le azioni che perdono qualcosa: qui si buttano le
                // modifiche appena digitate.
                ok.className = "btn btn-danger";
                if (glyph) { glyph.className = "fa fa-exclamation-triangle"; }
            }
            if (icon) { icon.className = "fa fa-exclamation-triangle confirm-modal-icon confirm-modal-icon-danger"; }

            // Un solo sì per conferma. Senza once, ogni uscita annullata
            // lascerebbe il gestore agganciato e la conferma successiva
            // farebbe partire due navigazioni.
            if (ok) {
                ok.addEventListener("click", function handler() {
                    ok.removeEventListener("click", handler);
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    onLeave();
                }, { once: true });
            }

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        function leave(url) {
            dirty    = false;
            leaveUrl = null;

            if (typeof window.ipNavigate === "function") {
                window.ipNavigate(url || window.location.href);
            } else {
                window.location.href = url || window.location.href;
            }
        }

        // Con la navigazione parziale beforeunload non viene emesso: la pagina
        // può fermare il cambio via ip-pjax.js. Stesso avviso, stessa variabile
        // dirty del prima/dopo.
        window.ipBeforeNavigate = function () {
            if (!dirty) { return true; }

            // Tornando indietro l'indirizzo di destinazione è già stato
            // ripristinato da ip-pjax: senza clic da seguire si ricarica la
            // pagina corrente, che scarta le modifiche in modo esplicito.
            askLeave(function () { leave(leaveUrl || window.location.href); });

            return false;
        };

        // Il clic su un link è l'uscita più frequente e va fermato prima della
        // navigazione parziale, che altrimenti sostituirebbe la pagina sotto la
        // modale: si annulla il clic, si chiede, e solo dopo il sì si naviga.
        document.addEventListener("click", function (e) {
            if (!dirty || e.defaultPrevented || e.button !== 0
                || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            var link = e.target.closest ? e.target.closest("a[href]") : null;
            if (!link || link.getAttribute("target") || link.hasAttribute("download")) {
                return;
            }

            var href = link.getAttribute("href");
            if (!href || href.charAt(0) === "#" || /^javascript:/i.test(href)) {
                return;
            }

            // Scaricare un PDF non fa perdere il lavoro: la pagina resta.
            if (typeof window.ipIsDownloadUrl === "function" && window.ipIsDownloadUrl(link.href)) {
                return;
            }

            e.preventDefault();
            leaveUrl = link.href;
            askLeave(function () { leave(link.href); });
        }, true);

        // I select del progetto (IPSelect) emettono un change quando si
        // inizializzano e marcherebbero la pagina come sporca al solo
        // caricamento: il lavoro dell'utente comincia con il primo tocco o
        // tasto, quindi prima di quello i change non contano.
        var userTouched = false;
        ["pointerdown", "keydown"].forEach(function (type) {
            document.addEventListener(type, function () { userTouched = true; }, true);
        });

        function markDirty() {
            if (listening && userTouched) { dirty = true; }
        }

        // Anche la testata è lavoro da salvare: cambiare cliente, data o note e
        // uscire perde tutto come perdere una riga. I campi nascosti delle
        // righe sono esclusi perché li scrive serialize() al submit.
        var docForm = (function () {
            var json = root.querySelector("[data-doc-items-json]");
            var id   = json ? json.getAttribute("form") : null;

            return id ? document.getElementById(id) : null;
        })();

        if (docForm) {
            var markFormDirty = function (e) {
                var el = e.target;
                if (el && el.matches && el.matches("[data-doc-items-json], [data-doc-items-deleted]")) {
                    return;
                }
                markDirty();
            };

            docForm.addEventListener("input", markFormDirty);
            docForm.addEventListener("change", markFormDirty);
        }

        /* ---- formattazione: stessa resa di format_currency() lato server ---- */

        function formatCurrency(n) {
            var negative = n < 0;
            var fixed    = Math.abs(n).toFixed(DEC ? 2 : 0);
            var parts    = fixed.split(".");

            if (THOU) {
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, THOU);
            }

            var out = parts.length > 1 ? parts[0] + DEC + parts[1] : parts[0];
            if (negative) { out = "-" + out; }

            if (PLACE === "before")     { return SYM + out; }
            if (PLACE === "afterspace") { return out + " " + SYM; }
            return out + SYM;
        }

        // I campi importo si digitano nel formato locale (1.234,56): vanno
        // riportati a float prima di poterci fare i conti.
        function parseAmount(value) {
            value = String(value == null ? "" : value).trim();
            if (!value) { return 0; }
            if (THOU) { value = value.split(THOU).join(""); }
            if (DEC)  { value = value.split(DEC).join("."); }
            var n = parseFloat(value);
            return isNaN(n) ? 0 : n;
        }

        /* ---- righe ---- */

        function fieldsOf(row) {
            var map = {};
            row.querySelectorAll("[data-field]").forEach(function (el) {
                map[el.dataset.field] = el;
            });
            return map;
        }

        // Stessa formula di ItemAmountModel::calculate(): lo sconto e' per
        // unita', quindi va moltiplicato per la quantita', e l'IVA si applica
        // al netto. Se qui e la' divergessero, il totale in pagina e quello
        // salvato sarebbero due numeri diversi.
        function amountsOf(row) {
            var f        = fieldsOf(row);
            var quantity = parseAmount(f.item_quantity && f.item_quantity.value);
            var price    = parseAmount(f.item_price && f.item_price.value);
            var discountAmount  = parseAmount(f.item_discount_amount && f.item_discount_amount.value) * quantity;
            var discountPercent = (price / 100) * parseAmount(f.item_discount_percent && f.item_discount_percent.value) * quantity;
            var discount = discountAmount + discountPercent;

            var taxSelect = f.item_tax_rate_id;
            var option    = taxSelect ? taxSelect.options[taxSelect.selectedIndex] : null;
            var percent   = option ? parseFloat(option.dataset.percent || 0) : 0;

            var subtotal   = quantity * price;
            var discounted = subtotal - discount;
            var tax        = discounted * (percent / 100);

            return {
                subtotal: subtotal,
                discount: discount,
                tax: tax,
                total: discounted + tax
            };
        }

        function setTotal(name, value) {
            var el = pick('[data-doc-total="' + name + '"]');
            if (el) { el.textContent = formatCurrency(value); }
            // Il totale in headerbar vive fuori dalla radice delle righe.
            if (name === "total") {
                document.querySelectorAll("[data-doc-total-mirror]").forEach(function (m) {
                    m.textContent = formatCurrency(value);
                });
            }
        }

        function recalculate() {
            var totals = { subtotal: 0, discount: 0, tax: 0, total: 0 };

            body.querySelectorAll(".ip-item-row").forEach(function (row) {
                var a = amountsOf(row);

                totals.subtotal += a.subtotal;
                totals.discount += a.discount;
                totals.tax      += a.tax;
                totals.total    += a.total;

                var cell = row.querySelector(".ip-item-row__total");
                if (cell) { cell.textContent = formatCurrency(a.total); }
            });

            setTotal("subtotal", totals.subtotal);
            setTotal("discount", totals.discount);
            setTotal("tax", totals.tax);
            setTotal("total", totals.total);
        }

        function addRow(values) {
            var fragment = document.importNode(template.content, true);
            var row      = fragment.querySelector(".ip-item-row");

            // Aliquota di partenza per le righe nuove: il <template> nasce con
            // quella globale selezionata lato server, ma il form di creazione
            // puo' aggiornare data-item-tax-default quando si sceglie il
            // cliente (autocompilazione dalle preferenze di fatturazione).
            // Solo per le righe che non arrivano gia' con un'aliquota esplicita:
            // le righe ricaricate dopo un withInput o prese da un'attivita'
            // devono restare come sono.
            var prefTax = root.dataset.itemTaxDefault;
            if (prefTax && (!values || values.item_tax_rate_id === undefined || values.item_tax_rate_id === "")) {
                var taxSel = row.querySelector('[data-field="item_tax_rate_id"]');
                if (taxSel && taxSel.value !== prefTax) { taxSel.value = prefTax; }
            }

            if (values) {
                var f = fieldsOf(row);
                Object.keys(values).forEach(function (key) {
                    if (f[key]) { f[key].value = values[key]; }
                });
                // item_id non e' un campo compilabile: identifica la riga gia'
                // salvata, e serve al submit per distinguere update da insert.
                if (values.item_id) { row.dataset.itemId = values.item_id; }
            }

            body.appendChild(fragment);
            recalculate();

            return body.lastElementChild;
        }

        // Senza almeno una riga vuota la tabella sembra rotta: si resta sempre
        // con una riga pronta da compilare.
        function ensureRow() {
            if (!body.querySelector(".ip-item-row")) { addRow(); }
        }

        if (addBtn) {
            addBtn.addEventListener("click", function () {
                var row   = addRow();
                var first = row.querySelector('[data-field="item_name"]');
                if (first) { first.focus(); }
            });
        }

        body.addEventListener("click", function (e) {
            var btn = e.target.closest('[data-action="remove-row"]');
            if (!btn) { return; }

            var row = btn.closest(".ip-item-row");
            if (!row) { return; }

            if (row.dataset.itemId) { deletedIds.push(row.dataset.itemId); }
            row.remove();
            ensureRow();
            recalculate();
            markDirty();
        });

        // 'input' copre digitazione e incolla; 'change' serve per il select IVA,
        // che IPSelect aggiorna scrivendo sul select nativo.
        body.addEventListener("input", recalculate);
        body.addEventListener("change", recalculate);

        /* ---- submit ---- */

        function serialize() {
            var items = [];

            body.querySelectorAll(".ip-item-row").forEach(function (row) {
                var f    = fieldsOf(row);
                var item = {};

                Object.keys(f).forEach(function (key) { item[key] = f[key].value; });

                // Le righe lasciate vuote non vanno salvate: la tabella parte
                // gia' con una riga pronta, che spesso resta intatta. Bastano
                // nome *o* descrizione, come lato server: una riga di sola
                // descrizione e' legittima.
                if (!item.item_name && !item.item_description) { return; }

                if (row.dataset.itemId) { item.item_id = row.dataset.itemId; }
                item.item_order = items.length + 1;
                items.push(item);
            });

            if (jsonField) {
                // Stringa vuota e "[]" non sono la stessa cosa lato server:
                // "[]" significa "l'utente ha tolto tutte le righe", e va
                // distinto da "nessuna riga inviata".
                jsonField.value = JSON.stringify(items);
            }
            if (deletedField) {
                deletedField.value = deletedIds.join(",");
            }
        }

        if (form) {
            form.addEventListener("submit", function () {
                serialize();
                dirty = false;
            });
        }

        body.addEventListener("input", markDirty);
        body.addEventListener("change", markDirty);

        ipOn(window, "beforeunload", function (e) {
            if (!dirty) { return; }
            e.preventDefault();
            // Il testo lo decide il browser; serve solo valorizzare returnValue.
            e.returnValue = "";
        });

        /* ---- avvio ---- */

        // Le righe iniziali (documento esistente, ritorno da withInput, o
        // precompilazione da attivita') arrivano come JSON nel data-attribute.
        var initial = [];
        if (root.dataset.docItemsInitial) {
            try { initial = JSON.parse(root.dataset.docItemsInitial) || []; } catch (e) { initial = []; }
        }

        initial.forEach(function (item) { addRow(item); });

        ensureRow();
        recalculate();
    }

    function boot() {
        document.querySelectorAll("[data-doc-items]").forEach(init);
    }

    document.addEventListener("ip:page-ready", boot);

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
