<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('calendar', '', 'Calendario'));

$today = new DateTimeImmutable('today');

// Etichette dei tre tipi di scadenza: usate nella legenda sotto la griglia e
// nel badge del popover del giorno. Le stesse tinte di stato dell'app.
$typeInfo = [
    'invoice' => ['label' => trans('invoice', '', 'Fattura'), 'icon' => 'fa-regular fa-file-lines'],
    'f24'     => ['label' => 'F24', 'icon' => 'fa-solid fa-building-columns'],
    'task'    => ['label' => trans('tasks', '', 'Attività'), 'icon' => 'fa-regular fa-square-check'],
];

$actions = '<div class="btn-group btn-group-sm ip-calendar-nav">'
    . '<a class="btn btn-secondary" href="' . site_url('calendar/' . $prev_year . '/' . $prev_month) . '" aria-label="' . esc(trans('previous', '', 'Precedente'), 'attr') . '"><i class="fa fa-solid fa-chevron-left" aria-hidden="true"></i></a>'
    . '<a class="btn btn-secondary" href="' . site_url('calendar/' . (int) $today->format('Y') . '/' . (int) $today->format('n')) . '">' . trans('today', '', 'Oggi') . '</a>'
    . '<a class="btn btn-secondary" href="' . site_url('calendar/' . $next_year . '/' . $next_month) . '" aria-label="' . esc(trans('next', '', 'Successivo'), 'attr') . '"><i class="fa fa-solid fa-chevron-right" aria-hidden="true"></i></a>'
    . '</div>';

// Il riepilogo laterale raggruppa le scadenze del mese per stato, non in una
// lista piatta: "scadute" e "oggi" saltano subito all'occhio, il resto è il
// programma. Stesso linguaggio a sezioni delle altre pagine.
$upcoming_sections = [
    ['label' => trans('calendar_overdue', '', 'Scadute'), 'cls' => 'is-overdue-group', 'items' => []],
    ['label' => trans('today', '', 'Oggi'), 'cls' => '', 'items' => []],
    ['label' => trans('calendar_planned', '', 'In programma'), 'cls' => '', 'items' => []],
];
foreach ($upcoming as $event) {
    if ($event['overdue']) {
        $upcoming_sections[0]['items'][] = $event;
    } elseif ($event['date'] === $today->format('Y-m-d')) {
        $upcoming_sections[1]['items'][] = $event;
    } else {
        $upcoming_sections[2]['items'][] = $event;
    }
}
?>
<?= $this->include('layout/headerbar', ['title' => esc($month_label), 'actions' => $actions]) ?>

