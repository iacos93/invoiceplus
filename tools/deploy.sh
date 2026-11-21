#!/bin/sh
# deploy — aggiornamento dell'installazione Invoice+ sul server.
#
# È la procedura di docs/installazione-e-aggiornamento.md messa in uno script
# perché copiarla a mano ha tre punti dove si sbaglia: la versione di PHP (il
# `php` del PATH non è detto sia quella del pool), l'ordine fra migrazioni e
# svuotamento della cache, e il backup, che si dimentica proprio quando
# serve. Lo script si ferma al primo passo che fallisce: un aggiornamento a
# metà è peggio di uno non iniziato.
#
# Prima del pull lascia la copia `release-pre-<commit di partenza>` accanto
# all'applicazione: è quella che `tools/rollback.sh` pretende per ripartire. La
# copia era un passo manuale, e un passo manuale lo si salta proprio nel deploy
# da cui si vuole tornare indietro.
#
# Uso, dall'utente del sito:
#
#   tools/deploy.sh
#
# Non va eseguito come root. I file che l'applicazione crea in `writable/`
# (log, PDF, dump) prendono il proprietario del processo che li scrive: da root
# resterebbero di root, e dopo l'aggiornamento log e backup smetterebbero di
# funzionare con errori che non dicono il perché.
#
# Il backup non è un pg_dump riscritto qui: passa dall'endpoint `cron/backup`
# dell'applicazione, che conosce il motore del database e salva anche i file
# caricati. Base URL e chiave stanno in `writable/cron-runner.conf`, lo stesso
# file del cron (non versionato, perché contiene un segreto); lo stesso file
# può indicare `PHP_BIN`, utile quando il `php` del PATH non è quello del pool.
#
# Variabili per le installazioni diverse da questa:
#   DEPLOY_REMOTE, DEPLOY_BRANCH, DEPLOY_FPM_SERVICE, DEPLOY_GOTENBERG_HEALTH,
#   DEPLOY_BACKUP_TIMEOUT, DEPLOY_QUEUE_TIMEOUT, DEPLOY_QUEUE_RESTART_WAIT,
#   PHP_BIN, COMPOSER_BIN.

set -eu

APP_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$APP_DIR"

say() {
    printf '\n==> %s\n' "$*"
}

die() {
    printf '\n!! %s\n' "$*" >&2
    exit 1
}

# Configurazione locale, letta prima dei valori predefiniti: le variabili
# DEPLOY_* dell'ambiente vincono comunque su quello che c'è nel file.
CONF="$APP_DIR/writable/cron-runner.conf"
if [ -f "$CONF" ]; then
    # La conf è shell, non un file ini: un errore di sintassi farebbe uscire lo
    # script senza dire perché, e un valore con spazi verrebbe eseguito come
    # comando. Prima si controlla la sintassi, poi si esegue senza `set -e` e si
    # avvisa: la validazione qui sotto dà il messaggio utile.
    sh -n "$CONF" 2>/dev/null \
        || die "$CONF non è un file di configurazione valido (errore di sintassi): correggilo e rilancia."
    set +e
    . "$CONF"
    conf_rc=$?
    set -e
    [ "$conf_rc" -eq 0 ] \
        || printf 'Attenzione: %s contiene una riga che non viene eseguita (exit %s).\n' "$CONF" "$conf_rc" >&2
fi
BASE_URL=${BASE_URL:-}
CRON_KEY=${CRON_KEY:-}

