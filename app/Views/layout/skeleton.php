<?php
/**
 * Partial skeleton di caricamento — overlay pagina + script ip-skeleton.js.
 *
 * Include in layout/base e layout/guest (primo figlio di #main-content).
 * Non più in layout/auth: dal login si passa alla dashboard, e l'overlay
 * con la card finta centrata disorientava chi premeva "Entra". L'overlay
 * è nascosto all'arrivo della pagina; js/ip-skeleton.js lo mostra al
 * click su un link o alla submit di un form (stessa origine) mentre il
 * browser carica la pagina successiva — ma solo se il caricamento supera
 * SHOW_DELAY_MS: su una pagina veloce il lampo della finta headerbar
 * sembrava un doppio caricamento. Il markup finto riproduce
 * headerbar + card + tabella delle pagine elenco.
 *
 * Lo script non è più qui dentro: il partial sta dentro #main-content, e la
 * navigazione parziale (ip-pjax.js) sostituisce quel contenuto a ogni click.
 * Se lo <script> restasse qui verrebbe rieseguito a ogni navigazione,
 * duplicando i listener. È caricato da layout/base e layout/guest.
 */
$ip_loading = esc(trans('loading', '', 'Caricamento…'));
?>
<div id="ip-page-skeleton" class="ip-page-skeleton" role="status" aria-live="polite" hidden>
    <span class="visually-hidden"><?= $ip_loading ?></span>
    <!-- Il resto è decorativo: i barre shimmer non devono essere lette. -->
    <div aria-hidden="true">
        <div class="ip-page-skeleton__bar">
            <span class="ip-skeleton ip-skeleton--lg"></span>
            <span class="ip-skeleton ip-skeleton--lg"></span>
        </div>
        <div class="ip-skeleton-card">
            <div class="ip-skeleton-card__head">
                <span class="ip-skeleton ip-skeleton--lg" style="--w:24%"></span>
                <span class="ip-skeleton" style="--w:12%"></span>
            </div>
            <div class="ip-skeleton-card__body">
                <div class="ip-skeleton-table">
                    <div class="ip-skeleton-table__head">
                        <span class="ip-skeleton"></span><span class="ip-skeleton"></span>
                        <span class="ip-skeleton"></span><span class="ip-skeleton"></span>
                        <span class="ip-skeleton"></span><span class="ip-skeleton"></span>
                    </div>
                    <?php for ($ip_sr = 0; $ip_sr < 6; $ip_sr++): ?>
                        <div class="ip-skeleton-table__row">
                            <span class="ip-skeleton" style="--w:<?= 35 + (($ip_sr * 9) % 40) ?>%"></span>
                            <span class="ip-skeleton" style="--w:70%"></span>
                            <span class="ip-skeleton" style="--w:<?= 45 + (($ip_sr * 13) % 35) ?>%"></span>
                            <span class="ip-skeleton" style="--w:60%"></span>
                            <span class="ip-skeleton" style="--w:<?= 30 + (($ip_sr * 11) % 30) ?>%"></span>
                            <span class="ip-skeleton" style="--w:15%"></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>
</div>