<div id="content">

    <?php /* Sottoscrizione: la stessa cosa che apri qui a mano, ma dentro
             Google Calendar o il tuo client CalDAV. Un solo URL di sola
             lettura, non un accesso all'app — vedi il commento in
             CalendarService::toIcs(). */ ?>
    <?php if ($feed_url): ?>
        <div class="ip-calendar-feed-bar">
            <div class="ip-calendar-feed-bar__main">
                <i class="fa fa-solid fa-rss ip-calendar-feed-bar__icon" aria-hidden="true"></i>
                <span class="ip-calendar-feed-bar__label">
                    <?= trans('calendar_feed_active', '', 'Sottoscrivi questo calendario da Google Calendar o da un client CalDAV (Apple Calendario, Thunderbird, Nextcloud...) con:') ?>
                </span>
                <code class="ip-calendar-feed-bar__url"><?= esc($feed_url) ?></code>
                <div class="ip-calendar-feed-bar__actions">
                    <a href="<?= esc($calendar_add_links['apple']) ?>" class="btn btn-outline-secondary btn-sm ip-calendar-feed-bar__btn">
                        <i class="fa fa-brands fa-apple" aria-hidden="true"></i> <?= trans('calendar_feed_add_apple', '', 'Apple Calendario') ?>
                    </a>
                    <a href="<?= esc($calendar_add_links['google']) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm ip-calendar-feed-bar__btn">
                        <i class="fa fa-brands fa-google" aria-hidden="true"></i> <?= trans('calendar_feed_add_google', '', 'Google Calendar') ?>
                    </a>
                    <a href="<?= esc($calendar_add_links['outlook']) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm ip-calendar-feed-bar__btn">
                        <i class="fa fa-brands fa-microsoft" aria-hidden="true"></i> <?= trans('calendar_feed_add_outlook', '', 'Outlook') ?>
                    </a>
                </div>
            </div>
            <a href="<?= site_url('settings') ?>#calendar_feed_url" class="ip-calendar-feed-bar__link">
                <?= trans('calendar_feed_manage', '', 'Gestisci in Impostazioni') ?>
            </a>
        </div>
    <?php else: ?>
        <div class="ip-calendar-feed-bar ip-calendar-feed-bar--missing">
            <div class="ip-calendar-feed-bar__main">
                <i class="fa fa-solid fa-rss ip-calendar-feed-bar__icon" aria-hidden="true"></i>
                <span class="ip-calendar-feed-bar__label">
                    <?= trans('calendar_feed_missing', '', 'Nessun feed attivo: generane uno in Impostazioni per sottoscrivere queste scadenze da Google Calendar o CalDAV.') ?>
                </span>
            </div>
            <a href="<?= site_url('settings') ?>#calendar_feed_url" class="btn btn-outline-warning btn-sm ip-calendar-feed-bar__btn">
                <?= trans('calendar_feed_generate', '', 'Genera il feed') ?>
            </a>
        </div>
    <?php endif; ?>

    <div class="ip-calendar-layout">

        <div class="card ip-calendar-card">
            <div class="table-responsive">
                <table class="ip-calendar-grid">
                    <thead>
                    <tr>
                        <?php foreach ($weekday_labels as $wd_i => $wd): ?>
                            <?php
                            // Giorno della settimana reale di questa colonna:
                            // sabato (6) e domenica (0) restano sempre weekend,
                            // qualunque sia il giorno d'inizio impostato.
                            $wd_real = ($week_start + $wd_i) % 7;
                            ?>
                            <th scope="col" class="<?= in_array($wd_real, [0, 6], true) ? 'is-weekend' : '' ?>"><?= esc($wd) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($weeks as $week): ?>
                        <tr>
                            <?php $w = 0; ?>
                            <?php foreach ($week as $cell): ?>
                                <?php
                                // Stessa logica dell'intestazione: la colonna $w
                                // corrisponde al giorno reale ($week_start + $w) % 7.
                                $w_real = ($week_start + $w) % 7;
                                ?>
                                <?php if ($cell === null): ?>
                                    <td class="ip-calendar-cell is-empty"></td>
                                <?php else: ?>
                                    <?php
                                    $cell_has_events = !empty($cell['events']);
                                    $cell_n_events   = count($cell['events']);
                                    ?>
                                    <td class="ip-calendar-cell <?= $cell['is_today'] ? 'is-today' : '' ?> <?= in_array($w_real, [0, 6], true) ? 'is-weekend' : '' ?> <?= $cell_has_events ? 'has-events' : '' ?>"
                                        <?= $cell['is_today'] ? 'aria-current="date"' : '' ?>>
                                        <?php if ($cell_has_events): ?>
                                            <button type="button"
                                                    class="ip-calendar-daynum"
                                                    data-day-popover
                                                    aria-haspopup="dialog"
                                                    aria-expanded="false"
                                                    title="<?= $cell_n_events ?> <?= trans('calendar_events', '', 'scadenze') ?>"
                                                    aria-label="<?= $cell['day'] ?> — <?= $cell_n_events ?> <?= trans('calendar_events', '', 'scadenze') ?>">
                                                <?= $cell['day'] ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="ip-calendar-daynum"><?= $cell['day'] ?></span>
                                        <?php endif; ?>
                                        <?php foreach (array_slice($cell['events'], 0, 3) as $event): ?>
                                            <a href="<?= esc($event['url']) ?>"
                                               class="ip-calendar-event ip-calendar-event--<?= esc($event['type']) ?> <?= $event['overdue'] ? 'is-overdue' : '' ?>"
                                               title="<?= esc($event['title']) ?>">
                                                <?= esc($event['title']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                        <?php if ($cell_n_events > 3): ?>
                                            <button type="button" class="ip-calendar-event-more" data-day-popover
                                                    aria-haspopup="dialog" aria-expanded="false">
                                                +<?= $cell_n_events - 3 ?> <?= trans('more', '', 'altre') ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($cell_has_events): ?>
                                            <template class="ip-calendar-popover-tpl">
                                                <div class="ip-calendar-popover__head">
                                                    <span class="ip-calendar-popover__day"><?= $cell['day'] ?></span>
                                                    <span class="ip-calendar-popover__count"><?= $cell_n_events ?> <?= trans('calendar_events', '', 'scadenze') ?></span>
                                                </div>
                                                <ul class="ip-calendar-popover__list">
                                                    <?php foreach ($cell['events'] as $event): ?>
                                                        <li class="ip-calendar-popover__item ip-calendar-popover__item--<?= esc($event['type']) ?><?= $event['overdue'] ? ' is-overdue' : '' ?>">
                                                            <a href="<?= esc($event['url']) ?>">
                                                                <span class="ip-calendar-popover__badge ip-calendar-popover__badge--<?= esc($event['type']) ?>"><?= esc($typeInfo[$event['type']]['label'] ?? $event['type']) ?></span>
                                                                <span class="ip-calendar-popover__title"><?= esc($event['title']) ?></span>
                                                                <?php if ($event['amount'] !== null): ?>
                                                                    <span class="ip-calendar-popover__amount"><?= format_currency($event['amount']) ?></span>
                                                                <?php endif; ?>
                                                            </a>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </template>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php $w++; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php /* Legenda dei tre tipi: la griglia usa le stesse tinte della
                     cartoleria (vedi sezione CSS), la legenda lo dice a chi non
                     arriva dal resto dell'app. */ ?>
            <div class="ip-calendar-legend">
                <span class="ip-calendar-legend__label"><?= trans('calendar_legend', '', 'Legenda') ?></span>
                <span class="ip-calendar-legend__item ip-calendar-legend__item--invoice"><?= esc($typeInfo['invoice']['label']) ?></span>
                <span class="ip-calendar-legend__item ip-calendar-legend__item--f24">F24</span>
                <span class="ip-calendar-legend__item ip-calendar-legend__item--task"><?= esc($typeInfo['task']['label']) ?></span>
                <span class="ip-calendar-legend__item is-overdue"><?= trans('overdue', '', 'Scaduto') ?></span>
            </div>
        </div>

        <aside class="ip-calendar-side">
            <div class="card">
                <div class="card-header">
                    <span class="card-header-icon"><i class="fa fa-regular fa-calendar-check" aria-hidden="true"></i></span>
                    <?= trans('calendar_upcoming', '', 'Scadenze del mese') ?>
                    <span class="badge text-bg-secondary"><?= count($upcoming) ?></span>
                </div>
                <div class="card-body no-padding">
                    <?php if (empty($upcoming)): ?>
                        <p class="ip-number-help" style="padding:16px">
                            <?= trans('calendar_no_upcoming', '', 'Nessuna scadenza in questo mese.') ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($upcoming_sections as $section): ?>
                            <?php if ($section['items']): ?>
                                <p class="ip-calendar-group <?= $section['cls'] ?>">
                                    <?= esc($section['label']) ?>
                                    <span class="ip-calendar-group__count"><?= count($section['items']) ?></span>
                                </p>
                                <ul class="ip-calendar-list">
                                    <?php foreach ($section['items'] as $event): ?>
                                        <li class="<?= $event['overdue'] ? 'is-overdue' : '' ?>">
                                            <a href="<?= esc($event['url']) ?>">
                                                <span class="ip-calendar-list__date">
                                                    <?= date('d/m', strtotime($event['date'])) ?>
                                                </span>
                                                <span class="ip-calendar-list__title"><?= esc($event['title']) ?></span>
                                                <?php if ($event['amount'] !== null): ?>
                                                    <span class="ip-calendar-list__amount"><?= format_currency($event['amount']) ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

    </div>
</div>

<script>
    // Popover del giorno: clicca sul numero del giorno (o su "+N altre") per
    // vedere tutte le scadenze di quel giorno. Pannello montato in <body>
    // (stesso pattern di IPSelect), posizionato sopra la cella; si chiude con
    // Escape, click fuori o scroll. Il contenuto viene clonato dal <template>
    // dentro la cella: stessa resa server-side, nessun JSON da tenere in sync.
    (function () {
        var panel = null;
        var currentTrigger = null;

        function close() {
            if (!panel) { return; }
            panel.remove();
            panel = null;
            if (currentTrigger) {
                currentTrigger.setAttribute('aria-expanded', 'false');
            }
            currentTrigger = null;
        }

        function place(el, anchor) {
            var rect = anchor.getBoundingClientRect();
            var pad = 8;
            var top = rect.bottom + pad;
            var left = rect.left;
            if (left + el.offsetWidth > window.innerWidth - pad) {
                left = Math.max(pad, window.innerWidth - el.offsetWidth - pad);
            }
            if (top + el.offsetHeight > window.innerHeight - pad) {
                top = Math.max(pad, rect.top - el.offsetHeight - pad);
            }
            el.style.top = top + 'px';
            el.style.left = left + 'px';
        }

        function open(cell, trigger) {
            var tpl = cell.querySelector('template.ip-calendar-popover-tpl');
            if (!tpl) { return; }

            close();

            panel = document.createElement('div');
            panel.className = 'ip-calendar-popover';
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('tabindex', '-1');
            panel.setAttribute('aria-label', (tpl.content.querySelector('.ip-calendar-popover__day') || { textContent: '' }).textContent.trim());
            panel.appendChild(tpl.content.cloneNode(true));
            document.body.appendChild(panel);

            currentTrigger = trigger;
            trigger.setAttribute('aria-expanded', 'true');
            place(panel, cell);
        }

        ipOn(document, 'click', function (e) {
            var trigger = e.target.closest('[data-day-popover]');
            if (trigger) {
                var cell = trigger.closest('.ip-calendar-cell');
                if (cell) {
                    e.preventDefault();
                    open(cell, trigger);
                }
                return;
            }
            if (panel && !panel.contains(e.target)) {
                close();
            }
        });

        ipOn(document, 'keydown', function (e) {
            if (e.key === 'Escape' && panel) {
                close();
                if (currentTrigger) { currentTrigger.focus(); }
            }
        });

        // Scroll (incluso lo scroll della sidebar, catturato in fase di
        // capturing): un pannello fisso ancorato a una cella che si muove
        // diventa subito sbagliato, meglio chiuderlo. Lo scroll interno alla
        // lista del pannello invece lo lascia aperto.
        ipOn(window, 'scroll', function (e) {
            if (panel && !(e.target instanceof Node && panel.contains(e.target))) {
                close();
            }
        }, true);
        ipOn(window, 'resize', function () { close(); });
    })();
</script>
<?= $this->endSection() ?>