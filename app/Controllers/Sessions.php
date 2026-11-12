<?php

namespace App\Controllers;

use App\Libraries\Totp;
use App\Libraries\WebAuthnService;
use App\Models\PasskeyModel;
use App\Models\UserModel;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * Sessions controller
 *
 * Porting CI4 del modulo sessions (CI3): login, logout e reset password.
 * Aggiunge il secondo fattore: se l'utente ha il TOTP attivo, dopo la
 * password si passa da /sessions/mfa prima di ottenere la sessione completa.
 * Le passkey (WebAuthn) sono usabili sia come secondo fattore sia come
 * accesso senza password (discoverable credential + verifica utente).
 */
class Sessions extends BaseController
{
    public function index()
    {
        return redirect()->to('/sessions/login');
    }

    public function login()
    {
        $request = service('request');

        // Installazione senza utenti: la prima cosa da fare e' creare
        // l'account, non provare ad accedere con credenziali inesistenti.
        if ((new UserModel())->countAllResults() === 0) {
            return redirect()->to('/sessions/setup');
        }

        if ($request->getMethod() === 'POST' && $request->getPost('btn_login')) {
            $email    = $request->getPost('email');
            $password = $request->getPost('password');

            // Freno sui tentativi falliti. Il secondo fattore aveva gia' un
            // tetto di 5 tentativi, il primo no: si poteva provare password a
            // ripetizione senza alcun costo. Il conteggio sale solo sui
            // fallimenti e si azzera all'accesso riuscito, quindi chi sbaglia
            // una volta e poi entra non si porta dietro niente.
            if ($this->loginBlocked($email)) {
                session()->setFlashdata('alert_error', trans('login_too_many_attempts', '',
                    'Troppi tentativi falliti. Riprova fra qualche minuto.'));

                return redirect()->to('/sessions/login');
            }

            $userModel = new UserModel();
            $user      = $userModel->findByEmail($email);

            // Un solo messaggio per tutti e tre i casi: prima "email
            // inesistente", "utente disattivato" e "password errata" erano
            // distinguibili, quindi bastava guardare quale avviso compariva
            // per sapere se un indirizzo e' registrato -- e per scoprire quali
            // account esistono senza indovinare nemmeno una password.
            if (!$user
                || !db_bool($user['user_active'])
                || !$this->authenticate($userModel, $user, $password)) {
                $this->registerLoginFailure($email);
                session()->setFlashdata('alert_error', trans('loginalert_credentials_incorrect'));

                return redirect()->to('/sessions/login');
            }

            $this->clearLoginFailures($email);

            // Secondo fattore: non si entra finché non si verifica. La
            // sessione resta "in attesa" (niente user_type), quindi i filtri
            // auth/guest respingono comunque verso il login.
            if (db_bool($user['user_mfa_enabled'])) {
                $this->setPendingSession($user);

                return redirect()->to('/sessions/mfa');
            }

            $this->completeLogin($user);

            return $this->loginRedirect($user);
        }

        // Chi torna al login abbandona l'eventuale verifica in corso.
        session()->remove($this->pendingKeys());

        return view('sessions/session_login');
    }