REMOTE=${DEPLOY_REMOTE:-origin}
BRANCH=${DEPLOY_BRANCH:-main}
FPM_SERVICE=${DEPLOY_FPM_SERVICE:-php8.4-fpm}
GOTENBERG_HEALTH=${DEPLOY_GOTENBERG_HEALTH:-http://127.0.0.1:3000/health}
BACKUP_TIMEOUT=${DEPLOY_BACKUP_TIMEOUT:-900}

# Una frase sola per tutti i casi in cui ci si ferma prima di aver toccato
# qualcosa: ripeterla in ogni messaggio la faceva divergere.
NOTHING_DONE='Nessun passo è stato eseguito: codice, backup e schema sono quelli di prima.'

# Gli strumenti si controllano prima di usarli: senza, il primo errore
# sarebbe "non è un repository git" invece di "manca git".
command -v git >/dev/null 2>&1 || die "git non è nel PATH: senza, lo script non può aggiornare il codice."
command -v curl >/dev/null 2>&1 || die "curl non è nel PATH: serve per il backup e per il controllo di Gotenberg."

[ "$(id -u)" -ne 0 ] || die "Non eseguire come root: i file creati in writable/ resterebbero di root.
   Entra con l'utente del sito (sudo -iu invoiceplus) e rilancia."

git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
    || die "$APP_DIR non è un repository git: lo script aggiorna da $REMOTE, non copia file."

# Un albero sporco rende il pull --ff-only impossibile a metà strada, e le
# modifiche locali andrebbero perse o mischiate al codice nuovo: meglio dirlo
# subito, prima del backup.
dirty=$(git status --porcelain)
if [ -n "$dirty" ]; then
    printf '%s\n' "$dirty" | head -10 >&2
    die "Ci sono modifiche non committate in $APP_DIR: committale o mettile da parte, poi rilancia."
fi

branch=$(git symbolic-ref -q --short HEAD) \
    || die "HEAD è staccato (un rollback a un commit?): torna sul ramo con 'git checkout $BRANCH' e rilancia."
[ "$branch" = "$BRANCH" ] \
    || die "Il repository è sul ramo '$branch', non su '$BRANCH': l'aggiornamento segue $REMOTE/$BRANCH."

# PHP: la versione del pool, non quella generica della macchina. Su un server
# con più versioni installate `php` è spesso una 8.1 che non regge il codice.
if [ -z "${PHP_BIN:-}" ]; then
    PHP_BIN=$(command -v php8.4 || command -v php || true)
fi
[ -n "${PHP_BIN:-}" ] || die "PHP non trovato: imposta PHP_BIN, o metti php8.4 nel PATH."
php_version=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || true)
[ -n "$php_version" ] || die "$PHP_BIN non esegue: imposta PHP_BIN con il binario del pool."
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.4", ">=") ? 0 : 1);' 2>/dev/null \
    || die "PHP $php_version è troppo vecchio: serve 8.4. Usa il binario del pool (PHP_BIN)."

say "Aggiornamento di $APP_DIR (ramo $BRANCH, PHP $php_version)"

say "Cerco gli aggiornamenti su $REMOTE"
git fetch --quiet "$REMOTE" || die "git fetch da $REMOTE non riuscito: controlla rete, credenziali e che il remoto esista."
target=$(git rev-parse "$REMOTE/$BRANCH" 2>/dev/null) \
    || die "$REMOTE/$BRANCH non esiste: controlla DEPLOY_BRANCH e il remoto."

current=$(git rev-parse HEAD)
if [ "$current" = "$target" ]; then
    printf 'Già aggiornato a %s (%s): niente da fare.\n' \
        "$(git rev-parse --short HEAD)" "$(git log -1 --format=%s)"
    exit 0
fi

# --- 0. Permessi -----------------------------------------------------------
# Il reload del pool è l'ultimo passo e richiede root. Se l'utente non può
# farlo, va detto adesso: scoprirlo a fine aggiornamento fa sembrare un guasto
# un deploy riuscito. Il controllo è una simulazione (`sudo -l` non esegue).
say "Permessi per ricaricare $FPM_SERVICE"
command -v sudo >/dev/null 2>&1 \
    || die "sudo non è installato e il reload di $FPM_SERVICE richiede root. $NOTHING_DONE"
SYSTEMCTL=$(command -v systemctl || true)
[ -n "$SYSTEMCTL" ] \
    || die "systemctl non è nel PATH: senza, né la verifica né il reload sono possibili. $NOTHING_DONE"

# La prova usa lo stesso percorso assoluto del comando che verrà eseguito
# davvero: una voce sudoers scritta su /usr/bin/systemctl non riconosce un
# `systemctl` risolto altrove, e la verifica negherebbe un permesso che c'è.
if sudo -n -l "$SYSTEMCTL" reload "$FPM_SERVICE" >/dev/null 2>&1; then
    printf 'Permesso presente: %s reload %s\n' "$SYSTEMCTL" "$FPM_SERVICE"
