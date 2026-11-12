#!/usr/bin/env bash
#
# Prova Invoice+ sul tuo computer, con dati demo.
#
# Crea un database PostgreSQL separato (invoiceplane_demo), scrive un .env
# dedicato, applica le migrazioni, carica i dati di prova e avvia il server di
# sviluppo su http://localhost:8080. Non tocca il database di lavoro.
#
# Uso:
#   bash tools/quickstart/quickstart.sh
#   bash tools/quickstart/quickstart.sh --no-serve   # configura e basta
#   bash tools/quickstart/quickstart.sh --force      # sostituisce un .env
#
# Variabili facoltative:
#   IP_DEMO_DB_HOST (127.0.0.1)  IP_DEMO_DB_PORT (5432)
#   IP_DEMO_DB_NAME (invoiceplane_demo)
#   IP_DEMO_DB_USER              IP_DEMO_DB_PASSWORD
#   IP_DEMO_PORT (8080)
#
# Il file .env non è versionato: questo script lo segnala con una riga
# "Generato da tools/quickstart" e, se ne trova uno diverso, si ferma invece
# di sovrascrivere la configurazione di chi sviluppa.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

DB_HOST="${IP_DEMO_DB_HOST:-127.0.0.1}"
DB_PORT="${IP_DEMO_DB_PORT:-5432}"
DB_NAME="${IP_DEMO_DB_NAME:-invoiceplane_demo}"
PORT="${IP_DEMO_PORT:-8080}"

FORCE=0
SERVE=1

for arg in "$@"; do
    case "$arg" in
        --force)    FORCE=1 ;;
        --no-serve) SERVE=0 ;;
        --help|-h)
            sed -n '3,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Opzione sconosciuta: $arg (usa --help)" >&2
            exit 1
            ;;
    esac
done

info() { printf '\033[1;34m%s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m%s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mErrore: %s\033[0m\n' "$*" >&2; exit 1; }

# --- Prerequisiti ---------------------------------------------------------

command -v php >/dev/null 2>&1 || die "PHP non è nel PATH. Installa PHP 8.4 o successivo."

php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' \
    || die "Serve PHP 8.4 o successivo (trovato $(php -r 'echo PHP_VERSION;'))."

MISSING=""
PHP_MODULES="$(php -m)"
for ext in intl mbstring curl gd zip pgsql pdo_pgsql; do
    # Il foglio dei moduli si legge una volta sola: con `php -m | grep -q` e
    # pipefail, grep chiude la pipe appena trova la riga e php esce per SIGPIPE,
    # facendo sembrare mancante ogni estensione.
    grep -qi "^${ext}$" <<< "$PHP_MODULES" || MISSING="$MISSING $ext"
done

[ -z "$MISSING" ] || die "Mancano le estensioni PHP:$MISSING. Abilitale in php.ini e riprova."

command -v composer >/dev/null 2>&1 || die "Composer non è nel PATH: https://getcomposer.org/download/"
command -v psql >/dev/null 2>&1 || die "psql non è nel PATH: serve il client di PostgreSQL."
command -v createdb >/dev/null 2>&1 || die "createdb non è nel PATH: serve il client di PostgreSQL."

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "IP_DEMO_DB_NAME può contenere solo lettere, numeri e underscore."

# --- .env -----------------------------------------------------------------

ENV_FILE="$ROOT/.env"

if [ -f "$ENV_FILE" ] && ! grep -q "Generato da tools/quickstart" "$ENV_FILE"; then
    if [ "$FORCE" -ne 1 ]; then
        die "Esiste già un .env che non è stato creato da questo script.
       Per non sovrascrivere la tua configurazione mi fermo qui.
       Sposta il file, oppure rilancia con --force: verrà salvata una copia
       .env.quickstart-backup-<data> prima di sostituirlo."
    fi

    BACKUP="$ENV_FILE.quickstart-backup-$(date +%Y%m%d-%H%M%S)"
    cp "$ENV_FILE" "$BACKUP"
    info "Configurazione precedente salvata in $(basename "$BACKUP")."
fi

# --- Database -------------------------------------------------------------

if [ -n "${IP_DEMO_DB_PASSWORD:-}" ]; then
    export PGPASSWORD="$IP_DEMO_DB_PASSWORD"
