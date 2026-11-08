/**
 * WebAuthn (passkey): registrazione e login senza librerie.
 *
 * Lavora su bottoni marcati con data-webauthn:
 *   - data-webauthn="login"     -> navigator.credentials.get  (login e passo MFA)
 *   - data-webauthn="register"  -> navigator.credentials.create (pagina Sicurezza)
 * Il POST usa l'header X-CSRF-TOKEN letto dal cookie: il filtro CSRF globale
 * protegge anche questi endpoint, che sono senza form.
 *
 * I blob binari viaggiano in base64url, il formato che navigator.credentials
 * restituisce (credential.rawId) e che il server si aspetta indietro.
 */
(function () {
    'use strict';

    /**
     * Prefisso dell'applicazione: '/' alla radice, '/invoiceplane/' quando
     * l'installazione sta in una sottocartella.
     *
     * Gli endpoint erano scritti come percorsi relativi ('sessions/passkey/…')
     * e il browser li risolveva rispetto alla cartella della pagina: da
     * /sessions/login la richiesta partiva verso
     * /sessions/sessions/passkey/options e tornava 404, quindi "Accedi con
     * passkey" non faceva nulla. Sulla pagina /security funzionava per caso,
     * perché lì la cartella corrente è la radice.
     *
     * Il prefisso si ricava dallo src di questo script: sta per costruzione in
     * <radice>/assets/core/js/, quindi tutto ciò che precede è la base. Così
     * non serve stampare una variabile in ogni layout (login, MFA, sicurezza,
     * setup) né toccare il caso della sottocartella.
     */
    var BASE = (function () {
        var src = (document.currentScript && document.currentScript.src) || '';
        var match = src.match(/^(.*?)\/assets\/core\/js\//);

        return match ? match[1] + '/' : '/';
    })();

    var ENDPOINTS = {
        login:    { options: BASE + 'sessions/passkey/options', verify: BASE + 'sessions/passkey/verify' },
        register: { options: BASE + 'security/passkey/options', verify: BASE + 'security/passkey/register' }
    };

    function b64urlEncode(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /**
     * Token CSRF corrente.
     *
     * Qui si leggeva solo il cookie csrf_cookie_name, ma il framework lo crea
     * HttpOnly (default di Config\Cookie): document.cookie non lo contiene
     * mai, quindi la funzione tornava stringa vuota e OGNI chiamata partiva
     * senza header X-CSRF-TOKEN. Il filtro CSRF rispondeva con un redirect
     * alla pagina precedente e il JS, aspettandosi JSON, falliva con
     * "Unexpected token '<'". Da qui il pulsante passkey che non faceva
     * nulla.
     *
     * L'ordine di lettura è: token ruotato dall'ultima risposta, poi la meta
     * `_csrf` stampata nel layout (la stessa che usa già layout/guest.php),
     * infine il cookie per i casi in cui sia leggibile (tokenRandomize = false).
     *
     * Il giro è necessario perché Security::$regenerate è true: ogni POST
     * valido ruota il token, e i due POST di un accesso passkey (options e
     * verify) devono usare ciascuno quello valido in quel momento. Il server
     * rimanda il nuovo in ogni risposta JSON, campo "csrf".
     */
    var csrfState = { token: '' };

    function csrfToken() {
        if (csrfState.token) {
            return csrfState.token;
        }

        var meta = document.querySelector('meta[name="_csrf"]');
        if (meta && meta.content) {
            return meta.content;
        }

        var match = document.cookie.match(/(?:^|;\s*)csrf_cookie_name=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    function api(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            // Una risposta che non è JSON vuol dire che il filtro CSRF ha
            // rimandato alla pagina precedente (Security::$redirect = true):
            // response.json() falliva con un errore di sintassi che non diceva
            // niente sull'accaduto. Meglio un messaggio che si capisce.
            var type = response.headers.get('content-type') || '';
            if (type.indexOf('application/json') === -1) {
                throw new Error('Sessione non valida: ricarica la pagina e riprova.');
            }

            return response.json().then(function (data) {
                // Token ruotato da questa stessa richiesta: serve alla
                // prossima (options -> verify, options -> register).
                if (data && typeof data.csrf === 'string' && data.csrf !== '') {
                    csrfState.token = data.csrf;
                }

                if (!response.ok || !data.ok) {
                    throw new Error((data && data.error) || 'Errore WebAuthn');
                }
                return data;
            });
        });
    }

    function setStatus(el, message, isError) {
        if (!el) { return; }
        el.textContent = message || '';
        el.classList.toggle('is-error', !!isError);
        // Il rosso va tolto quando l'errore sparisce: prima il messaggio di
        // successo restava in colore d'errore per il resto della visita.
        el.classList.toggle('text-danger', !!isError);
    }

    /**
     * Traduce l'errore del browser in una frase utile.
     *
     * Gli errori WebAuthn arrivano in inglese e, per i problemi di
     * configurazione, con messaggi tecnici che non dicono cosa fare (il
     * "The string did not match the expected pattern" di Safari era la
     * challenge passata come stringa invece che come buffer). Qui diventano
     * indicazioni brevi; il nome tecnico resta disponibile per chi depura.
     */
    function describeError(err, fallback) {
        var name = (err && err.name) || '';
        var known = {
            NotAllowedError:   'Operazione annullata o non consentita dal dispositivo.',
            AbortError:        'Operazione annullata.',
            NotSupportedError: 'Questo dispositivo non supporta le passkey.',
            SecurityError:     'Il browser ha rifiutato la richiesta: apri l\'app su HTTPS, oppure su localhost in sviluppo.',
            InvalidStateError: 'Questa passkey risulta gia registrata su questo dispositivo.',
            ConstraintError:   'Il dispositivo non soddisfa i requisiti richiesti (verifica utente).',
            TypeError:         'La richiesta al server e incompleta: ricarica la pagina e riprova.'
        };

        return known[name] || (err && err.message) || fallback;
    }

    function secureContext() {
        // Le passkey esistono solo in un contesto sicuro: HTTPS o localhost.
        // Su http://192.168.x.x il browser nasconde l'API, e il pulsante deve
        // dirlo invece di lasciar premere un comando che non puo funzionare.
        return window.isSecureContext !== false;
    }

    function setBusy(button, busy) {
        // Lo stato "in attesa del dispositivo" è visivo sul solo marchio
        // (pulsazione CSS) e semantico (aria-busy) — il pulsante resta
        // cliccabile per il browser, solo disabilitato dal blocco.
        button.classList.toggle('is-waiting', busy);
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        button.disabled = busy;
    }

    /**
     * base64url -> ArrayBuffer.
     *
     * atob() accetta solo base64 "classico": i due caratteri di base64url
     * ('-' e '_') e la lunghezza senza '=' vanno normalizzati qui, altrimenti
     * Safari rifiuta la stringa con "The string did not match the expected
     * pattern." e Chrome con "InvalidCharacterError".
     */
    function base64ToArrayBuffer(base64) {
        var normalized = String(base64).replace(/-/g, '+').replace(/_/g, '/');

        while (normalized.length % 4 !== 0) {
            normalized += '=';
        }

        var binary = atob(normalized);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * Riporta in binario i campi che il server manda in base64url.
     *
     * navigator.credentials.create()/get() vogliono BufferSource per
     * challenge, user.id e per gli id delle credenziali: passando loro la
     * stringa JSON si ottiene un errore del browser prima ancora che compaia
     * il prompt del dispositivo — era esattamente il caso della pagina
     * Sicurezza, dove la registrazione non partiva mai.
     */
    function preparePublicKey(publicKey) {
        // Copia: l'oggetto arrivato dal server viene riusato da chi chiama
        // (per esempio nel messaggio di errore), non va mutato sul posto.
        var pk = JSON.parse(JSON.stringify(publicKey || {}));

        if (typeof pk.challenge === 'string') {
            pk.challenge = base64ToArrayBuffer(pk.challenge);
        }

        if (pk.user && typeof pk.user.id === 'string') {
            pk.user.id = base64ToArrayBuffer(pk.user.id);
        }

        ['excludeCredentials', 'allowCredentials'].forEach(function (key) {
            if (!Array.isArray(pk[key])) {
                return;
            }

            pk[key].forEach(function (credential) {
                if (credential && typeof credential.id === 'string') {
                    credential.id = base64ToArrayBuffer(credential.id);
                }
            });
        });

        return pk;
    }

    function webauthnSupported() {
        return typeof window.PublicKeyCredential !== 'undefined'
            && typeof navigator.credentials !== 'undefined';
    }

    function credentialToPayload(credential) {
        // rawId e response sono ArrayBuffer: al server vanno in base64url.
        var response = credential.response;
        return {
            credentialId: b64urlEncode(credential.rawId),
            clientDataJSON: b64urlEncode(response.clientDataJSON),
            authenticatorData: response.authenticatorData ? b64urlEncode(response.authenticatorData) : null,
            signature: response.signature ? b64urlEncode(response.signature) : null,
            attestationObject: response.attestationObject ? b64urlEncode(response.attestationObject) : null,
            userHandle: response.userHandle ? b64urlEncode(response.userHandle) : null
        };
    }

    async function runLogin(button) {
        var statusEl = document.getElementById(button.dataset.status);

        setBusy(button, true);
        setStatus(statusEl, button.dataset.busy || 'Attendi il prompt del dispositivo…');

        try {
            var options = await api(ENDPOINTS.login.options);
            var credential = await navigator.credentials.get({ publicKey: preparePublicKey(options.publicKey) });
            var result = await api(ENDPOINTS.login.verify, credentialToPayload(credential));

            setBusy(button, false);
            setStatus(statusEl, '');
            window.location.href = result.redirect;
        } catch (err) {
            setBusy(button, false);
            setStatus(statusEl, describeError(err, 'Accesso con passkey non riuscito.'), true);
        }
    }

    async function runRegister(button) {
        var statusEl = document.getElementById(button.dataset.status);
        var nameInput = document.querySelector(button.dataset.nameInput || '');

        setBusy(button, true);
        setStatus(statusEl, button.dataset.busy || 'Attendi il prompt del dispositivo…');

        try {
            var options = await api(ENDPOINTS.register.options);

            // Resident key + verifica utente sono richiesti dal server: se il
            // dispositivo non li supporta, create() fallisce con NotAllowedError.
            var credential = await navigator.credentials.create({ publicKey: preparePublicKey(options.publicKey) });

            var payload = credentialToPayload(credential);
            payload.passkey_name = nameInput ? nameInput.value : '';

            await api(ENDPOINTS.register.verify, payload);

            setBusy(button, false);
            setStatus(statusEl, '');
            window.location.reload();
        } catch (err) {
            setBusy(button, false);
            if (err.name === 'NotAllowedError' || err.name === 'AbortError') {
                setStatus(statusEl, describeError(err, 'Operazione annullata.'), false);
            } else {
                setStatus(statusEl, describeError(err, 'Registrazione passkey non riuscita.'), true);
            }
        }
    }

    function init() {
        if (!webauthnSupported() || !secureContext()) {
            document.querySelectorAll('[data-webauthn]').forEach(function (button) {
                button.disabled = true;
                var statusEl = document.getElementById(button.dataset.status);
                setStatus(statusEl, secureContext()
                    ? 'Il browser non supporta le passkey.'
                    : 'Le passkey richiedono HTTPS o localhost: apri l\'app su un indirizzo sicuro.', true);
            });
            return;
        }

        // Invio con Enter dal campo "nome": il form non ha action, quindi il
        // browser farebbe un GET sulla stessa pagina e la registrazione in
        // corso si perderebbe con un ricaricamento. Meglio far partire lo
        // stesso comando del pulsante.
        var registerForm = document.getElementById('ip-passkey-register');

        if (registerForm && !registerForm.dataset.ipReady) {
            registerForm.dataset.ipReady = '1';
            registerForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var addButton = document.getElementById('ip-passkey-add');
                if (addButton && !addButton.disabled) {
                    addButton.click();
                }
            });
        }

        document.querySelectorAll('[data-webauthn]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.disabled) { return; }
                var done = function () { setBusy(button, false); };
                var run = button.dataset.webauthn === 'register' ? runRegister : runLogin;
                run(button).then(done, done);
            });
        });

        // data-webauthn-auto: la pagina passkey parte da sola (dopo che si è
        // dipinta) e il pulsante visibile serve solo da "riprova".
        document.querySelectorAll('[data-webauthn][data-webauthn-auto]').forEach(function (button) {
            setTimeout(function () { button.click(); }, 400);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();