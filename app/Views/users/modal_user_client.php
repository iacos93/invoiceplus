<?php
// Parziale: tabella dei clienti assegnati all'utente.
// Usato dalla view users/form e caricato via AJAX da UsersAjax::loadUserClientTable().
// $id = id utente (null per utente nuovo con clienti in sessione).
$id = $id ?? null;
?>
<div class="table-responsive">
    <table class="table table-hover no-margin" data-token="<?= csrf_hash() ?>">
        <thead>
        <tr>
            <th scope="col"><?= trans('client') ?></th>
            <th scope="col"><?= trans('options') ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($user_clients as $user_client): ?>
            <tr>
                <td><?= esc(($user_client['client_name'] ?? '') . ' ' . ($user_client['client_surname'] ?? '')) ?></td>
                <td>
                    <?php if ($id && !empty($user_client['user_client_id'])): ?>
                        <form action="<?= site_url('users/delete_user_client/' . $id . '/' . $user_client['user_client_id']) ?>"
                              method="POST">
                            <?= csrf_field() ?>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_record_warning')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('remove') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($user_clients)): ?>
            <tr>
                <td colspan="2"><?= trans('no_clients_assigned') ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>