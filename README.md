<img src="public/assets/core/img/logo_400x200.png" alt="Invoice+" width="400">

# Invoice+

Applicazione self-hosted per la fatturazione: fatture, preventivi, clienti,
fornitori e pagamenti. È un porting su CodeIgniter 4 di InvoicePlane, con
interfaccia in italiano e adattamenti per la fatturazione italiana.

**Stato: in sviluppo.** Le funzioni principali sono utilizzabili e coperte da
test, ma il progetto non è ancora dichiarato stabile per l'uso in produzione su
dati reali senza backup.

## Funzioni

- **Anagrafiche** clienti e fornitori, con schede, ricerca e collegamento ai
  documenti.
- **Preventivi, fatture, DDT**, pagamenti, spese e prodotti, con calcolo di
  totali, imposte e sconti.
- **Fatturazione italiana**: F24, RiBa, scadenzario, invio dei documenti via
  PEC e canale SDI.
- **Riconciliazione bancaria** con importazione degli estratti conto.
- **PDF** dei documenti con modelli sovrascrivibili e nomi file configurabili.
- **Area guest**: link temporanei per far vedere a un cliente i propri
  documenti senza creargli un account.
- **Accesso**: sessioni, modulo MFA e passkey (WebAuthn).
- **Calendario** delle scadenze, esportabile in formato iCalendar.
- **Coda di lavoro** per l'invio dei documenti, con worker da riga di comando.
- **Endpoint MCP** per far pilotare i dati a un assistente, con chiave dedicata.

Lingue disponibili: italiano e inglese.

## Requisiti

- PHP **8.4** o superiore, con le estensioni `intl`, `mbstring`, `json`,
  `curl`, `gd`, `zip` e `pdo`.
- **Composer 2**.
- Un database **PostgreSQL** oppure **MySQL/MariaDB**.
- **Redis** solo se usi cache e coda di lavoro.

## Installazione

```console
composer install
cp env .env
```

Nel file `.env` imposta almeno:

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://tuo-dominio.example/'
database.default.hostname = localhost
database.default.database = invoiceplus
database.default.username = tuo_utente
database.default.password = 'la-tua-password'
database.default.DBDriver = Postgre   # oppure MySQLi
encryption.key = 'chiave-esadecimale-di-32-byte'
```

La chiave di cifratura si genera con `php spark key:generate`.

Quindi crea lo schema:

```console
php spark migrate
```

Il primo amministratore si crea da riga di comando:

```console
php spark users:create
```

Per lo sviluppo:

```console
php spark serve --port 8080
```

Il server web deve puntare alla cartella **public/**, non alla radice del
progetto.

## Test

I test unitari usano SQLite in memoria e non hanno bisogno di altro; i test
d'integrazione (`tests/integration`) leggono invece da un PostgreSQL vero,
perché verificano PDF, XML FatturaPA e pagine complete:

```console
composer test
```

Senza un database quei 25 test si saltano, e la suite resta verde avendo
verificato meno. Per eseguirli in locale basta una copia del database di lavoro
(`createdb -T <database> invoiceplane_posttest`) oppure un altro database
indicato con `IP_PDF_TEST_DB`.

Il workflow `.github/workflows/ci.yml` gira a ogni push e pull request su PHP
8.4 e 8.5: avvia un PostgreSQL, crea lo schema con le migrazioni, carica
`tests/fixtures/ci_seed.sql` e fallisce se un test risulta saltato. I dettagli
sono in `tests/README.md`.

## Licenza e attribuzione

Distribuito con licenza **MIT** (`LICENSE`).

Il codice deriva da **InvoicePlane**, che è MIT: la sua licenza originale è
riprodotta in `LICENSES/InvoicePlane-LICENSE.txt`, come richiesto dai suoi
termini. Il nome e il logo InvoicePlane sono invece marchi di
InvoicePlane.com / Kovah.de con licenza separata, che ne vieta l'alterazione e
l'uso fuori dal progetto originale: questo progetto si chiama "Invoice+" e usa
un marchio proprio, e il nome InvoicePlane compare solo per citarne l'origine.
