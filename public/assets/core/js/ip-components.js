/* =============================================================================
   ip-components.js — componenti di input propri di InvoicePlane
   -----------------------------------------------------------------------------
   Sostituisce Select2 e bootstrap-datepicker con due componenti scritti in casa,
   senza dipendenze (niente jQuery, niente CSS di terze parti da sovrascrivere).
   Motivo: i temi vendor portavano una palette propria e selettori a specificità
   alta, che andavano continuamente ri-sovrascritti per restare coerenti col
   design system. Qui il markup è nostro, quindi la palette è quella dei token.

   Espone window.IPComponents.init(root) per (ri)inizializzare porzioni di DOM
   aggiunte dinamicamente (modali, tabelle ricaricate via AJAX).

     <select class="simple-select" data-placeholder="…" data-allow-clear>
     <input type="text" class="datepicker" data-date-format="dd/mm/yyyy">

   Entrambi mantengono in vita l'elemento nativo (select/input) e vi scrivono i
   valori, così i form si inviano come prima e gli handler 'change' esistenti
   (anche quelli agganciati con jQuery) continuano a funzionare.
   ========================================================================== */

(function (window, document) {
    'use strict';

    var LOCALE = document.documentElement.getAttribute('lang') || 'it';
    var uid = 0;

    function nextId(prefix) {
        uid += 1;
        return prefix + '-' + uid;
    }

    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    // Etichette dei componenti costruiti da JS. IPFile la chiamava senza che
    // fosse definita da nessuna parte: il ReferenceError interrompeva build()
    // subito dopo aver spostato l'input dentro il wrapper, quindi il controllo
    // custom non veniva mai inserito e in pagina restava l'input file nativo —
    // l'unico controllo dell'app che non seguiva il tema.
    // Le stringhe lato PHP passano da trans(); qui la traduzione, se serve,
    // arriva da un dizionario opzionale window.IP_LANG (chiave -> testo) e in
    // sua assenza vale il fallback italiano passato dal chiamante.
    function trans(key, fallback) {
        var dict = window.IP_LANG;
        if (dict && typeof dict[key] === 'string' && dict[key] !== '') {
            return dict[key];
        }
        return fallback;
    }

    // I pannelli sono in position:fixed e vengono agganciati al body, così non
    // vengono tagliati da contenitori con overflow (es. .table-responsive).
    // Dentro una modale Bootstrap restano però figli della modale: il focus
    // trap di BS5 rimanda il focus al suo interno e il campo di ricerca del
    // pannello diventerebbe inutilizzabile.
    function portalRoot(anchor) {
        return anchor.closest('.modal') || document.body;
    }

    function placePanel(panel, anchor) {
        var rect = anchor.getBoundingClientRect();
        var gap = 4;
        panel.style.minWidth = rect.width + 'px';
        panel.style.left = rect.left + 'px';

        // Misura reale del pannello per decidere se aprirlo verso l'alto.
        panel.style.top = '-9999px';
        panel.style.display = '';
        var height = panel.offsetHeight;
        var below = window.innerHeight - rect.bottom - gap;

        if (below < height && rect.top > below) {
            panel.style.top = Math.max(gap, rect.top - height - gap) + 'px';
            panel.classList.add('is-above');
        } else {
            panel.style.top = (rect.bottom + gap) + 'px';
            panel.classList.remove('is-above');
        }

        // Non uscire dal bordo destro della finestra.
        var width = panel.offsetWidth;
        if (rect.left + width > window.innerWidth - gap) {
            panel.style.left = Math.max(gap, window.innerWidth - width - gap) + 'px';
        }
    }

    /* =========================================================================
       IPSelect — select con ricerca
       ====================================================================== */

    // Sotto questa soglia il campo di ricerca è più rumore che aiuto.
    var SEARCH_THRESHOLD = 8;

    function IPSelect(select) {
        this.select = select;
        this.open = false;
        this.activeIndex = -1;
        this.build();
    }

    IPSelect.prototype.build = function () {
        var self = this;
        var select = this.select;

        this.placeholder = select.getAttribute('data-placeholder') ||
            (select.options[0] && select.options[0].value === '' ? select.options[0].textContent.trim() : '');
        this.allowClear = select.hasAttribute('data-allow-clear');

        this.wrap = make('span', 'ip-select');
        if (select.classList.contains('input-sm') || select.classList.contains('form-control-sm')) {
            this.wrap.classList.add('ip-select--sm');
        }

        this.control = make('button', 'ip-select__control');
        this.control.type = 'button';
        this.control.id = nextId('ip-select');
        this.control.setAttribute('aria-haspopup', 'listbox');
        this.control.setAttribute('aria-expanded', 'false');
        if (select.disabled) { this.control.disabled = true; }

        // Il nome accessibile del comando arriva dalla <label for>, se c'e'.
        // Altrimenti si recupera da aria-label/title della select nativa: quella
        // qui sotto diventa aria-hidden, quindi senza questo travaso il comando
        // resterebbe senza nome (era il caso del selettore azienda e del filtro
        // anno nella topbar, presenti su ogni pagina).
        var labelledBy = this.labelFor(select);
        if (labelledBy) {
            this.control.setAttribute('aria-labelledby', labelledBy);
        } else {
            var fallbackName = select.getAttribute('aria-label') || select.getAttribute('title');
            if (fallbackName) { this.control.setAttribute('aria-label', fallbackName); }
        }

        this.valueEl = make('span', 'ip-select__value');
        this.control.appendChild(this.valueEl);

        if (this.allowClear) {
            this.clearBtn = make('span', 'ip-select__clear');
            this.clearBtn.setAttribute('role', 'button');
            this.clearBtn.setAttribute('aria-label', 'Clear');
            this.clearBtn.innerHTML = '<i class="fa fa-solid fa-xmark" aria-hidden="true"></i>';
            this.control.appendChild(this.clearBtn);
            this.clearBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                self.commit('');
            });
        }

        var arrow = make('span', 'ip-select__arrow');
        arrow.innerHTML = '<i class="fa fa-solid fa-chevron-down" aria-hidden="true"></i>';
        this.control.appendChild(arrow);

        select.parentNode.insertBefore(this.wrap, select);
        this.wrap.appendChild(this.control);
        this.wrap.appendChild(select);
        select.classList.add('ip-select__native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        this.control.addEventListener('click', function () { self.toggle(); });
        this.control.addEventListener('keydown', function (e) { self.onControlKey(e); });

        select.addEventListener('change', function () { self.render(); });

        var form = select.form;
        if (form) {
            form.addEventListener('reset', function () {
                window.setTimeout(function () { self.render(); }, 0);
            });
        }

        // Assegnazioni diirette (select.value = x / select.selectedIndex = n)
        // non emettono 'change': senza questo l'etichetta visibile resterebbe
        // indietro rispetto al valore reale. Succede davvero, es. in
        // payments/form.php che precompila il metodo di pagamento.
        this.trackProgrammaticChanges();

        this.render();
    };

    // Ridefinisce value/selectedIndex sull'istanza per intercettare le scritture
    // diirette, delegando comunque al comportamento nativo del prototipo.
    IPSelect.prototype.trackProgrammaticChanges = function () {
        var self = this;

        ['value', 'selectedIndex'].forEach(function (prop) {
            var native = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
            if (!native || !native.set) { return; }

            Object.defineProperty(self.select, prop, {
                configurable: true,
                enumerable: true,
                get: function () { return native.get.call(this); },
                set: function (v) {
                    native.set.call(this, v);
                    self.render();
                }
            });
        });
    };

    IPSelect.prototype.labelFor = function (select) {
        if (!select.id) { return null; }
        var label = document.querySelector('label[for="' + select.id + '"]');
        if (!label) { return null; }
        if (!label.id) { label.id = nextId('ip-label'); }
        return label.id;
    };

    IPSelect.prototype.render = function () {
        var opt = this.select.options[this.select.selectedIndex];
        var text = opt ? opt.textContent.trim() : '';
        var empty = !opt || opt.value === '';

        // Un select senza opzioni renderebbe un riquadro vuoto e apparentemente
        // rotto: lo mostriamo disabilitato con un'etichetta esplicita.
        if (this.select.options.length === 0) {
            this.valueEl.textContent = this.select.getAttribute('data-empty-text') || '—';
            this.valueEl.classList.add('is-placeholder');
            this.control.disabled = true;
            if (this.clearBtn) { this.clearBtn.hidden = true; }
            return;
        }

        this.control.disabled = this.select.disabled;
        this.valueEl.textContent = empty ? (this.placeholder || '') : text;
        this.valueEl.classList.toggle('is-placeholder', empty);
        this.wrap.classList.toggle('has-value', !empty);
        if (this.clearBtn) { this.clearBtn.hidden = empty; }
    };

    IPSelect.prototype.buildPanel = function () {
        var self = this;
        var options = Array.prototype.slice.call(this.select.options);

        this.panel = make('div', 'ip-select-panel');
        this.panel.id = nextId('ip-select-panel');

        this.searchable = options.length >= SEARCH_THRESHOLD;
        if (this.searchable) {
            var searchWrap = make('div', 'ip-select-panel__search');
            this.search = make('input', 'ip-select-panel__input');
            this.search.type = 'text';
            this.search.autocomplete = 'off';
            this.search.spellcheck = false;
            this.search.setAttribute('aria-label', this.placeholder || 'Search');
            searchWrap.appendChild(this.search);
            this.panel.appendChild(searchWrap);
            this.search.addEventListener('input', function () { self.filter(self.search.value); });
            this.search.addEventListener('keydown', function (e) { self.onPanelKey(e); });
        }

        this.list = make('div', 'ip-select-panel__list');
        this.list.setAttribute('role', 'listbox');
        this.panel.appendChild(this.list);

        this.empty = make('div', 'ip-select-panel__empty', '—');
        this.empty.hidden = true;
        this.panel.appendChild(this.empty);

        this.items = [];
        var self2 = this;

        function addOption(option) {
            // Il primo <option value=""> fa da placeholder: non è una scelta.
            if (option.value === '' && self2.placeholder && !self2.allowClear) { return; }

            var item = make('div', 'ip-select-option', option.textContent.trim());
            item.id = nextId('ip-opt');
            item.setAttribute('role', 'option');
            item.dataset.value = option.value;
            if (option.disabled) { item.classList.add('is-disabled'); }

            // Riferimento all'<option> di origine: il codice delle view filtra
            // le voci impostando option.hidden (es. payments/form.php nasconde
            // le fatture non appartenenti al cliente scelto), e il pannello
            // deve rispettarlo a ogni apertura.
            item.ipOption = option;

            item.addEventListener('click', function () {
                if (option.disabled) { return; }
                self2.commit(option.value);
            });
            item.addEventListener('mousemove', function () {
                self2.setActive(self2.items.indexOf(item));
            });

            self2.list.appendChild(item);
            self2.items.push(item);
        }

        Array.prototype.forEach.call(this.select.children, function (child) {
            if (child.tagName === 'OPTGROUP') {
                var group = make('div', 'ip-select-group', child.label);
                self2.list.appendChild(group);
                Array.prototype.forEach.call(child.children, addOption);
            } else if (child.tagName === 'OPTION') {
                addOption(child);
            }
        });

        this.control.setAttribute('aria-controls', this.panel.id);
        portalRoot(this.control).appendChild(this.panel);
    };

    IPSelect.prototype.filter = function (query) {
        var q = (query || '').trim().toLowerCase();
        var visible = 0;
        var firstVisible = -1;

        this.items.forEach(function (item, i) {
            // Un'opzione nascosta a monte (option.hidden) resta esclusa
            // qualunque cosa si scriva nel campo di ricerca.
            var allowed = !(item.ipOption && item.ipOption.hidden);
            var match = allowed && (!q || item.textContent.toLowerCase().indexOf(q) !== -1);
            item.hidden = !match;
            if (match) {
                visible += 1;
                if (firstVisible === -1) { firstVisible = i; }
            }
        });

        // Nasconde le intestazioni di gruppo rimaste senza opzioni visibili.
        Array.prototype.forEach.call(this.list.querySelectorAll('.ip-select-group'), function (group) {
            var has = false;
            var node = group.nextElementSibling;
            while (node && !node.classList.contains('ip-select-group')) {
                if (!node.hidden) { has = true; break; }
                node = node.nextElementSibling;
            }
            group.hidden = !has;
        });

        this.empty.hidden = visible !== 0;
        this.setActive(firstVisible);
    };

    IPSelect.prototype.setActive = function (index) {
        var self = this;
        this.items.forEach(function (item, i) {
            var on = i === index;
            item.classList.toggle('is-active', on);
            if (on) { self.control.setAttribute('aria-activedescendant', item.id); }
        });
        this.activeIndex = index;

        if (index >= 0 && this.items[index]) {
            var item = this.items[index];
            var top = item.offsetTop;
            var bottom = top + item.offsetHeight;
            if (top < this.list.scrollTop) {
                this.list.scrollTop = top;
            } else if (bottom > this.list.scrollTop + this.list.clientHeight) {
                this.list.scrollTop = bottom - this.list.clientHeight;
            }
        }
    };

    IPSelect.prototype.step = function (delta) {
        var count = this.items.length;
        if (!count) { return; }
        var i = this.activeIndex;

        for (var n = 0; n < count; n++) {
            i = (i + delta + count) % count;
            if (!this.items[i].hidden && !this.items[i].classList.contains('is-disabled')) {
                this.setActive(i);
                return;
            }
        }
    };

    IPSelect.prototype.toggle = function () {
        if (this.open) { this.close(); } else { this.show(); }
    };

    IPSelect.prototype.show = function () {
        if (this.open || this.control.disabled) { return; }
        if (!this.panel) { this.buildPanel(); }

        this.open = true;
        openSelect = this;
        this.panel.classList.add('is-open');
        this.control.setAttribute('aria-expanded', 'true');
        this.wrap.classList.add('is-open');

        if (this.search) { this.search.value = ''; }
        this.filter('');

        var selected = this.select.value;
        var index = -1;
        this.items.forEach(function (item, i) {
            var on = item.dataset.value === selected && selected !== '';
            item.classList.toggle('is-selected', on);
            item.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) { index = i; }
        });
        if (index !== -1) { this.setActive(index); }

        placePanel(this.panel, this.control);
        if (this.search) { this.search.focus(); }
    };

    IPSelect.prototype.close = function () {
        if (!this.open) { return; }
        this.open = false;
        if (openSelect === this) { openSelect = null; }
        this.panel.classList.remove('is-open');
        this.panel.style.display = 'none';
        this.control.setAttribute('aria-expanded', 'false');
        this.control.removeAttribute('aria-activedescendant');
        this.wrap.classList.remove('is-open');
    };

    IPSelect.prototype.commit = function (value) {
        var self = this;
        this.select.value = value;
        this.render();
        this.close();
        this.control.focus();
        this.select.dispatchEvent(new Event('input', { bubbles: true }));
        this.select.dispatchEvent(new Event('change', { bubbles: true }));

        // Un handler di 'change' può a sua volta riazzerare il valore
        // (es. .tag-select, che inserisce il tag e si resetta): rileggiamo il
        // valore reale al giro successivo per non mostrare un'etichetta stale.
        window.setTimeout(function () { self.render(); }, 0);
    };

    IPSelect.prototype.onControlKey = function (e) {
        switch (e.key) {
            case 'ArrowDown':
            case 'ArrowUp':
            case 'Enter':
            case ' ':
                e.preventDefault();
                this.show();
                break;
            case 'Escape':
                this.close();
                break;
        }
    };

    IPSelect.prototype.onPanelKey = function (e) {
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.step(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.step(-1);
                break;
            case 'Enter':
                e.preventDefault();
                if (this.activeIndex >= 0) {
                    this.commit(this.items[this.activeIndex].dataset.value);
                }
                break;
            case 'Escape':
                e.preventDefault();
                this.close();
                this.control.focus();
                break;
            case 'Tab':
                this.close();
                break;
        }
    };

    var openSelect = null;

    /* =========================================================================
       IPDatepicker — calendario
       ====================================================================== */

    var MONTHS = [];
    var MONTHS_SHORT = [];
    var DOW_SHORT = [];

    (function buildNames() {
        var monthFmt = new Intl.DateTimeFormat(LOCALE, { month: 'long' });
        var monthShortFmt = new Intl.DateTimeFormat(LOCALE, { month: 'short' });
        var dowFmt = new Intl.DateTimeFormat(LOCALE, { weekday: 'short' });

        for (var m = 0; m < 12; m++) {
            var d = new Date(2021, m, 1);
            MONTHS.push(monthFmt.format(d));
            MONTHS_SHORT.push(monthShortFmt.format(d).replace('.', ''));
        }
        // 2021-08-01 è una domenica: base per l'ordine dei giorni.
        for (var w = 0; w < 7; w++) {
            DOW_SHORT.push(dowFmt.format(new Date(2021, 7, 1 + w)).replace('.', ''));
        }
    })();

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    // Tokenizza il formato (dd/mm/yyyy, d.m.yyyy, dd M,yyyy, …) una volta sola.
    function parseFormat(format) {
        var tokens = [];
        var re = /(yyyy|yy|mm|m|dd|d|MM|M)/g;
        var last = 0;
        var match;

        while ((match = re.exec(format)) !== null) {
            if (match.index > last) {
                tokens.push({ literal: format.slice(last, match.index) });
            }
            tokens.push({ token: match[0] });
            last = match.index + match[0].length;
        }
        if (last < format.length) { tokens.push({ literal: format.slice(last) }); }
        return tokens;
    }

    function formatDate(date, tokens) {
        return tokens.map(function (part) {
            if (part.literal !== undefined) { return part.literal; }
            switch (part.token) {
                case 'yyyy': return String(date.getFullYear());
                case 'yy':   return pad(date.getFullYear() % 100);
                case 'mm':   return pad(date.getMonth() + 1);
                case 'm':    return String(date.getMonth() + 1);
                case 'dd':   return pad(date.getDate());
                case 'd':    return String(date.getDate());
                case 'MM':   return MONTHS[date.getMonth()];
                case 'M':    return MONTHS_SHORT[date.getMonth()];
            }
            return '';
        }).join('');
    }

    function parseDate(value, tokens) {
        if (!value) { return null; }

        // Costruisce una regex dai token per estrarre i pezzi nell'ordine giusto.
        var groups = [];
        var pattern = tokens.map(function (part) {
            if (part.literal !== undefined) {
                return part.literal.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }
            groups.push(part.token);
            switch (part.token) {
                case 'yyyy': return '(\\d{4})';
                case 'yy':   return '(\\d{2})';
                case 'mm':
                case 'm':    return '(\\d{1,2})';
                case 'dd':
                case 'd':    return '(\\d{1,2})';
                case 'MM':
                case 'M':    return '([\\p{L}]+)';
            }
            return '';
        }).join('');

        var re;
        try {
            re = new RegExp('^' + pattern + '$', 'u');
        } catch (err) {
            re = new RegExp('^' + pattern.replace('[\\p{L}]', '[a-zA-Z\\u00C0-\\u024F]') + '$');
        }

        var match = re.exec(value.trim());
        if (!match) { return null; }

        var year = null, month = null, day = null;
        groups.forEach(function (token, i) {
            var raw = match[i + 1];
            switch (token) {
                case 'yyyy': year = parseInt(raw, 10); break;
                case 'yy':   year = 2000 + parseInt(raw, 10); break;
                case 'mm':
                case 'm':    month = parseInt(raw, 10) - 1; break;
                case 'dd':
                case 'd':    day = parseInt(raw, 10); break;
                case 'MM':
                case 'M':
                    var needle = raw.toLowerCase().replace('.', '');
                    [MONTHS, MONTHS_SHORT].some(function (list) {
                        var idx = list.findIndex(function (name) {
                            return name.toLowerCase() === needle;
                        });
                        if (idx !== -1) { month = idx; return true; }
                        return false;
                    });
                    break;
            }
        });

        if (year === null || month === null || day === null) { return null; }
        var date = new Date(year, month, day);
        // Scarta date impossibili tipo 31/02 (JS le farebbe traboccare a marzo).
        if (date.getFullYear() !== year || date.getMonth() !== month || date.getDate() !== day) {
            return null;
        }
        return date;
    }

    function sameDay(a, b) {
        return a && b &&
            a.getFullYear() === b.getFullYear() &&
            a.getMonth() === b.getMonth() &&
            a.getDate() === b.getDate();
    }

    function IPDatepicker(input) {
        this.input = input;
        this.tokens = parseFormat(input.getAttribute('data-date-format') || window.IP_DATE_FORMAT || 'dd/mm/yyyy');
        this.weekStart = parseInt(input.getAttribute('data-week-start') || window.IP_WEEK_START || 1, 10) % 7;
        this.open = false;
        this.build();
    }

    IPDatepicker.prototype.build = function () {
        var self = this;
        this.input.setAttribute('autocomplete', 'off');
        this.input.classList.add('ip-datepicker-input');

        this.input.addEventListener('focus', function () { self.show(); });
        this.input.addEventListener('click', function () { self.show(); });
        this.input.addEventListener('keydown', function (e) { self.onKey(e); });
        this.input.addEventListener('input', function () {
            var parsed = parseDate(self.input.value, self.tokens);
            if (parsed) {
                self.view = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
                if (self.open) { self.renderGrid(); }
            }
        });
    };

    IPDatepicker.prototype.value = function () {
        return parseDate(this.input.value, this.tokens);
    };

    IPDatepicker.prototype.buildPanel = function () {
        var self = this;
        this.panel = make('div', 'ip-dp');

        var head = make('div', 'ip-dp__head');
        this.prev = make('button', 'ip-dp__nav');
        this.prev.type = 'button';
        this.prev.innerHTML = '<i class="fa fa-solid fa-chevron-left" aria-hidden="true"></i>';
        this.title = make('button', 'ip-dp__title');
        this.title.type = 'button';
        this.next = make('button', 'ip-dp__nav');
        this.next.type = 'button';
        this.next.innerHTML = '<i class="fa fa-solid fa-chevron-right" aria-hidden="true"></i>';
        head.appendChild(this.prev);
        head.appendChild(this.title);
        head.appendChild(this.next);
        this.panel.appendChild(head);

        this.dow = make('div', 'ip-dp__dow');
        this.panel.appendChild(this.dow);

        this.grid = make('div', 'ip-dp__grid');
        this.panel.appendChild(this.grid);

        this.monthGrid = make('div', 'ip-dp__months');
        this.monthGrid.hidden = true;
        this.panel.appendChild(this.monthGrid);

        var foot = make('div', 'ip-dp__foot');
        this.todayBtn = make('button', 'ip-dp__today', 'Oggi');
        this.todayBtn.type = 'button';
        foot.appendChild(this.todayBtn);
        this.panel.appendChild(foot);

        this.prev.addEventListener('click', function () { self.shift(-1); });
        this.next.addEventListener('click', function () { self.shift(1); });
        this.title.addEventListener('click', function () { self.toggleMonths(); });
        this.todayBtn.addEventListener('click', function () { self.commit(new Date()); });

        // mousedown invece di click: evita che il blur dell'input chiuda il
        // pannello prima che il click sul giorno venga registrato.
        this.panel.addEventListener('mousedown', function (e) { e.preventDefault(); });

        portalRoot(this.input).appendChild(this.panel);
    };

    IPDatepicker.prototype.renderDow = function () {
        this.dow.innerHTML = '';
        for (var i = 0; i < 7; i++) {
            this.dow.appendChild(make('span', null, DOW_SHORT[(this.weekStart + i) % 7]));
        }
    };

    IPDatepicker.prototype.renderGrid = function () {
        var self = this;
        var view = this.view;
        this.title.textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();

        var first = new Date(view.getFullYear(), view.getMonth(), 1);
        var offset = (first.getDay() - this.weekStart + 7) % 7;
        var start = new Date(first);
        start.setDate(1 - offset);

        var selected = this.value();
        var today = new Date();

        this.grid.innerHTML = '';
        for (var i = 0; i < 42; i++) {
            var day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
            var cell = make('button', 'ip-dp__day', String(day.getDate()));
            cell.type = 'button';

            if (day.getMonth() !== view.getMonth()) { cell.classList.add('is-outside'); }
            if (sameDay(day, today)) { cell.classList.add('is-today'); }
            if (sameDay(day, selected)) { cell.classList.add('is-selected'); }

            (function (d) {
                cell.addEventListener('click', function () { self.commit(d); });
            })(day);

            this.grid.appendChild(cell);
        }
    };

    IPDatepicker.prototype.renderMonths = function () {
        var self = this;
        this.monthGrid.innerHTML = '';
        MONTHS_SHORT.forEach(function (name, index) {
            var cell = make('button', 'ip-dp__month', name);
            cell.type = 'button';
            if (index === self.view.getMonth()) { cell.classList.add('is-selected'); }
            cell.addEventListener('click', function () {
                self.view = new Date(self.view.getFullYear(), index, 1);
                self.toggleMonths(false);
            });
            self.monthGrid.appendChild(cell);
        });
    };

    IPDatepicker.prototype.toggleMonths = function (force) {
        var show = force === undefined ? this.monthGrid.hidden : force;
        if (show) { this.renderMonths(); }
        this.monthGrid.hidden = !show;
        this.dow.hidden = show;
        this.grid.hidden = show;
        if (!show) { this.renderGrid(); }
        this.title.textContent = show ? String(this.view.getFullYear())
                                      : MONTHS[this.view.getMonth()] + ' ' + this.view.getFullYear();
    };

    IPDatepicker.prototype.shift = function (delta) {
        if (!this.monthGrid.hidden) {
            this.view = new Date(this.view.getFullYear() + delta, this.view.getMonth(), 1);
            this.renderMonths();
            this.title.textContent = String(this.view.getFullYear());
            return;
        }
        this.view = new Date(this.view.getFullYear(), this.view.getMonth() + delta, 1);
        this.renderGrid();
    };

    IPDatepicker.prototype.show = function () {
        if (this.open || this.input.readOnly || this.input.disabled) { return; }
        if (!this.panel) { this.buildPanel(); }

        this.open = true;
        openDatepicker = this;

        var current = this.value() || new Date();
        this.view = new Date(current.getFullYear(), current.getMonth(), 1);

        this.monthGrid.hidden = true;
        this.dow.hidden = false;
        this.grid.hidden = false;
        this.renderDow();
        this.renderGrid();

        this.panel.classList.add('is-open');
        placePanel(this.panel, this.input);
    };

    IPDatepicker.prototype.close = function () {
        if (!this.open) { return; }
        this.open = false;
        if (openDatepicker === this) { openDatepicker = null; }
        this.panel.classList.remove('is-open');
        this.panel.style.display = 'none';
    };

    IPDatepicker.prototype.commit = function (date) {
        this.input.value = formatDate(date, this.tokens);
        this.close();
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    IPDatepicker.prototype.onKey = function (e) {
        if (e.key === 'Escape') { this.close(); return; }
        if (e.key === 'Enter' && this.open) { this.close(); return; }
        if (!this.open) { return; }

        var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
        if (step === undefined) { return; }

        e.preventDefault();
        var base = this.value() || new Date();
        this.commitSoft(new Date(base.getFullYear(), base.getMonth(), base.getDate() + step));
    };

    // Aggiorna il campo restando aperti (navigazione da tastiera).
    IPDatepicker.prototype.commitSoft = function (date) {
        this.input.value = formatDate(date, this.tokens);
        this.view = new Date(date.getFullYear(), date.getMonth(), 1);
        this.renderGrid();
    };

    var openDatepicker = null;

    /* =========================================================================
       Chiusura globale + riposizionamento
       ====================================================================== */

    document.addEventListener('mousedown', function (e) {
        if (openSelect && !openSelect.panel.contains(e.target) && !openSelect.wrap.contains(e.target)) {
            openSelect.close();
        }
        if (openDatepicker && !openDatepicker.panel.contains(e.target) && e.target !== openDatepicker.input) {
            openDatepicker.close();
        }
    });

    document.addEventListener('focusin', function (e) {
        if (openDatepicker && !openDatepicker.panel.contains(e.target) && e.target !== openDatepicker.input) {
            openDatepicker.close();
        }
    });

    window.addEventListener('resize', function () {
        if (openSelect) { placePanel(openSelect.panel, openSelect.control); }
        if (openDatepicker) { placePanel(openDatepicker.panel, openDatepicker.input); }
    });

    window.addEventListener('scroll', function () {
        if (openSelect) { placePanel(openSelect.panel, openSelect.control); }
        if (openDatepicker) { placePanel(openDatepicker.panel, openDatepicker.input); }
    }, true);

    /* =========================================================================
       Quick range — scorciatoie di periodo per i due campi data
       -------------------------------------------------------------------------
       Le pagine di report chiedono sempre lo stesso intervallo ("questo mese",
       "anno scorso") e farlo a mano vuol dire aprire due calendari e contare i
       giorni di febbraio. I bottoni scrivono nei campi veri, nel formato del
       datepicker, e lanciano 'change': per il form e per il resto del codice
       e' come se le date fossero state digitate.

         <div class="ip-quick-range" data-quick-range
              data-from="#from_date" data-to="#to_date">
           <button type="button" class="ip-quick-range__btn" data-range="this_month">…</button>
         </div>

       Il bottone corrispondente all'intervallo gia' nei campi resta acceso, cosi'
       dopo l'esecuzione del report si vede quale periodo si sta guardando.
       ====================================================================== */

    function startOfDay(date) {
        return new Date(date.getFullYear(), date.getMonth(), date.getDate());
    }

    // Ogni voce restituisce [dal, al] a partire da oggi. Il giorno 0 del mese
    // successivo e' l'ultimo del mese corrente: evita la tabella dei giorni
    // per mese e gli anni bisestili.
    var QUICK_RANGES = {
        today: function (now) {
            return [now, now];
        },
        this_month: function (now) {
            return [new Date(now.getFullYear(), now.getMonth(), 1),
                    new Date(now.getFullYear(), now.getMonth() + 1, 0)];
        },
        last_month: function (now) {
            return [new Date(now.getFullYear(), now.getMonth() - 1, 1),
                    new Date(now.getFullYear(), now.getMonth(), 0)];
        },
        this_quarter: function (now) {
            var q = Math.floor(now.getMonth() / 3);
            return [new Date(now.getFullYear(), q * 3, 1),
                    new Date(now.getFullYear(), q * 3 + 3, 0)];
        },
        this_year: function (now) {
            return [new Date(now.getFullYear(), 0, 1),
                    new Date(now.getFullYear(), 11, 31)];
        },
        last_year: function (now) {
            return [new Date(now.getFullYear() - 1, 0, 1),
                    new Date(now.getFullYear() - 1, 11, 31)];
        }
    };

    function initQuickRange(root) {
        var groups = root.querySelectorAll('[data-quick-range]');

        Array.prototype.forEach.call(groups, function (group) {
            if (group.dataset.ipReady) { return; }
            group.dataset.ipReady = '1';

            var from = document.querySelector(group.getAttribute('data-from') || '');
            var to   = document.querySelector(group.getAttribute('data-to') || '');
            if (!from || !to) { return; }

            // Il formato e' quello del campo: i due input sono gli stessi che
            // pilota IPDatepicker, quindi la scrittura deve usare i suoi token.
            var tokens = parseFormat(from.getAttribute('data-date-format')
                || window.IP_DATE_FORMAT || 'dd/mm/yyyy');

            var buttons = group.querySelectorAll('[data-range]');

            function write(input, date) {
                input.value = formatDate(date, tokens);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function sync() {
                var current = [parseDate(from.value, tokens), parseDate(to.value, tokens)];
                var now = startOfDay(new Date());

                Array.prototype.forEach.call(buttons, function (btn) {
                    var build = QUICK_RANGES[btn.getAttribute('data-range')];
                    var match = false;

                    if (build && current[0] && current[1]) {
                        var range = build(now);
                        match = sameDay(range[0], current[0]) && sameDay(range[1], current[1]);
                    }

                    btn.classList.toggle('is-active', match);
                    btn.setAttribute('aria-pressed', match ? 'true' : 'false');
                });
            }

            Array.prototype.forEach.call(buttons, function (btn) {
                btn.setAttribute('aria-pressed', 'false');
                btn.addEventListener('click', function () {
                    var build = QUICK_RANGES[btn.getAttribute('data-range')];
                    if (!build) { return; }

                    var range = build(startOfDay(new Date()));
                    write(from, range[0]);
                    write(to, range[1]);
                    sync();

                    // data-autosubmit (report): il periodo rapido e' gia' una
                    // scelta completa, chiedere anche "Esegui report" era un
                    // secondo clic per niente. Si usa il pulsante btn_submit,
                    // non quello del PDF, che scaricherebbe un file.
                    if (group.hasAttribute('data-autosubmit') && from.form) {
                        var run = from.form.querySelector('[type="submit"][name="btn_submit"]');
                        if (from.form.requestSubmit) {
                            from.form.requestSubmit(run || undefined);
                        } else if (run) {
                            run.click();
                        }
                    }
                });
            });

            // Chi digita una data a mano esce dall'intervallo predefinito:
            // lasciare acceso il bottone direbbe il falso.
            from.addEventListener('change', sync);
            to.addEventListener('change', sync);

            sync();
        });
    }

    /* =========================================================================
       Tabs — memoria della scheda aperta
       -------------------------------------------------------------------------
       Bootstrap gestisce lo scambio dei pannelli e le frecce da tastiera, non
       il fatto che la scheda aperta sopravviva al giro successivo. Nelle impostazioni il salvataggio
       ricarica la pagina, quindi si tornava sempre su "Generali" anche dopo
       aver modificato un campo in "Email"; nella scheda cliente bastava un
       refresh per perdere la tab su cui si stava lavorando.

       Priorita': hash dell'URL (link condivisibile / bottone Indietro) e poi
       l'ultima scheda usata, in sessionStorage per non lasciare tracce oltre
       la sessione del browser. Chiave = attributo data-ip-tabs della barra.
       ====================================================================== */

    function tabKey(list) {
        return 'ip_tab_' + (list.getAttribute('data-ip-tabs') || 'default');
    }

    function readStoredTab(list) {
        try { return window.sessionStorage.getItem(tabKey(list)); } catch (e) { return null; }
    }

    function storeTab(list, hash) {
        try { window.sessionStorage.setItem(tabKey(list), hash); } catch (e) { /* storage non disponibile */ }
    }

    function showTab(link) {
        if (!window.bootstrap || !window.bootstrap.Tab) { return false; }
        window.bootstrap.Tab.getOrCreateInstance(link).show();
        return true;
    }

    function initTabs(root) {
        var lists = root.querySelectorAll('[data-ip-tabs]');

        Array.prototype.forEach.call(lists, function (list) {
            if (list.dataset.ipReady) { return; }
            list.dataset.ipReady = '1';

            var links = list.querySelectorAll('[data-bs-toggle="tab"]');

            // Ripristino: prima l'hash dell'URL, poi l'ultima scheda usata.
            // Si prova l'hash e, se non appartiene a QUESTA barra, si ricade
            // sul valore salvato: su una pagina con due gruppi di schede
            // l'hash puo' riferirsi solo a uno dei due, e l'altro deve
            // comunque poter ripristinare il suo.
            var candidates = [window.location.hash, readStoredTab(list)];
            for (var c = 0; c < candidates.length; c++) {
                if (!candidates[c]) { continue; }
                var target = list.querySelector('[data-bs-toggle="tab"][href="' + candidates[c] + '"]');
                if (target) {
                    if (!target.classList.contains('active')) { showTab(target); }
                    break;
                }
            }

            Array.prototype.forEach.call(links, function (link) {
                link.addEventListener('shown.bs.tab', function () {
                    var hash = link.getAttribute('href');
                    storeTab(list, hash);

                    // replaceState e non location.hash: cambiare l'hash farebbe
                    // saltare lo scroll in cima al pannello a ogni cambio scheda.
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', window.location.pathname + window.location.search + hash);
                    }
                });
            });
        });
    }

    /* =========================================================================
       IPFile — input file personalizzato
       ====================================================================== */

    function IPFile(input) {
        this.input = input;
        this.build();
    }

    IPFile.prototype.build = function () {
        var self = this;
        var input = this.input;

        this.wrap = make('span', 'ip-file');
        input.parentNode.insertBefore(this.wrap, input);
        this.wrap.appendChild(input);
        input.classList.add('ip-file__native');

        this.control = make('div', 'ip-file__control');
        this.name = make('span', 'ip-file__name');
        this.name.textContent = trans('no_file_selected', 'Nessun file selezionato');
        this.btn = make('button', 'ip-file__btn', trans('choose_file', 'Scegli file'));
        this.btn.type = 'button';
        this.clearBtn = make('button', 'ip-file__clear');
        this.clearBtn.innerHTML = '<i class="fa fa-solid fa-xmark" aria-hidden="true"></i>';
        this.clearBtn.setAttribute('aria-label', trans('remove_file', 'Rimuovi file'));
        this.clearBtn.type = 'button';
        this.clearBtn.title = trans('remove_file', 'Rimuovi file');
        this.clearBtn.style.display = 'none';

        this.control.appendChild(this.name);
        this.control.appendChild(this.btn);
        this.control.appendChild(this.clearBtn);
        this.wrap.appendChild(this.control);

        // Help text se presente nel data-help
        if (input.dataset.help) {
            var help = make('div', 'ip-file__help');
            help.textContent = input.dataset.help;
            this.wrap.appendChild(help);
        }

        this.update();

        this.btn.addEventListener('click', function () { input.click(); });
        this.clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            input.value = '';
            self.update();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        input.addEventListener('change', function () { self.update(); });

        // Trascinamento: nelle pagine di import si caricano ZIP e decine di
        // XML presi da una cartella, e trascinarli è più naturale del
        // selettore. I file passano all'input nativo, quindi il form li invia
        // come se fossero stati scelti dal pulsante.
        ['dragenter', 'dragover'].forEach(function (type) {
            self.control.addEventListener(type, function (e) {
                if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') === -1) { return; }
                e.preventDefault();
                self.wrap.classList.add('is-dragover');
            });
        });
        ['dragleave', 'dragend', 'drop'].forEach(function (type) {
            self.control.addEventListener(type, function () { self.wrap.classList.remove('is-dragover'); });
        });
        this.control.addEventListener('drop', function (e) {
            if (!e.dataTransfer || !e.dataTransfer.files.length) { return; }
            e.preventDefault();
            try {
                var dt = new DataTransfer();
                var files = input.multiple ? e.dataTransfer.files : [e.dataTransfer.files[0]];
                Array.prototype.forEach.call(files, function (f) { dt.items.add(f); });
                input.files = dt.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (err) { /* browser senza DataTransfer costruibile: resta il pulsante */ }
        });

        input.addEventListener('focus', function () { self.wrap.classList.add('is-focused'); });
        input.addEventListener('blur', function () { self.wrap.classList.remove('is-focused'); });
        this.control.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                input.click();
            }
        });
    };

    IPFile.prototype.update = function () {
        var files = this.input.files || [];
        var file = files[0];
        // Più file (import XML): il nome del primo da solo faceva pensare che
        // gli altri non fossero stati presi. Conteggio, ed elenco nel title.
        this.name.removeAttribute('title');
        if (files.length > 1) {
            this.name.textContent = files.length + ' ' + trans('files_selected', 'file selezionati');
            this.name.title = Array.prototype.map.call(files, function (f) { return f.name; }).join('\n');
            this.name.classList.add('has-file');
            this.btn.textContent = trans('replace_file', 'Sostituisci');
            this.clearBtn.style.display = 'inline-flex';
        } else if (file) {
            this.name.textContent = file.name;
            this.name.classList.add('has-file');
            this.btn.textContent = trans('replace_file', 'Sostituisci');
            this.clearBtn.style.display = 'inline-flex';
        } else {
            this.name.textContent = trans('no_file_selected', 'Nessun file selezionato');
            this.name.classList.remove('has-file');
            this.btn.textContent = trans('choose_file', 'Scegli file');
            this.clearBtn.style.display = 'none';
        }
    };

    /* =========================================================================
       Init
       ====================================================================== */

    function init(root) {
        root = root || document;

        initTabs(root);
        initQuickRange(root);

        var selects = root.querySelectorAll('select.simple-select, select.tag-select');
        Array.prototype.forEach.call(selects, function (select) {
            if (select.dataset.ipReady) { return; }
            select.dataset.ipReady = '1';
            new IPSelect(select);
        });

        var dates = root.querySelectorAll('input.datepicker');
        Array.prototype.forEach.call(dates, function (input) {
            if (input.dataset.ipReady) { return; }
            input.dataset.ipReady = '1';
            new IPDatepicker(input);
        });

        var files = root.querySelectorAll('input[type="file"].ip-file-input');
        Array.prototype.forEach.call(files, function (input) {
            if (input.dataset.ipReady) { return; }
            input.dataset.ipReady = '1';
            new IPFile(input);
        });
    }

    window.IPComponents = { init: init, Select: IPSelect, Datepicker: IPDatepicker };

    function start() {
        init();

        // Contenuti iniettati dopo il load (modali, tabelle ricaricate via AJAX)
        // vengono inizializzati senza che il codice chiamante debba saperlo.
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes.length) { init(); return; }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    // Lo script è incluso nel <head>: a quel punto document.body non esiste
    // ancora, quindi sia l'init sia l'observer aspettano il DOM.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

})(window, document);
