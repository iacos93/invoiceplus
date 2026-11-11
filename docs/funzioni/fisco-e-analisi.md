# Fisco e analisi

Le schermate che leggono i numeri: F24, RiBa, scadenzario, calendario, utile e
imposte, controllo di gestione e report.

## F24

Le deleghe di pagamento delle imposte, con periodo, scadenza, importo e stato
(aperto / pagato). Da qui si scarica il PDF della delega e si registra il
pagamento.

**F24 > Importa quietanze** legge i PDF delle ricevute di versamento: se
riconosce protocollo, data e importo abbina la quietanza alla delega e la
segna come pagata; se non trova la delega corrispondente crea comunque un F24
da quella quietanza, perché il versamento è avvenuto. Una quietanza con lo
stesso protocollo già importato viene scartata come duplicato.

## RiBa

Presentazione delle ricevute bancarie:

1. si scelgono le fatture da incassare (quelle emesse con un metodo RiBa
   arrivano già in cima e spuntate);
2. si sceglie il conto di saldo e si genera il flusso CBI da caricare
   nell'home banking;
3. le fatture presentate restano marcate con il riferimento della distinta,
   così non vengono ripresentate per errore.

## Scadenzario

Registro delle **scadenze attive**: le fatture emesse con un saldo residuo,
raggruppate in scadute, entro 7 giorni, entro 30 giorni e oltre 30 giorni.
L'anno scelto nel contesto lavora sull'anno di scadenza. Da qui parte il
sollecito delle fatture scadute.

Lo scadenzario non contiene scadenze passive: le spese da pagare si vedono
nella pagina Spese.

## Calendario

Vista mensile di fatture in scadenza, F24 e attività con una data. Il primo
giorno della settimana si imposta in Impostazioni.

Il calendario è anche **sottoscrivibile**: un feed iCal protetto da token
(Impostazioni > Calendario) permette di aggiungerlo a Google Calendar o a un
client CalDAV. Rigenerare il token invalida i feed già sottoscritti.

Il cron `digest` manda inoltre una email settimanale con le scadenze scadute o
nei prossimi 7 giorni: stessa fonte dati della pagina.

## Utile e imposte

Prospetto annuale di entrate e uscite con una **stima** delle imposte, pensata
per accantonare durante l'anno: non è un calcolo fiscale. Le aliquote reali
dipendono da altri redditi, deduzioni, acconti e regimi agevolati, che
l'applicazione non conosce; il calcolo definitivo resta del commercialista.

Il prospetto si esporta in CSV (per Excel) e in PDF (per chi lo deve leggere o
girare al commercialista). L'anno è quello scelto nel contesto della sidebar.

## Controllo di gestione

Quattro schermate, una sola fonte di numeri:

- **Bilancino**: conto economico dell'anno per voce di ricavo e di costo.
- **Flusso di cassa**: entrate e uscite mese per mese.
- **Piano dei conti**: le voci usate per classificare ricavi e costi; se ne
  può importare uno standard e modificarle.
- **Budget**: valori previsti per voce e periodo, confrontati con il
  consuntivo.
- **Assegna voci**: collega le righe di fatture, spese e documenti alle voci
  del piano dei conti.

Bilancino, flusso di cassa e budget si esportano in CSV e PDF, con gli stessi
numeri della pagina.

## Report

- **Fatture per scadenza** (aging): quanto è aperto e da quanto tempo.
- **Storico pagamenti**: incassi del periodo scelto.
- **Vendite per cliente**: fatturato per cliente nel periodo.
- **Vendite per anno**: confronto fra anni.

I report hanno i propri filtri di periodo; il contesto anno della sidebar non
li sostituisce.

## Da sapere

- Le voci del piano dei conti sono dell'azienda attiva: cambiando azienda
  cambia il perimetro del bilancino.
- Il feed del calendario e le quietanze F24 sono file e token: non vanno
  condivisi fuori dall'azienda.
