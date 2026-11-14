# Fatture e DDT

Le fatture emesse, le loro copie e i documenti che le accompagnano.

## Stati

| Stato | Significato |
|---|---|
| Bozza | in preparazione, modificabile, numerazione provvisoria |
| Inviata | uscita dalla bozza, numero definitivo assegnato |
| Vista | il cliente ha aperto il link pubblico |
| Pagata | saldata dai pagamenti registrati |

Lo stato "pagata" non si imposta a mano: arriva dai pagamenti (vedi
[incassi-e-spese.md](incassi-e-spese.md)).

## Creare una fattura

Da **Fatture > Nuova fattura** o dalla scheda cliente:

- cliente, date, scadenza, gruppo di numerazione, valuta e metodo di pagamento;
- righe con prodotto o descrizione libera, quantità, prezzo, sconto e aliquota;
- sconti di documento, imposte, ritenuta, note e termini;
- conto di saldo da indicare nel PDF, se l'azienda ne ha più di uno.

La **numerazione** segue il gruppo scelto. Se `generate_invoice_number_for_draft`
è spenta (il caso normale), la bozza porta un numero provvisorio della serie
bozze e il numero definitivo viene assegnato quando la fattura esce dalla
bozza; se è accesa, la bozza consuma subito la numerazione vera.

## Operazioni sul documento

- **Duplica** per ripartire da una fattura simile.
- **Nota di credito**: genera un documento collegato (`creditinvoice_parent_id`)
  con segno negativo, stesse righe, stessi sconti e stessa ritenuta della
  fattura di origine.
- **Ricorrente**: imposta una frequenza (giorni, settimane, mesi o anni) e la
  data della prossima emissione. Le ricorrenze si gestiscono da **Fatture >
  Ricorrenti** (interrompi, elimina) e vengono generate dal cron o a mano.
  Se l'invio automatico è attivo, la nuova fattura parte anche via email.
- **Sollecito**: sulle fatture scadute compare il pulsante che apre l'invio
  già impostato come sollecito (template e PDF dedicati). L'invio è manuale;
  la data dell'ultimo sollecito resta sul documento.
- **Azioni di gruppo** dalla lista: preferiti, segna come inviata, elimina.
- **Ricalcola tutte** riscrive gli importi di tutte le fatture dell'azienda
  attiva: serve dopo una modifica alle aliquote, non è un'operazione da fare
  per abitudine.

**Filtro per periodo.** Sopra l'elenco c'è la barra dei mesi: un clic mostra
un mese solo, **Shift+clic** estende l'intervallo dal mese già scelto,
**Ctrl/⌘+clic** aggiunge o toglie un mese singolo. La selezione multipla vale
anche per i totali sotto la barra (documenti, imponibile, IVA, lordo, saldo).
**Anno intero** riaccende tutti e dodici i mesi; l'anno si sceglie dal filtro
globale in alto.

## PDF, XML e invio

- **PDF**: anteprima dal documento, modello scelto nelle impostazioni
  (`pdf_invoice_template`), con varianti per fattura pagata e sollecito.
- **XML FatturaPA**: generazione, anteprima e validazione. Per l'estero è
  disponibile anche l'XML ZUGFeRD.
- **Email**: schermata di invio con template e PDF allegato.
- **SdI**: invio della fattura al Sistema di Interscambio (canale PEC o
  SDICoop), con gli esiti RC/NS/NE/EC/MC/DT riportati sul documento; il cron
  `sdi_poll` importa le ricevute dalla casella PEC. Una fattura già trasmessa
  (anche importata da un altro gestionale, esito IMP) non va rinviata: il
  secondo invio sarebbe un duplicato.

## Importare fatture emesse

**Fatture > Importa XML** accetta file XML, `.p7m` o ZIP di fatture già
emesse con un altro gestionale. Il file deve essere una fattura emessa (non
un acquisto): gli acquisti si importano da **Spese > Importa XML**. Le
fatture importate nascono con i dati del file, cliente incluso, e restano
riconoscibili come importate.

## DDT

I **documenti di trasporto** descrivono cosa viaggia, non il valore: nel PDF
non compaiono i prezzi, ma quantità, descrizioni e riferimenti.

- Si creano da zero oppure **da una fattura o un preventivo** già compilato.
- Finché non sono collegati a una fattura risultano **da fatturare**; con
  **Crea fattura** si genera la fattura collegata e il DDT passa a
  **fatturato**.
- Dalla lista si scarica il PDF (o l'anteprima HTML) e si eliminano i DDT non
  più necessari.

## Da sapere

- **Fatture bloccate.** Una fattura trasmessa allo SdI diventa di sola
  lettura: la pagina di modifica non si apre, le righe, le imposte e il
  cliente non si cambiano, e non si elimina. Il motivo è che lo SdI ha la
  copia a cui l'app deve restare uguale; per correggerla si emette una nota di
  credito. Restano possibili i pagamenti e le note di credito. Lo scarto
  (`NS`) la sblocca da sola, perché va corretta e rinviata. Dalla scheda,
  accanto a "Bloccata", compare **Sblocca** per le fatture che lo SdI non ha
  per buone: importate da un altro gestionale, mai trasmesse, scartate o solo
  scaricate dal canale manuale. Con il canale manuale la fattura si blocca
  quando registri l'esito dalla pagina (per esempio la ricevuta di consegna),
  non quando scarichi l'XML. Le fatture importate dal vecchio gestionale sono
  segnate in sola lettura e prima di questo aggiornamento nessuna pagina lo
  rispettava: ora sì, e per correggerle serve **Sblocca**.
- **Avvisi dello SdI.** Con SDICoop, uno scarto, una mancata consegna o
  un'impossibilità di recapito alla PA arrivano anche per email alla casella
  di sistema (o al primo amministratore), con il motivo e cosa fare. Serve una
  casella di invio configurata in Impostazioni > Email e mittenti.
- Il link pubblico della fattura (`guest/view/invoice/...`) mostra il
  documento a chi lo possiede; il PDF si scarica da lì senza account.
- Una fattura vista dal cliente passa a "vista" solo se lo stato era ancora
  "inviata".
- L'archivio (`Fatture > Archivio`) elenca i PDF presenti in
  `writable/uploads/archive`: non è la lista delle fatture, è la cartella
  dell'archivio con ricerca per numero.