elif sudo -n -l >/dev/null 2>&1; then
    die "L'utente $(id -un) non può ricaricare $FPM_SERVICE senza password. $NOTHING_DONE
   Aggiungi in /etc/sudoers.d/invoiceplus-reload:
       $(id -un) ALL=(root) NOPASSWD: $SYSTEMCTL reload $FPM_SERVICE"
else
    # Qui non sappiamo se il permesso manca o se sudo non riesce a dirlo.
    die "sudo non elenca i comandi di $(id -un): il permesso di ricaricare $FPM_SERVICE
   non è verificabile in anticipo (password richiesta o utente fuori da sudoers). $NOTHING_DONE
   Provalo a mano con: sudo -n -l $SYSTEMCTL reload $FPM_SERVICE"
fi

# --- 1. Backup -------------------------------------------------------------
# Prima di toccare i file, perché è l'unica strada di ritorno: le migrazioni
# non hanno tutte una discesa affidabile e i dati scritti nel frattempo non
# sono nel codice.
say "Backup (dump del database e file caricati)"

if [ -z "$BASE_URL" ] || [ -z "$CRON_KEY" ]; then
    die "Backup non configurato: $CONF deve contenere BASE_URL e CRON_KEY, le stesse del cron.
   $NOTHING_DONE"
fi
# Un indirizzo senza schema o una chiave con spazi e barre non producono un
# errore comprensibile: curl risponde 404 o 400 e sembra un guasto del server.
case "$BASE_URL" in
    http://*|https://*) ;;
    *) die "BASE_URL in $CONF non è un indirizzo http(s): «$BASE_URL». $NOTHING_DONE" ;;
