<?php
/**
 * Partial modale di conferma riutilizzabile.
 *
 * Markup BS5 (.modal.fade .modal-dialog .modal-content) destinato a essere
 * mostrato via API JS (new bootstrap.Modal(...)). Una sola istanza per pagina
 * viene inclusa dai layout base.php e guest.php.
 *
 * Pattern di utilizzo (sostituisce data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="..." data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1"):
 *
 *   // Bottone submit dentro <form>
 *   <button type="button" class="dropdown-button"
 *           data-bs-toggle="confirm"
 *           data-confirm-title="<?= trans('delete') ?>"
 *           data-confirm-body="<?= trans('delete_invoice_warning') ?>"
 *           data-confirm-ok="<?= trans('delete') ?>"
 *           data-confirm-danger="1">
 *       <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete') ?>
 *   </button>
 *
 *   // Anchor con conferma (GET, no form) -> aggiungere data-confirm-href
 *   <a href=".../delete/123" data-bs-toggle="confirm"
 *      data-confirm-title="..." data-confirm-body="..." data-confirm-danger="1">
 *       ...
 *   </a>
 *
 * Inizializzato da custom.js (init in layout/base.php):
 *   document.addEventListener('click', ipConfirmDelegate);
 */

$title ??= trans('confirm', '', 'Conferma');
$body  ??= '';
$ok    ??= trans('confirm', '', 'Conferma');
$cancel ??= trans('cancel', '', 'Annulla');
$danger = isset($danger) ? (bool) $danger : true;
?>
<div class="modal fade" id="ip-confirm-modal" tabindex="-1" role="dialog"
     aria-labelledby="ip-confirm-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ip-confirm-modal-title"><?= esc($title) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= esc($cancel) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-start gap-3">
                    <i class="fa fa-solid fa-exclamation-circle confirm-modal-icon mt-1" aria-hidden="true"></i>
                    <p class="confirm-modal-body mb-0" id="ip-confirm-modal-body"><?= esc($body) ?></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="ip-confirm-cancel">
                    <?= esc($cancel) ?>
                </button>
                <button type="button" class="btn btn-danger" id="ip-confirm-ok">
                    <i class="fa fa-solid fa-check" aria-hidden="true"></i>
                    <span class="ip-confirm-ok-label"><?= esc($ok) ?></span>
                </button>
            </div>
        </div>
    </div>
</div>
