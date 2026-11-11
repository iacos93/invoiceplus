# Test

La suite gira con PHPUnit e usa SQLite in memoria, quindi non serve un database
esterno né un server avviato.

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

Il gruppo `tests` in `app/Config/Database.php` punta a SQLite `:memory:`, quindi
ogni esecuzione parte da uno schema vuoto e applica le migrazioni: quello che
scrivi in un test non resta e non tocca il database di lavoro.

Per usare un database tuo basta passare le chiavi `database.tests.*` in `.env`;
il file di configurazione le legge già.

## Come sono organizzati

- `tests/unit` — logica pura: servizi, librerie, calcoli, formattazione.
- `tests/integration` — rotte e controller, con database di test e sessione.
- `tests/database` — migrazioni, seed e integrità dello schema.
- `tests/session` — gestione della sessione e dell'autenticazione.
- `tests/_support` — classi di supporto, migrazioni e seed usati dai test.

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
