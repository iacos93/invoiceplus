#!/bin/bash
# harness di tools/rollback.sh — verifica lo script su repository finti.
#
# Un rollback si esegue quando qualcosa è già andato storto, quindi deve
# funzionare la prima volta: qui ogni caso costruisce un repository usa e getta
# con due commit e la copia `release-pre`, e controlla cosa lo script fa davvero
# — exit code, commit raggiunto, file toccati, messaggi — invece di leggere il
# codice e sperare. `php` e `composer` sono stub che registrano la chiamata: non
# servono né PHP né un database.
#
# Uso, dalla radice del repository:
#
#   bash tools/tests/rollback_harness.sh
#
# Esce 0 se tutti i controlli passano. Non tocca il repository in cui gira: ogni
# scenario vive in una cartella temporanea, rimossa all'uscita.

set -u

REPO=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
T=$(mktemp -d) || exit 1
trap 'rm -rf "$T"' EXIT
PASS=0
FAIL=0

ok() { PASS=$((PASS + 1)); printf '  ok   %s\n' "$1"; }
ko() { FAIL=$((FAIL + 1)); printf '  FAIL %s\n' "$1"; }
chk() { if [ "$2" = "$3" ]; then ok "$1"; else ko "$1 (atteso [$3], ottenuto [$2])"; fi; }
has() { case "$2" in *"$1"*) ok "output contiene «$1»" ;; *) ko "output senza «$1»" ;; esac; }
miss() { case "$2" in *"$1"*) ko "output non doveva contenere «$1»" ;; *) ok "output senza «$1»" ;; esac; }

mkdir -p "$T/bin"
cat > "$T/bin/php" <<'EOS'
#!/bin/sh
printf 'php %s\n' "$*" >> "${PHP_LOG:-/dev/null}"
if [ "$1" = "-r" ]; then
  case "$2" in *PHP_VERSION*) printf '8.4.14\n' ;; esac
  exit 0
fi
exit 0
EOS
cat > "$T/bin/composer" <<'EOS'
#!/bin/sh
printf 'composer %s\n' "$*" >> "${COMPOSER_LOG:-/dev/null}"
exit 0
EOS
chmod +x "$T/bin/php" "$T/bin/composer"

# Un'app finta con due commit e la copia del primo: è lo stato che un deploy ha
# lasciato dietro, quello che un rollback deve trovare.
#   make_app <cartella> <plain|migration|lock> [nodump]
make_app() {
    d=$1
    mode=$2
    nodump=${3:-0}
    mkdir -p "$d"
    (
        cd "$d" || exit 1
        git init -q -b main
        git config user.email harness@example.test
        git config user.name Harness
        mkdir -p app/Controllers app/Database/Migrations tools writable/backups vendor public
        printf 'database.default.DBDriver = Postgre\n' > .env
        printf '{"packages":[]}\n' > composer.lock
        printf 'dump\n' > writable/backups/backup_20260101_030000.dump
        if [ "$nodump" = "1" ]; then rm -f writable/backups/backup_*; fi
        printf 'lib\n' > vendor/lib.php
        printf '<html>\n' > public/index.php
        printf '<?php // a\n' > app/Controllers/A.php
        cp "$REPO/tools/rollback.sh" tools/rollback.sh
        chmod +x tools/rollback.sh
        git add -A && git commit -qm primo
        pre_short=$(git rev-parse --short HEAD)
        case "$mode" in
            migration) printf '<?php // mig\n' > app/Database/Migrations/2026-01-01-000000_AddX.php ;;
            lock) printf '{"packages":["x"]}\n' > composer.lock ;;
            plain) printf '<?php // b\n' > app/Controllers/A.php ;;
        esac
        git add -A && git commit -qm secondo
        # La copia si fa sull'albero del primo commit, com'era prima del deploy.
        git checkout -q "$pre_short"
        mkdir -p "../release-pre-$pre_short"
        tar -cf - . | tar -C "../release-pre-$pre_short" -xf -
        git checkout -q main
        printf '%s' "$pre_short" > "$d.pre"
    )
}

run_rb() {
    d=$1
    input=$2
    shift 2
    (
        cd "$d" || exit 1
        printf '%b' "$input" | PATH="$T/bin:$PATH" PHP_LOG="$d/php.log" COMPOSER_LOG="$d/composer.log" \
            ./tools/rollback.sh "$@" 2>&1
    )
}

echo "== 1. caso normale: nessuna migrazione, copia presente"
d="$T/s1"; make_app "$d" plain
pre=$(cat "$d.pre")
prev=$(git -C "$d" rev-parse HEAD~1)
out=$(run_rb "$d" ""); rc=$?
chk "1.1 exit" "$rc" 0
chk "1.2 HEAD al commit precedente" "$(git -C "$d" rev-parse HEAD)" "$prev"
chk "1.3 HEAD staccato" "$(git -C "$d" rev-parse --abbrev-ref HEAD)" "HEAD"
has "Nessuna migrazione fra i due commit" "$out"
has "spark cache:clear" "$(cat "$d/php.log" 2>/dev/null)"
miss "composer install" "$out"
has "systemctl reload php8.4-fpm" "$out"
has "$pre" "$out"

echo "== 2. copia release-pre assente"
d="$T/s2"; make_app "$d" plain
rm -rf "$T/release-pre-$(cat "$d.pre")"
head=$(git -C "$d" rev-parse HEAD)
out=$(run_rb "$d" ""); rc=$?
chk "2.1 exit" "$rc" 1
chk "2.2 codice intatto" "$(git -C "$d" rev-parse HEAD)" "$head"
has "Manca la copia" "$out"

