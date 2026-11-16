/**
 * Grafici interattivi: tooltip, evidenza del mese, apertura del dettaglio e
 * legenda che accende e spegne le serie.
 *
 * Il markup lo scrive il server (dashboard/index.php, clients/view.php): ogni
 * mese è un <g class="ip-chart-month"> con i valori già formattati in
 * data-tip (JSON) e, se c'è un elenco da aprire, il link in data-href. Qui non
 * c'è nessuna conoscenza di valuta, lingua o aliquote.
 *
 * I listener stanno sul document e si registrano una volta sola: il file è nel
 * bundle del layout e non viene ricaricato dalla navigazione parziale
 * (ip-pjax), che sostituisce solo #main-content; i gruppi nuovi funzionano
 * senza altro perché si intercetta per delegazione.
 */
(function () {
    'use strict';

    if (window.__ipChartTips) {
        return;
    }
    window.__ipChartTips = true;

    var SELECTOR = '.ip-chart-month[data-tip]';
    var tip = null;
    var active = null;
    var lastPointerType = 'mouse';
    var shownByTouch = null;

    function ensureTip() {
        if (tip) {
            return tip;
        }
        tip = document.createElement('div');
        tip.className = 'ip-chart-tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.appendChild(tip);

        return tip;
    }

    function parse(group) {
        try {
            return JSON.parse(group.getAttribute('data-tip'));
        } catch (e) {
            return null;
        }
    }

    // textContent e non innerHTML: i valori arrivano dal server già come testo
    // e non devono mai essere interpretati come markup.
    function render(data, clickable) {
        var el = ensureTip();
        el.textContent = '';

        var title = document.createElement('div');
        title.className = 'ip-chart-tip__title';
        title.textContent = data.title || '';
        el.appendChild(title);

        (data.rows || []).forEach(function (row) {
            var line = document.createElement('div');
            line.className = 'ip-chart-tip__row';

            var dot = document.createElement('span');
            dot.className = 'ip-chart-tip__dot' + (row.cls ? ' ip-chart-tip__dot--' + row.cls : '');
            line.appendChild(dot);

            var label = document.createElement('span');
            label.className = 'ip-chart-tip__label';
            label.textContent = row.label;
            line.appendChild(label);

            var value = document.createElement('span');
            value.className = 'ip-chart-tip__value';
            value.textContent = row.value;
            line.appendChild(value);

            el.appendChild(line);
        });

        if (clickable && data.hint) {
            var hint = document.createElement('div');
            hint.className = 'ip-chart-tip__hint';
            hint.textContent = data.hint;
            el.appendChild(hint);
        }
    }

    function place(x, y) {
        var el = ensureTip();
        var pad = 12;
        var w = el.offsetWidth;
        var h = el.offsetHeight;
        var left = x + pad;
        var top = y - h - pad;

        // Se non c'è spazio a destra o sopra, passa dall'altra parte del puntatore.
        if (left + w > window.innerWidth - 8) {
            left = x - w - pad;
        }
        if (top < 8) {
            top = y + pad * 1.5;
        }

        el.style.left = Math.max(8, left) + 'px';
        el.style.top = Math.max(8, top) + 'px';
    }

    function show(group, x, y) {
        var data = parse(group);
        if (!data) {
            return;
        }

        if (active && active !== group) {
            active.classList.remove('is-active');
        }
        active = group;
        group.classList.add('is-active');

        render(data, group.hasAttribute('data-href'));
        ensureTip().hidden = false;
        place(x, y);
    }

    function hide() {
        if (active) {
            active.classList.remove('is-active');
            active = null;
        }
        if (tip) {
            tip.hidden = true;
        }
    }

    function groupOf(target) {
        return target && target.closest ? target.closest(SELECTOR) : null;
    }

    function open(group) {
        var href = group.getAttribute('data-href');
        if (!href) {
            return;
        }
        hide();
        if (typeof window.ipNavigate === 'function') {
            window.ipNavigate(href);
        } else {
            window.location.href = href;
        }
    }

    document.addEventListener('pointerdown', function (event) {
        lastPointerType = event.pointerType || 'mouse';
    }, true);

    document.addEventListener('pointerover', function (event) {
        if (event.pointerType === 'touch') {
            return;
        }
        var group = groupOf(event.target);
        if (group) {
            show(group, event.clientX, event.clientY);
        }
    });

    document.addEventListener('pointermove', function (event) {
        if (event.pointerType === 'touch' || !active) {
            return;
        }
        place(event.clientX, event.clientY);
    });

    document.addEventListener('pointerout', function (event) {
        if (event.pointerType === 'touch') {
            return;
        }
        var group = groupOf(event.target);
        if (group && !(event.relatedTarget && group.contains(event.relatedTarget))) {
            hide();
        }
    });

    // Da tastiera il tooltip sta sopra il mese, non segue il puntatore.
    document.addEventListener('focusin', function (event) {
        var group = groupOf(event.target);
        if (!group) {
            return;
        }
        var box = group.getBoundingClientRect();
        show(group, box.left + box.width / 2, box.top + 24);
    });

    document.addEventListener('focusout', function (event) {
        if (groupOf(event.target)) {
            hide();
        }
    });

    document.addEventListener('click', function (event) {
        var legend = event.target.closest ? event.target.closest('.ip-chart-legend-item[data-series]') : null;
        if (legend) {
            toggleSeries(legend);

            return;
        }

        var group = groupOf(event.target);
        if (!group) {
            if (shownByTouch) {
                hide();
                shownByTouch = null;
            }

            return;
        }

        // Al tocco il primo tap mostra i valori, il secondo apre: senza, non
        // ci sarebbe modo di leggerli senza cambiare pagina.
        if (lastPointerType === 'touch' && group.hasAttribute('data-href') && shownByTouch !== group) {
            var box = group.getBoundingClientRect();
            show(group, box.left + box.width / 2, box.top + 24);
            shownByTouch = group;

            return;
        }

        shownByTouch = null;
        open(group);
    });

    function toggleSeries(item) {
        var body = item.closest('.ip-chart-body');
        var svg = body ? body.querySelector('svg.ip-chart') : null;
        if (!svg) {
            return;
        }
        var name = item.getAttribute('data-series');
        var off = item.getAttribute('aria-pressed') === 'true';

        item.setAttribute('aria-pressed', off ? 'false' : 'true');
        svg.classList.toggle('ip-hide-' + name, off);
    }

    document.addEventListener('keydown', function (event) {
        var legend = event.target.closest ? event.target.closest('.ip-chart-legend-item[data-series]') : null;
        if (legend && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            toggleSeries(legend);

            return;
        }

        if (event.key === 'Escape') {
            hide();

            return;
        }

        var group = groupOf(event.target);
        if (!group) {
            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            if (group.hasAttribute('data-href')) {
                event.preventDefault();
                open(group);
            }

            return;
        }

        // Frecce: da un mese all'altro dentro lo stesso grafico.
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            var svg = group.closest('svg');
            var list = svg ? Array.prototype.slice.call(svg.querySelectorAll(SELECTOR)) : [];
            var next = list[list.indexOf(group) + (event.key === 'ArrowRight' ? 1 : -1)];
            if (next) {
                event.preventDefault();
                next.focus();
            }
        }
    });

    // Cambio pagina: un tooltip rimasto aperto non deve restare sopra la nuova.
    document.addEventListener('ip:page-ready', hide);
    window.addEventListener('scroll', hide, { passive: true });
})();
