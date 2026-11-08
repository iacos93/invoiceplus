<?php

/**
 * Testi delle pagine di errore di CodeIgniter (namespace Errors).
 *
 * Il framework porta solo la versione inglese: senza questo file il titolo
 * della pagina 404 restava "404 - Page Not Found" mentre il corpo era gia' in
 * italiano, e la pagina di errore in produzione diceva "Whoops!".
 *
 * Le chiavi sono quelle usate da app/Views/errors/html/*.php: aggiungerne
 * altre serve solo se si aggiunge una view di errore che le richiama.
 */

return [
    'pageNotFound'    => '404 — Pagina non trovata',
    'sorryPageNotFound' => 'La pagina che cercavi non esiste o è stata spostata.',
    'badRequest'      => '400 — Richiesta non valida',
    'sorryBadRequest' => 'La richiesta non è arrivata in una forma comprensibile.',
    'whoops'          => 'Qualcosa è andato storto',
    'weHitASnag'      => 'Si è verificato un errore imprevisto. Riprova tra poco.',
    'home'            => 'Home',
    'goBack'          => 'Torna indietro',
];
