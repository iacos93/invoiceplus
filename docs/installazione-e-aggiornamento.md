# Installazione, aggiornamento e ripristino

Questa guida riguarda un'installazione reale. Per provare l'applicazione in
locale, con un database separato e dati demo, vedi
[installazione-locale.md](installazione-locale.md): su macOS e Linux basta
`bash tools/quickstart/quickstart.sh`, su Windows lo script PowerShell.

## Installazione

Il README copre già i passi (cartella `public/` come document root, `.env`,
`php spark key:generate`, `php spark migrate`, `php spark users:create`). Qui
restano le cose che si dimenticano e che non danno errore subito.

Aprendo per la prima volta l'indirizzo dell'installazione, Invoice+ verifica
PHP, database, schema, cartelle e ambiente e, se qualcosa non va, lo spiega
prima di chiedere l'account (`sessions/setup`). Il database non si configura
dal browser: va scritto nel `.env` a mano, perché la pagina è pubblica finché
non esiste un utente e non deve poter scrivere credenziali né puntare
l'applicazione a un database altrui. Dopo l'account la pagina **Primi passi**
(`first_steps`) elenca cosa resta da sistemare.

- **`writable/` deve essere scrivibile** dal processo PHP:
  `chown -R www-data:www-data writable` (o l'utente del pool PHP-FPM). Senza,
  log, sessioni, PDF e backup falliscono con errori che arrivano tardi.
- **`writable/` non deve essere servito dal web.** Il document root è `public/`:
  se il server serve la radice del progetto, `writable/backups` e i log sono
  scaricabili da chiunque conosca il percorso.
- **La chiave di cifratura va conservata.** `encryption.key` protegge le
  credenziali di PEC, SdI e banca. Se si perde, quei valori non si leggono più:
  vanno reinseriti a mano. Se finisce in un repository, va considerata
  compromessa e sostituita.
- **Le automazioni non partono da sole.** Fatture ricorrenti, interrogazione
  SdI, riepilogo scadenze, backup e potatura del tracciamento email passano da
  `tools/cron-runner.sh`, da mettere in crontab come spiegato nello script.
  Indirizzo e cron key si mettono in `writable/cron-runner.conf`: il file non è
  versionato, così la chiave non finisce nel repository.

## Aggiornamento

Prima di toccare i file, un backup che si è in grado di ripristinare (vedi
sotto). Poi, dalla cartella del progetto:

```console
# 1. ferma le automazioni, se sono in crontab
#    (commenta la riga del cron-runner)

git fetch --tags
git checkout v0.1          # oppure il tag/commit che vuoi installare
composer install --no-dev --prefer-dist --no-interaction

php spark migrate
php spark cache:clear
```

`php spark migrate` è la parte che non si può saltare: le migrazioni aggiungono
colonne, indici e correggono dati. Se una fallisce, **non** riavviare
l'applicazione: leggi il messaggio, ripristina il backup se serve e apri una
segnalazione con il nome della migrazione.

Dopo l'aggiornamento:

- riavvia PHP-FPM (o il processo che serve l'app), altrimenti l'opcache può
  continuare a eseguire i file vecchi;
- controlla che `writable/` sia ancora scrivibile;
- riattiva la riga di crontab;
- passa la checklist qui sotto.

### Tornare indietro

Il codice si riporta al tag precedente con `git checkout <tag>` e
`composer install`. Il database no: le migrazioni non hanno tutte una discesa
affidabile, e i dati scritti nel frattempo (fatture, incassi) non sono nel
codice. Se l'aggiornamento ha già modificato lo schema e qualcosa non va, il
ripristino del dump è l'unica strada sicura — ed è per questo che il backup si
fa **prima**.

## Backup

### Automatico

L'endpoint `cron/backup/<chiave>` salva il dump in `writable/backups` e tiene
gli ultimi 14 file. La chiave è quella configurata nelle impostazioni
(`cron_key`); la riga di crontab è una sola, quella di `tools/cron-runner.sh`.

Il motore lo decide la configurazione del database:

- **PostgreSQL** → `pg_dump -Fc` (formato custom, compresso) in
  `backup_*.dump`;
- **MySQL/MariaDB** → `mysqldump --single-transaction --quick` in
  `backup_*.sql`, così il dump è coerente senza bloccare le scritture.

La rotazione conta i file per formato: cambiando motore, i `.dump` vecchi non
vengono confusi con i `.sql` nuovi.

Nel `.env`, se `pg_dump` non è quello giusto (versioni diverse installate sul
server), si indica il binario esatto:

```ini
backup.pgDumpPath = /usr/lib/postgresql/17/bin/pg_dump
```

Il valore conta più di quanto sembri: `pg_dump` **rifiuta** di leggere un server
di versione maggiore della propria, e con più versioni installate il primo nel
PATH non è sempre quello abbinato. Su una macchina con Homebrew, `pg_dump` 16
con il server 17.11 produce un errore di versione e nessun file: il backup
smette di esistere senza che nessuno se ne accorga finché non serve. Per questo
l'endpoint prova da solo i percorsi abituali (`/usr/lib/postgresql/*/bin`,
`/opt/homebrew/opt/postgresql@*/bin`, `/usr/local/...`, Postgres.app) e sceglie
il primo binario di versione almeno pari al server; `backup.pgDumpPath` resta
la scelta esplicita e ha la precedenza.

Un fallimento risponde **500**: `tools/cron-runner.sh` usa `curl -f` e lo
registra come `FALLITO`, quindi riprova al giro successivo. Con un 200 e il
testo "Backup fallito" nel corpo, il log lo annotava come riuscito e non
riprovava fino al giorno dopo.

Il fallimento manda anche una email alla casella di sistema (o al primo
amministratore, se la casella non è configurata), con l'errore e il link a
Stato del sistema. Per non riempire la posta con un avviso a ogni tentativo,
se ne manda al massimo uno ogni dodici ore; il primo backup riuscito azzera il
freno, quindi un guasto successivo avvisa subito.

Il dump contiene i dati fiscali completi. **Va portato fuori dal server**:
`writable/backups` è una copia di lavoro, non un archivio. Se il server si
perde, si perdono anche i backup che stanno dentro. Il file viene creato con
permessi **0600**: lo legge solo l'utente del processo PHP, non ogni utente
locale.

Per portarlo fuori si può partire da **Gestione > Stato del sistema**: il
controllo "Backup del database" mostra **Scarica l'ultimo backup** e consegna
il dump più recente. Il pulsante compare solo quando un file valido esiste; il
nome non si sceglie nell'URL (è sempre l'ultimo prodotto), quindi non è un modo
per leggere altri file. Il download resta dietro l'accesso da amministratore.

