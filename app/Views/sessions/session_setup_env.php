<?php
/**
 * Primo avvio — l'ambiente non è pronto.
 *
 * Pagina autonoma e non dentro layout/auth: quel layout legge le impostazioni
 * dal database (titolo, logo), e questa pagina si mostra proprio quando il
 * database può non rispondere. Usa solo il foglio di stile, che è statico.
 *
 * @var list<array{key: string, label: string, state: string, detail: string, hint: string}> $checks
 */
$stati = [
    'ok'      => ['fa-circle-check', 'regolare', 'text-bg-success'],
    'warning' => ['fa-triangle-exclamation', 'da controllare', 'text-bg-warning'],
    'error'   => ['fa-circle-xmark', 'da sistemare', 'text-bg-danger'],
];
?>
<!DOCTYPE html>
<html lang="<?= trans('cldr', '', 'it') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="NOINDEX,NOFOLLOW">
    <title><?= esc(trans('setup_env_title', '', 'Prima di cominciare')) ?> · Invoice+</title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/core/img/favicon.svg') ?>">
    <?= view('layout/theme_head') ?>
    <?= view('layout/fonts') ?>
    <link rel="stylesheet" href="<?= base_url('assets/core/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/core/vendor/fontawesome6/css/app.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/core/css/custom.min.css') ?>">
    <style>
        .ip-setup-env { max-width: 760px; margin: 0 auto; padding: 40px 20px 64px; }
        .ip-setup-env__brand { display: flex; align-items: center; gap: 10px; margin-bottom: 28px;
            font-family: var(--ip-brand-font, inherit); font-weight: 600; font-size: 20px; color: var(--ip-text-strong); }
        .ip-setup-env__brand svg { width: 32px; height: 32px; }
        .ip-setup-env h1 { font-family: var(--ip-display); font-size: 1.6rem; margin: 0 0 8px; }
        .ip-setup-env__lead { color: var(--ip-text-muted); margin: 0 0 24px; line-height: 1.55; }
        .ip-setup-env__list { list-style: none; margin: 0 0 28px; padding: 0; background: var(--ip-bg-card);
            border: 1px solid var(--ip-border); border-radius: var(--ip-radius); }
        .ip-setup-env__list li { padding: 12px 16px; display: grid; grid-template-columns: 24px 1fr auto; gap: 4px 12px; align-items: start; }
        .ip-setup-env__list li + li { border-top: 1px solid var(--ip-border-soft); }
        .ip-setup-env__label { font-weight: 600; }
        .ip-setup-env__detail { grid-column: 2 / 4; color: var(--ip-text-muted); font-size: var(--ip-text-sm); overflow-wrap: anywhere; }
        .ip-setup-env__hint { grid-column: 2 / 4; font-size: var(--ip-text-sm); }
        .ip-setup-env__why, .ip-setup-env__how { background: var(--ip-bg-card); border: 1px solid var(--ip-border);
            border-radius: var(--ip-radius); padding: 16px 18px; margin: 0 0 16px; }
        .ip-setup-env h2 { font-size: var(--ip-text-lg); margin: 0 0 8px; }
        .ip-setup-env ol { margin: 8px 0 0; padding-left: 20px; line-height: 1.7; }
        .ip-setup-env pre { margin: 8px 0; padding: 10px 12px; background: var(--ip-bg); border: 1px solid var(--ip-border-soft);
            border-radius: 6px; font-family: var(--ip-mono); font-size: var(--ip-text-sm); overflow-x: auto; }
        .ip-setup-env code { font-family: var(--ip-mono); font-size: .92em; }
        .ip-setup-env ul.ip-setup-env__reasons { margin: 8px 0 0; padding-left: 20px; line-height: 1.6; }
        .ip-setup-env__actions { margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap; }
    </style>
