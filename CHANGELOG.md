# Registro delle modifiche

Le versioni seguono [Semantic Versioning](https://semver.org/): con il progetto
ancora in sviluppo (0.x) il numero minore sale a ogni versione pubblicata.

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
