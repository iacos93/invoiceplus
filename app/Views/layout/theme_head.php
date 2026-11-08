<?php
/* Tema chiaro/scuro: applicato PRIMA dei fogli di stile per evitare il
   lampeggio del tema sbagliato (FOUC). La preferenza vive in localStorage
   (ip-theme); senza scelta esplicita segue il sistema operativo. L'attributo
   data-theme pilota i token dark di custom.css, data-bs-theme attiva il
   tema scuro nativo di Bootstrap 5.3 per le superfici dei componenti. */
?>
<script>
    (function () {
        var theme = null;
        try { theme = localStorage.getItem('ip-theme'); } catch (e) { theme = null; }
        if (theme !== 'light' && theme !== 'dark') {
            theme = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
                ? 'dark' : 'light';
        }
        var root = document.documentElement;
        root.dataset.theme = theme;
        root.setAttribute('data-bs-theme', theme);
    })();
</script>