    /**
     * Primo avvio: creazione del primo utente amministratore.
     *
     * Il login presuppone un utente gia' esistente, ma su un'installazione
     * appena migrata il database e' vuoto: senza questa pagina l'applicazione
     * non ha alcun modo di farsi aprire. Il form chiede solo nome, email e
     * password; tipo (amministratore) e lingua sono fissati dal codice,
     * perche' il primo utente lo sono per definizione.
     *
     * La pagina esiste soltanto finché il database non ha utenti: con almeno
     * uno, chiunque potrebbe altrimenti crearsi un account admin da qui.
     */
    public function setup()
    {
        $request   = service('request');
        $userModel = new UserModel();

        if ($userModel->countAllResults() > 0) {
            return redirect()->to('/sessions/login');
        }

        if ($request->getMethod() === 'POST' && $request->getPost('btn_setup')) {
            $post = $request->getPost();

            $post['user_type']     = 1;
            $post['user_active']   = true;
            $post['user_language'] = 'system';

            $validation = service('validation');
            $validation->setRules($userModel->rulesForCreate(), $userModel->messagesForCreate());

            if ($validation->run($post)) {
                // Passa da saveRecord() cosi' salt e hash bcrypt coincidono
                // con quelli del form Utenti: la password scelta qui funziona
                // ovunque, incluso l'eventuale secondo fattore attivato dopo.
                $dbArray                  = $userModel->dbArrayFromPost($post);
                $dbArray['user_type']     = 1;
                $dbArray['user_active']   = true;
                $dbArray['user_language'] = 'system';

                $userModel->saveRecord(null, $dbArray);

                $created = $userModel->findByEmail($post['user_email']);

                if (!$created) {
                    // Non dovrebbe mai capitare: se il salvataggio e' andato
                    // storto meglio tornare al form che lasciare una sessione
                    // mezza aperta.
                    session()->setFlashdata('alert_error',
                        trans('setup_user_failed', '', 'Creazione account non riuscita, riprova.'));

                    return redirect()->to('/sessions/setup');
                }

                // Primo avvio in un passo solo: l'account appena creato entra
                // subito, senza ripassare dal login. La destinazione non e' la
                // dashboard ma i dati dell'azienda -- quella precompilata dalla
                // migrazione col solo nome di ripiego "Azienda" va completata
                // prima che le fatture abbiano un emittente sensato.
                $this->completeLogin($created);

                $company = (new \App\Models\CompanyModel())
                    ->orderBy('company_id', 'ASC')->first();

                session()->setFlashdata('alert_success',
                    trans('setup_user_created', '', 'Account creato. Completa ora i dati della tua azienda.'));

                return redirect()->to($company
                    ? '/companies/form/' . (int) $company['company_id']
                    : '/companies/form');
            }

            return view('sessions/session_setup', [
                'errors' => $validation->getErrors(),
                'values' => $post,
            ]);
        }

        return view('sessions/session_setup');
    }

