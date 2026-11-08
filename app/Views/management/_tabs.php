<?php
/**
 * Navigazione del Controllo di gestione.
 *
 * Sono link a pagine diverse, non schede nella stessa pagina: il piano dei
 * conti e il budget si modificano, il report si legge, e tenerli tutti in una
 * sola pagina significherebbe ricaricare i numeri a ogni salvataggio. Una
 * barra di link dice anche dove ci si trova, cosa che una linguetta Bootstrap
 * con pannelli nascosti non faceva.
 *
 * Parametri: $active ('report' | 'cashflow' | 'accounts' | 'budget' | 'assign').
 * L'anno non viaggia nei link: le cinque schermate leggono il contesto
 * globale di sessione (selected_year(), il selettore in sidebar).
 */
// Le opzioni di $this->include() non vengono estratte fra le variabili della
// view: finiscono in renderVars['options']. Leggendo $active direttamente il
// valore era sempre il default, quindi la prima voce restava attiva anche su
// Flusso di cassa, Piano dei conti, Budget e Assegna voci.
$mgOptions = (array) ($this->renderVars['options'] ?? []);
$mgActive  = $mgOptions['active'] ?? 'report';

$mgTabs = [
    // Chiave propria e non 'management': quella esiste già nei file di lingua
    // ("Controllo di gestione"), quindi il fallback "Bilancino" non entrava
    // mai in gioco e la prima scheda ripeteva il nome del modulo, cioè il
    // titolo della sezione in sidebar sopra le stesse cinque voci.
    'report'   => ['management', trans('management_report_tab', '', 'Bilancino'), 'fa-solid fa-scale-balanced'],
    'cashflow' => ['management/cashflow', trans('management_cashflow', '', 'Flusso di cassa'), 'fa-solid fa-money-bill'],
    'accounts' => ['management/accounts', trans('management_plan', '', 'Piano dei conti'), 'fa-solid fa-sitemap'],
    'budget'   => ['management/budget', trans('management_budget', '', 'Budget'), 'fa-solid fa-bullseye'],
    'assign'   => ['management/assign', trans('management_assign', '', 'Assegna voci'), 'fa-solid fa-link'],
];
?>
<nav class="ip-subnav" data-ip-subnav aria-label="<?= esc(trans('management', '', 'Controllo di gestione'), 'attr') ?>">
    <?php foreach ($mgTabs as $key => [$url, $label, $icon]): ?>
        <a class="ip-subnav__item<?= $key === $mgActive ? ' is-active' : '' ?>"
           href="<?= site_url($url) ?>"
           <?= $key === $mgActive ? 'aria-current="page"' : '' ?>>
            <i class="fa <?= esc($icon, 'attr') ?>" aria-hidden="true"></i>
            <?= esc($label) ?>
        </a>
    <?php endforeach; ?>
</nav>
<script>
/* Su schermo stretto la barra scorre in orizzontale (vedi CSS): la voce
 * attiva può stare oltre il bordo destro, e chi apre "Assegna voci" non
 * vedrebbe quale scheda è selezionata. La porta in vista senza scorrere la
 * pagina. Gira a ogni render del partial, quindi anche dopo uno swap pjax. */
(function () {
    var nav = document.querySelector("[data-ip-subnav]");
    // aria-current e non la classe: è l'attributo che dice "pagina corrente",
    // e ManagementTabsTest conta le occorrenze della classe nel markup.
    var active = nav && nav.querySelector('[aria-current="page"]');
    if (!active) { return; }

    function center() {
        if (nav.scrollWidth <= nav.clientWidth) { return; }
        // Posizioni misurate rispetto alla barra: offsetLeft è relativo
        // all'offsetParent (la barra non è posizionata) e sbagliava il centro.
        var offset = active.getBoundingClientRect().left - nav.getBoundingClientRect().left + nav.scrollLeft;
        nav.scrollLeft = offset - (nav.clientWidth - active.offsetWidth) / 2;
    }

    center();
    // Al primo caricamento Figtree arriva dopo: con il font di ripiego le
    // voci sono più strette e il centro calcolato lasciava l'attiva tagliata.
    if (document.fonts && document.fonts.status !== "loaded") {
        document.fonts.ready.then(center);
    }
})();
</script>
