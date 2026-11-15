/*
 * Service worker di Invoice+ (PWA).
 *
 * Fa due sole cose, di proposito:
 *
 *  1. tiene in cache i file statici di /assets (CSS, JS, caratteri, immagini),
 *     così l'app installata si apre subito anche con rete lenta;
 *  2. quando la rete manca del tutto mostra offline.html al posto della
 *     pagina d'errore del browser.
 *
 * NON mette in cache pagine né risposte dell'applicazione. Sono dati fiscali
 * dietro una sessione: una copia in cache resterebbe leggibile dopo il logout,
 * mostrerebbe i documenti di un'azienda a chi ha cambiato azienda, e farebbe
 * vedere importi vecchi come fossero veri. Le pagine passano sempre dalla rete
 * e questo script non le tocca: niente respondWith, il browser fa da solo.
 *
 * Cambiare VERSIONE butta via le cache precedenti alla prossima attivazione.
 */
const VERSIONE = 'v1';
const CACHE_STATICI = 'invoiceplus-statici-' + VERSIONE;
const BASE = new URL('./', self.location).pathname;   // l'app può stare in una sottocartella
const OFFLINE = BASE + 'offline.html';
const PRECACHE = [
    OFFLINE,
    BASE + 'assets/core/img/favicon.svg',
    BASE + 'assets/core/img/pwa-192.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_STATICI)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((nomi) => Promise.all(
                nomi.filter((n) => n.startsWith('invoiceplus-') && n !== CACHE_STATICI)
                    .map((n) => caches.delete(n))
            ))
            .then(() => self.clients.claim())
    );
});

// Gli asset hanno ?v=<data di modifica> nell'indirizzo: a ogni rilascio
// l'indirizzo cambia. La vecchia versione dello stesso file va tolta, o la
// cache crescerebbe di una copia a ogni aggiornamento.
async function salva(cache, request, response) {
    const url = new URL(request.url);
    const chiavi = await cache.keys();
    await Promise.all(
        chiavi
            .filter((k) => {
                const u = new URL(k.url);
                return u.pathname === url.pathname && u.search !== url.search;
            })
            .map((k) => cache.delete(k))
    );
    await cache.put(request, response);
}

async function statico(request) {
    const cache = await caches.open(CACHE_STATICI);
    const inCache = await cache.match(request);
    const versionato = new URL(request.url).searchParams.has('v');

    // Indirizzo versionato: il contenuto non cambia mai, la cache basta.
    if (inCache && versionato) {
        return inCache;
    }

    const rete = fetch(request)
        .then((response) => {
            if (response.ok && response.type === 'basic') {
                salva(cache, request, response.clone());
            }
            return response;
        });

    // Senza versione: subito la copia in cache, intanto la si aggiorna.
    if (inCache) {
        rete.catch(() => {});
        return inCache;
    }

    return rete;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith(BASE + 'assets/')) {
        event.respondWith(statico(request));
        return;
    }

    // Apertura di una pagina: sempre dalla rete. Solo se la rete non risponde
    // affatto si mostra la pagina "sei offline".
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE))
        );
    }
});
