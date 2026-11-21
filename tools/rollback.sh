#!/bin/sh
# rollback — ritorno al commit precedente di un'installazione Invoice+.
#
# È la procedura di docs/installazione-e-aggiornamento.md («Tornare indietro su
# questo server») messa in uno script, per la stessa ragione di deploy.sh: i
# passi che sotto stress si saltano sono sempre gli stessi, e una copia
# `release-pre-<sha>` controllata a metà non serve a niente. Lo script si ferma
# al primo passo che fallisce.
#
# Uso, dall'utente del sito:
#
#   tools/rollback.sh             # torna al commit precedente (HEAD~1)
#   tools/rollback.sh <commit>    # torna al commit indicato
#
# Non va eseguito come root: `git checkout` non tocca `.env`, `vendor/` e
# `writable/`, quindi i file che l'applicazione crea lì restano di chi li
# scrive; da root resterebbero di root, e log e backup smetterebbero di
# funzionare.
#
# Il codice torna indietro, il database no: le migrazioni non hanno tutte una
# discesa affidabile e i documenti creati nel frattempo non sono nel codice.
# Per questo, quando fra i due commit ci sono migrazioni, lo script si ferma e
# chiede prima di passare al ripristino del dump — che resta un'operazione a
# mano, perché richiede di fermare l'applicazione e di puntare `.env` al
# database ripristinato.
#
# Variabili per le installazioni diverse da questa: DEPLOY_FPM_SERVICE,
# PHP_BIN, COMPOSER_BIN.
#
# Esce 0 quando codice e cache sono tornati indietro; esce 1 anche quando il
# checkout è riuscito ma il dump non è stato ripristinato, perché chi lo lancia
# da uno script deve poter distinguere i due casi.

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

# La conf del cron può indicare PHP_BIN: è lo stesso file che legge deploy.sh,
# e la lettura è difensiva per gli stessi motivi (un errore di sintassi non deve
# far uscire lo script senza dire perché).
CONF="$APP_DIR/writable/cron-runner.conf"
if [ -f "$CONF" ]; then
    sh -n "$CONF" 2>/dev/null \
        || die "$CONF non è un file di configurazione valido (errore di sintassi): correggilo e rilancia."
    set +e
    . "$CONF"
    conf_rc=$?
    set -e
    [ "$conf_rc" -eq 0 ] \
        || printf 'Attenzione: %s contiene una riga che non viene eseguita (exit %s).\n' "$CONF" "$conf_rc" >&2
fi

FPM_SERVICE=${DEPLOY_FPM_SERVICE:-php8.4-fpm}

# Una frase sola per i casi in cui ci si ferma prima di aver toccato qualcosa.
NOTHING_DONE='Nessun passo è stato eseguito: codice, cache e schema sono quelli di prima.'

command -v git >/dev/null 2>&1 || die "git non è nel PATH: senza, lo script non può cambiare commit."

[ "$(id -u)" -ne 0 ] || die "Non eseguire come root: i file creati in writable/ resterebbero di root.
   Entra con l'utente del sito (sudo -iu invoiceplus) e rilancia."

git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
    || die "$APP_DIR non è un repository git: il rollback cambia commit, non copia file."

dirty=$(git status --porcelain)
if [ -n "$dirty" ]; then
    printf '%s\n' "$dirty" | head -10 >&2
    die "Ci sono modifiche non committate in $APP_DIR: un checkout le porterebbe via o le mischierebbe al codice vecchio."
fi

[ "$#" -le 1 ] || die "Uso: tools/rollback.sh [<commit>]. $NOTHING_DONE"
case "${1:-}" in
    -*) die "«$1» sembra un'opzione: lo script accetta solo un commit. $NOTHING_DONE" ;;
esac

from=$(git rev-parse HEAD)
wanted=${1:-HEAD~1}
target=$(git rev-parse --verify "$wanted^{commit}" 2>/dev/null) \
    || die "«$wanted» non è un commit di questo repository. $NOTHING_DONE"
target_short=$(git rev-parse --short "$target")
from_short=$(git rev-parse --short "$from")

[ "$target" != "$from" ] \
    || die "Il codice è già al commit $from_short: non c'è niente da far tornare indietro."

# Un commit che non sta nella storia di HEAD non è un rollback ma un salto: il
# codice tornerebbe a uno stato che questo server non ha mai eseguito, e nessun
# dump fatto prima del deploy corrisponderebbe.
git merge-base --is-ancestor "$target" "$from" \
    || die "Il commit $target_short non è nella storia di $from_short: non è un rollback.
   Per cambiare ramo o saltare a un altro punto usa git checkout a mano."