fi

CANDIDATES=()
[ -n "${IP_DEMO_DB_USER:-}" ] && CANDIDATES+=("$IP_DEMO_DB_USER")
[ -n "${PGUSER:-}" ] && CANDIDATES+=("$PGUSER")
[ -n "${USER:-}" ] && CANDIDATES+=("$USER")
CANDIDATES+=("postgres")

DB_USER=""
for candidate in "${CANDIDATES[@]}"; do
    if psql -h "$DB_HOST" -p "$DB_PORT" -U "$candidate" -d postgres -w -Atqc 'SELECT 1' >/dev/null 2>&1; then
        DB_USER="$candidate"
        break
    fi
done

if [ -z "$DB_USER" ]; then
    die "Non riesco a collegarmi a PostgreSQL su $DB_HOST:$DB_PORT.
       Controlla che il server sia acceso, poi indica utente e password:
         IP_DEMO_DB_USER=postgres IP_DEMO_DB_PASSWORD=... bash tools/quickstart/quickstart.sh"
fi

info "PostgreSQL: $DB_USER@$DB_HOST:$DB_PORT, database $DB_NAME"

ESISTE=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -w -Atqc \
    "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'")

if [ "$ESISTE" != "1" ]; then
    createdb -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME"
    ok "Database $DB_NAME creato."
else
    info "Database $DB_NAME già presente: i dati demo verranno ricaricati."
fi

# --- Applicazione ---------------------------------------------------------

cd "$ROOT"

if [ ! -d vendor ]; then
    info "Installo le dipendenze PHP (la prima volta può richiedere qualche minuto)..."
    composer install --no-interaction --prefer-dist
fi

{
    echo "# Generato da tools/quickstart/quickstart.sh — non versionato."
    echo "# Per la prova in locale. Non usare questa configurazione in produzione."
    echo "CI_ENVIRONMENT = development"
    echo
    echo "app.baseURL = http://localhost:${PORT}/"
    echo
    echo "database.default.hostname = ${DB_HOST}"
    echo "database.default.port = ${DB_PORT}"
    echo "database.default.database = ${DB_NAME}"
    echo "database.default.username = ${DB_USER}"
    if [ -n "${IP_DEMO_DB_PASSWORD:-}" ]; then
        printf "database.default.password = '%s'\n" "$IP_DEMO_DB_PASSWORD"
    fi
    echo "database.default.DBDriver = Postgre"
    echo
    echo "# Niente Redis per una prova sul portatile: cache e sessioni su file."
    echo "cache.handler = file"
    echo "session.driver = 'CodeIgniter\\Session\\Handlers\\FileHandler'"
    echo "session.savePath = '${ROOT}/writable/session'"
} > "$ENV_FILE"

info "Chiave di cifratura..."
php spark key:generate --force >/dev/null

info "Creo lo schema dalle migrazioni..."
php spark migrate >/dev/null

info "Carico i dati demo..."
PGOPTIONS='-c client_min_messages=warning' \
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -w \
    -v ON_ERROR_STOP=1 -q -f tests/fixtures/ci_seed.sql

# La fixture ha un hash di comodo: qui si imposta una password nota. user_psalt
# resta quello del seed (se fosse vuoto, il login proverebbe la vecchia via md5).
HASH=$(php -r 'echo password_hash("demo1234", PASSWORD_DEFAULT);')
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -w \
    -v ON_ERROR_STOP=1 -q -v hash="$HASH" <<'SQL'
UPDATE ip_users
   SET user_password = :'hash',
       user_mfa_enabled = false,
       user_passwordreset_token = ''
 WHERE user_id = 1;
SQL

ok "Tutto pronto."
echo
echo "  Indirizzo:  http://localhost:${PORT}/"
echo "  Email:      admin@example.com"
echo "  Password:   demo1234"
echo
echo "Dati demo inventati e database separato (${DB_NAME})."
echo "Per ricaricare i dati demo basta rilanciare questo script."
echo "Per fermare il server: Ctrl+C."
echo

if [ "$SERVE" -eq 1 ]; then
    exec php spark serve --host localhost --port "$PORT"
fi
