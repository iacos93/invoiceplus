# Prova in locale con dati demo

Questa guida serve a far girare Invoice+ sul tuo computer per vedere come
funziona, senza configurare un server e senza toccare dati reali. Crea un
database separato (`invoiceplane_demo`), carica dati inventati e avvia
l'applicazione su <http://localhost:8080>.

Non è un'installazione di produzione: il server di sviluppo ascolta solo su
localhost, le email non partono (manca un server SMTP) e i dati demo si possono
ricaricare quando vuoi.

## Cosa serve

- **PHP 8.4 o successivo**, con le estensioni `intl`, `mbstring`, `curl`, `gd`,
  `zip`, `pgsql`, `pdo_pgsql`.
- **[Composer](https://getcomposer.org/download/)**.
- **PostgreSQL** in esecuzione (va bene anche una installazione già presente
  per altri progetti).

Non serve Node.js: gli asset sono già compilati nel repository.

### macOS

```console
brew install php composer postgresql@16
brew services start postgresql@16
```

Se `psql` non viene trovato, aggiungi al `PATH` la cartella
`/opt/homebrew/opt/postgresql@16/bin` (su Intel: `/usr/local/opt/...`).

### Linux (Debian/Ubuntu)

```console
sudo apt install php8.4-cli php8.4-intl php8.4-mbstring php8.4-curl \
    php8.4-gd php8.4-zip php8.4-pgsql postgresql postgresql-client composer
```

Su versioni di Ubuntu precedenti alla 24.04, PHP 8.4 arriva dal PPA
`ondrej/php`. Il server PostgreSQL parte da solo dopo l'installazione.

### Windows

1. Installa PHP 8.4 da [windows.php.net](https://windows.php.net/download/) (o
   con Laragon) e aggiungi la cartella di PHP al `PATH`.
2. Nel `php.ini` abilita le estensioni togliendo il `;` davanti a:
   `intl`, `mbstring`, `curl`, `gd`, `zip`, `pgsql`, `pdo_pgsql`.
   Controlla che `extension_dir` punti alla cartella `ext` di PHP.
3. Installa [Composer](https://getcomposer.org/download/) con il programma di
   installazione per Windows.
4. Installa PostgreSQL da [postgresql.org/download/windows](https://www.postgresql.org/download/windows/)
   e aggiungi `C:\Program Files\PostgreSQL\16\bin` al `PATH`.
5. Riapri il terminale e verifica:

```console
php -v
composer --version
psql --version
```

## Avvio automatico

Dalla cartella del progetto.

**macOS e Linux:**

```console
bash tools/quickstart/quickstart.sh
```

**Windows (PowerShell):**

```console
powershell -ExecutionPolicy Bypass -File tools\quickstart\quickstart.ps1
```

Alla fine il terminale mostra indirizzo e credenziali:

| | |
|---|---|
| Indirizzo | <http://localhost:8080> |
| Email | `admin@example.com` |
| Password | `demo1234` |

Per fermare il server: `Ctrl+C`.

### Cosa fa lo script

1. Controlla PHP, le estensioni, Composer e il client PostgreSQL.
2. Crea il database `invoiceplane_demo` se non esiste (non tocca gli altri).
3. Scrive un `.env` dedicato, con cache e sessioni su file: per una prova sul
   portatile non serve Redis.
4. Installa le dipendenze PHP se manca `vendor/`.
5. Genera la chiave di cifratura, applica le migrazioni e carica
   `tests/fixtures/ci_seed.sql`.
6. Imposta la password demo sull'utente amministratore e avvia
   `php spark serve`.

Se esiste già un `.env` che non è stato creato dallo script, si ferma invece di
sovrascriverlo. Per sostituirlo comunque:

```console
bash tools/quickstart/quickstart.sh --force
```

Lo script salva prima una copia `.env.quickstart-backup-<data>`.

### Opzioni utili

| Opzione | Cosa fa |
|---|---|
| `--no-serve` (`-NoServe`) | configura tutto e termina, senza avviare il server |
| `--force` (`-Force`) | sostituisce un `.env` esistente, dopo averlo salvato |
| `--help` | mostra l'aiuto |

Variabili facoltative, se PostgreSQL non usa i valori predefiniti:

| Variabile | Predefinito |
|---|---|
| `IP_DEMO_DB_HOST` | `127.0.0.1` |
| `IP_DEMO_DB_PORT` | `5432` |
| `IP_DEMO_DB_NAME` | `invoiceplane_demo` |
| `IP_DEMO_DB_USER` | utente di sistema, poi `postgres` |
| `IP_DEMO_DB_PASSWORD` | vuota |
| `IP_DEMO_PORT` | `8080` |

Esempio su Windows, con la password scelta durante l'installazione di
PostgreSQL:

```powershell
$env:IP_DEMO_DB_USER = 'postgres'
$env:IP_DEMO_DB_PASSWORD = 'la-tua-password'
powershell -ExecutionPolicy Bypass -File tools\quickstart\quickstart.ps1
```

## Ripartire da zero

Rilanciare lo script ricarica i dati demo: la fixture svuota le tabelle che
popola e le riscrive, quindi le modifiche fatte nell'interfaccia si perdono e
si torna al punto di partenza.

Per cambiare la password demo, dopo l'avvio:

```console
php spark users:create
```

crea un nuovo amministratore con la password che scegli; da quel momento puoi
entrare con quello.

## Se qualcosa non parte

- **`psql: command not found`** — manca il client PostgreSQL o la sua cartella
  non è nel `PATH`.
- **`Non riesco a collegarmi a PostgreSQL`** — il server non è acceso, oppure
  servono utente e password. Imposta `IP_DEMO_DB_USER` e
  `IP_DEMO_DB_PASSWORD`.
- **`Mancano le estensioni PHP`** — abilita in `php.ini` quelle indicate e
  riapri il terminale; su Windows controlla anche `extension_dir`.
- **Porta 8080 occupata** — avvia con `IP_DEMO_PORT=8081` (PowerShell:
  `$env:IP_DEMO_PORT = '8081'`), ricordando che l'indirizzo mostrato cambia.
- **La pagina si apre ma il login non va** — rilancia lo script: ricarica il
  database demo e reimposta la password `demo1234`.

## Cosa non funziona nella prova

- **Invio email**: non c'è un server SMTP configurato. Le schermate di invio si
  aprono, ma l'invio fallisce finché non imposti un mittente in **Email e
  mittenti**.
- **SdI, PEC e banca**: sono canali esterni, da configurare con credenziali
  reali. I dati demo non ne hanno.
- **Backup e cron**: le automazioni passano da `tools/cron-runner.sh`, pensato
  per un server, non per una prova sul portatile.

Per l'installazione vera, con il web server e i backup, vedi
[installazione-e-aggiornamento.md](installazione-e-aggiornamento.md).