Su MySQL/MariaDB si può indicare il binario allo stesso modo, se `mysqldump`
non è nel PATH o è di una versione sbagliata:

```ini
backup.mysqldumpPath = /usr/bin/mysqldump
```

### I file caricati

Il dump contiene il database e **basta**. Gli XML delle fatture di acquisto
ricevute (`writable/uploads/expenses`, compresa la cartella `incoming/`), gli
allegati dei clienti, i file F24 e i loghi stanno su disco. Per una fattura
l'originale è l'XML, non la riga nel database: perdere il server senza questi
file vuol dire perderli.

Lo stesso giro di `cron/backup` salva quindi anche `writable/uploads` in
`files_AAAAMMGG_HHMMSS.zip` dentro `writable/backups`, con permessi **0600** e
gli ultimi 14. Il prefisso è diverso da quello dei dump (`backup_`) perché
Stato del sistema, il download e la verifica del ripristino cercano `backup_*`.
Come per il dump, **va portato fuori dal server**.

Se il database è salvato e i file no, la richiesta **non** risponde 500: il
cron-runner riproverebbe tutto il backup a ogni giro e, con la rotazione a 14,
spingerebbe fuori i dump buoni in poche ore. Il fallimento arriva invece per
email (al massimo una al giorno, finché dura) e la pagina Stato del sistema ha
il controllo "Backup dei file", che diventa un avviso se l'ultima copia manca o
ha più di due giorni. La causa più comune è l'estensione PHP `zip` mancante.

