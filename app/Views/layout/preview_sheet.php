<?php
/**
 * Anteprima del documento dentro l'iframe: la divide in fogli A4 e la scala
 * alla larghezza del riquadro.
 *
 * Il template produce un flusso continuo (un <body> con intestazione, righe,
 * chiusura e piede); il PDF lo spezza in pagine e ripete il piede su ognuna.
 * Senza questo script l'anteprima di un documento lungo era un foglio unico di
 * due o tre volte l'altezza di un A4, con un solo piede in fondo: non si
 * capiva dove finiva la prima pagina. Qui il flusso viene distribuito in fogli
 * da 210x297 mm — tagliando fra le righe della tabella, con l'intestazione di
 * colonna ripetuta, e spostando interi i blocchi che non si possono spezzare
 * (chiusura coi totali, note) — e ogni foglio riceve il proprio piede con
 * "n / N".
 *
 * Le misure si fanno a scala 1, quindi la divisione in pagine non dipende
 * dalla finestra: si calcola una volta, al caricamento. Lo zoom alla larghezza
 * del riquadro (1:1 fino a 794 px di colonna, ridotto sotto) si applica dopo,
 * e a ogni ridimensionamento si rifà solo quello.
 *
 * Era ricopiato in tre view — fatture, preventivi, DDT — e in tutte e tre
 * misurava documentElement.scrollHeight: quello è alto quanto l'iframe, quindi
 * ogni misura confermava l'altezza già impostata e il riquadro cresceva di
 * 32 px a ogni ridimensionamento, lasciando sotto il documento centinaia di px
 * di bianco.
 *
 * Si include dentro <script>:
 *   <script><?= $this->include('layout/preview_sheet') ?></script>
 */