# --- 0. Copia di sicurezza -------------------------------------------------
# Il nome è quello del commit da cui si torna indietro, perché la copia si fa
# prima del deploy che porta via quel commit.
# `cd .. && pwd` per stampare un percorso senza `..`: è quello che si copia in
# un terminale o in un messaggio senza doverlo correggere a mano.
parent=$(CDPATH= cd -- "$APP_DIR/.." && pwd)
copy="$parent/release-pre-$target_short"
say "Copia di sicurezza: $copy"
[ -d "$copy" ] \
    || die "Manca la copia $copy.
   `tools/deploy.sh` la crea da solo prima del pull: se non c'è, questo deploy
   è stato fatto a mano (o la copia è già stata eliminata). Per rifarla a mano:
       rsync -a --exclude 'writable/backups/' ./ ../release-pre-\$(git rev-parse --short HEAD)/
   Senza, un .env o un vendor/ incoerente non ha da dove essere ripreso. $NOTHING_DONE"
# Una copia interrotta passa il controllo della cartella: `.env` c'è sempre,
# quindi se manca la copia non è utilizzabile.
[ -f "$copy/.env" ] \
    || die "$copy esiste ma non contiene .env: è una copia incompleta, non una rete di sicurezza.
   Rifalla prima del prossimo aggiornamento; per questo rollback serve un altro appoggio. $NOTHING_DONE"

# --- 1. PHP ----------------------------------------------------------------
# La versione del pool, non quella generica della macchina: `php` è spesso una
# 8.1 che non regge il codice, e `spark cache:clear` fallirebbe a metà rollback.
if [ -z "${PHP_BIN:-}" ]; then
    PHP_BIN=$(command -v php8.4 || command -v php || true)
fi
[ -n "${PHP_BIN:-}" ] || die "PHP non trovato: imposta PHP_BIN, o metti php8.4 nel PATH. $NOTHING_DONE"
php_version=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || true)
[ -n "$php_version" ] || die "$PHP_BIN non esegue: imposta PHP_BIN con il binario del pool. $NOTHING_DONE"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.4", ">=") ? 0 : 1);' 2>/dev/null \
    || die "PHP $php_version è troppo vecchio: serve 8.4. Usa il binario del pool (PHP_BIN). $NOTHING_DONE"

say "Rollback di $APP_DIR ($from_short → $target_short, PHP $php_version)"

# --- 2. Codice -------------------------------------------------------------
# HEAD staccato è voluto: con un ramo, il prossimo pull riporterebbe il codice
# avanti, e il rollback sparirebbe al primo aggiornamento. deploy.sh se ne
# accorge e si rifiuta di partire.
say "Codice: $from_short → $target_short ($(git log -1 --format=%s "$target"))"
git checkout --quiet "$target" \
    || die "git checkout non riuscito: il codice è ancora a $(git rev-parse --short HEAD).
   Controlla l'errore qui sopra; se il problema è un file locale, recuperalo da $copy."

# Le dipendenze si riallineano solo se il lock è cambiato: un composer install
# completo riscrive vendor/ e allunga un rollback che spesso non ha toccato
# nessuna libreria.
if git diff --name-only "$target" "$from" -- composer.lock | grep -q .; then
    say "composer.lock è cambiato: riallineo le dipendenze"
    if [ -z "${COMPOSER_BIN:-}" ]; then
        COMPOSER_BIN=$(command -v composer || true)
    fi
    [ -n "${COMPOSER_BIN:-}" ] || die "composer.lock è cambiato ma composer non è nel PATH: imposta COMPOSER_BIN.
   Il codice è tornato indietro; `vendor/` è ancora quello del commit nuovo."
    "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction \
        || die "composer install non riuscito: le dipendenze non corrispondono al codice.
   Non ricaricare il pool: leggi l'errore e completa l'installazione."
else
    say "composer.lock invariato: dipendenze non toccate"
fi

# La cache dell'applicazione (Redis, o file dove Redis non c'è) tiene valori
# calcolati dal codice che si sta lasciando: impostazioni, totali dei report.
# `cache:clear` svuota quel gestore; la cache delle configurazioni è un'altra
# cosa — esiste solo dopo `spark optimize` — e va rimossa a parte.
say "Cache dell'applicazione"
"$PHP_BIN" spark cache:clear \
    || die "cache:clear non riuscito: l'applicazione potrebbe rileggere valori calcolati dal commit nuovo.
   Rilancia '$PHP_BIN spark cache:clear' e leggi writable/logs."
rm -f writable/cache/FactoriesCache_config