Per ripristinare i file, in una cartella vuota:

```bash
unzip files_AAAAMMGG_HHMMSS.zip -d writable/uploads
```

I percorsi nell'archivio sono relativi a `writable/uploads`.

**Non c'è il `.env` e non c'è la chiave di cifratura**, di proposito: sono
segreti e non vanno in un file che resta accanto ai dump. Conserva a parte
(gestore di password, cassaforte) una copia del `.env`, e in particolare di
`encryption.key`: senza, i segreti cifrati nel database (token dei canali SdI,
password delle caselle) diventano illeggibili anche con un dump perfetto.

### Verifica del ripristino

Sapere che il dump esiste non basta: un file può essere prodotto ogni giorno e
non essere ripristinabile (troncato, binario di versione sbagliata, tabella
mancante). `php spark backup:verify` prende l'ultimo dump, lo carica in un
database temporaneo `<database>_verify_<timestamp>`, confronta i conteggi delle
tabelle principali e il totale fatturato, poi elimina la copia.

```console
php spark backup:verify
php spark backup:verify --file writable/backups/backup_20261111_030000.dump
```

Esce con `0` se il ripristino coincide, con `1` se qualcosa non torna, e in
entrambi i casi scrive l'esito in `writable/cron/backup_verify.json`: la pagina
**Gestione > Stato del sistema** lo legge e segnala anche il caso in cui esista
un dump più recente non ancora provato.

Serve un utente del database con permesso di creare database — `CREATEDB` su
PostgreSQL, `CREATE` su MySQL/MariaDB. Se non vuoi darlo all'utente
dell'applicazione, crea un gruppo di connessione dedicato in
`app/Config/Database.php` e usa `php spark backup:verify --group=<gruppo>`.
Quando il permesso manca la pagina "Stato del sistema" mostra un avviso, non un
guasto: il dump potrebbe essere sano, semplicemente non è stato provato. Il
database temporaneo viene eliminato sempre, anche quando il ripristino
fallisce. Su un database grande il comando può durare qualche minuto: è
normale.

`tools/cron-runner.sh` lo esegue una volta a settimana, subito dopo il backup
del giorno. In crontab il PATH è ridotto: se `php` non è raggiungibile, imposta
`PHP_BIN` in `writable/cron-runner.conf`, dove stanno anche `BASE_URL` e
`CRON_KEY`.

### Ripristino

**PostgreSQL.** Il dump è in formato custom: si ripristina con `pg_restore`, in
un database vuoto.

```console
createdb invoiceplus_ripristino
pg_restore --no-owner --no-acl -d invoiceplus_ripristino writable/backups/backup_20261111_030000.dump
```

**MySQL/MariaDB.** Il dump è SQL: si ripristina con `mysql` su un database
vuoto.

```console
mysql -u root -p -e "CREATE DATABASE invoiceplus_ripristino CHARACTER SET utf8mb4"
mysql -u root -p invoiceplus_ripristino < writable/backups/backup_20261111_030000.sql
```

Poi si punta `.env` al database ripristinato, si esegue `php spark migrate`
(non deve trovare migrazioni da applicare: se ne trova, il dump è più vecchio
del codice) e si controllano i numeri che contano:

```console
psql -d invoiceplus_ripristino -c "SELECT COUNT(*) FROM ip_invoices;"
psql -d invoiceplus_ripristino -c "SELECT COUNT(*) FROM ip_payments;"
psql -d invoiceplus_ripristino -c "SELECT COUNT(*) FROM ip_clients;"
```

**Un backup non verificato non è un backup.** Almeno una volta, su un database
separato, provare il ripristino e aprire l'applicazione su quella copia: è
l'unico modo per sapere che il file è integro e che la procedura funziona.

