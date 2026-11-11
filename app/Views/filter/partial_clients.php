<?php
/**
 * Partial: risultati della ricerca live nei clienti.
 *
 * Deve produrre la STESSA tabella di clients/index.php, cella per cella. Prima
 * era una tabella diversa: sei colonne con una colonna "Attivo" che la lista
 * non ha più, nome senza iniziale né timbri, "0,00" invece del trattino sulle
 * celle vuote e nessun data-label — così bastava scrivere nella ricerca perché
 * la lista cambiasse forma, e su telefono smettesse di essere una scheda
 * (mancava ip-table-card-collapse) tornando una tabella larga da scorrere.
 *
 * Il controller passa $records; il nome $clients resta accettato perché è
 * quello che usa la lista completa, e i due si possono riusare in scambio.
 */
$clients = $records ?? $clients ?? [];
?>
<div class="table-responsive">
    <table class="table table-hover ip-clickable-rows ip-table-card-collapse">

        <thead>
        <tr>
            <th scope="col"><?= trans('client_name') ?></th>
            <?php // Etichette corte come nella lista (e nei fornitori): qui e in
                  // clients/index.php la tabella è la stessa e deve restare tale
                  // anche dopo una ricerca. ?>
            <th scope="col"><?= trans('email') ?></th>
            <th scope="col"><?= trans('phone') ?></th>
            <th class="amount" scope="col"><?= trans('balance') ?></th>
            <th scope="col"><?= trans('options') ?></th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($clients as $client): ?>
            <?php
            // Stesse regole della lista: nome completo con fallback, trattino
            // sulle celle vuote, riga spenta per le anagrafiche non attive.
            $clientName  = ($client['client_fullname'] ?? '') ?: format_client($client);
            $clientBal   = (float) ($client['client_invoice_balance'] ?? 0);
            $clientPhone = (string) (($client['client_phone'] ?? '') ?: (($client['client_mobile'] ?? '') ?: ''));
            $isActive    = db_bool($client['client_active'] ?? false);
            ?>
            <tr class="<?= $isActive ? '' : 'ip-status-empty' ?>">
                <?= $this->include('layout/anagrafica_nome', [
                    'label'  => trans('client_name'),
                    'url'    => site_url('clients/view/' . (int) $client['client_id']),
                    'name'   => $clientName,
                    'active' => $isActive,
                    'meta'   => client_type_badge($client['client_type'] ?? null),
                    'cell'   => 'number',
                ]) ?>
                <td data-cell="client" data-label="<?= esc(trans('email')) ?>"><?= ($client['client_email'] ?? '') ? esc($client['client_email']) : '<span class="ip-cell-empty">—</span>' ?></td>
                <td class="ip-hide-on-mobile" data-label="<?= esc(trans('phone')) ?>"><?= $clientPhone !== '' ? esc($clientPhone) : '<span class="ip-cell-empty">—</span>' ?></td>
                <td class="amount" data-cell="amount" data-label="<?= esc(trans('balance')) ?>"><?= $clientBal != 0.0 ? format_currency($clientBal) : '<span class="ip-cell-empty">—</span>' ?></td>
                <td class="ip-hide-on-mobile" data-label="<?= esc(trans('options')) ?>">
                    <div class="options btn-group">
                        <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a href="<?= site_url('clients/view/' . (int) $client['client_id']) ?>">
                                    <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view') ?>
                                </a>
                            </li>
                            <li>
                                <a href="<?= site_url('clients/form/' . (int) $client['client_id']) ?>">
                                    <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                </a>
                            </li>
                            <li>
                                <form action="<?= site_url('clients/delete/' . (int) $client['client_id']) ?>"
                                      method="POST">
                                    <?= csrf_field() ?>
                                    <button type="button" class="dropdown-button"
                                            data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_client_warning', '', 'Eliminare il cliente?')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                        <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete') ?>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php // Solo se la ricerca non ha trovato nulla (vedi clients/index.php). ?>
        <?php if (empty($clients)): ?>
            <?= $this->include('layout/empty_row', [
                'colspan' => 5,
                'icon'    => 'fa-solid fa-users',
                'message' => trans('no_clients_found'),
            ]) ?>
        <?php endif; ?>
        </tbody>

    </table>
</div>
<input type="hidden" name="csrf_test_name" value="<?= csrf_hash() ?>">
