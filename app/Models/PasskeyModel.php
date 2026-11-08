<?php

namespace App\Models;

/**
 * PasskeyModel
 *
 * Credenziali WebAuthn degli utenti (ip_passkeys). La chiave pubblica è un
 * PEM, l'id della credenziale è base64 standard (i client WebAuthn lo
 * mandano in base64url: la conversione avviene prima di arrivare qui).
 *
 * Il modello è a tutti gli effetti un archivio di soli insert: la verifica
 * delle credenziali non tocca quasi mai il database (solo aggiornamento del
 * contatore), quindi non c'è logica di business oltre al salvataggio e alla
 * ricerca per id credenziale — il lookup serve al login passkey senza
 * username, dove non sappiamo ancora a quale utente appartiene.
 */
class PasskeyModel extends IpBaseModel
{
    protected $table         = 'ip_passkeys';
    protected $primaryKey    = 'passkey_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'user_id',
        'passkey_name',
        'passkey_credential_id',
        'passkey_public_key',
        'passkey_counter',
        'passkey_created_at',
        'passkey_last_used_at',
    ];

    public function forUser(int $userId): array
    {
        return $this->where('user_id', $userId)->orderBy('passkey_created_at', 'ASC')->findAll();
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->where('user_id', $userId)->countAllResults();
    }

    public function findByCredentialId(string $credentialIdBase64): ?array
    {
        return $this->where('passkey_credential_id', $credentialIdBase64)->first();
    }

    /**
     * Salva una credenziale appena registrata.
     *
     * @return int passkey_id
     */
    public function saveCredential(int $userId, string $name, string $credentialIdBase64, string $publicKeyPem, int $counter): int
    {
        return (int) $this->saveRecord(null, [
            'user_id'              => $userId,
            'passkey_name'         => mb_substr(trim($name), 0, 100),
            'passkey_credential_id' => $credentialIdBase64,
            'passkey_public_key'   => $publicKeyPem,
            'passkey_counter'      => $counter,
            'passkey_created_at'   => date('Y-m-d H:i:s'),
        ]);
    }
}