esac
case "$CRON_KEY" in
    *[[:space:]]*|*/*) die "CRON_KEY in $CONF contiene spazi o barre: non può stare in un URL. $NOTHING_DONE" ;;
esac
BASE_URL=${BASE_URL%/}

backup_body=$(mktemp)
backup_status=$(curl -sS -o "$backup_body" -w '%{http_code}' --max-time "$BACKUP_TIMEOUT" \
    "${BASE_URL}/cron/backup/${CRON_KEY}") || {
    rm -f "$backup_body"
    die "Backup non eseguito: la richiesta a $BASE_URL è fallita (rete, TLS o sito irraggiungibile).
   $NOTHING_DONE"
}
backup_text=$(cat "$backup_body")
rm -f "$backup_body"

case "$backup_status" in
    200) ;;
    403) die "Backup rifiutato (HTTP 403): la CRON_KEY di $CONF non è quella dell'applicazione.
   $NOTHING_DONE" ;;
    429) die "Backup rifiutato (HTTP 429): l'endpoint cron è limitato adesso, rilancia fra qualche minuto.
   $NOTHING_DONE" ;;
    *) die "Backup non riuscito (HTTP $backup_status): $backup_text -- dettaglio in writable/logs.
   $NOTHING_DONE" ;;
esac
printf '%s\n' "$backup_text"

# --- 1b. Copia di sicurezza ------------------------------------------------
# Serve al rollback, che si rifiuta di partire senza: va fatta prima del pull,
# perché il commit da copiare è quello che il pull porta via. Se esiste già per
# questo commit la riuso: rifarla non aggiungerebbe niente.
say "Copia di sicurezza per il rollback"
parent=$(CDPATH= cd -- "$APP_DIR/.." && pwd)
short=$(git rev-parse --short HEAD)
copy="$parent/release-pre-$short"

if [ -d "$copy" ] && [ -f "$copy/.env" ]; then
    printf 'Esiste già (%s): la riuso.\n' "$copy"
elif [ -d "$copy" ]; then
    die "Esiste $copy ma non contiene .env: è una copia incompleta di un tentativo precedente.
   Eliminala (rm -rf $copy) e rilancia: senza una copia valida il rollback non partirebbe."
elif command -v rsync >/dev/null 2>&1; then
    # `writable/backups` resta fuori: sono i dump e gli zip dei file caricati, e
    # copiarli a ogni deploy raddoppierebbe i GB che la rotazione già tiene.
    rsync -a --exclude 'writable/backups/' "$APP_DIR"/ "$copy"/ \
        || die "Copia di sicurezza non riuscita ($copy): il pull non è stato fatto.
   Controlla lo spazio disco e rilancia."
else
    mkdir -p "$copy" \
        || die "Non posso creare $copy: il pull non è stato fatto."
    tar -C "$APP_DIR" -cf - --exclude=writable/backups . | tar -C "$copy" -xf - \
        || die "Copia di sicurezza non riuscita ($copy): il pull non è stato fatto."
fi

[ -f "$copy/.env" ] \
    || die "$copy non contiene .env: la copia non è utilizzabile, e senza una copia valida
   tools/rollback.sh non partirebbe. Il codice non è stato toccato."

# --- 2. Codice -------------------------------------------------------------
say "Codice: $current → $target"
git pull --ff-only "$REMOTE" "$BRANCH" \
    || die "git pull non riuscito (divergenza? modifiche comparse sul server?).
   Il codice è ancora a $(git rev-parse --short HEAD); migrazioni e reload non sono stati eseguiti."

# Le dipendenze si reinstallano solo se il lock è cambiato: un composer
# install completo a ogni aggiornamento riscrive vendor/ e allunga un deploy
# che nella maggior parte dei casi non ha toccato nessuna libreria.
if git diff --name-only "$current" HEAD -- composer.lock | grep -q .; then
    say "composer.lock è cambiato: installo le dipendenze"
    if [ -z "${COMPOSER_BIN:-}" ]; then
        COMPOSER_BIN=$(command -v composer || true)
    fi
    [ -n "${COMPOSER_BIN:-}" ] || die "composer.lock è cambiato ma composer non è nel PATH: imposta COMPOSER_BIN.
   Il codice è aggiornato; le dipendenze no."
    "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction \
        || die "composer install non riuscito: le dipendenze non corrispondono al codice.
   Non ricaricare il pool: leggi l'errore e completa l'installazione."
else
    say "composer.lock invariato: dipendenze non toccate"
fi

# --- 3. Schema e configurazione -------------------------------------------
# La migrazione è il passo che non si può saltare: senza, il codice nuovo
# legge colonne che non esistono.
say "Migrazioni"
"$PHP_BIN" spark migrate \
    || die "Migrazione fallita: fermati qui. Il codice è a $(git rev-parse --short HEAD), lo schema no.
   Leggi l'errore, ripristina il dump se serve e riapri la segnalazione con il nome della migrazione."

# `cache:clear` svuota il gestore configurato — qui Redis, o i file dove Redis
# non c'è — dove stanno valori calcolati dal codice precedente: impostazioni,
# totali dei report. Non è la cache delle configurazioni: quella esiste solo
# dopo `spark optimize` ed è un file, che `cache:clear` non tocca.
say "Cache dell'applicazione"
"$PHP_BIN" spark cache:clear \
    || die "cache:clear non riuscito: l'applicazione potrebbe rileggere valori calcolati dal codice vecchio.
   Rilancia '$PHP_BIN spark cache:clear' e leggi writable/logs."
rm -f writable/cache/FactoriesCache_config

# --- 4. PHP-FPM ------------------------------------------------------------
# L'opcache si carica all'avvio del pool: senza reload può continuare a
# eseguire i file vecchi. Il permesso è già stato verificato all'inizio, quindi
# un fallimento qui significa che è cambiato qualcosa nel frattempo (servizio
# assente, regola sudoers modificata): meglio dirlo che fingere che sia andata.
say "Reload del pool $FPM_SERVICE"
if ! sudo -n "$SYSTEMCTL" reload "$FPM_SERVICE" 2>/dev/null; then
    die "Il pool $FPM_SERVICE non è stato ricaricato, benché il permesso risultasse presente.
   Il codice è a $(git rev-parse --short HEAD). Da un utente con sudo: sudo $SYSTEMCTL reload $FPM_SERVICE
   poi controlla Gotenberg e apri l'applicazione."
fi

# --- 4b. Worker della coda ------------------------------------------------
# `queue:work` è un processo PHP di lunga durata: carica le classi all'avvio e
# senza un riavvio continua a eseguire il codice di prima, in silenzio, con gli
# errori che finiscono nei log come se fossero del codice nuovo. Si manda
# SIGTERM, che il worker intercetta: finisce il job corrente e esce. A
# riavviarlo è chi lo aveva avviato — la riga di crontab con `flock -n` entro un
# minuto, supervisor o systemd subito — perché è lì che si decide quanti worker
# per coda girare, non qui.
say "Worker della coda"
QUEUE_TIMEOUT=${DEPLOY_QUEUE_TIMEOUT:-30}
QUEUE_WAIT=${DEPLOY_QUEUE_RESTART_WAIT:-60}
if ! command -v pgrep >/dev/null 2>&1; then
    printf 'pgrep non è nel PATH: ferma i worker di queue:work a mano.\n'
else
    # Il pattern è il percorso assoluto dello `spark` di questa installazione:
    # un worker avviato con un percorso diverso (o da un'altra copia del sito)
    # non si tocca, ed è meglio un worker vecchio che un worker altrui ucciso.
    pids=$(pgrep -f "$APP_DIR/spark queue:work" 2>/dev/null || true)
    if [ -z "$pids" ]; then
        printf 'Nessun worker in esecuzione: niente da riavviare.\n'
    else
        printf 'Fermo %s worker (finiscono il job corrente).\n' "$(printf '%s\n' "$pids" | wc -l | tr -d ' ')"
        printf '%s\n' "$pids" | xargs kill -TERM 2>/dev/null || true
        waited=0
        while [ "$waited" -lt "$QUEUE_TIMEOUT" ] \
            && pgrep -f "$APP_DIR/spark queue:work" >/dev/null 2>&1; do
            sleep 1
            waited=$((waited + 1))
        done
        if pgrep -f "$APP_DIR/spark queue:work" >/dev/null 2>&1; then
            printf 'Attenzione: un worker è ancora attivo dopo %s s (job lungo?): controllalo a mano.\n' \
                "$QUEUE_TIMEOUT"
        else
            printf 'Worker fermi: li rilancia chi li aveva avviati.\n'
            # Che siano fermi è il punto: che tornino no, e nessun altro lo
            # controllerà. Il cron li riavvia al minuto successivo, supervisor
            # e systemd quasi subito, quindi si aspetta — ma un «Fatto» senza
            # questa attesa nasconderebbe code ferme, che non danno errori:
            # solo lavoro che non avanza.
            waited=0
            back=0
            while [ "$waited" -lt "$QUEUE_WAIT" ]; do
                sleep 2
                waited=$((waited + 2))
                if pgrep -f "$APP_DIR/spark queue:work" >/dev/null 2>&1; then
                    back=1
                    break
                fi
            done
            if [ "$back" = "1" ]; then
                printf 'Worker di nuovo attivi dopo %s s.\n' "$waited"
            else
                printf 'Attenzione: dopo %s s nessun worker è ripartito: le code restano ferme finché qualcuno non li avvia (crontab, supervisor, systemd). Controlla con: %s spark queue:status\n' \
                    "$QUEUE_WAIT" "$PHP_BIN"
            fi
        fi
    fi
fi

# --- 5. Gotenberg ----------------------------------------------------------
# Un Gotenberg fermo non blocca i PDF: il ripiego su dompdf li fa uscire
# comunque, con una riga nel log. È proprio per questo che va guardato qui,
# finché qualcuno sta leggendo l'output.
say "Gotenberg ($GOTENBERG_HEALTH)"
if ! gotenberg_status=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 "$GOTENBERG_HEALTH" 2>/dev/null); then
    gotenberg_status="nessuna risposta"
fi
[ "$gotenberg_status" = "200" ] \
    || die "Gotenberg: $gotenberg_status su $GOTENBERG_HEALTH (atteso 200).
   L'aggiornamento è completo, ma i PDF escono da dompdf finché il servizio non torna."

from_short=$(git rev-parse --short "$current")
printf '\nFatto: %s → %s\n' "$from_short" "$(git rev-parse --short HEAD)"
printf 'Per tornare indietro: tools/rollback.sh %s, poi il reload del pool e, se lo schema è cambiato, il ripristino del dump.\n' \
    "$from_short"
printf "La copia di prima del pull è in %s: eliminala quando l'applicazione è confermata,\nperché contiene .env con la chiave di cifratura.\n" "$copy"
