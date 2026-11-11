# Registro delle modifiche

Le versioni seguono [Semantic Versioning](https://semver.org/): la patch sale
per le correzioni, il numero minore quando cambia qualcosa di visibile. Il
progetto è ancora in sviluppo (0.x), quindi il maggiore resta 0.

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

[v0.1]: https://github.com/iacos93/invoiceplus/releases/tag/v0.1