echo "== 3. albero sporco"
d="$T/s3"; make_app "$d" plain
printf 'x\n' >> "$d/app/Controllers/A.php"
out=$(run_rb "$d" ""); rc=$?
chk "3.1 exit" "$rc" 1
has "non committate" "$out"

echo "== 4. commit inesistente"
d="$T/s4"; make_app "$d" plain
out=$(run_rb "$d" "" deadbeef); rc=$?
chk "4.1 exit" "$rc" 1
has "non è un commit" "$out"

echo "== 5. già al commit indicato"
d="$T/s5"; make_app "$d" plain
out=$(run_rb "$d" "" HEAD); rc=$?
chk "5.1 exit" "$rc" 1
has "già al commit" "$out"

echo "== 6. commit fuori dalla storia (non è un rollback)"
d="$T/s6"; make_app "$d" plain
(
    cd "$d" || exit 1
    main=$(git rev-parse --abbrev-ref HEAD)
    git checkout -q -b altro HEAD~1
    printf '<?php // z\n' > app/Controllers/Z.php
    git add -A && git commit -qm terzo
    git rev-parse HEAD > "$T/alt-sha"
    git checkout -q "$main"
)
alt=$(cat "$T/alt-sha")
out=$(run_rb "$d" "" "$alt"); rc=$?
chk "6.1 exit" "$rc" 1
has "non è nella storia" "$out"

echo "== 7. migrazioni + conferma sì"
d="$T/s7"; make_app "$d" migration
prev=$(git -C "$d" rev-parse HEAD~1)
out=$(run_rb "$d" "s\n"); rc=$?
chk "7.1 exit" "$rc" 0
chk "7.2 HEAD al commit precedente" "$(git -C "$d" rev-parse HEAD)" "$prev"
has "Ripristinare il dump" "$out"
has "pg_restore" "$out"
has "backup_20260101_030000.dump" "$out"
has "2026-01-01-000000_AddX.php" "$out"

echo "== 8. migrazioni + rifiuto"
d="$T/s8"; make_app "$d" migration
prev=$(git -C "$d" rev-parse HEAD~1)
out=$(run_rb "$d" "\n"); rc=$?
chk "8.1 exit" "$rc" 1
chk "8.2 HEAD al commit precedente" "$(git -C "$d" rev-parse HEAD)" "$prev"
has "schema invariato" "$out"
miss "pg_restore" "$out"

echo "== 9. composer.lock cambiato"
d="$T/s9"; make_app "$d" lock
out=$(run_rb "$d" ""); rc=$?
chk "9.1 exit" "$rc" 0
has "composer.lock è cambiato" "$out"
has "composer install --no-dev" "$(cat "$d/composer.log" 2>/dev/null)"

echo "== 10. lanciato da un'altra cartella"
d="$T/s10"; make_app "$d" plain
prev=$(git -C "$d" rev-parse HEAD~1)
rc=0
out=$( cd "$T" && printf '' | PATH="$T/bin:$PATH" PHP_LOG="$T/s10/php.log" "$d/tools/rollback.sh" 2>&1 ) || rc=$?
chk "10.1 exit" "$rc" 0
chk "10.2 HEAD al commit precedente" "$(git -C "$d" rev-parse HEAD)" "$prev"

echo "== 11. migrazioni senza risposta (stdin chiuso)"
d="$T/s11"; make_app "$d" migration
prev=$(git -C "$d" rev-parse HEAD~1)
rc=0
out=$( cd "$d" && PATH="$T/bin:$PATH" ./tools/rollback.sh < /dev/null 2>&1 ) || rc=$?
chk "11.1 exit" "$rc" 1
chk "11.2 HEAD al commit precedente" "$(git -C "$d" rev-parse HEAD)" "$prev"
has "schema invariato" "$out"

echo "== 12. commit più vecchio ma valido"
d="$T/s12"; make_app "$d" plain
first=$(git -C "$d" rev-parse HEAD~1)
out=$(run_rb "$d" "" "$first"); rc=$?
chk "12.1 exit" "$rc" 0
chk "12.2 HEAD" "$(git -C "$d" rev-parse HEAD)" "$first"

echo "== 13. copia incompleta (senza .env)"
d="$T/s13"; make_app "$d" plain
rm -f "$T/release-pre-$(cat "$d.pre")/.env"
head=$(git -C "$d" rev-parse HEAD)
out=$(run_rb "$d" ""); rc=$?
chk "13.1 exit" "$rc" 1
chk "13.2 codice intatto" "$(git -C "$d" rev-parse HEAD)" "$head"
has "non contiene .env" "$out"

echo "== 14. due argomenti"
d="$T/s14"; make_app "$d" plain
out=$(run_rb "$d" "" HEAD~1 HEAD); rc=$?
chk "14.1 exit" "$rc" 1
has "Uso: tools/rollback.sh" "$out"

echo "== 15. argomento che sembra un'opzione"
d="$T/s15"; make_app "$d" plain
out=$(run_rb "$d" "" --force); rc=$?
chk "15.1 exit" "$rc" 1
has "sembra un'opzione" "$out"

echo "== 16. migrazioni, nessun dump, rifiuto"
d="$T/s16"; make_app "$d" migration 1
prev=$(git -C "$d" rev-parse HEAD~1)
out=$(run_rb "$d" "\n"); rc=$?
chk "16.1 exit" "$rc" 1
chk "16.2 HEAD al commit precedente" "$(git -C "$d" rev-parse HEAD)" "$prev"
has "non c'è un dump" "$out"
miss "Ripristinare il dump" "$out"

echo
echo "risultato: $PASS ok, $FAIL falliti"
[ "$FAIL" -eq 0 ]
