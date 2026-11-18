<?php

namespace App\Libraries;

use App\Models\UserModel;

/**
 * Verifica che l'utente di una sessione già aperta esista ancora, sia attivo
 * e abbia lo stesso tipo con cui è entrato.
 *
 * Il login scrive user_id e user_type in sessione una volta sola: senza questo
 * controllo un amministratore eliminato o disattivato da Impostazioni >
 * Account utente continuava a navigare l'area admin finché la sessione non
 * scadeva. La lettura è una sola riga per chiave primaria a ogni richiesta;
 * si è scelto di non metterla in cache perché lo scopo è proprio che
 * l'eliminazione abbia effetto alla richiesta successiva, e una cache anche di
 * pochi secondi lascerebbe una finestra in cui l'utente rimosso agisce ancora.
 */
class SessionUserGuard
{
    /** @var (callable(int): ?array)|null sostituibile nei test, che non hanno un database reale */
    public static $loader = null;

    public static function isValid(mixed $userId, int $expectedType): bool
    {
        $id = (int) $userId;
        if ($id <= 0) {
            return false;
        }

        $loader = self::$loader ?? static fn (int $id): ?array => (new UserModel())
            ->select('user_id, user_type, user_active')
            ->find($id);

        $user = $loader($id);

        return is_array($user)
            && db_bool($user['user_active'] ?? false)
            && (int) ($user['user_type'] ?? 0) === $expectedType;
    }

    /**
     * Svuota e distrugge la sessione. Le chiavi si rimuovono prima del
     * destroy perché il destroy agisce sullo storage e non sull'array già
     * caricato: senza la rimozione il resto della richiesta (e i test, dove
     * la sessione è simulata) vedrebbe ancora l'utente rimosso.
     */
    public static function reject(): void
    {
        $session = session();
        $session->remove(array_keys($_SESSION ?? []));
        $session->destroy();
    }
}
