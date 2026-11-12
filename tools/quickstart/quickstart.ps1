# Prova Invoice+ sul tuo computer, con dati demo (Windows).
#
# Crea un database PostgreSQL separato (invoiceplane_demo), scrive un .env
# dedicato, applica le migrazioni, carica i dati di prova e avvia il server di
# sviluppo su http://localhost:8080. Non tocca il database di lavoro.
#
# Uso (PowerShell):
#   powershell -ExecutionPolicy Bypass -File tools\quickstart\quickstart.ps1
#   ... quickstart.ps1 -NoServe    # configura e basta
#   ... quickstart.ps1 -Force      # sostituisce un .env
#
# Variabili facoltative:
#   IP_DEMO_DB_HOST (127.0.0.1)  IP_DEMO_DB_PORT (5432)
#   IP_DEMO_DB_NAME (invoiceplane_demo)
#   IP_DEMO_DB_USER              IP_DEMO_DB_PASSWORD
#
# Il file .env non e' versionato: questo script lo segnala con una riga
# "Generato da tools/quickstart" e, se ne trova uno diverso, si ferma invece
# di sovrascrivere la configurazione di chi sviluppa.

[CmdletBinding()]
param(
    [switch]$Force,
    [switch]$NoServe,
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'

try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

$dbHost = if ($env:IP_DEMO_DB_HOST) { $env:IP_DEMO_DB_HOST } else { '127.0.0.1' }
$dbPort = if ($env:IP_DEMO_DB_PORT) { $env:IP_DEMO_DB_PORT } else { '5432' }
$dbName = if ($env:IP_DEMO_DB_NAME) { $env:IP_DEMO_DB_NAME } else { 'invoiceplane_demo' }

function Write-Info($message) { Write-Host $message -ForegroundColor Cyan }
function Write-Ok($message)   { Write-Host $message -ForegroundColor Green }
function Die($message)        { Write-Host "Errore: $message" -ForegroundColor Red; exit 1 }

# --- Prerequisiti ---------------------------------------------------------

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Die "PHP non e' nel PATH. Installa PHP 8.4 o successivo e riapri il terminale."
}

& php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) {
    $versione = (& php -r 'echo PHP_VERSION;')
    Die "Serve PHP 8.4 o successivo (trovato $versione)."
}

$moduli = & php -m
$mancanti = @()
foreach ($ext in @('intl', 'mbstring', 'curl', 'gd', 'zip', 'pgsql', 'pdo_pgsql')) {
    if ($moduli -notcontains $ext) { $mancanti += $ext }
}
if ($mancanti.Count -gt 0) {
    Die ("Mancano le estensioni PHP: " + ($mancanti -join ', ') + ". Abilitale in php.ini e riprova.")
}

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    Die "Composer non e' nel PATH: https://getcomposer.org/download/"
}
if (-not (Get-Command psql -ErrorAction SilentlyContinue)) {
    Die "psql non e' nel PATH: serve il client di PostgreSQL."
}
if (-not (Get-Command createdb -ErrorAction SilentlyContinue)) {
    Die "createdb non e' nel PATH: serve il client di PostgreSQL."
}

if ($dbName -notmatch '^[A-Za-z0-9_]+$') {
    Die "IP_DEMO_DB_NAME puo' contenere solo lettere, numeri e underscore."
}

# --- .env -----------------------------------------------------------------

$envFile = Join-Path $Root '.env'

if ((Test-Path $envFile) -and -not (Select-String -Path $envFile -SimpleMatch 'Generato da tools/quickstart' -Quiet)) {
    if (-not $Force) {
        Die "Esiste gia' un .env che non e' stato creato da questo script.`n       Per non sovrascrivere la tua configurazione mi fermo qui.`n       Sposta il file, oppure rilancia con -Force: verra' salvata una copia`n       .env.quickstart-backup-<data> prima di sostituirlo."
    }

    $backup = "$envFile.quickstart-backup-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
    Copy-Item $envFile $backup
    Write-Info "Configurazione precedente salvata in $(Split-Path $backup -Leaf)."
}

# --- Database -------------------------------------------------------------

if ($env:IP_DEMO_DB_PASSWORD) {
    $env:PGPASSWORD = $env:IP_DEMO_DB_PASSWORD
}

$candidati = @()
if ($env:IP_DEMO_DB_USER) { $candidati += $env:IP_DEMO_DB_USER }
if ($env:PGUSER)          { $candidati += $env:PGUSER }
if ($env:USERNAME)        { $candidati += $env:USERNAME }
$candidati += 'postgres'

$dbUser = ''
foreach ($candidato in $candidati) {
    & psql -h $dbHost -p $dbPort -U $candidato -d postgres -w -Atqc 'SELECT 1' 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        $dbUser = $candidato
        break
    }
}

