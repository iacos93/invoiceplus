<?php
/**
 * Pannello Sicurezza account: riepilogo, verifica in due passaggi (TOTP),
 * passkey e codici di recupero dell'utente collegato.
 *
 * Partial perche' compare in due posti: la pagina Sicurezza account e il
 * profilo del proprio utente (Impostazioni > Account utenti). Va incluso
 * FUORI da altri <form>: contiene form propri, e un form annidato viene
 * ignorato dal browser (il pulsante finirebbe per inviare quello esterno).
 * Dati: Security::panelData().
 */
?>

    <?php
        // Riepilogo a colpo d'occhio: dove sta la protezione dell'account.
        // Tre fattori allo stesso rango (password, MFA, passkey); lo scudo
        // cambia colore col livello complessivo, non con un singolo toggle.
        $passkeyCount = count($passkeys);

        if ($mfa_enabled && $passkeyCount > 0) {
            $levelClass = 'is-strong';
            $levelLabel = trans('security_level_strong', '', 'Protezione solida');
            $levelTip   = trans('security_tip_both', '', 'Accedi con un fattore oltre alla password: chi ruba la password resta fuori.');
        } elseif ($mfa_enabled || $passkeyCount > 0) {
            $levelClass = 'is-good';
            $levelLabel = trans('security_level_good', '', 'Protezione discreta');
            $levelTip   = trans('security_tip_one', '', 'Hai un fattore aggiuntivo. Aggiungine un altro per non dipendere da un solo metodo.');
        } else {
            $levelClass = 'is-weak';
            $levelLabel = trans('security_level_weak', '', 'Protezione debole');
            $levelTip   = trans('security_tip_none', '', 'Solo la password ti separa da chiunque. Attiva almeno un fattore aggiuntivo.');
        }
    ?>

    <div class="ip-security-summary <?= $levelClass ?>">
        <div class="ip-security-summary__shield" aria-hidden="true">
            <i class="fa fa-solid fa-shield" aria-hidden="true"></i>
        </div>
        <div class="ip-security-summary__body">
            <h2 class="ip-security-summary__title"><?= esc($levelLabel) ?></h2>
            <p class="ip-security-summary__text"><?= trans('security_intro', '', 'Decidi come proteggere l\'accesso al tuo registro: un codice dall\'app authenticator, una passkey del dispositivo, o entrambi.') ?></p>
            <ul class="ip-security-chips">
                <li class="ip-security-chip is-on">
                    <i class="fa fa-solid fa-lock fa-fw" aria-hidden="true"></i>
                    <span class="ip-security-chip__label"><?= trans('password', '', 'Password') ?></span>
                    <span class="ip-security-chip__meta"><?= trans('active', '', 'Attivo') ?></span>
                </li>
                <li class="ip-security-chip <?= $mfa_enabled || $totp_pending ? 'is-on' : 'is-off' ?>">
                    <i class="fa fa-solid fa-mobile-screen-button fa-fw" aria-hidden="true"></i>
                    <span class="ip-security-chip__label"><?= trans('mfa_title', '', 'Verifica in due passaggi') ?></span>
                    <span class="ip-security-chip__meta"><?= $mfa_enabled ? trans('active', '', 'Attivo') : ($totp_pending ? trans('mfa_pending_badge', '', 'Da confermare') : trans('mfa_off_badge', '', 'Disattivo')) ?></span>
                </li>
                <li class="ip-security-chip <?= $passkeyCount ? 'is-on' : 'is-off' ?>">
                    <i class="fa fa-solid fa-fingerprint fa-fw" aria-hidden="true"></i>
                    <span class="ip-security-chip__label"><?= trans('passkeys_title', '', 'Passkey') ?></span>
                    <span class="ip-security-chip__meta"><?= $passkeyCount ?: trans('mfa_off_badge', '', 'Disattivo') ?></span>
                </li>
            </ul>
        </div>
    </div>

    <div class="ip-factors">

        <!-- ======================= MFA (TOTP) ======================= -->
        <div class="card ip-factor-card">
            <div class="card-header">
                <span><i class="fa fa-solid fa-mobile-screen-button fa-fw" aria-hidden="true"></i>
                    <?= trans('mfa_title', '', 'Verifica in due passaggi') ?></span>
                <?php if ($mfa_enabled && !$totp_pending): ?>
                    <span class="ip-status-pill is-success"><?= trans('active', '', 'Attivo') ?></span>
                <?php elseif ($totp_pending): ?>
                    <span class="ip-status-pill is-warning"><?= trans('mfa_pending_badge', '', 'Da confermare') ?></span>
                <?php else: ?>
                    <span class="ip-status-pill is-muted"><?= trans('mfa_off_badge', '', 'Disattivo') ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">

                <?php if ($totp_pending): ?>
                    <!-- Setup in corso: il QR è il "ticket" da timbrare con
                         l'app, il segreto una riga stampata con copia rapida. -->
                    <p><?= trans('mfa_setup_help', '', 'Scansiona il codice con l\'app authenticator (Google Authenticator, Microsoft Authenticator, 1Password…) e inserisci il codice che genera per attivare la verifica.') ?></p>

                    <div class="row align-items-center g-3 mb-3">
                        <div class="col-auto">
                            <span class="ip-totp-ticket">
                                <img src="<?= $totp_qr ?>" alt="QR code TOTP" width="168" height="168">
                            </span>
                        </div>
                        <div class="col">
                            <label for="totp_secret" class="form-badge"><?= trans('mfa_secret', '', 'Chiave segreta') ?></label>
                            <div class="ip-secret-line">
                                <span id="totp_secret"><?= esc($totp_pending) ?></span>
                                <button type="button" class="ip-copy-btn" data-copy-target="#totp_secret"
                                        data-copy-ok="<?= esc(trans('mfa_copied', '', 'Copiato')) ?>" aria-label="<?= esc(trans('mfa_copy_secret', '', 'Copia la chiave')) ?>">
                                    <i class="fa fa-solid fa-paste" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="form-text mb-3"><?= trans('mfa_secret_manual', '', 'Se non riesci a scansionare il QR, inserisci la chiave a mano.') ?></p>

                            <form method="post" action="<?= site_url('security/totp/confirm') ?>" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <div class="col-auto">
                                    <label for="mfa_code" class="visually-hidden"><?= trans('mfa_code', '', 'Codice di verifica') ?></label>
                                    <input type="text" name="mfa_code" id="mfa_code" class="form-control font-monospace"
                                           placeholder="000000" required autocomplete="one-time-code"
                                           inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="fa fa-solid fa-check fa-margin" aria-hidden="true"></i> <?= trans('mfa_activate', '', 'Attiva') ?>
                                    </button>
                                </div>
                            </form>
                            <form method="post" action="<?= site_url('security/totp/cancel') ?>" class="mt-2">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-link btn-sm p-0 text-muted"><?= trans('cancel', '', 'Annulla') ?></button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($mfa_enabled): ?>
                    <p><?= trans('mfa_enabled_help', '', 'L\'accesso richiede un codice dall\'app authenticator o una passkey.') ?></p>

                    <p class="mb-2">
                        <?= esc(sprintf(trans('mfa_backup_codes_left', '', 'Codici di recupero rimasti: %s'), $backup_left)) ?>
                        <span class="text-muted">(<?= trans('mfa_backup_codes_help', '', 'ognuno usabile una sola volta') ?>)</span>
                    </p>

                    <details class="ip-factor-danger">
                        <summary><?= trans('mfa_disable', '', 'Disattiva la verifica in due passaggi') ?></summary>
                        <form method="post" action="<?= site_url('security/totp/disable') ?>" class="mt-2 row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <div class="col-auto">
                                <label for="mfa_disable_code" class="visually-hidden"><?= trans('mfa_code', '', 'Codice di verifica') ?></label>
                                <input type="text" name="mfa_code" id="mfa_disable_code" class="form-control"
                                       placeholder="<?= trans('mfa_disable_code_help', '', 'Codice o codice di recupero') ?>"
                                       required autocomplete="one-time-code" maxlength="10">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="fa fa-solid fa-times fa-margin" aria-hidden="true"></i> <?= trans('mfa_disable', '', 'Disattiva') ?>
                                </button>
                            </div>
                            <div class="col-12">
                                <p class="form-text"><?= trans('mfa_disable_why_code', '', 'Serve un codice valido: chi ha solo la sessione aperta non deve poter spegnere la protezione.') ?></p>
                            </div>
                        </form>
                    </details>

                <?php else: ?>
                    <p><?= trans('mfa_disabled_help', '', 'La verifica in due passaggi aggiunge un codice dell\'app authenticator al login con password.') ?></p>
                    <form method="post" action="<?= site_url('security/totp/setup') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa fa-solid fa-mobile-screen-button fa-margin" aria-hidden="true"></i> <?= trans('mfa_enable', '', 'Attiva la verifica in due passaggi') ?>
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </div>

        <!-- ======================= PASSKEY ======================= -->
        <div class="card ip-factor-card">
            <div class="card-header">
                <span><i class="fa fa-solid fa-fingerprint fa-fw" aria-hidden="true"></i>
                    <?= trans('passkeys_title', '', 'Passkey') ?></span>
                <?php if ($passkeys): ?>
                    <span class="ip-status-pill is-success"><?= count($passkeys) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">

                <p><?= trans('passkeys_help', '', 'Una passkey sostituisce password e secondo fattore: ti identifichi con biometria o PIN del dispositivo (Face ID, Windows Hello, impronta…). Si usa per accedere con un tocco, anche da solo, senza password.') ?></p>

                <?php if (!$passkeys): ?>
                    <!-- Stato vuoto: un invito, non una riga di tabella grigia -->
                    <div class="ip-empty-passkeys">
                        <svg class="ip-passkey-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M8.2 7.1c-1.6 1.3-2.7 3.1-2.7 5.1 0 1.4.3 2.7.7 3.9"/>
                            <path d="M11.9 5.6c.4 0 .9.1 1.3.2 1.8.4 3.4 1.3 4.5 2.7"/>
                            <path d="M9.5 9.5c-.7 1-1.1 2.1-1.1 3.3 0 .9.2 1.9.4 2.7"/>
                            <path d="M12.9 8.3c.9.3 1.7.8 2.3 1.4.7.8 1.1 1.7 1.1 2.7 0 .8-.1 1.6-.4 2.3"/>
                            <path d="M10.2 11.8c0-1.1.8-2 2-2 .5 0 1 .2 1.4.6"/>
                            <path d="M13.1 12.2c.1.6.1 1.2 0 1.8"/>
                            <path d="M11.9 15.3c-.5-.4-.8-.9-.8-1.5"/>
                        </svg>
                        <span><?= trans('passkeys_none', '', 'Nessuna passkey registrata.') ?></span>
                    </div>
                <?php else: ?>
                    <?php /* Elenco e non tabella: tre colonne di date per una o due
                             chiavi lasciavano soprattutto spazio vuoto e un "—"
                             per "mai usata". Ogni riga dice cosa e' la chiave e
                             quando e' servita l'ultima volta, in una frase. */ ?>
                    <ul class="ip-passkey-list">
                        <?php foreach ($passkeys as $passkey): ?>
                            <?php $lastUsed = $passkey['passkey_last_used_at'] ?? null; ?>
                            <li class="ip-passkey-item">
                                <span class="ip-passkey-item__icon" aria-hidden="true"><i class="fa fa-solid fa-fingerprint"></i></span>
                                <span class="ip-passkey-item__body">
                                    <span class="ip-passkey-item__name"><?= esc($passkey['passkey_name']) ?></span>
                                    <span class="ip-passkey-item__meta">
                                        <?= esc(sprintf(trans('passkey_registered_on', '', 'Registrata il %s'), date('d/m/Y', strtotime($passkey['passkey_created_at'])))) ?>
                                        &middot;
                                        <?= $lastUsed
                                            ? esc(sprintf(trans('passkey_last_used_on', '', 'ultimo accesso %s'), date('d/m/Y H:i', strtotime($lastUsed))))
                                            : esc(trans('passkey_never_used', '', 'mai usata per accedere')) ?>
                                    </span>
                                </span>
                                <form method="post" action="<?= site_url('security/passkey/delete/' . $passkey['passkey_id']) ?>" class="ip-passkey-item__action">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            aria-label="<?= esc(sprintf(trans('passkey_delete_named', '', 'Elimina la passkey %s'), $passkey['passkey_name']), 'attr') ?>"
                                            title="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>"
                                            data-bs-toggle="confirm"
                                            data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>"
                                            data-confirm-body="<?= esc(trans('passkey_delete_warning', '', 'Eliminare questa passkey? Non potrai più usarla per accedere.')) ?>"
                                            data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>"
                                            data-confirm-danger="1">
                                        <i class="fa fa-solid fa-trash-can" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form id="ip-passkey-register" class="row g-2 align-items-end">
                    <div class="col-auto flex-grow-1">
                        <label for="passkey_name" class="visually-hidden"><?= trans('passkey_name', '', 'Nome') ?></label>
                        <input type="text" name="passkey_name" id="passkey_name" class="form-control"
                               placeholder="<?= esc(trans('passkey_name_placeholder', '', 'Nome del dispositivo (es. iPhone di Marco)')) ?>" maxlength="100">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-primary ip-passkey-btn" id="ip-passkey-add"
                                data-webauthn="register" data-status="ip-webauthn-status"
                                data-name-input="#passkey_name"
                                data-busy="<?= esc(trans('passkey_busy', '', 'Attendi la richiesta sul dispositivo…'), 'attr') ?>">
                            <svg class="ip-passkey-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M8.2 7.1c-1.6 1.3-2.7 3.1-2.7 5.1 0 1.4.3 2.7.7 3.9"/>
                                <path d="M11.9 5.6c.4 0 .9.1 1.3.2 1.8.4 3.4 1.3 4.5 2.7"/>
                                <path d="M9.5 9.5c-.7 1-1.1 2.1-1.1 3.3 0 .9.2 1.9.4 2.7"/>
                                <path d="M12.9 8.3c.9.3 1.7.8 2.3 1.4.7.8 1.1 1.7 1.1 2.7 0 .8-.1 1.6-.4 2.3"/>
                                <path d="M10.2 11.8c0-1.1.8-2 2-2 .5 0 1 .2 1.4.6"/>
                                <path d="M13.1 12.2c.1.6.1 1.2 0 1.8"/>
                                <path d="M11.9 15.3c-.5-.4-.8-.9-.8-1.5"/>
                            </svg>
                            <?= trans('passkey_register', '', 'Registra una passkey') ?>
                        </button>
                    </div>
                    <div class="col-12">
                        <p class="ip-webauthn-status mb-0" id="ip-webauthn-status" role="status" aria-live="polite"></p>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($backup_codes): ?>
        <!-- Codici di recupero appena generati: mostrati una volta sola.
             Numerati e spezzati a meta' (XXXXX-XXXXX) perche' vengono
             ricopiati a mano: un trattino e un indice dimezzano gli errori
             di lettura. Il download e' generato nel browser da un Blob, non
             da un endpoint: i codici non devono ripassare dal server dopo
             il primo render, ne' finire in un log di accesso. -->
        <div class="card border-danger ip-backup-card">
            <div class="card-header text-danger">
                <span><i class="fa fa-solid fa-exclamation-triangle fa-fw" aria-hidden="true"></i>
                    <?= trans('mfa_backup_codes_title', '', 'Codici di recupero') ?></span>
                <span class="ip-status-pill is-danger"><?= esc(trans('mfa_backup_codes_shown_once', '', 'Mostrati una sola volta')) ?></span>
            </div>
            <div class="card-body">
                <p class="mb-3"><?= trans('mfa_backup_codes_once', '', 'Salva questi codici in un posto sicuro: non saranno mai più mostrati. Ogni codice funziona una sola volta, per entrare quando non hai l\'app a portata di mano.') ?></p>
                <ol class="ip-backup-grid" id="ip-backup-codes">
                    <?php foreach ($backup_codes as $code): ?>
                        <?php $half = intdiv(strlen($code), 2); ?>
                        <li class="ip-backup-stub" data-code="<?= esc($code, 'attr') ?>"><?= esc(substr($code, 0, $half)) ?><span class="ip-backup-sep" aria-hidden="true">-</span><?= esc(substr($code, $half)) ?></li>
                    <?php endforeach; ?>
                </ol>
                <div class="ip-backup-actions">
                    <div class="ip-backup-actions__save">
                        <button type="button" class="btn btn-sm btn-primary" id="ip-backup-download"
                                data-filename="invoiceplane-codici-recupero-<?= date('Y-m-d') ?>.txt"
                                data-header="<?= esc(trans('mfa_backup_codes_file_header', '', 'Invoice+ - codici di recupero'), 'attr') ?>">
                            <i class="fa fa-solid fa-download fa-margin" aria-hidden="true"></i> <?= trans('mfa_backup_codes_download', '', 'Scarica .txt') ?>
                        </button>
                        <button type="button" class="btn btn-sm btn-default" id="ip-backup-copy"
                                data-copy-ok="<?= esc(trans('copied', '', 'Copiati'), 'attr') ?>">
                            <i class="fa fa-solid fa-clipboard fa-margin" aria-hidden="true"></i> <span><?= trans('mfa_backup_codes_copy', '', 'Copia') ?></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-default" onclick="window.print()">
                            <i class="fa fa-solid fa-print fa-margin" aria-hidden="true"></i> <?= trans('print', '', 'Stampa') ?>
                        </button>
                    </div>
                    <form method="post" action="<?= site_url('security/backup/ack') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="fa fa-solid fa-check fa-margin" aria-hidden="true"></i> <?= trans('mfa_backup_codes_saved', '', 'Ho salvato i codici') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>