    /**
     * Passo di verifica del secondo fattore: selezione metodo MFA o verifica diretta.
     *
     * Flusso:
     * 1. Se non c'è mfa_method, mostra la selezione dei metodi
     * 2. Se mfa_method=passkey, mostra il form per l'autenticazione passkey
     * 3. Se mfa_method=totp (o nullo con solo TOTP abilitato), mostra l'input TOTP
     * 4. Se mfa_method=recovery, mostra l'input per i codici di recupero
     */
    public function mfa()
    {
        $request = service('request');

        if (!$this->pendingUser() || !session('pending_user_id')) {
            return redirect()->to('/sessions/login');
        }

        // Annulla l'accesso in corso e torna al login
        if ($request->getMethod() === 'POST' && $request->getPost('btn_mfa_cancel')) {
            session()->remove($this->pendingKeys());
            return redirect()->to('/sessions/login');
        }

        $mfaMethod = $request->getGet('method') ?? $request->getPost('mfa_method');
        $user = (new UserModel())->find((int) session('pending_user_id'));

        if (!$user || !db_bool($user['user_mfa_enabled'])) {
            return $this->resetPendingToLogin();
        }

        // --- VERIFICA TOTP ---
        if ($mfaMethod === 'totp' && $request->getMethod() === 'POST' && $request->getPost('btn_mfa')) {
            if (session('mfa_attempts') >= 5) {
                session()->setFlashdata('alert_error', trans('mfa_too_many_attempts'));
                return $this->resetPendingToLogin();
            }

            $code   = trim((string) $request->getPost('mfa_code'));
            $result = $this->verifyMfaCode($user, $code);

            if ($result['ok']) {
                $this->completeLogin($user);
                session()->remove($this->pendingKeys());
                return $this->loginRedirect($user);
            }

            // Un codice già usato o un orologio disallineato non sono
            // tentativi sbagliati: contarli porterebbe al blocco proprio chi
            // ha il codice giusto. Sale solo il contatore dei codici davvero
            // errati.
            if ($result['reason'] === 'invalid') {
                session()->set('mfa_attempts', (int) session('mfa_attempts') + 1);
            }

            session()->setFlashdata('alert_error', $this->mfaErrorMessage($result));
            return redirect()->to('/sessions/mfa?method=totp');
        }

        // --- VERIFICA RECOVERY CODE ---
        if ($mfaMethod === 'recovery' && $request->getMethod() === 'POST') {
            if (session('mfa_attempts') >= 5) {
                session()->setFlashdata('alert_error', trans('mfa_too_many_attempts'));
                return $this->resetPendingToLogin();
            }

            $code   = trim((string) $request->getPost('recovery_code'));
            $result = $this->verifyMfaCode($user, $code);

            if ($result['ok']) {
                $this->completeLogin($user);
                session()->remove($this->pendingKeys());
                return $this->loginRedirect($user);
            }

            if ($result['reason'] === 'invalid') {
                session()->set('mfa_attempts', (int) session('mfa_attempts') + 1);
            }

            session()->setFlashdata('alert_error', $this->mfaErrorMessage($result));
            return redirect()->to('/sessions/mfa?method=recovery');
        }

        // --- SELEZIONE METODO MFA ---
        $hasPasskeys = (new PasskeyModel())->countForUser((int) session('pending_user_id')) > 0;

        // Se nessun metodo è stato scelto, mostra la selezione
        if (!$mfaMethod) {
            return view('sessions/session_mfa_selection', [
                'user_email'   => (string) session('pending_user_email'),
                'has_passkeys' => $hasPasskeys,
            ]);
        }

        // --- VISUALIZZA METODO SCELTO ---
        if ($mfaMethod === 'passkey') {
            return view('sessions/session_passkey_auth', [
                'user_email' => (string) session('pending_user_email'),
            ]);
        }

        if ($mfaMethod === 'totp') {
            return view('sessions/session_mfa', [
                'user_email'   => (string) session('pending_user_email'),
                'has_passkeys' => $hasPasskeys,
                'attempts_left' => 5 - (int) session('mfa_attempts'),
            ]);
        }

        if ($mfaMethod === 'recovery') {
            return view('sessions/session_recovery_code', [
                'user_email' => (string) session('pending_user_email'),
            ]);
        }

        // Metodo non riconosciuto: torna alla selezione
        return redirect()->to('/sessions/mfa');
    }

    // ---------------------------------------------------------------------
    // Passkey: opzioni e verifica (accesso senza password o secondo fattore)
    // ---------------------------------------------------------------------

