# Impostazioni

Le tabelle e le opzioni che reggono il resto dell'applicazione. Alcune voci
sono dell'installazione (valgono per tutte le aziende), altre sono **per
azienda** e si trovano nella scheda dell'azienda.

## Generali

- **Lingua**, primo giorno della settimana, paese predefinito, formato di date
  e di numeri, elementi per pagina nelle liste.
- **Valuta**: simbolo, codice, posizione rispetto all'importo.
- **Interfaccia**: logo della schermata di accesso (chiaro e scuro), titolo e
  sottotitolo personalizzati, font monospaziato per gli importi.
- **Sistema**: cron key e token del feed calendario, con rigenerazione. Il
  token del calendario è un URL di sola lettura: chi lo possiede vede le
  scadenze.

## Fatture e preventivi

- Termini predefiniti, giorni di scadenza, metodo di pagamento predefinito.
- **Numerazione delle bozze**: se le bozze consumano la numerazione vera o
  ricevono un numero provvisorio.
- Invio automatico delle fatture ricorrenti.
- Passaggio a sola lettura dopo invio o dopo visualizzazione.
- Modello PDF, logo di intestazione e di piè di pagina, testo del piè di
  pagina e impostazioni di stampa.

Le **serie di numerazione** non sono qui: sono per azienda, nella scheda
dell'azienda (`default_invoice_group`, `draft_invoice_group`,
`default_quote_group`, `draft_quote_group`). Le serie stesse si creano da
**Impostazioni > Gruppi di numerazione**.

## Imposte

Aliquota predefinita del documento e delle righe, e ordine di applicazione
(prima o dopo l'imposta di riga). Le aliquote disponibili si gestiscono da
**Impostazioni > Aliquote**.

## Progetti e attività

Attivazione dei progetti (se spenta, la voce sparisce dalla sidebar) e tariffa
oraria predefinita delle attività.

## Tabelle di base

Ognuna è una pagina a sé in Impostazioni:

- **Gruppi di numerazione** (fatture, preventivi, DDT) con formato e serie.
- **Aliquote**, **unità di misura**, **famiglie prodotto**.
- **Metodi di pagamento**, compresi quelli usati per RiBa.
- **Categorie di spesa**.
- **Conti di saldo** (banca, carta, cassa), usati da riconciliazione, RiBa e
  istruzioni di pagamento nel PDF.
- **Parametri fiscali** per anno (aliquote, scaglioni, contributi, regimi):
  servono a Utile e imposte e al controllo di gestione.
- **Campi personalizzati**.

## Aziende

**Impostazioni > Aziende** gestisce il multi-azienda: dati anagrafici e
fiscali, regime, serie di numerazione, casella email di sistema. Il
selettore nel contesto della sidebar cambia l'azienda attiva; documenti e
tabelle operative sono separati per azienda, mentre le impostazioni generali
(lingua, valuta, formato) restano dell'installazione.

Un'azienda si può disattivare senza cancellarla: non compare più nel selettore
ma i dati restano.

## Email

- **Email e mittenti**: ogni mittente ha il proprio SMTP. Non ci sono
  impostazioni SMTP generali: la casella di sistema (notifiche, reset
  password, SdI di ripiego) è scelta per azienda fra i mittenti.
- **Template email**: testi con segnaposto per invio documenti, solleciti e
  notifiche.
- **SdI**: canale (PEC o SDICoop), dati del trasmettitore e opzioni del
  tracciato si configurano nella sezione dedicata delle impostazioni.

Gli utenti, la sicurezza account e l'integrazione MCP non sono in questa
pagina: vedi [accesso-e-integrazioni.md](accesso-e-integrazioni.md).

## Importare ed esportare

- **Importa CSV** (Analisi > Importa / Esporta): clienti, fatture, righe di
  fattura e pagamenti. I modelli con le intestazioni attese si scaricano dalla
  stessa pagina; il file caricato resta nello storico e si può annullare.
- **Esporta**: CSV per Excel, ZIP con i PDF dei documenti, ZIP con gli XML
  FatturaPA. Il periodo si sceglie nel form o con i pulsanti rapidi in coda
  alle liste.
- **Anagrafiche**: clienti e fornitori hanno l'export CSV e l'import diretto
  dalla propria lista.
- **Backup**: il cron `backup` produce un dump del database in
  `writable/backups`, con rotazione. Non è un archivio: i file vanno portati
  fuori dal server.

## Da sapere

- Prima di cambiare una serie di numerazione, controlla l'ultimo numero usato:
  la serie non riparte da sola e un numero già assegnato non si riusa.
- Disattivare un'azienda non nasconde i suoi documenti a chi ha l'accesso
  diretto: cambia solo cosa compare nel selettore.
- Le modifiche alle impostazioni hanno effetto sui documenti nuovi; i
  documenti esistenti non vengono riscritti (per quello c'è il ricalcolo nelle
  liste di fatture e preventivi).
