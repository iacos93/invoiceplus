<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Creazione di un utente da riga di comando.
 *
 * Serve alla prima installazione: la pagina di login presuppone un utente
 * gia' esistente e non c'e' nessun wizard che lo crei, quindi senza questo
 * comando l'applicazione appena migrata non ha alcun modo di farsi aprire.
 * Passa da UserModel::saveRecord() cosi' salt e hash bcrypt sono identici
 * a quelli del form Utenti, e una password scelta qui funziona ovunque.
 *
 * Uso:
 *   php spark users:create
 */
class UsersCreate extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'users:create';
    protected $description = 'Crea un utente (di norma il primo amministratore) da riga di comando.';
    protected $usage       = 'users:create';

    public function run(array $params)
    {
        $model  = new UserModel();
        $esisti = $model->countAllResults();

        if ($esisti > 0) {
            CLI::write("Attenzione: esistono già {$esisti} utenti nel database.", 'yellow');
            if (CLI::prompt('Procedere comunque?', ['n', 's']) === 'n') {
                return;
            }
        }

        $email = CLI::prompt('Email', null, 'required|valid_email');
        $nome  = CLI::prompt('Nome e cognome', null, 'required');
        $tipo  = CLI::prompt('Tipo utente', [1, 2]);

        // La password si legge due volte per evitare refusi: su terminale non
        // c'e' il campo "conferma" del form, e un admin chiuso fuori dalla
        // propria installazione per un carattere sbagliato e' fastidioso.
        while (true) {
            $password = CLI::prompt('Password (minimo 8 caratteri)', null, 'required|min_length[8]');
            $conferma = CLI::prompt('Conferma password', null, 'required');

            if ($password === $conferma) {
                break;
            }

            CLI::write('Le password non coincidono, riprova.', 'red');
        }

        $lingua = CLI::prompt('Lingua (system / italian / english)', 'system');

        $userId = (int) $model->saveRecord(null, [
            'user_email'    => $email,
            'user_name'     => $nome,
            'user_type'     => (int) $tipo,
            'user_password' => $password,
            'user_language' => $lingua,
            'user_active'   => true,
        ]);

        if ($userId <= 0) {
            CLI::write('Creazione fallita: controllare i log del database.', 'red');

            return;
        }

        $ruolo = ((int) $tipo === 1) ? 'amministratore' : 'guest (sola lettura)';
        CLI::write("Utente #{$userId} creato ({$ruolo}): {$email}", 'green');

        // La migrazione delle aziende precompila la prima azienda col profilo
        // del primo utente: se quella era rimasta al ripiego "Azienda", qui
        // l'utente puo' completare i dati dal form Impostazioni > Aziende.
        if ($esisti === 0) {
            CLI::write('Prossimo passo: accedi e completa i dati dell\'azienda in Impostazioni > Aziende.', 'yellow');
        }
    }
}
