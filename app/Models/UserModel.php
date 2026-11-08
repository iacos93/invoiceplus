<?php

namespace App\Models;

/**
 * UserModel
 *
 * Porting CI4 di Mdl_Users (CI3): gestione utenti con hash bcrypt
 * compatibile con la libreria Crypt del CI3 (user_psalt + user_password).
 */
class UserModel extends IpBaseModel
{
    protected $table            = 'ip_users';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'user_name',
        'user_email',
        'user_password',
        'user_psalt',
        'user_company',
        'user_type',
        'user_active',
        'user_language',
        'user_iban',
        'user_vat_id',
        'user_tax_code',
        'user_it_codfisc',
        'user_it_piva',
        'user_passwordreset_token',
        // Senza questa riga CI4 scarterebbe la scadenza in silenzio a ogni
        // update(), e il token tornerebbe a non scadere mai.
        'user_passwordreset_token_expires',
        'user_all_clients',
    ];

    protected $validationRules = [
        'user_name'     => 'required',
        'user_email'    => 'required|valid_email|is_unique[ip_users.user_email]',
        'user_password' => 'required|min_length[8]|matches[user_passwordv]',
    ];

    protected $validationMessages = [
        'user_name' => [
            'required' => 'Il nome utente e\' obbligatorio',
        ],
        'user_email' => [
            'required'    => 'L\'email e\' obbligatoria',
            'valid_email' => 'L\'email non e\' valida',
            'is_unique'   => 'Esiste gia\' un utente con questa email',
        ],
        'user_password' => [
            'required'    => 'La password e\' obbligatoria',
            'min_length'  => 'La password deve essere di almeno 8 caratteri',
            'matches'     => 'Le password non coincidono',
        ],
    ];

    protected $validationRulesCreate = [
        'user_type'          => 'required',
        'user_email'         => 'required|valid_email|is_unique[ip_users.user_email]',
        'user_name'          => 'required',
        'user_password'      => 'required|min_length[8]',
        'user_passwordv'     => 'required|matches[user_password]',
        'user_language'      => 'required',
        'user_company'       => 'permit_empty',
        'user_active'        => 'permit_empty',
        'user_vat_id'        => 'permit_empty',
        'user_tax_code'      => 'permit_empty',
        'user_iban'          => 'permit_empty',
    ];

    protected $validationRulesExisting = [
        'user_type'          => 'required',
        'user_email'         => 'required|valid_email',
        'user_name'          => 'required',
        'user_language'      => 'required',
        'user_company'       => 'permit_empty',
        'user_active'        => 'permit_empty',
        'user_vat_id'        => 'permit_empty',
        'user_tax_code'      => 'permit_empty',
        'user_iban'          => 'permit_empty',
    ];

    protected $validationMessagesCreate = [
        'user_name' => [
            'required' => 'Il nome utente e\' obbligatorio',
        ],
        'user_email' => [
            'required'    => 'L\'email e\' obbligatoria',
            'valid_email' => 'L\'email non e\' valida',
            'is_unique'   => 'Esiste gia\' un utente con questa email',
        ],
        'user_password' => [
            'required'   => 'La password e\' obbligatoria',
            'min_length' => 'La password deve essere di almeno 8 caratteri',
        ],
        'user_passwordv' => [
            'required' => 'La verifica della password e\' obbligatoria',
            'matches'  => 'Le password non coincidono',
        ],
        'user_type' => [
            'required' => 'Il tipo utente e\' obbligatorio',
        ],
        'user_language' => [
            'required' => 'La lingua e\' obbligatoria',
        ],
    ];

    protected $validationMessagesExisting = [
        'user_name' => [
            'required' => 'Il nome utente e\' obbligatorio',
        ],
        'user_email' => [
            'required'    => 'L\'email e\' obbligatoria',
            'valid_email' => 'L\'email non e\' valida',
        ],
        'user_type' => [
            'required' => 'Il tipo utente e\' obbligatorio',
        ],
        'user_language' => [
            'required' => 'La lingua e\' obbligatoria',
        ],
    ];

    protected $validationMessagesChangePassword = [
        'user_password' => [
            'required'   => 'La password e\' obbligatoria',
            'min_length' => 'La password deve essere di almeno 8 caratteri',
        ],
        'user_passwordv' => [
            'required' => 'La verifica della password e\' obbligatoria',
            'matches'  => 'Le password non coincidono',
        ],
    ];

    protected $validationRulesChangePassword = [
        'user_password'  => 'required|min_length[8]',
        'user_passwordv' => 'required|matches[user_password]',
    ];

    /**
     * Regole di validazione in stile legacy (nuovo utente): usate anche da
     * dbArrayFromPost() per filtrare i campi del POST, come in CI3.
     */
    public function validationRulesLegacy(): array
    {
        return $this->validationRulesCreate;
    }

    public function rulesForCreate(): array
    {
        return $this->validationRulesCreate;
    }

    public function rulesForExisting(): array
    {
        return $this->validationRulesExisting;
    }

    public function rulesForChangePassword(): array
    {
        return $this->validationRulesChangePassword;
    }

    public function messagesForCreate(): array
    {
        return $this->validationMessagesCreate;
    }

    public function messagesForExisting(): array
    {
        return $this->validationMessagesExisting;
    }

    public function messagesForChangePassword(): array
    {
        return $this->validationMessagesChangePassword;
    }

    /**
     * Costruisce l'array da salvare per la modifica di un utente esistente,
     * filtrando il POST con le regole ridotte (senza password, senza is_unique).
     */
    public function dbArrayForExisting(array $post): array
    {
        $dbArray = [];

        foreach ($post as $key => $value) {
            if (array_key_exists($key, $this->validationRulesExisting)) {
                $dbArray[$key] = $value;
            }
        }

        return $dbArray;
    }

    public function userTypes(): array
    {
        return [1 => 'administrator', 2 => 'guest_read_only'];
    }

    /**
     * Aggiorna i campi MFA (segreto TOTP, codici di recupero, contatore).
     *
     * Queste colonne NON stanno in $allowedFields: dbArrayFromPost() le
     * terrebbe fuori dai form utente, e un utente che modifica il proprio
     * profilo non deve poter toccare i propri fattori di autenticazione
     * (disattivare la MFA richiede un codice, vedi Security::totpDisable).
     * Di conseguenza l'update passa qui, fuori dal filtro del Model.
     */
    public function updateMfaFields(int $userId, array $fields): void
    {
        $allowed = ['user_mfa_secret', 'user_mfa_enabled', 'user_mfa_backup_codes', 'user_mfa_last_counter'];
        $fields  = array_intersect_key($fields, array_flip($allowed));

        // Il segreto TOTP va cifrato a riposo come mcp_key, cron_key e le
        // password SMTP: chi legge una riga di ip_users -- un backup, un dump,
        // un'iniezione residua -- altrimenti genera codici validi per sempre,
        // e il secondo fattore smette di essere un secondo fattore.
        //
        // La cifratura e' idempotente: un valore che comincia gia' per "enc:"
        // viene lasciato stare, cosi' nessun percorso puo' cifrarlo due volte.
        // I segreti gia' salvati in chiaro continuano a funzionare, perche'
        // decrypt_secret() restituisce tal quale cio' che non ha il prefisso,
        // e passano a cifrati al primo salvataggio.
        if (!empty($fields['user_mfa_secret']) && strpos((string) $fields['user_mfa_secret'], 'enc:') !== 0) {
            $fields['user_mfa_secret'] = encrypt_secret((string) $fields['user_mfa_secret']);
        }

        if ($fields) {
            $this->db->table($this->table)->where($this->primaryKey, $userId)->update($fields);
        }
    }

    /**
     * Segreto TOTP in chiaro di un utente, o '' se non ne ha uno.
     *
     * Unico punto di lettura: decrypt_secret() gestisce da solo sia i valori
     * cifrati sia quelli legacy in chiaro, quindi i chiamanti non devono
     * sapere quale dei due hanno davanti.
     */
    public function mfaSecret(array $user): string
    {
        return decrypt_secret((string) ($user['user_mfa_secret'] ?? ''));
    }

    /**
     * Salva (insert/update) con hash bcrypt della password
     * (equivalente di Mdl_Users::db_array() + save() in CI3).
     */
    public function saveRecord(?int $id, array $dbArray)
    {
        if (!empty($dbArray['user_password'])) {
            $salt             = $this->salt();
            $dbArray['user_psalt']     = $salt;
            $dbArray['user_password']  = $this->generatePassword($dbArray['user_password'], $salt);
        } else {
            unset($dbArray['user_password'], $dbArray['user_psalt']);
        }

        unset($dbArray['user_passwordv']);

        // La validazione vera l'ha gia' fatta il chiamante con le regole
        // create/existing (che includono la conferma password). Quella
        // interna del Model qui non puo' passare: matches[user_passwordv]
        // punta a un campo che questa stessa funzione ha appena tolto
        // dall'array, quindi insert() tornava false in silenzio e l'utente
        // non veniva mai creato -- nemmeno dal form Utenti.
        $this->skipValidation(true);

        return parent::saveRecord($id, $dbArray);
    }

    /**
     * Cambia la password di un utente (equivalente di save_change_password() CI3).
     */
    public function saveChangePassword(int $userId, string $password): void
    {
        $salt                       = $this->salt();
        $dbArray['user_psalt']      = $salt;
        $dbArray['user_password']   = $this->generatePassword($password, $salt);

        // Stessa ragione di saveRecord(): la validazione interna del Model
        // pretende matches[user_passwordv], il campo di conferma che il
        // chiamante ha gia' verificato sul form (o che qui non esiste, come
        // nel reset password, dove la nuova password arriva da sola).
        // Senza questo, update() falliva la validazione e tornava false in
        // silenzio: il cambio password dalla scheda utente e il reset
        // sembravano riusciti e non salvavano niente, lasciando la vecchia
        // password valida.
        $this->skipValidation(true);

        $this->update($userId, $dbArray);
    }

    /**
     * Verifica una password (equivalente di Crypt::check_password() CI3).
     */
    public function checkPassword(string $hash, string $password): bool
    {
        return password_verify($password, $hash);
    }

    public function salt(): string
    {
        $salt = '';
        for ($i = 0; $i < 22; $i++) {
            $salt .= substr('./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', random_int(0, 63), 1);
        }
        return $salt;
    }

    public function generatePassword(string $password, string $salt): string
    {
        return crypt($password, '$2a$10$' . $salt);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->where('user_email', $email)->first();
    }
}