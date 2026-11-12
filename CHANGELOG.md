# Registro delle modifiche

Le versioni seguono [Semantic Versioning](https://semver.org/): la patch sale
per le correzioni, il numero minore quando cambia qualcosa di visibile. Il
progetto è ancora in sviluppo (0.x), quindi il maggiore resta 0.

Le regole per scrivere le voci e preparare una release sono in
[docs/changelog.md](docs/changelog.md).

## [Non pubblicata]

## [v0.7.1] — 2026-11-12

### Aggiunto

- **Pulizia del tracciamento email**: il cron settimanale elimina le righe più
  vecchie di un anno e quelle di fatture o preventivi eliminati. Prima la
  tabella cresceva a ogni invio e non calava mai, e i documenti cancellati
  lasciavano righe che nessuna pagina poteva più mostrare.

### Modificato

- **Privacy del tracciamento**: l'indirizzo IP viene salvato in forma anonima
  (rete, non singolo host) e lo user agent non viene più memorizzato. Erano
  dati personali che nessuna schermata mostrava.
- **"Prima apertura il …"** invece di "Aperta il …": il pixel dice quando è
  stata rilevata la prima richiesta dell'immagine, non quando la persona ha
  letto il messaggio.

## [v0.7] — 2026-11-12

### Aggiunto

- **Tracciamento delle aperture email** per fatture e preventivi: le email
  portano un pixel invisibile e la pagina del documento mostra gli invii con
  data, destinatario e apertura. Il conteggio è indicativo, perché chi blocca
  le immagini non carica il pixel e alcuni client le scaricano in anticipo;
  la nota è scritta accanto all'elenco. Si spegne da **Email e mittenti**. Se
  il tracciamento non è raggiungibile l'email parte lo stesso, e un invio
  fallito non lascia righe nella pagina del documento.

### Modificato

- **Le email partono in HTML**: i a capo del testo semplice non si perdono e i
  caratteri speciali (`&`, `<`) non vengono interpretati come markup. Prima il
  corpo era inviato così com'era, con i `<br>` del testo semplice visibili in
  alcuni client.

## [v0.6] — 2026-11-12

### Aggiunto

- **Selezione multipla dei mesi** spiegata sotto la barra del periodo in
  fatture, preventivi e spese: un clic sceglie un mese, Shift+clic estende
  l'intervallo, Ctrl/⌘+clic aggiunge o toglie un mese. La funzione c'era già,
  ma senza istruzioni restava invisibile. La riga compare solo con JavaScript
  attivo, perché senza script i mesi sono link singoli.

### Modificato

- **Stato del sistema** separa i controlli che richiedono un intervento da
  quelli regolari: i primi stanno in cima con il comando suggerito in evidenza,
  gli altri in un gruppo a parte. Prima dieci righe dello stesso peso chiedevano
  la stessa attenzione e un guasto si perdeva tra i "regolare".

### Corretto

- **Polling SdI**: quando la casella IMAP non è configurata il job si ferma
  senza scrivere un errore nel log. Prima `cron/sdi_poll` registrava un ERROR a
  ogni giro (ogni 15 minuti) per l'estensione `php-imap` mancante, anche se il
  canale SdI non usava la PEC.
- **Migrazione fornitori**: `BackfillSuppliers` inserisce `true` nella colonna
  `supplier_active`. Su PostgreSQL l'inserimento di `1` in una colonna boolean
  faceva fallire le migrazioni di un database nuovo.
- **Log del download del backup**: riporta il nome del dump scaricato. Il
  segnaposto `{file}` è riservato dal logger di CodeIgniter e veniva sostituito
  con il file del controller (`APPPATH/Controllers/SystemStatus.php`).

### Sicurezza

- **Cron key fuori dal repository**: `tools/cron-runner.sh` legge `BASE_URL` e
  `CRON_KEY` da `writable/cron-runner.conf`, che non è versionato; i file
  tracciati restano con i segnaposto. La chiave non finisce più in un commit.

## [v0.5] — 2026-11-12

### Aggiunto

- **Invia fattura** e **Invia preventivo** per email dalla pagina del documento
  e dall'elenco: apre la schermata con template, mittente, destinatari e PDF
  allegabile. Il Mailer c'era già, ma ci si arrivava solo dallo scadenzario e
  dal sollecito, quindi dalla fattura sembrava che la funzione non esistesse.

## [v0.4] — 2026-11-12

### Aggiunto

- **Gestione > Stato del sistema**: il controllo "Backup del database" offre
  **Scarica l'ultimo backup**, per portare la copia fuori dal server. Il file
  è sempre l'ultimo dump valido (niente percorsi nell'URL), il pulsante compare
  solo se esiste e il download resta riservato agli amministratori.
- Un backup fallito manda una **email** alla casella di sistema (o al primo
  amministratore): prima restava solo nel log e in un HTTP 500. Il freno è di
  dodici ore, azzerato dal primo backup riuscito.
