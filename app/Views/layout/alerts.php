<?php
/**
 * Notifiche flashdata come toast (prima erano banner fissi in cima al
 * contenuto, che spingevano giù tutta la pagina finché non si ricaricava).
 * Bootstrap 5 ha già il componente Toast nel bundle già caricato in
 * layout/base.php: qui si genera solo il markup, l'attivazione (mostra +
 * auto-dismiss) è nello script in fondo a questo file.
 */
$toasts = [
    'success' => ['msg' => session()->getFlashdata('alert_success'), 'icon' => 'fa-solid fa-circle-check',        'variant' => 'success'],
    'info'    => ['msg' => session()->getFlashdata('alert_info'),    'icon' => 'fa-solid fa-circle-info',          'variant' => 'info'],
    'error'   => ['msg' => session()->getFlashdata('alert_error'),   'icon' => 'fa-solid fa-triangle-exclamation', 'variant' => 'danger'],
];
$toasts = array_filter($toasts, static fn($t) => !empty($t['msg']));
?>
<?php if ($toasts): ?>
<div class="ip-toast-container" id="ip-toast-container" aria-live="polite" aria-atomic="true">
    <?php foreach ($toasts as $key => $t): ?>
        <div class="toast fade ip-toast ip-toast--<?= esc($t['variant']) ?>" role="alert" aria-live="assertive" aria-atomic="true"
             data-bs-autohide="<?= $key === 'error' ? 'false' : 'true' ?>" data-bs-delay="5000">
            <div class="toast-body">
                <i class="fa <?= esc($t['icon']) ?> ip-toast-icon" aria-hidden="true"></i>
                <div class="ip-toast-message"><?= $t['msg'] ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="<?= esc(trans('close', '', 'Chiudi')) ?>"></button>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script>
    (function () {
        var container = document.getElementById('ip-toast-container');
        if (!container || typeof bootstrap === 'undefined' || !bootstrap.Toast) { return; }
        container.querySelectorAll('.toast').forEach(function (el) {
            new bootstrap.Toast(el).show();
            // Il container non serve più una volta svuotato: senza autohide
            // (caso errore) resterebbe lì a occupare posizione dopo la chiusura manuale.
            el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        });
    })();
</script>
<?php endif; ?>