if ($dbUser -eq '') {
    Die "Non riesco a collegarmi a PostgreSQL su ${dbHost}:${dbPort}.`n       Controlla che il server sia acceso, poi indica utente e password:`n         `$env:IP_DEMO_DB_USER='postgres'; `$env:IP_DEMO_DB_PASSWORD='...'; .\tools\quickstart\quickstart.ps1"
}

Write-Info "PostgreSQL: $dbUser@${dbHost}:${dbPort}, database $dbName"

$esiste = (& psql -h $dbHost -p $dbPort -U $dbUser -d postgres -w -Atqc "SELECT 1 FROM pg_database WHERE datname = '$dbName'" | Out-String).Trim()

if ($esiste -ne '1') {
    & createdb -h $dbHost -p $dbPort -U $dbUser $dbName
    if ($LASTEXITCODE -ne 0) { Die "Creazione del database $dbName non riuscita." }
    Write-Ok "Database $dbName creato."
} else {
    Write-Info "Database $dbName gia' presente: i dati demo verranno ricaricati."
}

# --- Applicazione ---------------------------------------------------------

Set-Location $Root

if (-not (Test-Path (Join-Path $Root 'vendor'))) {
    Write-Info "Installo le dipendenze PHP (la prima volta puo' richiedere qualche minuto)..."
    & composer install --no-interaction --prefer-dist
    if ($LASTEXITCODE -ne 0) { Die "composer install non riuscito." }
}

$righe = @(
    '# Generato da tools/quickstart/quickstart.ps1 - non versionato.'
    '# Per la prova in locale. Non usare questa configurazione in produzione.'
    'CI_ENVIRONMENT = development'
    ''
    "app.baseURL = http://localhost:${Port}/"
    ''
    "database.default.hostname = ${dbHost}"
    "database.default.port = ${dbPort}"
    "database.default.database = ${dbName}"
    "database.default.username = ${dbUser}"
)
if ($env:IP_DEMO_DB_PASSWORD) {
    $righe += "database.default.password = '$($env:IP_DEMO_DB_PASSWORD)'"
}
$righe += @(
    'database.default.DBDriver = Postgre'
    ''
    '# Niente Redis per una prova sul portatile: cache e sessioni su file.'
    'cache.handler = file'
    "session.driver = 'CodeIgniter\Session\Handlers\FileHandler'"
    "session.savePath = '$Root\writable\session'"
)

$contenuto = ($righe -join "`r`n") + "`r`n"
[System.IO.File]::WriteAllText($envFile, $contenuto, (New-Object System.Text.UTF8Encoding($false)))

Write-Info 'Chiave di cifratura...'
& php spark key:generate --force | Out-Null
if ($LASTEXITCODE -ne 0) { Die "Generazione della chiave non riuscita." }

Write-Info 'Creo lo schema dalle migrazioni...'
& php spark migrate | Out-Null
if ($LASTEXITCODE -ne 0) { Die "Migrazioni non riuscite." }

Write-Info 'Carico i dati demo...'
$env:PGOPTIONS = '-c client_min_messages=warning'
& psql -h $dbHost -p $dbPort -U $dbUser -d $dbName -w -v ON_ERROR_STOP=1 -q -f 'tests/fixtures/ci_seed.sql'
$esitoSeed = $LASTEXITCODE
Remove-Item Env:PGOPTIONS -ErrorAction SilentlyContinue
if ($esitoSeed -ne 0) { Die "Caricamento dei dati demo non riuscito." }

# La fixture ha un hash di comodo: qui si imposta una password nota. user_psalt
# resta quello del seed (se fosse vuoto, il login proverebbe la vecchia via md5).
$hash = (& php -r 'echo password_hash("demo1234", PASSWORD_DEFAULT);')
@"
UPDATE ip_users
   SET user_password = :'hash',
       user_mfa_enabled = false,
       user_passwordreset_token = ''
 WHERE user_id = 1;
"@ | & psql -h $dbHost -p $dbPort -U $dbUser -d $dbName -w -v ON_ERROR_STOP=1 -q -v "hash=$hash"
if ($LASTEXITCODE -ne 0) { Die "Impostazione della password demo non riuscita." }

Write-Ok 'Tutto pronto.'
Write-Host ''
Write-Host "  Indirizzo:  http://localhost:${Port}/"
Write-Host '  Email:      admin@example.com'
Write-Host '  Password:   demo1234'
Write-Host ''
Write-Host "Dati demo inventati e database separato (${dbName})."
Write-Host 'Per ricaricare i dati demo basta rilanciare questo script.'
Write-Host 'Per fermare il server: Ctrl+C.'
Write-Host ''

if (-not $NoServe) {
    & php spark serve --host localhost --port $Port
}