### Verifica periodica, in pratica

Il giro completo, da fare su un database di servizio e non su quello in uso:

```console
createdb invoiceplus_verifica
pg_restore --no-owner --no-acl -d invoiceplus_verifica writable/backups/backup_20261111_183626.dump

# i numeri devono coincidere con quelli del database vero
for t in ip_invoices ip_quotes ip_clients ip_payments ip_expenses ip_invoice_items; do
  echo "$t reale=$(psql -d invoiceplane -Atc "select count(*) from $t") \
ripristino=$(psql -d invoiceplus_verifica -Atc "select count(*) from $t")"
done

psql -d invoiceplane            -Atc "select round(sum(invoice_total),2) from ip_invoice_amounts"
psql -d invoiceplus_verifica    -Atc "select round(sum(invoice_total),2) from ip_invoice_amounts"
```

Su MySQL/MariaDB gli stessi conteggi si leggono con
`mysql -u ... -N -B <db> -e "SELECT COUNT(*) FROM ip_invoices"`, e il ripristino
è quello della sezione precedente.

Poi si punta l'applicazione alla copia (basta `database.default.database` in un
`.env` separato, o una variabile d'ambiente davanti a `spark`) e si controlla:

- `php spark migrate:status` non deve trovare migrazioni da applicare: se ne
  trova, il dump è più vecchio del codice;
- la pagina di login risponde;
- il link pubblico di una fattura esistente si apre e mostra il documento;
- il PDF di quella fattura si scarica.

Se i conteggi tornano, `migrate:status` è pulito e le pagine si aprono, il dump
è integro. Poi `dropdb invoiceplus_verifica`.

Il controllo che conta non è il numero assoluto ma l'uguaglianza: i conteggi
per tabella e i totali degli importi devono essere **identici** fra il database
di partenza e la copia. Annotali prima del ripristino (per esempio con
`SELECT COUNT(*)` su `ip_invoices`, `ip_quotes`, `ip_clients`, `ip_payments`,
`ip_expenses` e sul totale di `ip_invoice_amounts`) e confrontali dopo: se
coincidono, `migrate:status` è pulito e le pagine si aprono, il dump è integro.
La stessa verifica vale su MySQL/MariaDB.

## Header di sicurezza e Content-Security-Policy

Gli header `X-Frame-Options`, `X-Content-Type-Options` e `Referrer-Policy`
sono sempre attivi. Le anteprime di fatture, preventivi e DDT sono iframe dello
stesso sito, quindi non ne risentono.

La **Content-Security-Policy** si sceglie in `.env` con `app.cspMode`:

| Valore | Cosa fa |
|--------|---------|
| `enforce` | Applica la policy: il browser blocca le risorse non ammesse. È il predefinito in produzione. |
| `report` | La manda come *Report-Only*: il browser segnala ciò che bloccherebbe, ma lascia passare tutto. È il predefinito altrove (sviluppo, test). |
| `off` | Nessuna CSP. |

Un valore sbagliato (per esempio `enforced`) vale `report`: non spegne la
protezione e non blocca niente.

**Condizione da rispettare:** `app.baseURL` deve coincidere con l'indirizzo con
cui apri il sito (stesso protocollo, host e porta). I riferimenti agli asset
partono da lì: se differiscono, il browser li considera di un altro sito e la
policy li blocca, lasciando le pagine senza stile né JavaScript. È anche il
motivo per cui in sviluppo il predefinito è `report`: il server integrato parte
su un'altra porta quando la 8080 è occupata. Il predefinito segue
`CI_ENVIRONMENT`: un'istanza locale con `CI_ENVIRONMENT = production` nel
`.env` ottiene quindi `enforce` anche in locale, e se la apri su una porta
diversa da quella di `app.baseURL` le pagine perdono stile e JavaScript. In quel
caso libera la porta oppure imposta `app.cspMode = report`.

