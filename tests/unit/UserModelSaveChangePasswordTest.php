<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * saveChangePassword() ignorava il risultato di update(): un cambio password
 * rifiutato dal database veniva annunciato come riuscito, con la vecchia
 * password ancora valida. Ora torna l'id scritto oppure 0, come saveRecord().
 *
 * update() è simulato: qui interessa che il risultato non venga più perso e
 * cosa finisce nell'array da salvare, non la scrittura (che ha il suo test di
 * integrazione).
 *
 * @internal
 */
final class UserModelSaveChangePasswordTest extends CIUnitTestCase
{
    public function testRestituisceLIdEscriveHashESaltQuandoIlDatabaseSalva(): void
    {
        $model = new UserModelSpia();
        $model->esito = true;

        $this->assertSame(7, $model->saveChangePassword(7, 'NuovaPassword1!'));
        $this->assertSame(7, $model->scritto['id']);

        $salt = (string) $model->scritto['row']['user_psalt'];
        $hash = (string) $model->scritto['row']['user_password'];

        $this->assertSame(22, mb_strlen($salt), 'Il salt non è quello di 22 caratteri di salt()');
        $this->assertTrue($model->checkPassword($hash, 'NuovaPassword1!'), "L'hash scritto non verifica la password nuova");
        $this->assertFalse($model->checkPassword($hash, 'VecchiaPassword1!'), "L'hash scritto verifica ancora la vecchia password");
    }

    public function testRestituisceZeroQuandoUpdateRifiuta(): void
    {
        $model = new UserModelSpia();
        $model->esito = false;

        $this->assertSame(0, $model->saveChangePassword(7, 'NuovaPassword1!'));
    }

    /**
     * La deroga alla validazione serve a far passare l'update senza il campo
     * di conferma; se restasse accesa, il salvataggio successivo sullo stesso
     * modello salterebbe i controlli.
     */
    public function testLaDerogaAllaValidazioneValeSoloPerQuellaScrittura(): void
    {
        $model = new UserModelSpia();
        $model->esito = true;

        $model->saveChangePassword(7, 'NuovaPassword1!');

        $this->assertFalse($model->skipValidation, 'skipValidation è rimasto acceso dopo il cambio password');
    }
}

/**
 * UserModel con update() che risponde quel che serve al test, senza scrivere.
 */
final class UserModelSpia extends UserModel
{
    /** @var array{id: int|string|null, row: array<string, mixed>} */
    public array $scritto = ['id' => null, 'row' => []];

    public bool $esito = true;

    public function update($id = null, $row = null): bool
    {
        $this->scritto = ['id' => $id, 'row' => (array) $row];

        return $this->esito;
    }
}
