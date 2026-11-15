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
<meta name="theme-color" content="#1E73B8">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Invoice+">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="<?= base_url('assets/core/img/apple-touch-icon.png') ?>">
<script>
    // Registrazione a caricamento finito: non deve contendere la rete alla
    // pagina. Un errore qui (http senza TLS, browser senza supporto) non deve
    // disturbare nessuno: l'app funziona identica senza service worker.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('<?= base_url('sw.js') ?>').catch(function () {});
        });
    }
</script>