# --- 3. Reload del pool ----------------------------------------------------
# Il reload richiede root e lo script non lo esegue: qui è un promemoria, il
# passo che si dimentica più spesso. L'opcache si carica all'avvio del pool e
# senza reload continua a eseguire i file vecchi.
say "Reload del pool $FPM_SERVICE: sudo systemctl reload $FPM_SERVICE (da un utente con sudo)"

# --- 4. Schema e dump ------------------------------------------------------
migrations=$(git diff --name-only "$target" "$from" -- app/Database/Migrations)

if [ -z "$migrations" ]; then
    say "Schema"
    printf "Nessuna migrazione fra i due commit: il database resta com'è.\n"
    printf '\nFatto: %s → %s\n' "$from_short" "$target_short"
    printf 'Per risalire: git checkout main && git pull --ff-only origin main.\n'
    printf "La copia %s si può eliminare quando l'applicazione è confermata.\n" "$copy"
    exit 0
fi

say "Schema: il database non torna indietro con git"
printf '%s\n' "$migrations" | sed 's/^/   /'
printf '\nQueste migrazioni restano applicate: il codice vecchio può trovarsi colonne\n'
printf 'che non conosce, o tabelle in uno stato che non prevede.\n'

# Il più recente non è per forza quello giusto: se il deploy è di qualche giorno
# fa, i backup notturni successivi contengono già lo schema nuovo. La data sta
# nel messaggio proprio perché venga confrontata con quella del deploy.
dump=$(ls -t "$APP_DIR"/writable/backups/backup_* 2>/dev/null | head -1 || true)
if [ -n "$dump" ]; then
    dump_date=$(ls -l "$dump" 2>/dev/null | awk '{print $6, $7, $8}')
    printf 'Il dump da ripristinare è quello fatto prima di questo deploy.\n'
    printf 'Il più recente è del %s:\n   %s\n' "$dump_date" "$dump"
    printf 'Controlla che sia precedente al deploy: i backup notturni successivi hanno già\n'
    printf 'lo schema nuovo e ripristinarli riporterebbe indietro i dati senza il codice.\n'
else
    # Chiedere «ripristino?» senza un dump sarebbe una domanda con una sola
    # risposta possibile: meglio fermarsi qui, con il codice già tornato
    # indietro e il motivo scritto.
    die "Codice al commit $target_short, schema invariato, e in writable/backups non c'è un dump
   da ripristinare: l'applicazione può non funzionare. La copia resta in $copy."
fi

# La conferma è l'ultimo punto in cui si può scegliere di lasciare lo schema
# com'è (per esempio se la migrazione è solo additiva e il codice vecchio la
# tollera). Il ripristino non lo fa lo script: richiede di fermare
# l'applicazione e di puntare `.env` al database ripristinato.
printf '\nRipristinare il dump? [s/N] '
answer=''
read -r answer || answer=''

case "$answer" in
    s|S|si|Si|sì|y|Y|yes|YES)
        say "Ripristino del dump (a mano)"
        driver=$(sed -n 's/^[[:space:]]*database[.]default[.]DBDriver[[:space:]]*=[[:space:]]*//p' .env 2>/dev/null | head -1 | sed 's/[[:space:]"]//g')
        case "$driver" in
            Postgre*)
                printf 'createdb invoiceplus_ripristino\n'
                printf 'pg_restore --no-owner --no-acl -d invoiceplus_ripristino %s\n' "$dump"
                ;;
            MySQL*|MariaDB*)
                printf 'mysql -u root -p -e "CREATE DATABASE invoiceplus_ripristino CHARACTER SET utf8mb4"\n'
                printf 'mysql -u root -p invoiceplus_ripristino < %s\n' "$dump"
                ;;
            *)
                printf 'Motore non riconosciuto in .env: segui la sezione «Ripristino» della guida.\n'
                ;;
        esac
        printf '\nPoi: punta .env al database ripristinato, `php spark migrate` (non deve trovare\n'
        printf 'migrazioni da applicare) e ricontrolla login, una fattura con il PDF e writable/logs.\n'
        printf 'Un controllo senza toccare niente: php spark backup:verify.\n'
        ;;
    *)
        die "Codice al commit $target_short, schema invariato: l'applicazione può non funzionare.
   Se qualcosa non va, ripristina $dump e ricontrolla. La copia resta in $copy."
        ;;
esac

printf '\nFatto: %s → %s (codice; dopo il ripristino anche lo schema)\n' "$from_short" "$target_short"
printf 'Per risalire: git checkout main && git pull --ff-only origin main.\n'
printf "La copia %s si può eliminare quando l'applicazione è confermata.\n" "$copy"
