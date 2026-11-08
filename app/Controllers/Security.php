<?php

namespace App\Controllers;

use App\Libraries\QrCodeHelper;
use App\Libraries\Totp;
use App\Libraries\WebAuthnService;
use App\Models\PasskeyModel;
use App\Models\UserModel;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * Sicurezza account: MFA (TOTP) e passkey (WebAuthn).
 *
 * Tutto è per-utente, quindi niente sezioni nelle impostazioni globali: la
 * pagina è raggiungibile dal menu utente. Le operazioni sensibili richiedono
 * riprova: attivare/disattivare il TOTP chiede un codice valido, e il
 * segreto in attesa di conferma vive in sessione (un setup a metà non deve
 * mai bloccare il login, perché user_mfa_enabled resta 0 finché il primo
 * codice non viene verificato).
 */
class Security extends BaseController
{
    public function index()
    {
        $data = self::panelData((int) session('user_id'));

        if ($data === null) {
            session()->setFlashdata('alert_error', trans('loginalert_user_not_found'));

            return redirect()->to('/dashboard');
        }

        return view('security/index', $data);
    }

    /**
     * Dati del pannello security/panel. Sempre per l'utente collegato: il
     * pannello registra passkey e segreti sul dispositivo di chi lo usa.
     * Condiviso con il profilo (Users::form), che ne mostra lo stato in una
     * scheda riassuntiva con il rimando a questa pagina.
     */
    public static function panelData(int $userId): ?array
    {
        $user = (new UserModel())->find($userId);

        if (!$user) {
            return null;
        }

        $mfaEnabled = db_bool($user['user_mfa_enabled']);

        $data = [
            'user'          => $user,
            'mfa_enabled'   => $mfaEnabled,
            'passkeys'      => (new PasskeyModel())->forUser($userId),
            // Setup in corso (segreto generato ma non ancora confermato)
            'totp_pending'  => session('totp_pending_secret') ? (string) session('totp_pending_secret') : null,
            'totp_uri'      => null,
            'totp_qr'       => null,
            // Codici di recupero appena generati, da mostrare una volta sola
            'backup_codes'  => session('totp_backup_codes') ? (array) session('totp_backup_codes') : null,
            'backup_left'   => 0,
        ];

        if ($data['totp_pending']) {
            $issuer = get_setting('custom_title', '', true) ?: 'InvoicePlane';
            $uri    = Totp::uri($data['totp_pending'], $issuer, $user['user_email']);

            $data['totp_uri'] = $uri;
            $data['totp_qr']  = QrCodeHelper::svg($uri);
        }

        if ($mfaEnabled) {
            $hashes = json_decode((string) $user['user_mfa_backup_codes'], true);

            $data['backup_left'] = is_array($hashes) ? count($hashes) : 0;
        }

        return $data;
    }

    // ---------------------------------------------------------------------
    // MFA (TOTP)
    // ---------------------------------------------------------------------

    /** Genera il segreto e mostra QR + form di conferma. */
    public function totpSetup()
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        $userId = (int) session('user_id');
        $user   = (new UserModel())->find($userId);

        if (!$user || db_bool($user['user_mfa_enabled'])) {
            return $this->redirectIndex();
        }

        $secret = Totp::generateSecret();
        session()->set('totp_pending_secret', $secret);
        session()->remove('totp_backup_codes');

