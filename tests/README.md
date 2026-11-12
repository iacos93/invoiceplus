# Test

La suite gira con PHPUnit. I test unitari usano SQLite in memoria e non hanno
bisogno di altro; i test di `tests/integration` leggono invece da un database
PostgreSQL vero, perché verificano PDF, XML FatturaPA e pagine complete, e su
uno schema vuoto non avrebbero niente da leggere.

## Come si eseguono

```console
composer test
```

oppure, direttamente:

```console
vendor/bin/phpunit
```

Il file di configurazione è `phpunit.dist.xml` nella radice. Se ti serve una
configurazione personale (es. escludere i test lenti), copialo in `phpunit.xml`,
che non è versionato.

Filtrare un singolo file o una singola area:

```console
vendor/bin/phpunit tests/unit/TotpTest.php
vendor/bin/phpunit --testsuite App --filter GuestScoping
```

## Database

Il gruppo `tests` in `app/Config/Database.php` punta a SQLite `:memory:`: ogni
esecuzione parte da uno schema vuoto e applica le migrazioni, quindi quello che
scrivi in un test non resta e non tocca il database di lavoro.

I test d'integrazione fanno eccezione: prima di ogni test spostano quel gruppo
sui parametri di connessione di `default` e cambiano solo il nome del database,
poi verificano che la tabella `ip_invoices` risponda. Se non c'è, il test si
salta con "Copia del database non disponibile": è voluto, così la suite resta
verde su una macchina che non ha quella copia.

### La copia in locale

In locale la copia si crea dal database di lavoro, senza toccarlo:

```console
createdb -T invoiceplane invoiceplane_posttest
```

Da quel momento `vendor/bin/phpunit` esegue anche i 46 test d'integrazione: la
suite completa conta 399 test e 1662 asserzioni. Senza la copia quei test si
saltano, e la suite resta verde avendo verificato meno di quello che dice. Un
altro database si indica con `IP_PDF_TEST_DB`, che è una variabile di processo,
non una chiave di `.env`:

```console
IP_PDF_TEST_DB=invoiceplane_prova vendor/bin/phpunit
```

I test che scrivono (approvazione di un preventivo, assegnazioni cliente-utente)
ripristinano lo stato in `tearDown()` o dentro una transazione con rollback:
la copia è di sola lettura nei fatti, e si può rilanciare la suite quante volte
si vuole.

## Come girano in CI

In CI non esiste nessuna copia, quindi il database viene costruito da zero a
ogni esecuzione. Il workflow ha quattro job: due con la stessa sequenza (uno per
motore) più copertura e browser. I due job sui motori supportati sono:

1. un servizio `postgres:17` oppure `mariadb:11` (definiti in
   `.github/workflows/ci.yml`);
2. `php spark migrate` crea lo schema — le 58 migrazioni bastano da sole, non
   serve un dump;
3. la fixture inserisce i dati minimi: `tests/fixtures/ci_seed.sql` con
   `psql`, `tests/fixtures/ci_seed_mysql.sql` con `mysql`;
4. `IP_PDF_TEST_DB=invoiceplus_test` dice ai test quale database usare.

Le due fixture contengono gli stessi dati scritti in due dialetti: la versione
MySQL non può usare `generate_series()`, `UPDATE ... FROM` o
`TRUNCATE ... CASCADE`, e la versione PostgreSQL le usa. Vanno cambiate
insieme.

`tests/fixtures/ci_seed.sql` è un dataset inventato ma coerente: importi che
tornano con le righe, saldi a zero solo dove c'è un incasso, un preventivo
pubblico e una fattura emessa con saldo aperto. Deve restare così: la
Panoramica verifica le relazioni fra i numeri, non solo che le pagine si
aprano. Chi aggiunge un test che legge tabelle nuove deve allargare la fixture,
altrimenti in CI quel test fallisce — che è il comportamento giusto, meglio di
un salto silenzioso.

## Test nel browser

PHPUnit non esegue il JavaScript: tema chiaro/scuro, ricerca nelle liste e
scorciatoie da tastiera vivono solo nel browser. `tests/browser` usa Playwright
per guidare Chrome sull'applicazione avviata da `php spark serve` e copre
accesso, cambio tema persistente, filtro in elenco e `Ctrl+K`.

In locale serve un database di prova con l'utente `admin@example.com` e la
password `e2e-password` (o `E2E_EMAIL` / `E2E_PASSWORD`), più Redis per sessioni
e cache:

```console
cd tests/browser
npm install
npx playwright install chromium
npx playwright test
```

In CI il job `browser` prepara PostgreSQL, applica migrazioni e seed, imposta la
password dell'utente di prova e lancia gli stessi test. I dettagli stanno in
`tests/browser/README.md`.

## Copertura

Un test verde non dice quanto codice tocca. Il clover si produce con:

```console
vendor/bin/phpunit --coverage-clover build/logs/clover.xml
php tools/coverage_gate.php 45
```

`coverage_gate.php` conta le righe eseguibili (non i metodi) e fallisce sotto la
soglia. La soglia in CI è **45%**, sotto il valore attuale di proposito: deve
cogliere un crollo, non trasformare ogni refactoring in un negoziato. Il job
`copertura` usa pcov e conserva il clover fra gli artifact.

## Collaudo sui dati veri

`tests/collaudo` non fa parte della suite e non gira in CI: legge una copia del
database di lavoro, con i clienti, le aliquote e la numerazione veri. Serve per
provate i percorsi completi prima di una release, quando i dati di prova non
bastano a far emergere un problema.

```console
createdb -T invoiceplane invoiceplane_cicli
IP_PDF_TEST_DB=invoiceplane_cicli vendor/bin/phpunit tests/collaudo --no-coverage
```

Il test crea due fatture su due clienti reali (una con la data del mese scorso),
le emette, genera PDF e XML, le incassa e verifica totali, numeri e stato. Gira
in transazione con rollback: la copia resta com'era.

## Come sono organizzati

- `tests/unit` — logica pura: servizi, librerie, calcoli, formattazione.
- `tests/integration` — rotte e controller, con database di test e sessione.
- `tests/collaudo` — percorsi completi su una copia dei dati veri, a mano.
- `tests/database` — migrazioni, seed e integrità dello schema.
- `tests/session` — gestione della sessione e dell'autenticazione.
- `tests/browser` — test end-to-end con Playwright (JavaScript, tema, ricerca).
- `tests/_support` — classi di supporto, migrazioni e seed usati dai test.
- `tests/fixtures` — dati di prova per la CI.

## Convenzioni

- Le classi derivano da `CodeIgniter\Test\CIUnitTestCase`, e i test che toccano
  il database usano anche `DatabaseTestTrait`.
- I metadata vanno scritti come **attributi**, non come annotazioni nei docblock:
  `#[DataProvider('metodo')]`, `#[CoversClass(...)]`. Dalla PHPUnit 11 le
  annotazioni sono deprecate e dalla 12 non funzionano più.
- Il conteggio delle asserzioni conta: se un test smette di asserire qualcosa,
  la suite deve accorgersene, non passare lo stesso.

## Versioni

PHPUnit 13 richiede **PHP 8.4** o superiore, come il resto del progetto. Se hai
ancora la 8.2 o la 8.3 il vincolo in `composer.json` non ti lascia installare le
dipendenze di sviluppo.
