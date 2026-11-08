<?php
/**
 * Foglio di stile condiviso dei documenti PDF (fatture, preventivi, DDT).
 *
 * Nasce perche' i quattro template erano quattro copie divergenti dello stesso
 * documento: la fattura pagata e quella scaduta avevano perso per strada gli
 * estremi di pagamento, il preventivo era rimasto senza logo, e ogni file
 * ridichiarava a modo suo margini, corpi e bordi. Un documento fiscale della
 * stessa azienda deve avere la stessa faccia, quindi la faccia sta in un file
 * solo e i template portano il contenuto.
 *
 * Vincoli di dompdf, che non e' un browser: CSS 2.1: niente flex, niente grid,
 * niente calc(). L'impaginazione si fa con tabelle e float, e le unita' sono
 * in punti perche' la destinazione e' la carta.
 *
 * Uso:
 *   <style><?= $this->include('layout/pdf_styles') ?></style>
 *
 * Opzione `accent`: 'neutral' (default), 'paid', 'overdue' — tinge il numero
 * del documento e il saldo, cioe' le due cose che cambiano di stato.
 *
 * Ottimizzazioni per la stampa e la leggibilita' (valgono per fatture,
 * preventivi e DDT, che condividono questo foglio): formato pagina esplicito,
 * interruzioni di pagina evitate sui blocchi intestazione, testo che va a capo
 * anziche' sforare le colonne strette, e colori di accento preservati anche
 * nella stampa da browser.
 */
$_ps    = (array) ($this->renderVars['options'] ?? []);
$accent = (string) ($_ps['accent'] ?? ($accent ?? 'neutral'));

$accentColor = [
    'paid'    => '#2f7a31',
    'overdue' => '#a33b39',
][$accent] ?? '#1a1a1a';
?>
/* Formato esplicito A4: il controller chiama comunque setPaper('A4'), ma
   dichiararlo qui rende il foglio corretto anche se il PDF viene generato
   da un altro punto (es. report) senza quel setPaper. I margini restano
   ampi per dare respiro e per lasciare spazio al piede fisso (bottom -14mm). */
@page { size: A4; margin: 16mm 15mm 22mm 15mm; }

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 9pt;
    line-height: 1.5;
    color: #1a1a1a;
    margin: 0;
    /* Nella stampa dal browser (anteprima stampa) i colori di sfondo dei
       badge e della riga "saldo" altrimenti spariscono, rendendo il testo
       bianco su bianco. exact li conserva; dompdf li rende comunque. */
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* Testo lungo (descrizioni, indirizzi, note) che va a capo dentro le colonne
   strette invece di sforarle o far debordare la tabella: dompdf non fa il
   wrapping automatico delle parole troppo lunghe, quindi lo chiediamo noi. */
.party-line,
.block-body,
table.items td,
table.sums td {
    overflow-wrap: break-word;
    word-break: break-word;
}

/* IBAN e codici: restano copiabili ma non sforano la colonna stretta. */
.mono { word-break: break-all; }

/* A schermo (anteprima nell'iframe) il margine @page non esiste e il foglio
   non e' vincolato ad A4: qui si ricrea un vero foglio A4 bianco, centrato su
   uno sfondo "scrivania", con i suoi margini interni. Nel PDF dompdf rende
   come "print" e questa regola non scatta, restano i margini @page e il
   setPaper('A4') — cosi' le due resa non divergono. */
