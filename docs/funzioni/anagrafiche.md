# Anagrafiche

Clienti, fornitori, prodotti e le tabelle che li accompagnano.

## Clienti

La lista mostra i clienti attivi e non attivi, con ricerca e filtri; dalla
scheda si crea subito una fattura o un preventivo già intestati al cliente.

Nella scheda cliente trovi:

- **Riepilogo economico**: fatturato, incassato, scaduto, numero di documenti.
- **Andamento** del fatturato e degli incassi e composizione per stato.
- **Contatti**, **note** (con salvataggio dalla scheda) e lo storico di
  preventivi, fatture e pagamenti.
- **Dati fiscali**: P.IVA, codice fiscale, codice destinatario SdI o PEC,
  regime di split payment, tipo cliente (azienda, persona fisica, pubblica
  amministrazione), lingua dei documenti.
- **Condizioni commerciali**: sconto predefinito, giorni di pagamento, metodo
  di pagamento, aliquota predefinita.
- **IBAN** e riferimento per gli incassi.

Dalla lista si possono **esportare** i clienti in CSV (con il modello delle
intestazioni scaricabile) e **importarne** un file. Lo stesso vale per i
fornitori. Un cliente non attivo resta consultabile ma non compare nei
selettori dei nuovi documenti.

I clienti possono essere collegati a un **utente cliente**: quell'account vede
solo i propri documenti nell'area guest (vedi
[accesso-e-integrazioni.md](accesso-e-integrazioni.md)).

## Fornitori

Anagrafica separata dai clienti, usata dalle spese e dai documenti di acquisto.
Ha gli stessi dati fiscali e di contatto, la scheda con i documenti collegati,
l'export CSV e l'import.

## Prodotti, famiglie e unità

- **Prodotti**: descrizione, prezzo, aliquota, unità di misura, famiglia,
  sconto e note. Nelle righe di preventivi e fatture si può partire da un
  prodotto e poi modificare la riga senza toccare l'anagrafica.
- **Famiglie**: raggruppamento dei prodotti, usato nei filtri e nelle liste.
- **Unità di misura**: quelle selezionabili nelle righe dei documenti.

## Attività e progetti

Se l'opzione `projects_enabled` è attiva (Impostazioni > Generali), la sidebar
mostra **Attività** con progetti e task. Le attività hanno stato (non iniziata,
in corso, completata, in attesa), prezzo, aliquota e data di fine, e si
collegano a un progetto (e quindi al cliente del progetto); servono a tenere
traccia del lavoro da fatturare, non generano documenti da sole.

## Campi personalizzati

**Impostazioni > Campi personalizzati** definisce campi aggiuntivi per le
anagrafiche e i documenti; **Valori personalizzati** raccoglie i valori
inseriti. Un campo personalizzato non elimina né rinomina i campi standard:
si aggiunge accanto.

## Da sapere

- La creazione di un cliente rifiuta un nome e cognome già presenti: per due
  omonimi serve distinguerli (secondo nome, ragione sociale, codice).
- L'import CSV non unisce i duplicati: un cliente già presente viene creato di
  nuovo se il file lo ripete.
- L'eliminazione non è bloccata dai documenti collegati: cancellare un cliente
  con storico lascia fatture e preventivi senza anagrafica. In quel caso
  conviene disattivarlo.
