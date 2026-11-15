<?php
/**
 * PWA: manifest, icone e registrazione del service worker.
 *
 * Incluso nelle pagine dell'area amministrativa e di accesso. Non nel portale
 * clienti: chi riceve il link a una fattura non deve vedersi proporre di
 * "installare" il gestionale di qualcun altro.
 *
 * Il service worker sta in public/sw.js perché il suo ambito è la cartella da
 * cui viene servito: lì copre tutta l'app, sotto /assets non coprirebbe le
 * pagine. Cosa fa e soprattutto cosa non fa (mai pagine o dati in cache) è
 * spiegato in testa a quel file.
 */
?>
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<?php /* Colore della barra del titolo della finestra installata (e della barra
         del browser sul telefono): lo stesso della barra dell'app, non il blu
         del marchio, altrimenti sopra la barra scura se ne vede una blu. Segue
         il tema scelto nell'app (data-theme, vedi layout/theme_head.php) e non
         quello del sistema; i due valori sono --ip-navbar-bg chiaro e scuro. */ ?>
<meta name="theme-color" content="#333c4a">
<script>
    (function () {
        var meta = document.querySelector('meta[name="theme-color"]');
        var root = document.documentElement;
        function allinea() {
            meta.setAttribute('content', root.getAttribute('data-theme') === 'dark' ? '#3b3b45' : '#333c4a');
        }
        allinea();
        new MutationObserver(allinea).observe(root, { attributes: true, attributeFilter: ['data-theme'] });
    })();
</script>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Invoice+">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="<?= base_url('assets/core/img/apple-touch-icon.png') ?>">
<script>
    // Installazione. Chrome ed Edge avvisano con `beforeinstallprompt` che l'app
    // si può installare: l'evento va trattenuto e riproposto al clic sul
    // pulsante in barra. Il listener è su window e registrato qui, in testa
    // alla pagina: l'evento arriva una volta sola e presto, e questo script
    // non viene sostituito dalla navigazione parziale (a differenza del corpo).
    //
    // Il pulsante però non dipende dall'evento: in incognito, su Safari e su
    // Firefox l'evento non arriva mai e un pulsante che compare solo con
    // l'evento lì non si vedrebbe, lasciando chi lo cerca senza risposta.
    // Si mostra quindi sempre, finché l'app non è installata o aperta come app,
    // e senza evento il clic spiega come installare in quel browser.
    (function () {
        var rinviato = null;
        var CHIAVE = 'ip-pwa-installata';
        var standalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
        var installata = false;
        try { installata = localStorage.getItem(CHIAVE) === '1'; } catch (e) {}
        // In una finestra dell'app il ricordo vale: nel browser normale, dopo
        // l'installazione, il pulsante non deve tornare a ogni visita.
        if (standalone) { try { localStorage.setItem(CHIAVE, '1'); } catch (e) {} }

        function mostra(si) {
            document.querySelectorAll('[data-ip-install]').forEach(function (b) {
                b.hidden = !si;
            });
        }
        function visibile() { return !standalone && !installata; }

        function istruzioni() {
            var ua = navigator.userAgent;
            var incognito = null;
            if (/iPad|iPhone|iPod/.test(ua)) {
                return <?= json_encode(trans('pwa_help_ios', '', 'Tocca il tasto Condividi di Safari e scegli «Aggiungi alla schermata Home».')) ?>;
            }
            if (/Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua)) {
                return <?= json_encode(trans('pwa_help_safari', '', 'In Safari: menu File → «Aggiungi al Dock…».')) ?>;
            }
            if (/Firefox/.test(ua)) {
                return <?= json_encode(trans('pwa_help_firefox', '', 'Firefox sul computer non installa le app: usa Chrome, Edge o Safari.')) ?>;
            }
            return <?= json_encode(trans('pwa_help_chrome', '', 'Chrome non propone l\'installazione in una finestra di navigazione in incognito, o se l\'app è già installata. Dalla finestra normale: menu ⋮ → «Trasmetti, salva e condividi» → «Installa pagina come app».')) ?>;
        }

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            rinviato = e;
        });

        window.addEventListener('appinstalled', function () {
            rinviato = null;
            installata = true;
            try { localStorage.setItem(CHIAVE, '1'); } catch (e) {}
            mostra(false);
            var h = document.getElementById('ip-install-help');
            if (h) { h.hidden = true; }
        });

        document.addEventListener('click', function (e) {
            var help = document.getElementById('ip-install-help');
            var b = e.target.closest('[data-ip-install]');
            if (!b) {
                if (help && !help.contains(e.target)) { help.hidden = true; }
                return;
            }

            if (rinviato) {
                var p = rinviato;
                p.prompt();
                p.userChoice.then(function () {
                    // Rifiutato: il browser non permette di riproporre lo
                    // stesso evento; resta il pulsante con le istruzioni.
                    rinviato = null;
                });
                return;
            }

            if (!help) {
                help = document.createElement('div');
                help.id = 'ip-install-help';
                help.className = 'ip-install-help';
                help.setAttribute('role', 'status');
                help.hidden = true;   // nasce chiuso: il toggle sotto lo apre
                document.body.appendChild(help);
            }
            var r = b.getBoundingClientRect();
            help.textContent = istruzioni();
            help.style.top = (r.bottom + 8) + 'px';
            help.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
            help.hidden = !help.hidden;
        });

        document.addEventListener('keydown', function (e) {
            var h = document.getElementById('ip-install-help');
            if (e.key === 'Escape' && h) { h.hidden = true; }
        });

        // Il corpo della pagina nasce dopo questo script: il pulsante va
        // mostrato appena esiste.
        document.addEventListener('DOMContentLoaded', function () { mostra(visibile()); });
    })();

    // Registrazione a caricamento finito: non deve contendere la rete alla
    // pagina. Un errore qui (http senza TLS, browser senza supporto) non deve
    // disturbare nessuno: l'app funziona identica senza service worker.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('<?= base_url('sw.js') ?>').catch(function () {});
        });
    }
</script>