@media screen {
    html { background: #e9ecef; }
    body {
        width: 210mm;
        min-height: 297mm;
        margin: 16mm auto;
        padding: 16mm 15mm 22mm;
        background: #fff;
        box-shadow: 0 1mm 5mm rgba(0, 0, 0, 0.18);
    }
}

/* Anteprima dentro l'iframe: il foglio resta un A4 vero — 210x297 mm con gli
   stessi margini del PDF — perché l'anteprima serve a vedere la pagina che si
   stampa, non un documento che si allarga con la finestra. Prima il corpo era
   elastico: a 1.600 px di finestra le righe della fattura erano lunghe il
   doppio di quelle stampate e le colonne della tabella non cadevano più sotto
   le stesse intestazioni, quindi anteprima e PDF sembravano due documenti
   diversi.

   Lo zoom che porta il foglio alla larghezza del riquadro lo imposta
   layout/preview_sheet.php (zoom e non transform: rilayouta il documento,
   quindi l'altezza che si misura è quella vera), insieme alla divisione del
   flusso nei fogli qui sotto. Vale anche sul telefono,
   dove il foglio si riduce a un francobollo: la prova fatta a 390 px con il
   corpo elastico (foglio adattato alla larghezza invece che scalato) mostra
   le etichette della riga dati che si accavallano e i numeri che si spezzano
   ("805,20 €" in "805,20" + "€"), perché sei colonne fiscali non stanno in
   306 px. Meglio una pagina intera e piccola, che si legge ingrandendo, che
   un documento rotto.

   box-sizing: border-box perché i 15 mm laterali devono stare DENTRO i 210:
   con il default content-box il corpo misurava 240 mm e il foglio sforava di
   30 mm. Il margine inferiore di 8 mm è quello a cui il PDF appoggia il piede
   fisso (bottom -14 mm dal box del contenuto, che finisce 22 mm sopra il bordo
   del foglio): così logo e note cadono alla stessa distanza dal bordo anche a
   schermo. Lo sfondo è trasparente perché dietro deve vedersi il fondo
   "scrivania" del riquadro, di qualunque tema sia.

   La classe, e non una media query: dompdf valuta anche le regole
   @media screen (verificato: il blocco qui sopra cambia la resa del PDF),
   quindi una media query avrebbe reimpaginato anche i PDF. La classe viene
   aggiunta solo da Invoices::previewSheet()/Quotes::previewPdf()/
   DeliveryNotes::previewSheet() — le stesse view usate per il PDF non la
   ricevono. */
html.ip-sheet-embedded { background: transparent; }
html.ip-sheet-embedded body {
    box-sizing: border-box;
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 16mm 15mm 8mm;
    background: #fff;
    box-shadow: 0 1mm 4mm rgba(0, 0, 0, 0.35);
}

/* Fogli dell'anteprima. Il template produce un flusso continuo; il PDF lo
   spezza in pagine e ripete il piede su ognuna (intestazione di colonna
   compresa). La divisione la fa layout/preview_sheet.php, che costruisce
   questi elementi: il corpo diventa il piano di lavoro e ogni .doc-page è un
   foglio A4 con il suo piede. Solo anteprima: nel PDF questi elementi non
   esistono. */
html.ip-sheet-embedded body.doc-paged {
    padding: 0;
    min-height: 0;
    background: transparent;
    box-shadow: none;
}

.doc-pages { display: block; }
.doc-page {
    position: relative;
    box-sizing: border-box;
    width: 210mm;
    height: 297mm;
    padding: 16mm 15mm 8mm;
    margin: 0 auto 10mm;
    background: #fff;
    box-shadow: 0 1mm 4mm rgba(0, 0, 0, 0.35);
    overflow: hidden;
}
.doc-page:last-child { margin-bottom: 0; }

/* Altezza del box del contenuto di stampa: 297 − 16 (sopra) − 22 (sotto, dove
   il testo finisce e comincia la fascia del piede fisso). Sotto il corpo
   restano i 14 mm della banda in cui il PDF appoggia il piede a 8 mm dal
   bordo: così il piede di ogni foglio cade dove cade su carta. */
.doc-page__body { height: 259mm; overflow: hidden; }

/* Foglio che non può spezzare ciò che contiene (note lunghe): cresce, e il
   piede gli sta in coda. Meglio un foglio più alto del previsto che testo
   tagliato. */
.doc-page.is-tall { height: auto; }
.doc-page.is-tall .doc-page__body { height: auto; overflow: visible; }

/* Il piede va dove lo mette la stampa, non subito dopo il testo: in stampa è
   fisso a -14 mm dal box del contenuto (che finisce 22 mm sopra il bordo),
   cioè con il bordo inferiore a 8 mm dal fondo del foglio. Qui si ottiene con
   un posizionamento assoluto, così un documento corto e uno lungo hanno il
   piede nello stesso punto e nel preview si vede davvero la pagina stampata. */
.doc-page .doc-footer {
    position: absolute;
    left: 15mm;
    right: 15mm;
    bottom: 8mm;
    margin: 0;
}
/* Pagina cresciuta per un blocco inseparabile: il piede torna in coda al
   testo, altrimenti lo coprirebbe. */
.doc-page.is-tall .doc-footer { position: static; margin-top: 10px; }

/* Numerazione per foglio: "1 / 3", come in calce al PDF. Il "numero /" lo
   compone counter(page) solo in stampa; qui il conteggio è noto e lo scrive
   lo script, quindi il pseudo-elemento va spento. */
html.ip-sheet-embedded .doc-page .pages { display: inline; }
html.ip-sheet-embedded .doc-page .pages:before { content: none; }

/* dompdf non ha flex: le affiancature si fanno con tabelle di impaginazione,
   che sono anche l'unica cosa che regge un'interruzione di pagina in mezzo. */
table.layout { width: 100%; border-collapse: collapse; }
table.layout > tr > td,
table.layout td { border: none; padding: 0; vertical-align: top; }

.muted { color: #6b6b6b; }
.text-right, .text-end { text-align: right; }
.text-center { text-align: center; }
.nowrap { white-space: nowrap; }

/* -------------------------------------------------------------------------
   Intestazione: chi emette a sinistra sotto il logo, destinatario a destra
   ---------------------------------------------------------------------- */

/* L'intestazione non deve mai spezzarsi a cavallo di due pagine: un
   mittente/destinatario tagliati a meta' sono illeggibili e poco professionali
   su carta. Stesso discorso per titolo e riga dati, tenuti uniti al blocco. */
.doc-head,
.doc-title,
.doc-meta { page-break-inside: avoid; }
.doc-title,
.doc-meta { page-break-after: avoid; }

.doc-head { margin-bottom: 18px; }
.doc-head td.emitter { width: 55%; padding-right: 18px; }
.doc-head td.recipient { width: 45%; }

/* Logo del mittente: blocco a se', in alto nella colonna emittente. Le
   dimensioni sono vincolate in proporzione (width/height auto) cosi' un logo
   piu' alto che largo non si schiaccia e non invade i dati sotto. Se il logo
   manca l'helper ritorna stringa vuota: :empty toglie il margine, evitando
   un buco bianco sotto l'intestazione. */
.logo { margin-bottom: 10px; line-height: 0; }
.logo:empty { display: none; }
.logo img {
    display: block;
    width: auto;
    height: auto;
    max-width: 220px;
    max-height: 90px;
}

.party-label {
    font-size: 7pt;
    font-weight: bold;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #6b6b6b;
    padding-bottom: 3px;
}

/* Il riquadro del destinatario e' l'unico bordo dell'intestazione: separa a
   colpo d'occhio "a chi va" da "chi lo manda", che sono i due blocchi che si
   assomigliano di piu' e che nel vecchio layout si confondevano. */
.party-box {
    border: 0.5pt solid #d8d8d8;
    padding: 8px 10px;
}

.party-name { font-weight: bold; font-size: 10pt; }
.party-line { font-size: 8.5pt; }
.party-ids { font-size: 8.5pt; color: #6b6b6b; }

/* -------------------------------------------------------------------------
   Titolo del documento e riga dei dati
   ---------------------------------------------------------------------- */

/* Prima si diceva la data e poi che documento fosse. Un documento dichiara
   se stesso per primo: tipo e numero in testa, i dati sotto. */
.doc-title {
    border-top: 1pt solid #1a1a1a;
    padding-top: 9px;
    margin: 0 0 4px;
    font-size: 15pt;
    font-weight: bold;
    letter-spacing: -0.01em;
}

.doc-number { color: <?= $accentColor ?>; }

/* Stato del documento accanto al numero. Sostituisce il tag <watermarktext>
   che i template pagata/scaduta portavano dietro da mPDF: dompdf lo ignora,
   quindi una fattura scaduta usciva senza alcun segno di esserlo. */
.doc-badge {
    display: inline-block;
    border: 0.5pt solid <?= $accentColor ?>;
    color: <?= $accentColor ?>;
    font-size: 7.5pt;
    font-weight: bold;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding: 1px 6px;
    margin-left: 8px;
    vertical-align: middle;
}

/* I dati del documento su una riga sola invece che in una tabellina di quattro
   righe alta un quarto di pagina: sono etichette brevi con valori brevi. */
.doc-meta {
    border-bottom: 0.5pt solid #d8d8d8;
    padding-bottom: 8px;
    margin-top: 2px;
    margin-bottom: 16px;
    font-size: 8.5pt;
}

/* Quattro colonne uguali quando ci sono metodo di pagamento e importo dovuto,
   tre quando no: senza le larghezze, la seconda colonna del preventivo
   cadeva ~60mm più a destra di quella della fattura e i due documenti della
   stessa azienda non si allineavano letti uno sotto l'altro. */
/* Larghezze fisse: con l'impaginazione automatica le tre colonne del
   preventivo si dividevano il 25% avanzato e la seconda finiva a 33%,
   mentre nella fattura (quattro colonne) stava a 25%: i due documenti non
   si allineavano. Con table-layout fixed le colonne sono esattamente
   quelle dichiarate e i valori lunghi vanno a capo invece di sforare. */
.doc-meta { table-layout: fixed; }
.doc-meta td { width: 25%; padding-right: 18px; }
/* Preventivo: tre celle. La terza prende quello che resta, così la seconda
   cade a 25% come nella fattura invece che a 33%. */
.doc-meta td.doc-meta-amount { width: 50%; }
.doc-meta .v { overflow-wrap: break-word; }
.doc-meta .k {
    font-size: 7pt;
    font-weight: bold;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #6b6b6b;
}
.doc-meta .v { font-size: 9.5pt; }
.doc-meta .v.is-accent { color: <?= $accentColor ?>; font-weight: bold; }

/* -------------------------------------------------------------------------
   Righe del documento
   ---------------------------------------------------------------------- */

table.items { width: 100%; border-collapse: collapse; margin: 14px 0; }

/* Su piu' pagine le intestazioni di colonna si ripetono: senza, dalla seconda
   pagina in poi la tabella e' una colonna di numeri senza nome. */
table.items thead { display: table-header-group; }
table.items tr { page-break-inside: avoid; }

table.items th {
    border-bottom: 1pt solid #1a1a1a;
    padding: 5px 6px;
    font-size: 7.5pt;
    font-weight: bold;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    text-align: left;
    color: #1a1a1a;
}

table.items td {
    border-bottom: 0.5pt solid #e4e4e4;
    padding: 6px;
    font-size: 8.5pt;
    vertical-align: top;
}

table.items th.num, table.items td.num { text-align: right; white-space: nowrap; }

/* Larghezze dichiarate una volta sola e sommate a 100: senza, la descrizione
   si prendeva lo spazio che voleva e le colonne degli importi ballavano da un
   documento all'altro. Le due varianti dipendono dalla colonna sconto. */
/* Oggetto e descrizione stanno nella stessa cella (oggetto in grassetto,
   descrizione sotto); la colonna codice, quando c'e', si prende la sua quota
   da quella cella. */
.items-4 .c-desc  { width: 64%; }
.items-4.has-code .c-code { width: 14%; }
.items-4.has-code .c-desc { width: 50%; }
.items-4 .c-qty   { width: 10%; }
.items-4 .c-price { width: 13%; }
.items-4 .c-total { width: 13%; }

.items-5 .c-desc     { width: 55%; }
.items-5.has-code .c-code { width: 13%; }
.items-5.has-code .c-desc { width: 42%; }
.items-5 .c-qty      { width: 9%; }
.items-5 .c-price    { width: 12%; }
.items-5 .c-discount { width: 11%; }
.items-5 .c-total    { width: 13%; }

.item-name { font-weight: bold; }
.item-desc { margin-top: 2px; color: #4a4a4a; }
.item-code { white-space: nowrap; color: #4a4a4a; }
.item-unit { font-size: 7.5pt; color: #6b6b6b; }

/* -------------------------------------------------------------------------
   Chiusura: pagamento a sinistra, totali a destra
   ---------------------------------------------------------------------- */

/* I totali stavano in fondo alla tabella righe, come righe con un colspan che
   attraversava mezza pagina vuota: l'etichetta finiva lontanissima dalla cifra
   e per leggere "Saldo" bisognava seguire il rigo. Qui sono un blocco stretto
   e appoggiato a destra, e a sinistra resta lo spazio per il come si paga. */
.doc-close { margin-bottom: 18px; page-break-inside: avoid; }
.doc-close td.aside { width: 55%; padding-right: 20px; }
.doc-close td.sums { width: 45%; }

/* Senza estremi di pagamento (preventivi, fatture senza IBAN) la colonna
   sinistra resta vuota. Prima in quel caso i totali si allargavano a tutta
   pagina: l'etichetta finiva sul bordo sinistro e la cifra su quello destro,
   ottocento punti di distanza, mentre la stessa fattura con l'IBAN li teneva
   in un blocco stretto a destra. Ora il blocco resta lo stesso in entrambi i
   casi, così fattura e preventivo della stessa azienda si leggono uguali. */
.doc-close.no-aside td.aside { width: 55%; padding-right: 20px; }
.doc-close.no-aside td.sums { width: 45%; }

table.sums { width: 100%; border-collapse: collapse; }
table.sums td { padding: 4px 0; font-size: 8.5pt; border: none; }
table.sums td.k { color: #6b6b6b; }
table.sums td.v { text-align: right; white-space: nowrap; }

table.sums tr.is-total td {
    border-top: 0.5pt solid #9a9a9a;
    padding-top: 6px;
    font-size: 10pt;
    font-weight: bold;
    color: #1a1a1a;
}

/* Il saldo e' la cifra per cui il documento viene aperto: e' l'unica in
   negativo, cosi' e' la prima che si trova sfogliando un raccoglitore. */
table.sums tr.is-balance td {
    border-top: 0.5pt solid #1a1a1a;
    background: <?= $accentColor ?>;
    color: #ffffff;
    padding: 6px 8px;
    font-size: 10.5pt;
    font-weight: bold;
}

.block { page-break-inside: avoid; }
.block + .block { margin-top: 12px; }

.block-title {
    font-size: 7pt;
    font-weight: bold;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #6b6b6b;
    margin-bottom: 3px;
}

.block-body { font-size: 8.5pt; }
.block-body .kv-k { color: #6b6b6b; }

/* IBAN e codici: spaziatura da registro, si copiano a mano. */
.mono { font-family: "DejaVu Sans Mono", Courier, monospace; font-size: 8.5pt; letter-spacing: 0.02em; }

/* -------------------------------------------------------------------------
   Piede fisso
   ---------------------------------------------------------------------- */

/* Ripiego per quando lo script dell'anteprima non gira (o è disattivato): il
   corpo è una colonna flessibile alta almeno un foglio, così il piede viene
   spinto in fondo (margin-top: auto) invece di restare appeso al testo.
   Con i fogli costruiti da layout/preview_sheet.php il piede è invece
   posizionato in ogni .doc-page (vedi sotto). dompdf renderizza con media
   "print", quindi queste regole non toccano il PDF. */
.doc-footer {
    border-top: 0.5pt solid #d8d8d8;
    padding-top: 5px;
    font-size: 7.5pt;
    color: #6b6b6b;
}
@media screen {
    /* Il corpo e' gia' simulato come foglio A4 (min-height: 297mm) dall'altra
       regola @media screen: basta renderlo colonna flessibile e spingere il
       piede in fondo al foglio, cosi' resta "incollato" in basso anche quando
       il documento e' piu' breve di una pagina. */
    body { display: flex; flex-direction: column; }
    .doc-footer { margin-top: auto; }
}

.doc-footer img { max-width: 150px; max-height: 34px; }

/* Numerazione: un documento di piu' fogli, stampato e spillato, deve dire
   quanti fogli e'. Il numero corrente viene dal contatore CSS, il totale lo
   scrive il template: dompdf non conosce counter(pages) e stampava "1 / 0"
   (vedi pdf_page_total() in ip_helpers). */
.doc-footer .pages:before { content: counter(page) " / "; }

/* Il segnaposto %%PAGINE%% non va mai mostrato così com'è. Nei fogli
   dell'anteprima il "n / N" lo scrive lo script (vedi .doc-page .pages più
   sotto); fuori dai fogli resta nascosto. */
html.ip-sheet-embedded .pages { display: none; }

@media print {
    /* Fisso in fondo a ogni pagina. Il margine inferiore di @page e' 22mm
       proprio per lasciargli posto: prima il piede era fisso senza margine
       riservato e su un documento lungo finiva sopra le ultime righe. */
    .doc-footer {
        position: fixed;
        left: 0;
        right: 0;
        bottom: -14mm;
    }
}

/* Piè di pagina configurabile (impostazione pdf_invoice_footer / pdf_quote_footer):
   testo libero (es. dati fiscali, note legali) che l'utente vuole in calce a
   ogni documento. Va sopra il logo/pagina e resta nello stesso tono sobrio del
   piede, per non competere con il corpo del documento. */
.doc-footer-note {
    text-align: center;
    font-size: 7.5pt;
    color: #6b6b6b;
    margin-bottom: 4px;
    line-height: 1.35;
}
.doc-footer-note p:last-child { margin-bottom: 0; }
