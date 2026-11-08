<?php
/**
 * Caricamento dei tre caratteri del design system.
 *
 * Prima stavano in un @import dentro custom.css. Quando modern-theme.css e
 * custom.css sono stati fusi in un unico file, l'@import si e' ritrovato dopo
 * un centinaio di regole: la specifica CSS ammette @import solo in testa al
 * foglio (dopo @charset/@layer e nient'altro), quindi il browser lo scartava
 * in silenzio. Risultato: --ip-font, --ip-display e --ip-mono cadevano tutti
 * sul fallback di sistema e l'app girava senza la propria tipografia.
 *
 * Qui i font arrivano da <link> nel <head>: non dipendono piu' dalla posizione
 * di una riga dentro un CSS da 6600 righe, e partono in parallelo a custom.css
 * invece di aspettarne il download (l'@import serializzava le due richieste).
 *
 * Se la rete non raggiunge Google Fonts si ricade sugli stack di sistema
 * dichiarati nei token: la pagina resta leggibile, come oggi.
 *
 * I pesi sono chiesti come intervalli (`400..700`) e non uno per uno: sono
 * famiglie variabili, quindi Google risponde con un solo file per famiglia
 * invece di una istanza per peso. Misurato sui file latini: Figtree passa da
 * 4 file (79 KB) a 1 (20 KB), Fraunces da 3 (197 KB) a 1 (66 KB); restano
 * separati solo i due pesi di IBM Plex Mono, che variabile non e'. In tutto
 * 296 KB -> 105 KB e 9 richieste -> 4, e i pesi intermedi (il 650 dei titoli)
 * ora esistono davvero invece di essere arrotondati al piu' vicino.
 */
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Figtree:wght@400..700&family=Fraunces:opsz,wght@9..144,500..700&family=IBM+Plex+Mono:wght@500;600&display=swap">