        return $this->redirectIndex();
    }

    /** Conferma il setup verificando il primo codice TOTP. */
    public function totpConfirm()
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        $code = (string) service('request')->getPost('mfa_code');

        $userId = (int) session('user_id');
        $secret = session('totp_pending_secret');

        if ($secret === null || $code === '') {
            return $this->redirectIndex();
        }

        $counter = Totp::counterFor($secret, $code);

        if ($counter === null) {
            session()->setFlashdata('alert_error', trans('mfa_invalid_code'));

            return $this->redirectIndex();
        }

        $codes = Totp::generateBackupCodes();

        (new UserModel())->updateMfaFields($userId, [
            'user_mfa_secret'        => $secret,
            'user_mfa_enabled'       => true,
            'user_mfa_backup_codes'  => json_encode(Totp::hashBackupCodes($codes)),
            'user_mfa_last_counter'  => $counter,
        ]);

        session()->remove('totp_pending_secret');
        session()->set('totp_backup_codes', $codes);

        session()->setFlashdata('alert_success', trans('mfa_enabled_ok'));

        return $this->redirectIndex();
    }

    /** Annulla un setup in corso. */
    public function totpCancel()
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        session()->remove('totp_pending_secret');

        return $this->redirectIndex();
    }

    /** Ho salvato i codici di recupero: via la notifica. */
    public function backupAck()
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        session()->remove('totp_backup_codes');

        return $this->redirectIndex();
    }

    /**
     * Disattiva il TOTP: richiede un codice valido (TOTP o di recupero)
     * perché chi ha rubato la sessione non deve poterlo spegnere.
     */
    public function totpDisable()
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        $userId = (int) session('user_id');
        $user   = (new UserModel())->find($userId);

        if (!$user || !db_bool($user['user_mfa_enabled'])) {
            return $this->redirectIndex();
        }

        $code  = trim((string) service('request')->getPost('mfa_code'));
        $valid = false;

        if ($code !== '') {
            $secret = (new UserModel())->mfaSecret($user);

            if ($secret !== '' && Totp::verify($secret, $code, 2, $user['user_mfa_last_counter'])) {
                $valid = true;
            } elseif ($newJson = Totp::consumeBackupCode($code, $user['user_mfa_backup_codes'])) {
                (new UserModel())->updateMfaFields($userId, ['user_mfa_backup_codes' => $newJson]);
                $valid = true;
            }
        }

        if (!$valid) {
            session()->setFlashdata('alert_error', trans('mfa_invalid_code'));

            return $this->redirectIndex();
        }

        (new UserModel())->updateMfaFields($userId, [
            'user_mfa_secret'       => null,
            'user_mfa_enabled'      => false,
            'user_mfa_backup_codes' => null,
            'user_mfa_last_counter' => null,
        ]);

        session()->setFlashdata('alert_success', trans('mfa_disabled_ok'));

        return $this->redirectIndex();
    }

    // ---------------------------------------------------------------------
    // Passkey
    // ---------------------------------------------------------------------

    /** Opzioni per navigator.credentials.create (registrazione). */
    public function passkeyOptions()
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        $userId = (int) session('user_id');
        $user   = (new UserModel())->find($userId);

        if (!$user) {
            return $this->json(['ok' => false, 'error' => trans('loginalert_user_not_found')], 400);
        }

        // Id delle credenziali già registrate: impedisce di duplicare la
        // stessa passkey sullo stesso dispositivo.
        $existing = [];
        foreach ((new PasskeyModel())->forUser($userId) as $passkey) {
            $existing[] = base64_decode($passkey['passkey_credential_id']);
        }

        try {
            $wa   = WebAuthnService::instance();
            $args = $wa->getCreateArgs(
                (string) $userId,
                $user['user_email'],
                $user['user_name'] ?: $user['user_email'],
                60,
                true,   // resident key: login passkey senza username
                true,   // richiedi verifica utente (biometria/PIN)
                null,
                $existing
            );

            // La challenge la genera la libreria dentro getCreateArgs: è
            // quella che processCreate si aspetta nella verifica (come
            // binario, non ByteBuffer: vedi Sessions::passkeyOptions).
            session()->set('webauthn_challenge', $wa->getChallenge()->getBinaryString());

            return $this->json(['ok' => true, 'publicKey' => $args->publicKey]);
        } catch (WebAuthnException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** Verifica l'attestazione e salva la credenziale. */
    public function passkeyRegister()
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        $userId = (int) session('user_id');
        $user   = (new UserModel())->find($userId);

        if (!$user) {
            return $this->json(['ok' => false, 'error' => trans('loginalert_user_not_found')], 400);
        }

        // Il corpo arriva in JSON (vedi payload()): l'attestazione è un
        // oggetto, e un form urlencoded non la trasporta.
        $payload = $this->payload();

        $challenge      = session('webauthn_challenge');
        $clientDataJSON = $this->decodeBase64Value($payload['clientDataJSON'] ?? null);
        $attestation    = $this->decodeBase64Value($payload['attestationObject'] ?? null);

        if (!$challenge || $clientDataJSON === null || $attestation === null) {
            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_failed')], 400);
        }

        try {
            $data = WebAuthnService::instance()->processCreate($clientDataJSON, $attestation, $challenge, true);

            $credentialId = base64_encode($data->credentialId);
            $name         = trim((string) ($payload['passkey_name'] ?? ''));

            if ($name === '') {
                $name = $user['user_name'] ?: $user['user_email'];
            }

            $passkeyId = (new PasskeyModel())->saveCredential(
                $userId,
                $name,
                $credentialId,
                $data->credentialPublicKey,
                // Le passkey sincronizzate (portachiavi iCloud, Google Password
                // Manager) non usano il contatore: vale sempre 0, e la libreria
                // lo restituisce come null. Passato cosi' a saveCredential(int)
                // faceva fallire ogni registrazione da Safari con un TypeError.
                (int) ($data->signatureCounter ?? 0)
            );

            session()->remove('webauthn_challenge');

            return $this->json(['ok' => true, 'id' => $passkeyId]);
        } catch (WebAuthnException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // Senza questo ramo un errore imprevisto diventava un 500 HTML, e
            // la pagina mostrava solo "Errore WebAuthn" senza dire cosa.
            log_message('error', 'Registrazione passkey fallita: ' . $e->getMessage());

            return $this->json(['ok' => false, 'error' => trans('mfa_passkey_failed')], 500);
        }
    }

    public function passkeyDelete(int $id)
    {
        if (!$this->isPost()) {
            return $this->redirectIndex();
        }

        $passkey = (new PasskeyModel())->find($id);

        if ($passkey && (int) $passkey['user_id'] === (int) session('user_id')) {
            (new PasskeyModel())->delete($id);
            session()->setFlashdata('alert_success', trans('mfa_passkey_deleted'));
        }

        return $this->redirectIndex();
    }

    // ---------------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------------

    private function isPost(): bool
    {
        return service('request')->getMethod() === 'POST';
    }

    private function redirectIndex()
    {
        return redirect()->to('/security');
    }

    /**
     * Risposta JSON degli endpoint passkey (opzioni e registrazione).
     *
     * Il token CSRF viaggia anche nel corpo: il cookie csrf_cookie_name e'
     * HttpOnly, quindi webauthn.js non puo' leggerlo, e con
     * Security::$regenerate = true ogni POST valido lo ruota. Le due
     * chiamate consecutive (options, register) devono usare ciascuna il token
     * valido in quel momento, quindi il server glielo rimanda qui.
     */
    private function json(array $data, int $status = 200)
    {
        $data['csrf'] = csrf_hash();

        return $this->response->setStatusCode($status)->setJSON($data);
    }

    /**
     * Corpo della richiesta come array, comunque sia stato inviato.
     *
     * webauthn.js manda JSON: con Content-Type application/json PHP non
     * popola $_POST, quindi getPost() tornava vuoto e la registrazione
     * falliva con "passkey non riuscita" senza mai arrivare alla verifica.
     * Il JSON si legge solo quando il form non c'è, così i POST normali
     * (form di MFA, conferme) restano identici.
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

    /** Decodifica un valore base64url in binario (null se mancante o invalido). */
    private function decodeBase64Value($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}