</head>
<body>
<main class="ip-setup-env">
    <div class="ip-setup-env__brand">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="ip-brand-mark"><?= view('layout/brand_mark') ?></svg>
        Invoice+
    </div>

    <h1><?= esc(trans('setup_env_title', '', 'Prima di cominciare')) ?></h1>
    <p class="ip-setup-env__lead">
        <?= esc(trans('setup_env_lead', '', 'Invoice+ ha controllato l\'installazione e qualcosa non è ancora a posto. Sistemalo e ricarica la pagina: appena tutto è regolare comparirà il modulo per creare il tuo account.')) ?>
    </p>

    <ul class="ip-setup-env__list" aria-label="<?= esc(trans('setup_env_checks', '', 'Esito dei controlli'), 'attr') ?>">
        <?php foreach ($checks as $check): ?>
            <?php [$icona, $testo, $classe] = $stati[$check['state']] ?? $stati['warning']; ?>
            <li>
                <i class="fa fa-solid <?= esc($icona, 'attr') ?>" aria-hidden="true"></i>
                <span class="ip-setup-env__label"><?= esc($check['label']) ?></span>
                <span class="badge <?= esc($classe, 'attr') ?>"><?= esc($testo) ?></span>
                <span class="ip-setup-env__detail"><?= esc($check['detail']) ?></span>
                <?php if ($check['hint'] !== '' && $check['state'] !== 'ok'): ?>
                    <span class="ip-setup-env__hint"><?= esc($check['hint']) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <section class="ip-setup-env__how" aria-labelledby="setup-how">
        <h2 id="setup-how"><?= esc(trans('setup_env_how', '', 'Come configurare il database')) ?></h2>
        <ol>
            <li>Copia <code>env</code> in <code>.env</code> nella cartella dell'applicazione.</li>
            <li>Imposta le credenziali del database e l'indirizzo del sito:
<pre>database.default.hostname = 127.0.0.1
database.default.database = invoiceplane
database.default.username = ...
database.default.password = ...
database.default.DBDriver = Postgre   # oppure MySQLi
app.baseURL = 'https://fatture.tuodominio.it/'
CI_ENVIRONMENT = production</pre></li>
            <li>Genera la chiave di cifratura, e conservala: senza, i segreti cifrati nel database non si leggono più.
<pre>php spark key:generate</pre></li>
            <li>Crea lo schema:
<pre>php spark migrate</pre></li>
            <li>Ricarica questa pagina.</li>
        </ol>
    </section>

    <section class="ip-setup-env__why" aria-labelledby="setup-why">
        <h2 id="setup-why"><?= esc(trans('setup_env_why', '', 'Perché il database non si configura da qui')) ?></h2>
        <p style="margin:0">A differenza di altri gestionali, Invoice+ non ti chiede le credenziali del database in una pagina web. È una scelta voluta:</p>
        <ul class="ip-setup-env__reasons">
            <li><strong>Questa pagina è pubblica.</strong> Finché non esiste un utente, chiunque raggiunga l'indirizzo la vede. Se potesse scrivere la configurazione, il primo che arriva su un'installazione appena messa online potrebbe puntarla a un database suo e leggerne tutto ciò che inserirai.</li>
            <li><strong>Il <code>.env</code> contiene i segreti.</strong> Password del database e chiave di cifratura dei token non devono mai passare da una richiesta HTTP, finire in un log del server o in una cache.</li>
            <li><strong>Un server web che può riscrivere la propria configurazione</strong> è un server che, se compromesso, può farlo anche un attaccante. Così il file resta in sola lettura per il processo PHP e sotto il controllo di chi amministra la macchina.</li>
            <li><strong>Lo fai una volta sola</strong>, e chi installa un'applicazione con dati fiscali ha comunque accesso al server.</li>
        </ul>
    </section>

    <div class="ip-setup-env__actions">
        <a href="<?= site_url('sessions/setup') ?>" class="btn btn-primary">
            <i class="fa fa-solid fa-rotate" aria-hidden="true"></i> <?= esc(trans('setup_env_recheck', '', 'Ricontrolla')) ?>
        </a>
    </div>
</main>
</body>
</html>
