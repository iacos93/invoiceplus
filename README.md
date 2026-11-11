<p align="center">
  <a href="https://github.com/iacos93/invoiceplus">
    <img src="public/assets/core/img/logo_400x200.png" alt="Invoice+" width="360">
  </a>

  <p align="center">
    La fatturazione self-hosted per chi lavora in Italia: fatture, preventivi,
    incassi e scadenze in un unico registro.
    <br>
    <br>
    <a href="docs/funzioni.md">Documentazione</a>
    &middot;
    <a href="#installazione">Installazione</a>
    &middot;
    <a href="https://github.com/iacos93/invoiceplus/issues">Segnalazioni</a>
  </p>
</p>

[![CI](https://github.com/iacos93/invoiceplus/actions/workflows/ci.yml/badge.svg)](https://github.com/iacos93/invoiceplus/actions/workflows/ci.yml)
[![Licenza](https://img.shields.io/badge/licenza-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.4%2B-777BB3?logo=php&logoColor=white)](https://www.php.net)
[![CodeIgniter](https://img.shields.io/badge/codeigniter-4.7-EF4223?logo=codeigniter&logoColor=white)](https://codeigniter.com)

![Copertina di Invoice+: la fatturazione self-hosted per l'Italia, accanto alla Bacheca dell'applicazione](docs/img/copertina.png)

Invoice+ è un'applicazione web **self-hosted** per gestire la fatturazione di
una piccola azienda o di uno studio: anagrafiche, preventivi, fatture, DDT,
incassi, spese e scadenze, con i dati che restano sul tuo server.

Nasce come porting su [CodeIgniter 4](https://codeigniter.com) di
[InvoicePlane](https://invoiceplane.com), di cui conserva il modello dei dati e
buona parte del flusso di lavoro, aggiungendo gli adattamenti che servono in
Italia: fattura elettronica e canale SDI, F24, RiBa, split payment, ritenute e
cassa previdenziale. L'interfaccia è in italiano e in inglese.

> **Stato: in sviluppo.** Le funzioni principali sono utilizzabili e coperte da
> test, ma il progetto non è ancora dichiarato stabile per l'uso in produzione
> su dati reali senza backup.

<!-- TOC depthFrom:2 depthTo:6 orderedList:false updateOnSave:true withLinks:true -->

- [Funzioni principali](#funzioni-principali)
- [Schermate](#schermate)
- [Documentazione](#documentazione)
- [Requisiti software](#requisiti-software)
- [Requisiti hardware](#requisiti-hardware)
- [Installazione rapida](#installazione-rapida)
- [Installazione](#installazione)
- [Versioni](#versioni)
- [Strumenti di sviluppo e debug](#strumenti-di-sviluppo-e-debug)
- [Test](#test)
- [Community e supporto](#community-e-supporto)
- [Contribuire](#contribuire)
- [Licenza e attribuzione](#licenza-e-attribuzione)

<!-- /TOC -->

## Funzioni principali

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

## Schermate

Le immagini sono prese dai dati di prova (`tests/fixtures/ci_seed.sql`), non da
clienti veri.

### Bacheca

Indice di redditività, ricavi, incassato, saldo da incassare e grafici dei
movimenti:

![Bacheca: indice di redditività in alto, riquadri di ricavi, incassato, saldo da incassare, fatture scadute, spese e IVA](docs/img/bacheca.png)

La stessa pagina nel tema scuro:

![Bacheca in tema scuro, con gli stessi riquadri e gli stessi grafici](docs/img/bacheca-tema-scuro.png)

### Documenti

I documenti che l'applicazione produce:

![Fattura di esempio: intestazione, righe con codice articolo, totali e saldo](docs/img/fattura.png)

![Preventivo di esempio: intestazione, righe e totali](docs/img/preventivo.png)

## Documentazione

Guida alle funzioni, area per area: cosa fa ogni schermata e cosa sapere prima
di usarla.

- [Panoramica e indice](docs/funzioni.md)
- [Anagrafiche](docs/funzioni/anagrafiche.md)
- [Preventivi](docs/funzioni/preventivi.md)
- [Fatture e DDT](docs/funzioni/fatture-e-ddt.md)
- [Incassi e spese](docs/funzioni/incassi-e-spese.md)
- [Fisco e analisi](docs/funzioni/fisco-e-analisi.md)
- [Accesso e integrazioni](docs/funzioni/accesso-e-integrazioni.md)
- [Impostazioni](docs/funzioni/impostazioni.md)

## Requisiti software

L'installazione richiede PHP con [Composer](https://getcomposer.org) e un
database supportato. Non serve Node.js: gli asset sono già compilati e
versionati.

<table>
<tr>
<td valign="top">

| PHP | Fine supporto | Supportato |
|-----|-----|:----------:|
| 8.5 | 31/12/2029 | 🟢 |
| 8.4 | 31/12/2028 | 🟢 |
| 8.3 | 31/12/2027 | 🔴 |
| 8.2 | 31/12/2026 | 🔴 |
| 8.1 | 31/12/2025 | 🔴 |
| 8.0 | 26/11/2023 | 🔴 |
| 7.4 | 28/11/2022 | 🔴 |

</td>
<td valign="top">

| Database | Versione | Supportato |
|-----|-----|:----------:|
| PostgreSQL | 17 (testata in CI) | 🟢 |
| PostgreSQL | 14+ | 🟢 |
| MySQL | 8.0+ | 🟢 |
| MariaDB | 10.11+ | 🟢 |
| SQLite | solo test unitari | 🟡 |

</td>
</tr>
</table>

Fonte delle date di fine supporto: <https://endoflife.date/php>.

Le estensioni PHP necessarie sono `intl`, `mbstring`, `json`, `curl`, `gd`,
`zip`, `pdo` e il driver del database scelto (`pdo_pgsql` oppure `pdo_mysql`).
Per i test unitari servono anche `pdo_sqlite` e `sqlite3`.

La CI esegue la suite su PHP 8.4 e 8.5 con PostgreSQL 17: è l'ambiente su cui
il progetto viene verificato a ogni push. Il driver MySQL/MariaDB è supportato
dal framework e dal codice, ma non è coperto dai test automatici.

**Redis** (opzionale) serve solo se vuoi usare Redis per cache e coda di
lavoro: senza, restano attivi i gestori su file e su database.

## Requisiti hardware

Minimi:

- 1 CPU
- 1 GB di RAM
- 200 MB di spazio per il codice

Consigliati:

- 2 CPU
- 2 GB di RAM
- 2 GB di spazio per il codice e i documenti generati

Lo spazio cresce con i PDF e gli allegati: i documenti restano in
`writable/`, quindi va dimensionato sul volume che prevedi di archiviare.

## Installazione rapida

```bash
git clone https://github.com/iacos93/invoiceplus.git
cd invoiceplus

composer install
cp env .env
php spark key:generate
```

Poi completa la configurazione del database (vedi sotto), crea lo schema con
`php spark migrate` e avvia l'applicazione.

## Installazione

1. Copia il progetto sul server, oppure clona la repository come sopra. La
   cartella pubblica è **`public/`**: è quella che il server web deve servire,
   non la radice del progetto.

2. Installa le dipendenze:

   ```console
   composer install
   ```

3. Crea il file di configurazione e genera la chiave di cifratura:

   ```console
   cp env .env
   php spark key:generate
   ```

4. Apri `.env` e imposta almeno questi valori:

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

   `encryption.key` è quella generata al punto 3. Senza, le credenziali salvate
   (PEC, SDI, banca) non si possono cifrare.

5. Crea lo schema del database:

   ```console
   php spark migrate
   ```

6. Crea il primo amministratore:

   ```console
   php spark users:create
   ```

7. Apri `https://tuo-dominio.example/` e accedi con quell'account. Dalla
   schermata delle impostazioni puoi creare l'azienda e completare i dati
   fiscali.

Per lo sviluppo, in alternativa al server web:

```console
php spark serve --port 8080
```

Il server di sviluppo risponde su <http://localhost:8080>.

## Versioni

Il progetto non ha ancora release taggate: si usa il ramo `main`, che la CI
verifica a ogni push. Quando il progetto uscirà dallo stato di sviluppo, le
versioni seguiranno [Semantic Versioning](https://semver.org/).

## Strumenti di sviluppo e debug

Per lavorare sul codice servono:

- **PHP 8.4** o superiore, con le estensioni elencate sopra.
- **Composer 2**.
- Un **PostgreSQL** (o MySQL/MariaDB) per i test d'integrazione e per provare
  l'applicazione; i test unitari bastano da soli, con SQLite in memoria.

Comandi utili:

| Comando | Cosa fa |
|-----|-----|
| `composer install` | Installa le dipendenze PHP |
| `composer test` | Esegue tutta la suite PHPUnit |
| `composer serve` | Avvia il server di sviluppo sulla porta 8080 |
| `vendor/bin/phpunit tests/unit/NavItemStateTest.php` | Esegue un solo file di test |
| `php spark migrate` | Applica le migrazioni |

In `development` l'applicazione mostra la debug bar di CodeIgniter; in
`production` resta spenta. I log finiscono in `writable/logs/`.

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
sono in [`tests/README.md`](tests/README.md).

## Community e supporto

Non ci sono forum o canali esterni: il progetto vive su GitHub.

- Per un dubbio o un problema apri una
  [issue](https://github.com/iacos93/invoiceplus/issues), indicando versione di
  PHP, database e i passi per riprodurre.
- Per un problema di sicurezza non aprire una issue pubblica: contatta
  l'autore tramite GitHub.

## Contribuire

Le modifiche passano da pull request sul ramo `main`.

1. Fai un fork e crea un ramo per la modifica.
2. Scrivi o aggiorna i test: la CI fallisce anche quando un test si salta.
3. Esegui `composer test` in locale.
4. Apri la pull request spiegando il problema che risolve, non solo la
   modifica.

Lo stile è quello del codice esistente: commenti in italiano che spiegano il
perché di una scelta, non cosa fa la riga. Se cambi un comportamento visibile,
aggiorna anche la guida in `docs/`.

## Licenza e attribuzione

Distribuito con licenza **MIT** (`LICENSE`).

Il codice deriva da **InvoicePlane**, che è MIT: la sua licenza originale è
riprodotta in `LICENSES/InvoicePlane-LICENSE.txt`, come richiesto dai suoi
termini. Il nome e il logo InvoicePlane sono invece marchi di
InvoicePlane.com / Kovah.de con licenza separata, che ne vieta l'alterazione e
l'uso fuori dal progetto originale: questo progetto si chiama "Invoice+" e usa
un marchio proprio, e il nome InvoicePlane compare solo per citarne l'origine.

## Star History

[![Star History Chart](https://api.star-history.com/image?repos=iacos93/invoiceplus&type=date&legend=top-left)](https://www.star-history.com/?repos=iacos93%2Finvoiceplus&type=date&legend=top-left)