- **Test nel browser** con Playwright (login, tema chiaro/scuro persistente,
  ricerca nelle liste, Ctrl+K) e job dedicato in CI.
- **Misura della copertura** in CI, con soglia minima sotto il valore attuale
  per intercettare un crollo senza bloccare i refactoring.
- `backup.pgDumpPath` e `backup.mysqldumpPath` sono nel `env` di esempio, dove
  chi installa li cerca, oltre che nella guida.

### Modificato

- **Gestione > Stato del sistema**: riepilogo in testa con esito complessivo e
  conteggi per stato; i controlli sono una lista con icona, etichetta,
  dettaglio e suggerimento a tutta larghezza, invece di una tabella a tre
  colonne. Guasti e avvisi salgono in cima; in testa ci sono l'orario del
  rilevamento e il pulsante "Aggiorna".

### Corretto

- Il dettaglio delle migrazioni diceva "1 migrazioni da applicare": ora dice
  "1 migrazione".
- **Errori nel log** leggeva solo gli ultimi 200 KB del file: in una giornata
  piena un errore scritto la mattina restava invisibile e la pagina diceva
  "nessun errore". Ora scorre tutto il file una riga per volta e indica l'ora
  dell'ultimo errore; un log non leggibile è un avviso, non un "tutto ok".

### Sicurezza

- I dump nascono con permessi **0600** invece di 0644: contengono dati
  personali e fiscali e non devono essere leggibili da ogni utente locale.
- Un test verifica che `custom.min.css` corrisponda alla minificazione fresca di
  `custom.css`: in produzione si serve il compilato, e senza questa guardia una
  modifica al foglio poteva non arrivare mai alla pagina.

## [v0.3] — 2026-11-12

### Aggiunto

- `php spark backup:verify`: prova che l'ultimo dump si ripristini davvero.
  Lo carica in un database temporaneo `<database>_verify_<timestamp>`,
  confronta i conteggi delle tabelle principali e il totale fatturato, poi
  elimina la copia; l'esito finisce in `writable/cron/backup_verify.json`.
  Serve un utente con permesso di creazione database (`CREATEDB` su
  PostgreSQL, `CREATE` su MySQL/MariaDB).
- Quattro controlli nuovi in **Gestione > Stato del sistema**: verifica del
  ripristino (con avviso se l'ultimo dump non è stato provato; un permesso di
  creazione database mancante è un avviso, non un guasto), ambiente
  dell'applicazione (`CI_ENVIRONMENT`, per accorgersi di un'installazione con
  dati veri lasciata in sviluppo), spazio libero sul disco, errori nel log di
  oggi.

### Modificato

- `tools/cron-runner.sh` esegue `backup:verify` una volta a settimana dopo il
  backup, con il binario PHP configurabile in `PHP_BIN` (in crontab il PATH è
  ridotto).

### Corretto

- Con utente del database vuoto (connessione come utente di sistema) i comandi
  di dump passavano `-U ''`; la verifica del ripristino perdeva anche il nome
  del database da creare. Ora l'opzione viene omessa quando l'utente non è
  configurato.

## [v0.2] — 2026-11-11

### Aggiunto

- **Gestione > Stato del sistema**: pagina in sola lettura con i controlli che
  possono guastarsi in silenzio — versione di PHP, cartelle scrivibili,
  connessione al database, migrazioni da applicare, età dell'ultimo backup,
  ultima esecuzione delle automazioni. Un backup assente o vecchio, o un
  cron-runner fermo, ora si vedono senza aprire i log del server.
- Il backup automatico funziona anche su MySQL/MariaDB: l'endpoint sceglie
  `pg_dump` o `mysqldump` dal motore configurato (`backup_*.dump` e
  `backup_*.sql`), con rotazione separata per formato. Prima lanciava sempre
  `pg_dump`, quindi su un'installazione MariaDB falliva parlando di PostgreSQL.
  Il binario di `mysqldump` si può indicare in `backup.mysqldumpPath`.
- Test sul comando di dump (argomenti, password fuori dalla riga di comando,
  quotatura dei nomi), sulla rotazione e sui controlli di stato.

### Modificato

- `tools/cron-runner.sh` scrive log e stato dei job in `writable/cron` invece
  che in `~/.invoiceplane-cron` (con ripiego sulla home se la cartella non è
  scrivibile): è il posto da cui la pagina "Stato del sistema" legge se le
  automazioni girano. Un log fuori dall'applicazione non lo guarda nessuno.

## [v0.1.1] — 2026-11-11

### Aggiunto

- Test d'integrazione sugli incassi (eliminazione, modifica dell'importo,
  importo oltre il saldo, fattura inesistente), sulle spese (totale derivato,
  tendine vuote, fornitore digitato a mano, modifica, fornitore mancante) e
  sulla conversione preventivo → fattura (righe, sconto di riga, data e
  collegamento fra i documenti).