    /** Opzioni per navigator.credentials.get. */
    public function passkeyOptions()
    {
        if (service('request')->getMethod() !== 'POST') {
            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_failed')], 400);
        }

        // Con una sessione in attesa (login via password già verificata) la
        // scelta della passkey è ristretta a quelle dell'utente; senza, è una
        // discoverable credential (il dispositivo decide chi è).
        $allowed = [];
        $pendingUserId = session('pending_user_id');

        if ($pendingUserId) {
            foreach ((new PasskeyModel())->forUser((int) $pendingUserId) as $passkey) {
                $allowed[] = base64_decode($passkey['passkey_credential_id']);
            }
        }

        try {
            $wa   = WebAuthnService::instance();
            $args = $wa->getGetArgs($allowed, 60, true, true, true, true, true, true);

            // La challenge la genera la libreria dentro getGetArgs: è quella
            // che va confrontata in processGet, quindi si salva in sessione
            // la SUA, non una nostra (come binario, non ByteBuffer: la
            // serializzazione della sessione non deve dipendere dalle classi).
            session()->set('webauthn_challenge', $wa->getChallenge()->getBinaryString());

            return $this->json(['ok' => true, 'publicKey' => $args->publicKey]);
        } catch (WebAuthnException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** Verifica l'assertion e completa l'accesso. */
    public function passkeyVerify()
    {
        if (service('request')->getMethod() !== 'POST') {
            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_failed')], 400);
        }

        $challenge = session('webauthn_challenge');

        if (!$challenge) {
            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_failed')], 400);
        }

        // Il corpo è JSON (webauthn.js): getPost() legge solo i form
        // urlencoded, quindi qui si parte dal payload vero.
        $payload = $this->payload();

        $credentialId      = $this->decodeBase64((string) ($payload['credentialId'] ?? ''));
        $clientDataJSON    = $this->decodeBase64((string) ($payload['clientDataJSON'] ?? ''));
        $authenticatorData = $this->decodeBase64((string) ($payload['authenticatorData'] ?? ''));
        $signature         = $this->decodeBase64((string) ($payload['signature'] ?? ''));

        if ($credentialId === null || $clientDataJSON === null || $authenticatorData === null || $signature === null) {
            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_failed')], 400);
        }

        $passkey = (new PasskeyModel())->findByCredentialId(base64_encode($credentialId));

        if (!$passkey) {
            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_unknown')], 400);
        }

        // La passkey deve appartenere all'utente del passo di verifica, se c'è
        // una sessione in attesa; altrimenti è l'utente della credenziale.
        $pendingUserId = session('pending_user_id');
        if ($pendingUserId && (int) $pendingUserId !== (int) $passkey['user_id']) {
            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_unknown')], 400);
        }

        $user = (new UserModel())->find((int) $passkey['user_id']);

        if (!$user || !db_bool($user['user_active'])) {
            return $this->json(['ok' => false, 'error' => trans('loginalert_user_inactive')], 400);
        }

        try {
            $wa = WebAuthnService::instance();
            $ok = $wa->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $passkey['passkey_public_key'],
                $challenge,
                (int) $passkey['passkey_counter'],
                true, // richiedi verifica utente: la passkey è un secondo fattore a pieno titolo
                true
            );

            if (!$ok) {
                return $this->json(['ok' => false, 'error' => trans('mfa_passkey_failed')], 400);
            }

            // Il contatore aggiornato va preso dalla STESSA istanza che ha
            // eseguito la verifica (processGet lo salva su di sé).
            // null per le passkey sincronizzate (iCloud, Google), che non usano
            // il contatore: si salva 0, come in registrazione.
            $newCounter = (int) ($wa->getSignatureCounter() ?? 0);
        } catch (WebAuthnException $e) {
            // Errore sul contatore anti-clonazione: la chiave è probabilmente
            // stata duplicata, meglio non insistere.
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        (new PasskeyModel())->update($passkey['passkey_id'], [
            'passkey_counter'      => $newCounter,
            'passkey_last_used_at' => date('Y-m-d H:i:s'),
        ]);

        session()->remove('webauthn_challenge');
        session()->remove($this->pendingKeys());

        $this->completeLogin($user);

        return $this->json(['ok' => true, 'redirect' => $this->loginUrl($user)]);
    }

    /**
     * Corpo della richiesta come array, comunque sia stato inviato.
     *
     * Le chiamate WebAuthn arrivano in JSON: con Content-Type
     * application/json PHP non popola $_POST, quindi getPost() tornava vuoto
     * e l'accesso con passkey rispondeva "passkey non riuscita" senza mai
     * verificare la firma. Il JSON si legge solo quando il form non c'è, così
     * i POST normali (login, MFA) restano identici.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $request = service('request');
        $post    = $request->getPost();
        $post    = is_array($post) ? $post : [];

        if ($post !== []) {
            return $post;
        }

        if (!str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
            return $post;
        }

        try {
            $json = $request->getJSON(true);
        } catch (\Throwable) {
            $json = null;
        }

        return is_array($json) ? $json : [];
    }

    private function authenticate(UserModel $userModel, array $user, string $password): bool
    {
        // Migrazione dal vecchio hash md5 (pre-1.2.0) come in CI3
        if (empty($user['user_psalt'])) {
            if (md5($password) !== $user['user_password']) {
                return false;
            }

            $salt  = $userModel->salt();
            $hash  = $userModel->generatePassword($password, $salt);
            $userModel->update($user['user_id'], [
                'user_psalt'    => $salt,
                'user_password' => $hash,
            ]);
            $user['user_psalt']    = $salt;
            $user['user_password'] = $hash;
        }

        return $userModel->checkPassword($user['user_password'], $password);
    }

    /**
     * Verifica TOTP o codice di recupero.
     *
     * Restituisce anche il motivo del rifiuto, così il login può distinguere
     * un codice sbagliato da uno corretto ma già usato o fuori dalla finestra
     * di tolleranza: solo il primo deve consumare un tentativo.
     *
     * @return array{ok: bool, reason: string, offset: int}
     */
    private function verifyMfaCode(array $user, string $code): array
    {
        if ($code === '') {
            return ['ok' => false, 'reason' => 'invalid', 'offset' => 0];
        }

        $userModel = new UserModel();

        $secret = $userModel->mfaSecret($user);

        if ($secret !== '') {
            $counter = Totp::counterFor($secret, $code, Totp::VERIFY_WINDOW, null);

            if ($counter !== null) {
                $last = $user['user_mfa_last_counter'];

                // Anti-replay: lo stesso codice non vale due volte. Capita
                // anche a chi ha appena confermato il secondo fattore e prova
                // subito ad accedere; il messaggio lo dice, invece di
                // sembrare un codice sbagliato.
                if ($last !== null && $counter <= (int) $last) {
                    return ['ok' => false, 'reason' => 'replay', 'offset' => 0];
                }

                $userModel->updateMfaFields((int) $user['user_id'], ['user_mfa_last_counter' => $counter]);

                return ['ok' => true, 'reason' => 'ok', 'offset' => 0];
            }

            // Il codice non entra nella finestra: se però corrisponde a un
            // passo vicino, non è un codice sbagliato ma un orologio
            // disallineato. Si registra lo scarto, mai il codice.
            $offset = Totp::nearestOffset($secret, $code, 10);

            if ($offset !== null) {
                log_message('warning', 'MFA utente {userId}: codice corretto ma fuori finestra di {secondi} secondi', [
                    'userId'   => (int) $user['user_id'],
                    'secondi'  => $offset * Totp::PERIOD,
                ]);

                return ['ok' => false, 'reason' => 'skew', 'offset' => $offset];
            }
        }

        // Codice di recupero: monouso, va consumato subito dopo la verifica.
        $newJson = Totp::consumeBackupCode($code, $user['user_mfa_backup_codes']);

        if ($newJson !== null) {
            $userModel->updateMfaFields((int) $user['user_id'], ['user_mfa_backup_codes' => $newJson]);

            return ['ok' => true, 'reason' => 'ok', 'offset' => 0];
        }

        return ['ok' => false, 'reason' => 'invalid', 'offset' => 0];
    }

    /** Messaggio da mostrare per un rifiuto del secondo fattore. */
    private function mfaErrorMessage(array $result): string
    {
        return match ($result['reason']) {
            'replay' => trans('mfa_code_reused', '', 'Questo codice è già stato usato. Attendi che l\'app ne mostri uno nuovo e riprova.'),
            'skew'   => trans('mfa_clock_skew', (string) abs($result['offset'] * Totp::PERIOD),
                'Il codice è corretto ma l\'orologio del dispositivo sembra disallineato di circa %s secondi. Attiva data e ora automatiche e riprova.'),
            default  => trans('mfa_invalid_code'),
        };
    }

    /** Chiave del contatore tentativi: indirizzo IP + email tentata. */
    private function loginThrottleKey(string $email): string
    {
        return 'login_fail_' . md5(strtolower(trim($email)) . '|' . service('request')->getIPAddress());
    }

    /**
     * Troppi tentativi falliti da questo IP per questa email?
     *
     * La soglia e' volutamente larga (10 in un quarto d'ora): deve fermare chi
     * prova password a raffica, non chi ha sbagliato a digitare tre volte di
     * fila. La chiave comprende l'IP, cosi' un attaccante non puo' bloccare
     * l'account di qualcun altro semplicemente sbagliando apposta.
     */
    private function loginBlocked(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return (int) (cache()->get($this->loginThrottleKey($email)) ?? 0) >= 10;
    }

    private function registerLoginFailure(string $email): void
    {
        if ($email === '') {
            return;
        }

        $key   = $this->loginThrottleKey($email);
        $count = (int) (cache()->get($key) ?? 0) + 1;

        cache()->save($key, $count, 900);
    }

    private function clearLoginFailures(string $email): void
    {
        if ($email !== '') {
            cache()->delete($this->loginThrottleKey($email));
        }
    }

    private function setPendingSession(array $user): void
    {
        // Chi arriva qui ha appena superato il primo fattore ma non è ancora
        // dentro: le chiavi di sessione complete (user_type, user_id, …) vanno
        // rimosse, altrimenti il filtro auth/guest lascerebbe passare con
        // quelle della sessione precedente senza aver verificato il secondo
        // fattore.
        session()->remove($this->sessionUserKeys());

        // Anche questa e' una transizione di privilegio: l'id di sessione
        // cambia qui e poi di nuovo in completeLogin().
        session()->regenerate();

        session()->set([
            'pending_user_id'       => $user['user_id'],
            'pending_user_type'     => $user['user_type'],
            'pending_user_name'     => $user['user_name'],
            'pending_user_email'    => $user['user_email'],
            'pending_user_company'  => $user['user_company'],
            'pending_user_language' => $user['user_language'] ?? 'system',
            'mfa_attempts'          => 0,
        ]);
    }

    private function sessionUserKeys(): array
    {
        return [
            'user_type',
            'user_id',
            'user_name',
            'user_email',
            'user_company',
            'user_language',
        ];
    }

    private function pendingKeys(): array
    {
        return [
            'pending_user_id',
            'pending_user_type',
            'pending_user_name',
            'pending_user_email',
            'pending_user_company',
            'pending_user_language',
            'mfa_attempts',
        ];
    }

    private function pendingUser(): bool
    {
        return session('pending_user_id') !== null;
    }

    private function resetPendingToLogin()
    {
        session()->remove($this->pendingKeys());

        return redirect()->to('/sessions/login');
    }

    private function completeLogin(array $user): void
    {
        // Nuovo id di sessione al passaggio di privilegio: senza, un id noto
        // prima dell'accesso resta valido dopo, e chi lo conosce si ritrova
        // dentro come l'utente appena autenticato. Il ricambio periodico di
        // CI4 non basta, non e' legato all'evento di autenticazione.
        session()->regenerate();

        session()->set([
            'user_type'     => $user['user_type'],
            'user_id'       => $user['user_id'],
            'user_name'     => $user['user_name'],
            'user_email'    => $user['user_email'],
            'user_company'  => $user['user_company'],
            'user_language' => $user['user_language'] ?? 'system',
        ]);
    }

    private function loginRedirect(array $user)
    {
        return redirect()->to($this->loginUrl($user));
    }

    private function loginUrl(array $user): string
    {
        return (int) $user['user_type'] === 1 ? '/dashboard' : '/guest';
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('/sessions/login');
    }

    public function passwordreset(?string $token = null)
    {
        $request = service('request');

        // Token presente: mostra il form per la nuova password
        if ($token) {
            $userModel = new UserModel();
            $user      = $userModel->where('user_passwordreset_token', $token)->first();

            if (!$user) {
                session()->setFlashdata('alert_error', trans('wrong_passwordreset_token'));
                return redirect()->to('/sessions/passwordreset');
            }

            return view('sessions/session_new_password', [
                'token'   => $token,
                'user_id' => $user['user_id'],
            ]);
        }

        // Invio della nuova password
        if ($request->getMethod() === 'POST' && $request->getPost('btn_new_password')) {
            $newPassword = $request->getPost('new_password');
            $userId      = (int) $request->getPost('user_id');

            if (!$userId || empty($newPassword)) {
                session()->setFlashdata('alert_error', trans('loginalert_no_password'));
                return redirect()->to('/sessions/passwordreset');
            }

            $userModel = new UserModel();
            $user      = $userModel->find($userId);

            if (!$user) {
                session()->setFlashdata('alert_error', trans('loginalert_user_not_found'));
                return redirect()->to('/sessions/passwordreset');
            }

            // hash_equals(): confronto a tempo costante, come per gli altri
            // segreti del progetto.
            // Scadenza mancante = scaduto: i token emessi prima che la colonna
            // esistesse sono proprio quelli senza scadenza, e devono smettere
            // di funzionare.
            $expires = (string) ($user['user_passwordreset_token_expires'] ?? '');

            if (empty($user['user_passwordreset_token'])
                || $expires === ''
                || strtotime($expires) < time()
                || !hash_equals((string) $user['user_passwordreset_token'], (string) $request->getPost('token'))) {
                session()->setFlashdata('alert_error', trans('loginalert_wrong_auth_code'));
                return redirect()->to('/sessions/passwordreset');
            }

            $userModel->saveChangePassword($userId, $newPassword);
            $userModel->update($userId, [
                'user_passwordreset_token'         => '',
                'user_passwordreset_token_expires' => null,
            ]);

            return redirect()->to('/sessions/login');
        }

        // Richiesta di reset (invio email con link)
        if ($request->getMethod() === 'POST' && $request->getPost('btn_reset')) {
            $email = $request->getPost('email');

            if (empty($email)) {
                session()->setFlashdata('alert_error', trans('loginalert_user_not_found'));
                return redirect()->to('/sessions/passwordreset');
            }

            $userModel = new UserModel();
            $user      = $userModel->findByEmail($email);

            if ($user) {
                // md5(time() . $email) era indovinabile: time() ha risoluzione
                // al secondo e l'email e' proprio quella appena digitata, quindi
                // in una finestra di pochi secondi i candidati da provare erano
                // una manciata, non i 128 bit nominali dell'hash.
                $token = bin2hex(random_bytes(32));
                $userModel->update($user['user_id'], [
                    'user_passwordreset_token'         => $token,
                    // Un'ora: il tempo che serve a chi il reset lo ha chiesto
                    // davvero. Senza scadenza un link finito in un backup o in
                    // un log di posta restava valido mesi dopo.
                    'user_passwordreset_token_expires' => date('Y-m-d H:i:s', time() + 3600),
                ]);

                $resetLink  = site_url('sessions/passwordreset/' . $token);
                $message    = view('emails/passwordreset', ['resetlink' => $resetLink]);

                $from = system_mail_from();
                if (empty($from)) {
                    $from = 'system@' . parse_url(base_url(), PHP_URL_HOST);
                }

                $this->sendEmail($from, $email, trans('email_template_tags_reset_password') ?: 'Password reset', $message);
            }

            session()->setFlashdata('alert_success', trans('email_sent'));
            return redirect()->to('/sessions/login');
        }

        return view('sessions/session_passwordreset');
    }

    private function sendEmail(string $from, string $to, string $subject, string $message): bool
    {
        $email = \Config\Services::email(null, false);

        $email->initialize(build_mail_config(system_email_sender()));
        $email->setFrom($from);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        return $email->send();
    }

    /**
     * Risposta JSON degli endpoint passkey.
     *
     * Il token CSRF viaggia anche nel corpo, non solo nel cookie: il cookie
     * csrf_cookie_name e' HttpOnly (il framework lo crea con il default di
     * Config\Cookie), quindi webauthn.js non puo' leggerlo, e con
     * Security::$regenerate = true ogni POST valido lo ruota comunque. Il
     * login passkey fa due POST di seguito (options e verify): il secondo deve
     * usare il token generato dal primo, e questa e' l'unica via per
     * consegnarglielo. Senza, ogni chiamata partiva senza header, il filtro
     * rimandava alla pagina precedente e il JS riceveva HTML.
     */
    private function json(array $data, int $status = 200)
    {
        $data['csrf'] = csrf_hash();

        return $this->response->setStatusCode($status)->setJSON($data);
    }

    private function decodeBase64(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}