<script>
    /* Copia della chiave segreta: niente plugin, clipboard API con fallback
       alla selezione del testo. Feedback visivo sull'icona per un secondo e
       mezzo, senza tocchi — il pulsante comunica "fatto" cambiando simbolo. */
    (function () {
        var btn = document.querySelector('.ip-copy-btn');
        if (!btn || !navigator.clipboard) { return; }
        var target = document.querySelector(btn.dataset.copyTarget);
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(target.textContent).then(function () {
                var icon = btn.querySelector('.fa');
                icon.classList.replace('fa-clipboard', 'fa-check');
                var original = btn.title;
                btn.title = btn.dataset.copyOk || original;
                setTimeout(function () {
                    icon.classList.replace('fa-check', 'fa-clipboard');
                    btn.title = original;
                }, 1500);
            });
        });
    })();

    /* Codici di recupero: testo semplice, un codice per riga, con data e
       intestazione per riconoscere il file tra sei mesi. */
    (function () {
        var list = document.getElementById('ip-backup-codes');
        if (!list) { return; }
        var codes = Array.prototype.map.call(list.querySelectorAll('[data-code]'), function (li) { return li.dataset.code; });
        var dl = document.getElementById('ip-backup-download');
        var asText = function () {
            return dl.dataset.header + '\n' + new Date().toLocaleString() + '\n\n' +
                codes.map(function (c, i) { return (i + 1 < 10 ? ' ' : '') + (i + 1) + '. ' + c; }).join('\n') + '\n';
        };
        dl.addEventListener('click', function () {
            var url = URL.createObjectURL(new Blob([asText()], {type: 'text/plain;charset=utf-8'}));
            var a = document.createElement('a');
            a.href = url; a.download = dl.dataset.filename;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        });
        var cp = document.getElementById('ip-backup-copy');
        if (!navigator.clipboard) { cp.hidden = true; return; }
        cp.addEventListener('click', function () {
            navigator.clipboard.writeText(codes.join('\n')).then(function () {
                var label = cp.querySelector('span'), icon = cp.querySelector('.fa'), orig = label.textContent;
                label.textContent = cp.dataset.copyOk; icon.classList.replace('fa-clipboard', 'fa-check');
                setTimeout(function () { label.textContent = orig; icon.classList.replace('fa-check', 'fa-clipboard'); }, 1500);
            });
        });
    })();
</script>

<script src="<?php _core_asset('js/webauthn.js'); ?>"></script>
