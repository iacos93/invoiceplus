#!/bin/bash
# harness di tools/deploy.sh — la copia `release-pre` e il riavvio dei worker.
#
# Un aggiornamento sbagliato si scopre dopo, quando il rollback ha bisogno della
# copia che il deploy doveva lasciare: qui ogni caso costruisce un remoto, un
# clone e i due commit che separano «prima» e «dopo», esegue lo script e
# controlla quello che resta nel disco — la copia con `.env` e `vendor`, senza
# `writable/backups`, il codice aggiornato, i pid che hanno ricevuto SIGTERM —
# invece di leggere il codice e sperare.
#
# `php`, `curl`, `systemctl` e il comando privilegiato sono stub che registrano
# la chiamata e non fanno nulla: non servono PHP, un sito raggiungibile, root né
# le code. Lo scenario 4 toglie `rsync` dal PATH per percorrere il ripiego con
# `tar`, che è quello che gira su un server senza rsync.
#
# Uso, dalla radice del repository:
#
#   bash tools/tests/deploy_harness.sh
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
cat > "$T/bin/curl" <<'EOS'
#!/bin/sh
# -w '%{http_code}' in ultima posizione: risponde 200 e non scrive file.
printf '200'
exit 0
EOS
cat > "$T/bin/systemctl" <<'EOS'
#!/bin/sh
printf 'systemctl %s\n' "$*" >> "${SYS_LOG:-/dev/null}"
exit 0
EOS
# Il comando privilegiato accetta la simulazione (`-n -l`) come il vero `sudo`
# con la regola in sudoers: lo stub risponde sempre 0.
cat > "$T/bin/sudo" <<'EOS'
#!/bin/sh
printf 'privilegiato %s\n' "$*" >> "${SYS_LOG:-/dev/null}"
exit 0
EOS
# `pgrep` trova i worker alla prima chiamata e nessuno dopo: è la sequenza vera
# di uno stop riuscito. PGREP_ALWAYS=1 li tiene vivi, per lo scenario del job
# che non finisce.
cat > "$T/bin/pgrep" <<'EOS'
#!/bin/sh
if [ "${PGREP_ALWAYS:-0}" = "1" ]; then printf '%s\n' ${PGREP_PIDS:-}; exit 0; fi
state="${PGREP_STATE:-/dev/null}"
n=$(cat "$state" 2>/dev/null || echo 0)
n=$((n + 1))
printf '%s' "$n" > "$state"
if [ "$n" -eq 1 ] && [ -n "${PGREP_PIDS:-}" ]; then printf '%s\n' $PGREP_PIDS; exit 0; fi
# Dopo lo stop i worker sono assenti per due chiamate (l'attesa e il controllo
# finale) e ricompaiono dalla quarta: PGREP_AGAIN sono i processi riavviati.
if [ "$n" -ge 4 ] && [ -n "${PGREP_AGAIN:-}" ]; then printf '%s\n' $PGREP_AGAIN; exit 0; fi
exit 1
EOS
cat > "$T/bin/kill" <<'EOS'
#!/bin/sh
printf 'kill %s\n' "$*" >> "${KILL_LOG:-/dev/null}"
exit 0
EOS
chmod +x "$T/bin"/*

# PATH senza rsync, per il ramo di ripiego con tar. Solo i binari che servono a
# deploy.sh e agli stub: se ne manca uno il caso si vede subito, invece di
# riuscire per caso grazie a un rsync trovato altrove.
mkdir -p "$T/bin2"
for b in sh git tar mkdir rm cat head sed awk grep cut tr id dirname cp ln chmod env sort wc mktemp basename date stat touch tail; do
    p=$(command -v "$b" 2>/dev/null) && ln -sf "$p" "$T/bin2/$b"
done
cp "$T/bin"/* "$T/bin2"/

# Un'installazione finta con un remoto bare, un clone in `$d/app` e un commit
# nuovo già sul remoto: è lo stato in cui il deploy trova qualcosa da fare.
setup() {
    d=$1
    mkdir -p "$d"
    git init -q --bare "$d/origin.git"
    git clone -q "$d/origin.git" "$d/seed" 2>/dev/null
    (
        cd "$d/seed" || exit 1
        git checkout -q -b main
        git config user.email harness@example.test
        git config user.name Harness
        mkdir -p app/Controllers tools writable/backups writable/logs vendor public
        printf 'writable/\n' > .gitignore
        printf 'database.default.DBDriver = Postgre\n' > .env
        printf '{}\n' > composer.lock
        printf 'dump vecchio\n' > writable/backups/backup_20260101_030000.dump
        printf 'log\n' > writable/logs/x.log
        printf 'lib\n' > vendor/lib.php
        printf '<?php // a\n' > app/Controllers/A.php
        cp "$REPO/tools/deploy.sh" tools/deploy.sh
        cp "$REPO/tools/rollback.sh" tools/rollback.sh
        chmod +x tools/deploy.sh tools/rollback.sh
        git add -A && git commit -qm primo
        git push -q -u origin main
    )
    git clone -q -b main "$d/origin.git" "$d/app" 2>/dev/null
    (
        cd "$d/seed" || exit 1
        printf '<?php // nuovo\n' > app/Controllers/N.php
        git add -A && git commit -qm secondo
        git push -q origin main
    )
    old=$(git -C "$d/app" rev-parse --short HEAD)
    printf '%s' "$old" > "$d.old"
    # writable/ è fuori da git: il clone non ha né i backup né la configurazione
    # del cron, e il deploy li pretende.
    mkdir -p "$d/app/writable/backups" "$d/app/writable/logs"
    printf 'dump vecchio\n' > "$d/app/writable/backups/backup_20260101_030000.dump"
    printf 'log\n' > "$d/app/writable/logs/x.log"
    printf 'BASE_URL=http://127.0.0.1:9999\nCRON_KEY=chiave\n' > "$d/app/writable/cron-runner.conf"
}

run_deploy() {
    d=$1
    path=$2
    (
        cd "$d/app" || exit 1
        printf '' | PATH="$path" PHP_LOG="$d/php.log" SYS_LOG="$d/sys.log" ./tools/deploy.sh 2>&1
    )
}

echo "== 1. la copia nasce prima del pull, senza i backup"
d="$T/d1"; setup "$d"; old=$(cat "$d.old")
mkdir -p "$d/app/writable/cache"
printf 'cache\n' > "$d/app/writable/cache/FactoriesCache_config"
out=$(run_deploy "$d" "$T/bin:$PATH"); rc=$?
chk "1.1 exit" "$rc" 0
has "Copia di sicurezza per il rollback" "$out"
copy="$d/release-pre-$old"
chk "1.2 cartella copia" "$([ -d "$copy" ] && echo si || echo no)" "si"
chk "1.3 copia con .env" "$([ -f "$copy/.env" ] && echo si || echo no)" "si"
chk "1.4 copia con vendor" "$([ -f "$copy/vendor/lib.php" ] && echo si || echo no)" "si"
chk "1.5 copia con writable/logs" "$([ -f "$copy/writable/logs/x.log" ] && echo si || echo no)" "si"
chk "1.6 copia senza writable/backups" "$([ -e "$copy/writable/backups" ] && echo presente || echo assente)" "assente"
chk "1.7 copia del commit di partenza" "$(git -C "$copy" rev-parse --short HEAD)" "$old"
chk "1.8 codice aggiornato" "$(git -C "$d/app" rev-parse --short HEAD)" "$(git -C "$d/seed" rev-parse --short HEAD)"
has "Per tornare indietro: tools/rollback.sh" "$out"
chk "1.9 cache delle configurazioni rimossa" "$([ -e "$d/app/writable/cache/FactoriesCache_config" ] && echo presente || echo assente)" "assente"
has "La copia di prima del pull è in $copy" "$out"

echo "== 2. copia già presente per lo stesso commit: riusata, non riscritta"
d="$T/d2"; setup "$d"; old=$(cat "$d.old")
mkdir -p "$d/release-pre-$old"
printf 'x\n' > "$d/release-pre-$old/.env"
printf 'mio\n' > "$d/release-pre-$old/marker"
out=$(run_deploy "$d" "$T/bin:$PATH"); rc=$?
chk "2.1 exit" "$rc" 0
has "Esiste già" "$out"
chk "2.2 marcatore intatto" "$(cat "$d/release-pre-$old/marker")" "mio"

echo "== 3. copia incompleta: nessun pull"
d="$T/d3"; setup "$d"; old=$(cat "$d.old")
head=$(git -C "$d/app" rev-parse HEAD)
mkdir -p "$d/release-pre-$old"
out=$(run_deploy "$d" "$T/bin:$PATH"); rc=$?
chk "3.1 exit" "$rc" 1
has "non contiene .env" "$out"
chk "3.2 codice intatto" "$(git -C "$d/app" rev-parse HEAD)" "$head"

echo "== 4. senza rsync: ripiego con tar, stessa esclusione"
d="$T/d4"; setup "$d"; old=$(cat "$d.old")
chk "4.0 rsync fuori dal PATH" "$(PATH="$T/bin2" command -v rsync || echo assente)" "assente"
out=$(run_deploy "$d" "$T/bin2"); rc=$?
chk "4.1 exit" "$rc" 0
copy="$d/release-pre-$old"
chk "4.2 copia con .env" "$([ -f "$copy/.env" ] && echo si || echo no)" "si"
chk "4.3 copia senza writable/backups" "$([ -e "$copy/writable/backups" ] && echo presente || echo assente)" "assente"
chk "4.4 copia del commit di partenza" "$(git -C "$copy" rev-parse --short HEAD)" "$old"

echo "== 5. worker della coda: SIGTERM e attesa"
d="$T/d5"; setup "$d"
export PGREP_PIDS="123 456" PGREP_AGAIN="789" PGREP_STATE="$T/pgrep5" KILL_LOG="$T/kill5.log"
: > "$KILL_LOG"
out=$(run_deploy "$d" "$T/bin:$PATH"); rc=$?
chk "5.1 exit" "$rc" 0
has "Fermo 2 worker" "$out"
has "Worker fermi" "$out"
if grep -q TERM "$KILL_LOG" && grep -q 123 "$KILL_LOG" && grep -q 456 "$KILL_LOG"; then
    ok "5.2 SIGTERM ai due pid"
else
    ko "5.2 SIGTERM ai due pid ($(cat "$KILL_LOG"))"
fi
has "Worker di nuovo attivi" "$out"
unset PGREP_PIDS PGREP_AGAIN PGREP_STATE KILL_LOG

echo "== 6. nessun worker in esecuzione"
d="$T/d6"; setup "$d"
export PGREP_STATE="$T/pgrep6"
out=$(run_deploy "$d" "$T/bin:$PATH"); rc=$?
chk "6.1 exit" "$rc" 0
has "Nessun worker in esecuzione" "$out"
unset PGREP_STATE

echo "== 7. worker che non muore: avviso e deploy che prosegue"
d="$T/d7"; setup "$d"
export PGREP_PIDS="999" PGREP_ALWAYS=1 PGREP_STATE="$T/pgrep7" KILL_LOG="$T/kill7.log" DEPLOY_QUEUE_TIMEOUT=1
out=$(run_deploy "$d" "$T/bin:$PATH"); rc=$?
chk "7.1 exit" "$rc" 0
has "ancora attivo dopo 1 s" "$out"
unset PGREP_PIDS PGREP_ALWAYS PGREP_STATE KILL_LOG DEPLOY_QUEUE_TIMEOUT

echo "== 8. worker fermati che nessuno riavvia: avviso, non un «Fatto»"
d="$T/d8"; setup "$d"
export PGREP_PIDS="321" PGREP_STATE="$T/pgrep8" KILL_LOG="$T/kill8.log" DEPLOY_QUEUE_RESTART_WAIT=1
out=$(run_deploy "$d" "$T/bin:$PATH"); rc=$?
chk "8.1 exit" "$rc" 0
has "nessun worker è ripartito" "$out"
has "spark queue:status" "$out"
unset PGREP_PIDS PGREP_STATE KILL_LOG DEPLOY_QUEUE_RESTART_WAIT

echo
echo "risultato: $PASS ok, $FAIL falliti"
[ "$FAIL" -eq 0 ]
