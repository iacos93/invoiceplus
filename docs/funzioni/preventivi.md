# Preventivi

Il preventivo è il documento che precede la fattura: si prepara, si invia al
cliente e, se il cliente lo approva, diventa una fattura in bozza.

## Stati

| Stato | Significato |
|---|---|
| Bozza | in preparazione, non ancora inviato |
| Inviato | mandato al cliente (email o link), nessuna decisione |
| Visto | il cliente ha aperto il link pubblico |
| Approvato | il cliente ha accettato |
| Rifiutato | il cliente ha rifiutato |
| Cancellato | annullato da te |

Lo stato "visto" viene messo quando il documento viene aperto dal cliente
(link pubblico o area guest), non quando lo apri tu dall'area amministrativa.

## Creare un preventivo

Da **Preventivi > Nuovo preventivo**, oppure dalla scheda cliente con il
cliente già impostato:

- cliente, date, data di scadenza, gruppo di numerazione e valuta;
- righe con prodotto o descrizione libera, quantità, prezzo, sconto e aliquota;
- sconti a livello di documento, imposte, note e termini;
- riordino delle righe e ricalcolo dei totali.

Dalla lista puoi **duplicare** un preventivo (utile per offerte simili),
segnarlo **preferito**, applicare azioni di gruppo (segna come inviato,
elimina) e **ricalcolare** gli importi di tutti i preventivi dopo una modifica
alle aliquote.

**Filtro per periodo.** Sopra l'elenco c'è la barra dei mesi: un clic mostra
un mese solo, **Shift+clic** estende l'intervallo dal mese già scelto,
**Ctrl/⌘+clic** aggiunge o toglie un mese singolo. Così si guardano, per
esempio, solo i preventivi di settembre e ottobre senza cambiare pagina.
**Anno intero** riaccende tutti e dodici i mesi; l'anno si sceglie dal filtro
globale in alto, e i totali sotto la barra seguono la selezione.

## Inviare e far approvare

- **Email**: dal documento si apre la schermata di invio con il template
  dell'email e il PDF allegato.
- **Link pubblico**: il preventivo ha una chiave nell'URL
  (`guest/view/quote/...`) che si può girare al cliente. Il link può essere
  protetto da password; senza password chiunque abbia l'URL vede il documento.
- Il cliente apre, legge e può **approvare** o **rifiutare**. L'operazione
  cambia lo stato del documento, quindi passa da un pulsante con token CSRF:
  non basta visitare un indirizzo.

L'approvazione e il rifiuto sono possibili solo da "inviato" o "visto"; su un
preventivo già deciso il secondo clic non cambia nulla e lo dice.

## Da preventivo a fattura

**Crea fattura** genera una fattura in **bozza** con le stesse righe, lo stesso
cliente e gli stessi sconti. La numerazione è quella delle fatture (il gruppo
predefinito dell'azienda, o quello scelto al momento), non quella dei
preventivi; il preventivo resta collegato alla fattura creata.

## PDF e personalizzazione

Il PDF si genera con lo stesso motore delle fatture, dal modello scelto nelle
impostazioni (`pdf_quote_template`) fra quelli presenti in
`app/Views/quote_templates/pdf/`. Aggiungere un modello vuol dire aggiungere
una vista al codice: non è una personalizzazione da interfaccia.

## Da sapere

- Il link pubblico resta valido finché il documento esiste: se il preventivo
  non deve più essere visibile, cancellalo o mettilo in uno stato non aperto.
- "Approvato" non crea la fattura da solo: la conversione è sempre un gesto
  esplicito.
- Le azioni di gruppo della lista agiscono solo sui preventivi selezionati;
  il ricalcolo riguarda invece tutti quelli dell'azienda attiva.
