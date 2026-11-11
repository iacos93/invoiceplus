# Incassi e spese

Il denaro che entra (pagamenti) e quello che esce (spese), più la
riconciliazione con l'estratto conto.

## Pagamenti

Un pagamento è l'incasso registrato su una fattura:

- fattura, data, importo, metodo di pagamento e note;
- l'importo può essere **parziale**: il saldo resta aperto e la fattura
  continua a comparire fra quelle da incassare;
- quando la somma dei pagamenti copre il totale, la fattura passa a **pagata**;
  cancellando un pagamento lo stato torna indietro.

Dalla lista si registrano pagamenti anche senza passare dalla fattura, si
eliminano (anche in gruppo) e si consultano i **registri dei pagamenti
online**: i tentativi passati dal gateway di pagamento, con esito e
riferimento, utili quando un cliente dice di aver pagato ma la fattura non
risulta saldata.

## Spese

Il ciclo passivo: fatture e note ricevute dai fornitori.

- fornitore, categoria, data, numero documento, imponibile, IVA e totale;
- stato **da pagare / pagata** con data di pagamento;
- azioni di gruppo (segna come pagata o da pagare, elimina);
- anteprima e download dell'XML della fattura di acquisto, quando la spesa
  arriva da un file FatturaPA.

**Spese > Importa XML** importa fatture di acquisto in blocco. La deduplica
evita di creare due volte la stessa spesa: un file già importato viene
saltato e l'esito lo dice. Se un file non entra, resta disponibile per
l'import manuale senza perdere nulla.

Le **categorie di spesa** si gestiscono in Impostazioni e servono ai report e
al controllo di gestione.

## Riconciliazione bancaria

La pagina mette a confronto i movimenti del conto con fatture e spese aperte.

1. **Importa l'estratto conto** (CSV o CAMT.053) scegliendo il conto di saldo,
   oppure lascia che arrivi dalla sincronizzazione PSD2 se il conto è
   collegato.
2. Per ogni movimento non abbinato l'app propone i **candidati**: documenti
   con lo stesso importo (tolleranza di pochi centesimi) e data vicina, con
   più peso se il numero del documento compare nella causale.
3. Confermi l'abbinamento: il pagamento viene creato e il movimento passa a
   **riconciliato**. Puoi anche **annullare** un abbinamento già fatto.

Il suggerimento non decide: assegnare un incasso alla fattura sbagliata la fa
risultare pagata quando non lo è, e l'errore si scopre tardi. La conferma è
sempre manuale.

I **conti di saldo** (banca, carta, cassa) si configurano in Impostazioni e
servono anche a RiBa e alle istruzioni di pagamento nel PDF delle fatture.

## Da sapere

- I movimenti riconciliati non ricompaiono fra quelli da abbinare; il filtro
  della pagina permette di rivedere anche quelli chiusi.
- Un pagamento registrato a mano e uno arrivato dalla riconciliazione sono la
  stessa cosa: entrambi muovono il saldo della fattura.
- Reimportare lo stesso estratto conto non duplica i movimenti: ogni riga ha
  un'impronta fatta di conto, data, importo e causale normalizzata. Se in
  banca lo stesso movimento cambia importo o data, l'impronta cambia e la
  riga risulta nuova.