La policy ammette soltanto lo stesso sito e le immagini `data:` (i QR code):
non c'è nessuna origine esterna da autorizzare, perché i caratteri sono file
dell'app (`assets/core/fonts`, dichiarati in `css/src/01-token.css`). Chiude plugin, form verso altri siti, richieste verso altri host e
incorporamento in pagine esterne. Gli script e gli stili in linea sono ancora
ammessi: le pagine ne usano molti e la navigazione parziale riesegue gli script
di un'altra risposta, quindi una policy a nonce richiede un lavoro a parte. La
policy impedisce di caricare codice da altri siti e di spedire dati altrove; non
sostituisce l'escape dell'output, e non ferma uno `<script>` in linea iniettato.
Per questo il servizio CSP dell'app non emette mai un nonce
(`App\Libraries\CspWithoutNonce`): con un solo nonce nell'header i browser
ignorano `'unsafe-inline'` e gli script in linea smettono di girare.

### Se dopo un aggiornamento una pagina non funziona

1. Imposta `app.cspMode = report` e ricarica la pagina: se ora funziona, la
   policy bloccava qualcosa.
2. Apri la console del browser: le righe "violates the following Content
   Security Policy directive" dicono quale risorsa e quale direttiva.
3. Per raccogliere le segnalazioni senza aprire la console pagina per pagina,
   imposta `app.cspReportUri` a un indirizzo che accetti richieste POST: il
   browser vi spedisce una segnalazione JSON per ogni violazione, anche con
   `enforce` (e in quel caso dice cosa è stato bloccato davvero).
4. Se la risorsa è legittima (un nuovo font, un servizio esterno), va aggiunta
   alla policy in `app/Config/ContentSecurityPolicy.php`.

### Cosa è stato provato

Il passaggio a `enforce` è stato verificato il 2026-11-13 su un'installazione
di prova con i dati demo: circa 48 schermate (bacheca, liste e schede di
fatture, preventivi, clienti, pagamenti, spese, fornitori, prodotti, F24, RiBa,
riconciliazione, calendario, scadenzario, utile e imposte, controllo di
gestione, report, impostazioni, sicurezza account, stato del sistema, utenti,
modelli email, esportazione e importazione, DDT, progetti, attività, anteprima
in iframe, validazione XML, portale cliente, login e recupero password), più
tema chiaro/scuro, navigazione parziale, ricerca in elenco e selettori.
Nessuna violazione, misurata con `app.cspReportUri` e con una violazione
provocata di proposito per verificare che la misura funzionasse.

Non sono state percorse le funzioni che richiedono dati o servizi esterni:
invio reale di email, canali SdI, scarico dell'estratto conto da una banca.
Sono richieste lato server e non toccano la policy, ma se ne avessi
dubbi, un giro con `report` e `app.cspReportUri` sulle tue schermate vere è
il controllo da fare.

## Checklist dopo un aggiornamento

Da fare sull'ambiente reale, con un utente vero, non solo con i test:

1. Login e cambio azienda, se ce n'è più di una.
2. La bacheca mostra i totali dell'anno atteso.
3. Una fattura esistente si apre, il PDF si genera e il numero è quello giusto.
4. Una fattura nuova: cliente, due righe, emissione, PDF, XML FatturaPA.
5. Un incasso parziale sulla stessa fattura: saldo e stato coerenti.
6. Il preventivo di prova si converte in fattura con le righe giuste.
7. Scadenzario e Utile e imposte si aprono senza errori.
8. Il portale pubblico di un documento risponde con il link già inviato.
9. **Gestione > Stato del sistema** è tutto regolare (o gli avvisi sono
   spiegati): PHP, cartelle scrivibili, database, schema, ultimo backup,
   automazioni. Le automazioni girano: `writable/logs` non ha errori nuovi e
   il cron-runner ha scritto l'esito dei job in `writable/cron`.
10. Se è il giorno del backup, il dump nuovo esiste e non è da 0 byte.

Se un punto fallisce, il CHANGELOG dice cosa è cambiato in quella versione e
`writable/logs` ha l'errore con lo stack: quello è il contenuto utile di una
segnalazione.
