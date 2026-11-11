# Installazione, aggiornamento e ripristino

Questa guida riguarda un'installazione reale. Per provare l'applicazione in
locale bastano i comandi del README (`composer install`, `php spark migrate`,
`php spark serve`).

## Installazione

Il README copre già i passi (cartella `public/` come document root, `.env`,
`php spark key:generate`, `php spark migrate`, `php spark users:create`). Qui
restano le cose che si dimenticano e che non danno errore subito.

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
  SdI, riepilogo scadenze e backup passano da `tools/cron-runner.sh`, da
  mettere in crontab come spiegato nello script.

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

### Automatico (PostgreSQL)

L'endpoint `cron/backup/<chiave>` lancia `pg_dump` in formato custom e salva il
file in `writable/backups`, tenendo gli ultimi 14. La chiave è quella
configurata nelle impostazioni (`cron_key`); la riga di crontab è una sola,
quella di `tools/cron-runner.sh`.

Nel `.env`, se `pg_dump` non è quello giusto (versioni diverse installate sul
server), si indica il binario esatto:

```ini
backup.pgDumpPath = /usr/lib/postgresql/17/bin/pg_dump
```

Il dump contiene i dati fiscali completi. **Va portato fuori dal server**:
`writable/backups` è una copia di lavoro, non un archivio. Se il server si
perde, si perdono anche i backup che stanno dentro.

Su MySQL/MariaDB questo endpoint non è disponibile: il backup si fa con
`mysqldump` da crontab, con la stessa regola (fuori dal server, rotazione,
verifica periodica).

### Ripristino

Il dump è in formato custom: si ripristina con `pg_restore`, in un database
vuoto.

```console
createdb invoiceplus_ripristino
pg_restore --no-owner --no-acl -d invoiceplus_ripristino writable/backups/backup_20261111_030000.dump
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
9. Le automazioni girano: `writable/logs` non ha errori nuovi e il cron-runner
   ha scritto l'esito dei job.
10. Se è il giorno del backup, il dump nuovo esiste e non è da 0 byte.

Se un punto fallisce, il CHANGELOG dice cosa è cambiato in quella versione e
`writable/logs` ha l'errore con lo stack: quello è il contenuto utile di una
segnalazione.
