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
        window.__ipTheme = theme;
    })();

    /* Cambio tema, condiviso dalle tre layout (interna, accesso, guest).
       Se il browser ha le View Transitions, fotografa la pagina prima e dopo
       il cambio e dissolve le due immagini: ogni fotogramma e' una pagina
       completa, quindi il testo resta leggibile per tutta la transizione.

       Prima si metteva una transition su ogni elemento per 300ms: il testo
       sbiadiva (chiaro su chiaro a meta' strada), le ombre e gli sfondi a
       gradiente scattavano di colpo, e la regola !important spegneva le
       transizioni dei componenti — il menu che si chiudeva smetteva di
       sfumare proprio mentre si cambiava tema. Senza View Transitions, o con
       "riduci animazioni" attivo, il cambio e' immediato. */
    window.ipSetTheme = function (theme) {
        var root = document.documentElement;
        var apply = function () {
            root.dataset.theme = theme;
            root.setAttribute('data-bs-theme', theme);
        };

        window.__ipTheme = theme;
        try { localStorage.setItem('ip-theme', theme); } catch (e) {}

        var reduce = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce || typeof document.startViewTransition !== 'function') {
            apply();
            return;
        }

        // Cambio ravvicinato: la transizione in corso verrebbe completata
        // insieme alla nuova, con il tema che torna indietro. La si salta.
        if (window.__ipThemeTransition) {
            try { window.__ipThemeTransition.skipTransition(); } catch (e) {}
        }
        // Topbar e sidebar hanno un view-transition-name per la navigazione fra
        // pagine (15-movimento-rifiniture.css): senza questa classe resterebbero
        // fuori dallo snapshot e cambierebbero colore di colpo. Va messa prima
        // che il browser fotografi la pagina.
        root.classList.add('ip-theme-changing');
        var transition;
        try {
            transition = document.startViewTransition(apply);
        } catch (e) {
            // Il browser rifiuta la transizione se il documento non e' del tutto
            // attivo (scheda in scaricamento, iframe staccato): il tema cambia
            // lo stesso, senza dissolvenza.
            root.classList.remove('ip-theme-changing');
            apply();
            return;
        }
        window.__ipThemeTransition = transition;
        var done = function () {
            if (window.__ipThemeTransition === transition) {
                window.__ipThemeTransition = null;
                root.classList.remove('ip-theme-changing');
            }
        };
        transition.finished.then(done, done);
    };
</script>
