<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= lang('Errors.pageNotFound') ?></title>

    <?php
    /* Questa pagina vive fuori dal layout dell'applicazione: CodeIgniter la
       rende senza sessione né helper, quindi non può includere layout/base.
       Restava però anche fuori dal TEMA: con l'app in scuro, un URL sbagliato
       apriva una pagina bianca — l'utente pensava di essere finito su un altro
       sito proprio nel momento in cui era già disorientato.
       Qui sotto i token sono gli stessi di custom.css e la preferenza si legge
       da localStorage con lo stesso script inline del layout principale. */
    ?>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('ip-theme');
                if (!t) {
                    t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) { /* localStorage negato: resta il tema chiaro */ }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600&family=Fraunces:opsz,wght@9..144,600&family=IBM+Plex+Mono:wght@400&display=swap">

    <style>
        :root {
            --ip-bg: #f6f6f7;
            --ip-bg-card: #ffffff;
            --ip-border: #e4e4e7;
            --ip-text: #18181b;
            --ip-text-muted: #71717a;
            --ip-primary: #18181b;
            --ip-on-primary: #ffffff;
            --ip-danger: #B94A48;
            --ip-hover: #f4f4f5;
            --ip-radius: 10px;
        }
        html[data-theme="dark"] {
            --ip-bg: #151518;
            --ip-bg-card: #1e1e23;
            --ip-border: #33333b;
            --ip-text: #e7e7ea;
            --ip-text-muted: #9b9ba5;
            --ip-primary: #e7e7ea;
            --ip-on-primary: #151518;
            --ip-danger: #d0716e;
            --ip-hover: #26262d;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--ip-bg);
            color: var(--ip-text);
            font-family: "Figtree", "Helvetica Neue", Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .wrap {
            width: 100%;
            max-width: 520px;
            padding: 40px;
            background: var(--ip-bg-card);
            border: 1px solid var(--ip-border);
            border-radius: var(--ip-radius);
            text-align: center;
        }
        h1 {
            font-family: "Fraunces", Georgia, serif;
            font-weight: 600;
            letter-spacing: -0.02em;
            font-size: 2.5rem;
            line-height: 1.1;
            margin: 0;
        }
        p {
            margin: 12px 0 0;
            color: var(--ip-text-muted);
            font-size: 15px;
            line-height: 1.55;
        }
        /* Dettaglio tecnico: c'è solo fuori produzione ed è testo del framework,
           quindi in inglese. Trattato da dato di servizio (monospazio, piccolo,
           allineato a sinistra) e non da messaggio all'utente. */
        .detail {
            margin-top: 18px;
            padding: 10px 12px;
            background: var(--ip-hover);
            border-radius: 6px;
            font-family: "IBM Plex Mono", ui-monospace, monospace;
            font-size: 12px;
            line-height: 1.6;
            color: var(--ip-text-muted);
            text-align: left;
            overflow-x: auto;
        }
        .actions {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 24px;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: background-color .15s ease, border-color .15s ease;
        }
        .btn-primary {
            color: var(--ip-on-primary);
            background-color: var(--ip-primary);
            border-color: var(--ip-primary);
        }
        .btn-secondary {
            color: var(--ip-text);
            background-color: var(--ip-bg-card);
            border-color: var(--ip-border);
        }
        .btn-secondary:hover { background-color: var(--ip-hover); }
        .btn:focus-visible {
            outline: 2px solid var(--ip-primary);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <?php /* Niente timbro "404" a parte: la stringa tradotta lo contiene già
             ("404 — Pagina non trovata") e comparivano due volte di fila. */ ?>
    <h1><?= lang('Errors.pageNotFound') ?></h1>

    <p><?= lang('Errors.sorryPageNotFound') ?></p>

    <?php if (ENVIRONMENT !== 'production' && ! empty($message)) : ?>
        <div class="detail"><?= nl2br(esc($message)) ?></div>
    <?php endif; ?>

    <?php /* "Torna indietro" prima di "Vai alla home": nel 404 la pagina utile
             è quasi sempre quella da cui si arriva, non la bacheca. */ ?>
    <div class="actions">
        <button type="button" class="btn btn-secondary" onclick="window.history.back()">
            &larr; <?= lang('Errors.goBack') ?>
        </button>
        <a href="<?= base_url() ?>" class="btn btn-primary"><?= lang('Errors.home') ?></a>
    </div>
</div>
</body>
</html>