- Il fornitore della fixture di test ora ha un'azienda: senza, i test che lo
  cercano nell'azienda attiva lo saltavano.
- Test sul `pg_dump` da usare: versione letta dal server, scelta del primo
  binario abbastanza recente e ripiego sul più nuovo disponibile.
- Analisi statica con PHPStan (livello 3) e `composer audit` in CI, più il
  comando `composer analyse` per eseguirla in locale.
- Guida `docs/installazione-e-aggiornamento.md`: aggiornamento, backup,
  ripristino verificato e checklist da passare sull'ambiente reale.

### Corretto

- `GenerateFatturaJob` chiamava `FatturaXml::generate()`, che non esiste: il
  job in coda sarebbe fallito a ogni esecuzione. Ora usa `FatturaPaXmlService`.
- `ClientModel::delete()` e `InvoiceModel::delete()` non restituivano l'esito
  della cancellazione, quindi un errore del database passava per riuscita.
- La lingua italiana aveva due chiavi `new`: la seconda sovrascriveva la
  prima in silenzio.
- `ProjectModel::getSummary()` usava `get()->getRowArray()` su un metodo del
  model che PHPStan non risolve; ora usa `first()`.
- Documentazione di `set_node()`, `_set_anagr()`, `_to_xml()`, `Cryptor` e
  `Management::csv()` allineata ai parametri e ai tipi reali; `FatturaPA`
  converte esplicitamente a float l'aliquota letta dalla chiave.
- `tools/cron-runner.sh` chiamava `/cron/remind_overdue/`, rotta che non
  esiste: ogni giorno il job finiva in 404 e nel log sembrava un guasto. I
  solleciti si inviano a mano dalla scheda della fattura e la riga è stata
  tolta.
- Il backup automatico non produceva nessun file quando il `pg_dump` nel PATH
  era di versione inferiore al server (16 contro 17.11): ora l'endpoint sceglie
  il binario adatto fra i percorsi installati, e `backup.pgDumpPath` resta la
  scelta esplicita.
- Un backup fallito rispondeva `200` con il messaggio d'errore nel corpo, e il
  cron-runner lo registrava come riuscito: ora risponde `500`, viene annotato
  `FALLITO` e riprovato al giro successivo.

## [v0.1] — 2026-11-11

Prima versione pubblica. Comprende fatture e note di credito con fatturazione
elettronica (XML FatturaPA, PDF, invio allo SdI), preventivi, DDT, incassi,
spese, ritenute, F24, RiBa, bilancino e piano dei conti, gestione multi-azienda,
portale pubblico per i documenti e API per l'automazione.

### Aggiunto

- Test d'integrazione sulle scritture di fatture e preventivi: totali con più
  righe e aliquote diverse, sconti di riga e di documento, modifica e rimozione
  delle righe, duplicazione, eliminazione, incasso parziale, creazione di un
  preventivo con testata e righe. Girano in transazione con rollback e usano il
  token CSRF della pagina del form.
- Job di CI su MariaDB accanto a quello su PostgreSQL, così i rami del codice
  che dipendono dal motore vengono eseguiti almeno una volta a ogni push.
- Fixture `tests/fixtures/ci_seed_mysql.sql`, con gli stessi dati della versione
  PostgreSQL.
- `CHANGELOG.md`, `SECURITY.md` e copertina del progetto nel README.

### Corretto

- La creazione di una fattura senza cliente (o senza data e gruppo di
  numerazione) finiva su `/invoices/view/0` senza spiegazioni: le regole di
  validazione non venivano caricate e, anche caricandole, i campi assenti dal
  POST non venivano controllati. Ora il form si riapre con il motivo.
- Stesso difetto nella creazione di un preventivo senza cliente, che in più
  arrivava a calcolare gli importi di un id inesistente.
- Le migrazioni non si applicavano a un database MySQL/MariaDB vuoto: un
  `DROP INDEX` su un indice mai creato, `CREATE INDEX IF NOT EXISTS` (che MySQL
  non accetta), `UPDATE ... FROM` e cast `::tipo` solo PostgreSQL.
- I conteggi delle ricorrenti (`NOW()::date`) e il progressivo SdI
  (`(setting_value::int + 1)::text`) usavano sintassi PostgreSQL e su MySQL
  fallivano a runtime.

### Rimosso

- Script di verifica una tantum dalla radice (`smoke_test.php`, `test_db.php`),
  il comando temporaneo `audit:check` e la cartella `pg-compat/`, sostituiti
  dai test automatici. `tag_check.php` resta come strumento di sviluppo in
  `tools/`.

[v0.3]: https://github.com/iacos93/invoiceplus/releases/tag/v0.3
[v0.2]: https://github.com/iacos93/invoiceplus/releases/tag/v0.2
[v0.1]: https://github.com/iacos93/invoiceplus/releases/tag/v0.1