?>
(function () {
    var frame = document.getElementById('document_sheet');
    if (!frame) { return; }

    // 210 mm in px CSS (96 dpi): la larghezza del foglio. Serve solo a sapere
    // di quanto ridurlo, la misura vera la porta il template.
    var PAPER = 210 * 96 / 25.4;

    /* ---------------------------------------------------------------------
       Divisione in fogli
       ------------------------------------------------------------------ */

    function paginate(doc) {
        var body = doc && doc.body;
        if (!body || body.classList.contains('doc-paged')) { return; }

        // Le misure vanno prese a scala 1: lo zoom del riquadro falserebbe
        // altezze e soglie.
        body.style.zoom = '';

        var footer = body.querySelector('.doc-footer');
        var flow = [];
        for (var i = 0; i < body.children.length; i++) {
            if (body.children[i] !== footer) { flow.push(body.children[i]); }
        }
        if (!flow.length) { return; }

        var wrap = doc.createElement('div');
        wrap.className = 'doc-pages';
        // Nascosto mentre si costruisce: altrimenti per un istante si vede il
        // documento a pezzi che si sposta da una pagina all'altra.
        wrap.style.visibility = 'hidden';
        body.appendChild(wrap);

        // Altezza utile di un foglio, misurata su un foglio vuoto prima di
        // spostare qualsiasi cosa. Se manca (CSS non caricato) la divisione
        // non avrebbe senso: senza un limite, ogni blocco risulterebbe
        // "troppo alto" e finirebbe su un foglio suo, cioè il documento
        // verrebbe distrutto invece di essere impaginato.
        var probe = doc.createElement('div');
        probe.className = 'doc-page';
        var probeBody = doc.createElement('div');
        probeBody.className = 'doc-page__body';
        probe.appendChild(probeBody);
        wrap.appendChild(probe);
        var limit = probeBody.clientHeight;
        wrap.removeChild(probe);
        if (!limit) { body.removeChild(wrap); return; }

        var content = null;  // .doc-page__body della pagina in corso

        function newPage() {
            var page = doc.createElement('div');
            page.className = 'doc-page';
            var inner = doc.createElement('div');
            inner.className = 'doc-page__body';
            page.appendChild(inner);
            if (footer) { page.appendChild(footer.cloneNode(true)); }
            wrap.appendChild(page);
            content = inner;
            return inner;
        }

        function full() { return content.scrollHeight > limit + 1; }

        // Un blocco che da solo supera il foglio (note lunghe) non si può
        // spostare altrove: la pagina cresce, il piede le sta in coda. Meglio
        // un foglio più alto del previsto che testo tagliato.
        function allowGrowth() { content.parentNode.classList.add('is-tall'); }

        // Guscio della tabella righe: stesse classi, intestazione di colonna
        // ripetuta (come fa il PDF dalla seconda pagina in poi) e tbody vuoto
        // in cui spostare le righe vere, una alla volta.
        function tableShell(table) {
            var shell = table.cloneNode(false);
            if (table.tHead) { shell.appendChild(table.tHead.cloneNode(true)); }
            var tbody = doc.createElement('tbody');
            shell.appendChild(tbody);
            content.appendChild(shell);
            return tbody;
        }

        function placeTable(table) {
            var tbody = tableShell(table);

            // L'intestazione non sta in fondo alla pagina: la tabella comincia
            // sul foglio nuovo invece di lasciare il titolo delle colonne
            // orfano in calce.
            if (full() && content.children.length > 1) {
                content.removeChild(tbody.parentNode);
                newPage();
                tbody = tableShell(table);
            }

            var rows = [];
            for (var b = 0; b < table.tBodies.length; b++) {
                for (var r = 0; r < table.tBodies[b].rows.length; r++) {
                    rows.push(table.tBodies[b].rows[r]);
                }
            }

            rows.forEach(function (row) {
                tbody.appendChild(row);
                if (!full()) { return; }

                if (tbody.rows.length === 1 && content.children.length === 1) {
                    // La riga non ci sta nemmeno su un foglio vuoto — e un
                    // foglio nuovo lascerebbe il primo bianco, con il suo
                    // piede e la sua numerazione. Meglio farlo crescere.
                    allowGrowth();
                    return;
                }

                if (tbody.rows.length === 1) {
                    // Nemmeno una riga ci sta: si sposta l'intera tabella.
                    tbody.removeChild(row);
                    content.removeChild(tbody.parentNode);
                    newPage();
                    tbody = tableShell(table);
                    tbody.appendChild(row);
                } else {
                    tbody.removeChild(row);
                    newPage();
                    tbody = tableShell(table);
                    tbody.appendChild(row);
                }
                if (full()) { allowGrowth(); }
            });
        }

        newPage();

        flow.forEach(function (node) {
            if (node.classList && node.classList.contains('items')) {
                placeTable(node);
                return;
            }

            content.appendChild(node);
            if (!full()) { return; }
            if (content.children.length === 1) { allowGrowth(); return; }

            content.removeChild(node);
            newPage();
            content.appendChild(node);
            if (full()) { allowGrowth(); }
        });

        body.innerHTML = '';
        body.appendChild(wrap);
        body.classList.add('doc-paged');
        wrap.style.visibility = '';

        // Numerazione. Nel PDF la compone counter(page) con il totale risolto
        // da pdf_page_total(); qui il conteggio è già noto, quindi il "n / N"
        // si scrive per intero in ogni piede.
        var sheets = wrap.querySelectorAll('.doc-page');
        for (var p = 0; p < sheets.length; p++) {
            var label = sheets[p].querySelector('.pages');
            if (label) { label.textContent = (p + 1) + ' / ' + sheets.length; }
        }
    }

    /* ---------------------------------------------------------------------
       Scala e altezza del riquadro
       ------------------------------------------------------------------ */

    function fit() {
        var doc;
        try {
            doc = frame.contentDocument;
        } catch (e) {
            return; // documento di un'altra origine: resta l'altezza da CSS
        }
        if (!doc || !doc.body) { return; }

        var available = frame.clientWidth;
        if (!available) { return; } // riquadro non ancora impaginato

        // Se il riquadro non aveva layout quando il documento è arrivato
        // (nascosto, oppure uno scambio pjax che lo mostra dopo), la divisione
        // in fogli è stata rinviata: qui è il primo momento in cui si può
        // misurare, quindi si riprova. La guardia su .doc-paged la rende
        // innocua quando i fogli esistono già.
        if (!doc.body.classList.contains('doc-paged')) {
            try { paginate(doc); } catch (e) { /* resta il foglio continuo */ }
        }

        // A 1:1 l'anteprima è la pagina: ingrandirla oltre non aggiunge
        // informazione e la fa sembrare un poster. Sul telefono la scala
        // scende sotto il mezzo: il foglio resta intero e si legge
        // ingrandendo, mentre un documento reimpaginato a 330 px si spezza
        // (etichette accavallate, importi a capo) — vedi pdf_styles.
        var scale = Math.min(available / PAPER, 1);
        doc.body.style.zoom = scale.toFixed(3);

        // Altezza del CONTENUTO, non del documento: documentElement è alto
        // quanto l'iframe, quindi misurarlo confermava l'altezza appena
        // impostata e il riquadro si allungava di 32 px a ogni resize.
        var height = Math.ceil(doc.body.getBoundingClientRect().height);
        if (height > 0 && Math.abs(height - frame.clientHeight) > 1) {
            frame.style.height = height + 'px';
        }
    }

    function start() {
        var doc = frame.contentDocument;
        try {
            paginate(doc);
        } catch (e) {
            // Costruzione fallita: i fogli già costruiti sono rimasti nascosti
            // (visibility: hidden messo all'inizio), quindi si riMostrano —
            // meglio una divisione incompleta che un documento invisibile.
            if (doc && doc.querySelector) {
                var built = doc.querySelector('.doc-pages');
                if (built) { built.style.visibility = ''; }
            }
        }
        fit();
        // Font e logo cambiano l'altezza dopo il primo layout.
        window.setTimeout(fit, 150);
        try {
            doc && doc.fonts.ready.then(fit);
        } catch (e) {
            // Nessuna API Font Loading: basta il ritardo qui sopra.
        }
    }

    frame.addEventListener('load', function () {
        // Il load di about:blank può precedere l'arrivo del documento vero;
        // la guardia su .doc-paged rende innocuo un secondo passaggio.
        start();
    });

    ipOn(window, 'resize', fit);

    // L'iframe può essere GIA' caricato quando questo script arriva — pjax,
    // bfcache, oppure uno script che parte dopo il load. Allora il load non
    // arriva più e senza questa chiamata il documento resterebbe un foglio
    // unico alto quanto il testo, con un solo piede in fondo: il difetto che
    // l'impaginazione esiste per togliere.
    try {
        if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
            start();
        }
    } catch (e) {
        // Documento di un'altra origine: resta l'altezza da CSS.
    }
})();
