#!/bin/sh
# cron-runner — voce di crontab unica per tutte le automazioni InvoicePlane.
#
# Al posto di cinque righe crontab (una per endpoint) ne basta una che
# invoca questo script ogni 15 minuti: lo script decide quali job eseguire
# in base alla frequenza prevista, con tre garanzie che il curl nudo non dà:
#
#   1. timeout: nessun job può bloccare la coda (IMAP lento, pg_dump lungo);
#   2. anti-sovrapposizione: un job ancora in esecuzione non viene rilanciato;
#   3. log con rotazione: gli output finiscono su file invece che nel nulla,
#      così un fallimento silenzioso del curl si vede il giorno dopo.
#
# Installazione:
#   1. crea writable/cron-runner.conf con BASE_URL e CRON_KEY (il file non è
#      versionato: una copia del repository non deve contenere la chiave);
#   2. crontab -e  e aggiungi UNA riga:
#        */15 * * * * /percorso/ci4-invoiceplane/tools/cron-runner.sh
#
# Frequenze interne (modificabili nella tabella run_job più sotto):
#   sdi_poll ogni 15 min, recur e backup una volta al giorno, digest una volta
#   alla settimana. I solleciti per le fatture scadute si inviano a mano dalla
#   scheda della fattura: non c'è un endpoint automatico per loro.

BASE_URL=""       # es. https://fatture.tuodominio.it/index.php
CRON_KEY=""

# Valori locali dell'installazione, fuori dal repository. Il file è opzionale:
# chi preferisce può compilare BASE_URL e CRON_KEY qui sopra, ma i file
# versionati devono restare senza segreti.
APP_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CRON_CONF="${APP_DIR}/writable/cron-runner.conf"
[ -f "$CRON_CONF" ] && . "$CRON_CONF"

# Binario PHP per i comandi CLI (verifica del ripristino). In crontab il PATH
# è ridotto: il percorso assoluto evita che `php` non venga trovato.
PHP_BIN="${PHP_BIN:-/opt/homebrew/bin/php}"
[ -x "$PHP_BIN" ] || PHP_BIN=$(command -v php)

# Log e stato dei job stanno dentro writable/cron: la pagina "Stato del
# sistema" li legge per dire se le automazioni girano ancora. In una cartella
# fuori dall'applicazione (era ~/.invoiceplane-cron) nessuno se ne accorge, ed
# è lo stesso motivo per cui il backup è stato fermo giorni senza avvisi.
LOG_DIR="${APP_DIR}/writable/cron"
LOG_FILE="${LOG_DIR}/cron.log"
LOG_MAX_LINES=2000

set -u

if [ -z "$BASE_URL" ] || [ -z "$CRON_KEY" ]; then
    echo "cron-runner: compila BASE_URL e CRON_KEY in writable/cron-runner.conf" >&2
    exit 1
fi

# Se writable/cron non è scrivibile dall'utente del cron, si torna alla
# cartella nella home: meglio un log fuori posto che nessun log.
if ! mkdir -p "$LOG_DIR" 2>/dev/null; then
    LOG_DIR="${HOME}/.invoiceplane-cron"
    LOG_FILE="${LOG_DIR}/cron.log"
    mkdir -p "$LOG_DIR"
fi

log() {
    # Rotazione elementare: oltre LOG_MAX_LINES tiene solo l'ultima metà.
    if [ -f "$LOG_FILE" ] && [ "$(wc -l < "$LOG_FILE")" -ge "$LOG_MAX_LINES" ]; then
        tail -n $((LOG_MAX_LINES / 2)) "$LOG_FILE" > "${LOG_FILE}.tmp" && mv "${LOG_FILE}.tmp" "$LOG_FILE"
    fi
    echo "$(date '+%Y-%m-%d %H:%M:%S') [$1] $2" >> "$LOG_FILE"
}

# Ha già girato abbastanza di recente? (file stato con l'epoca dell'ultima
# esecuzione riuscita; assenza del file = mai girato = esegui)
due() {
    _name=$1
    _interval=$2
    _stamp="${LOG_DIR}/${_name}.last"
    [ -f "$_stamp" ] || return 0
    _now=$(date +%s)
    _last=$(cat "$_stamp" 2>/dev/null || echo 0)
    [ $((_now - _last)) -ge "$_interval" ]
}

mark_done() {
    date +%s > "${LOG_DIR}/${1}.last"
}

# Esegue un job se è ora e se non è già in corso. Il lock è una directory
# (mkdir è atomico anche dove flock non esiste, come macOS).
run_job() {
    _name=$1
    _interval=$2
    _timeout=$3
    _path=$4

    due "$_name" "$_interval" || return 0

    _lock="${LOG_DIR}/${_name}.lock"
    if ! mkdir "$_lock" 2>/dev/null; then
        log "$_name" "saltato: esecuzione precedente ancora in corso"
        return 0
    fi
    trap 'rmdir "${_lock}" 2>/dev/null' EXIT INT TERM

    _out=$(curl -fsS --max-time "$_timeout" "${BASE_URL}${_path}${CRON_KEY}" 2>&1)
    _rc=$?

    if [ $_rc -eq 0 ]; then
        mark_done "$_name"
        log "$_name" "ok: ${_out:-nessun output}"
    else
        # Senza mark_done il prossimo giro riprova: un failure non salta il job.
        log "$_name" "FALLITO (curl rc=$_rc): ${_out}"
    fi

    rmdir "$_lock" 2>/dev/null
    trap - EXIT INT TERM
}

# Come run_job, ma per un comando CLI (php spark ...) invece di un endpoint.
# La verifica del ripristino non può passare da HTTP: crea un database
# temporaneo e confronta i conteggi, e restituisce un codice d'uscita.
run_cli() {
    _name=$1
    _interval=$2
    _timeout=$3
    shift 3

    due "$_name" "$_interval" || return 0

    _lock="${LOG_DIR}/${_name}.lock"
    if ! mkdir "$_lock" 2>/dev/null; then
        log "$_name" "saltato: esecuzione precedente ancora in corso"
        return 0
    fi
    trap 'rmdir "${_lock}" 2>/dev/null' EXIT INT TERM

    if command -v timeout >/dev/null 2>&1; then
        _out=$(cd "$APP_DIR" && timeout "$_timeout" "$@" 2>&1)
    else
        # macOS non ha timeout(1): il comando gira senza limite. La verifica
        # ha un suo limite interno dettato dal database, non dai dati esterni.
        _out=$(cd "$APP_DIR" && "$@" 2>&1)
    fi
    _rc=$?

    if [ $_rc -eq 0 ]; then
        mark_done "$_name"
        log "$_name" "ok: ${_out:-nessun output}"
    else
        log "$_name" "FALLITO (rc=$_rc): ${_out}"
    fi

    rmdir "$_lock" 2>/dev/null
    trap - EXIT INT TERM
}

DAY=86400
WEEK=$((7 * DAY))

#            nome         frequenza  timeout  endpoint
run_job      sdi_poll     $((15 * 60))  120   "/cron/sdi_poll/"
run_job      recur        $DAY          300   "/cron/recur/"
run_job      digest       $WEEK         300   "/cron/digest/"
run_job      backup       $DAY          900   "/cron/backup/"
# Dopo il backup: il dump appena creato viene ripristinato in un database
# temporaneo e confrontato. Fallisce se serve un utente senza permesso di
# creazione database; in quel caso la pagina "Stato del sistema" lo dice.
run_cli      verify       $WEEK        1800   "$PHP_BIN" spark backup:verify
