<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('import_data')); ?>
<?php /* Una sola uscita: qui c'era anche un "Annulla" che portava allo storico,
         la stessa destinazione del link qui sopra e del pulsante in fondo al
         form. Tre comandi per la stessa scelta su una pagina sola. */
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('imports_exports') . '"><i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . esc(trans('imports_exports', '', 'Importa / Esporta')) . '</a>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('import_data'), 'actions' => $actions]) ?>

<div id="content" class="ip-import-page">
    <?php /* La pagina è un flusso: i passi numerati coincidono con l'ordine in cui
             il controller elabora i file (clienti → fatture → righe → pagamenti).
             Prima i passi "scarica / carica / importa" stavano in una riga a parte,
             scollegati dalle card, e l'utente doveva ricostruire da solo quale file
             dipendesse da quale. Ora la dipendenza è scritta sul passo stesso. */ ?>
    <div class="ip-import-intro">
        <p class="ip-import-intro__eyebrow">Importa dati · CSV</p>
        <h2 class="ip-import-intro__title">Parti dal modello, non dal foglio che hai.</h2>
        <p class="ip-import-intro__desc">
            Per ogni archivio scarica il modello, compilalo e caricalo nel suo passo. Puoi importare anche un solo file.</p>
        <p class="ip-import-intro__desc">
            Come separatore va bene il punto e virgola o la virgola, e la codifica viene riconosciuta da sola.
            Date in formato <span class="ip-mono-inline">gg/mm/aaaa</span> e importi con la virgola decimale vengono convertiti automaticamente.
        </p>
    </div>

    <form method="POST" action="<?= site_url('import/form') ?>" enctype="multipart/form-data" id="ip-import-form">
        <?= csrf_field() ?>

        <?php
        $cardMeta = [
            'clients.csv'       => ['icon' => 'fa-solid fa-users',        'class' => 'ip-import-card--clients',  'label' => 'Clienti',       'hint' => 'Anagrafiche: nome, P.IVA, contatti',                  'needs' => ''],
            'invoices.csv'      => ['icon' => 'fa-regular fa-file-lines', 'class' => 'ip-import-card--invoices', 'label' => 'Fatture',       'hint' => 'Testate fattura: numero, cliente, date, stato',       'needs' => 'Si aggancia ai clienti per nome: caricali prima o assicurati che esistano già.'],
            'invoice_items.csv' => ['icon' => 'fa-solid fa-list',         'class' => 'ip-import-card--items',    'label' => 'Righe fattura', 'hint' => 'Righe fattura: descrizione, quantità, prezzo, IVA',   'needs' => 'Si aggancia alla fattura per numero: le testate devono essere già dentro.'],
            'payments.csv'      => ['icon' => 'fa-solid fa-money-bill',   'class' => 'ip-import-card--payments', 'label' => 'Pagamenti',     'hint' => 'Incassi: data, fattura, importo, metodo',             'needs' => 'Si aggancia alla fattura per numero: le testate devono essere già dentro.'],
        ];
        ?>

        <div class="ip-import-flow" role="list">
            <?php foreach ($files as $i => $file):
                $name = $file['name'];
                $meta = $cardMeta[$name] ?? ['icon' => 'fa-regular fa-file', 'class' => '', 'label' => $name, 'hint' => 'CSV', 'needs' => ''];
                $fid  = $file['field'];
            ?>
            <section role="listitem" aria-label="Passo <?= $i + 1 ?>: <?= esc($meta['label'], 'attr') ?>" class="ip-import-card <?= esc($meta['class']) ?><?= $file['exists'] ? ' is-ready' : '' ?>" data-import-card>
                <span class="ip-import-card__n" aria-hidden="true"><?= $i + 1 ?></span>

                <div class="ip-import-card__head">
                    <div class="ip-import-card__left">
                        <div class="ip-import-card__icon" aria-hidden="true"><i class="fa <?= esc($meta['icon']) ?>"></i></div>
                        <div class="ip-import-card__titles">
                            <h3 class="ip-import-card__title">
                                <?= esc($meta['label']) ?>
                                <span class="ip-import-card__file"><?= esc($name) ?></span>
                            </h3>
                            <p class="ip-import-card__hint"><?= esc($meta['hint']) ?></p>
                        </div>
                    </div>
                    <?php /* Il badge riflette lo stato *della selezione*, non solo del
                             disco: lo aggiorna lo script quando si sceglie un file o si
                             toglie la spunta "usa file già presente". */ ?>
                    <span class="ip-import-badge" data-import-badge aria-live="polite"></span>
                </div>

                <div class="ip-import-card__body">
                    <div class="ip-import-card__tracciato">
                        <a class="ip-import-template" href="<?= site_url('import/template/' . $name) ?>">
                            <i class="fa fa-solid fa-download" aria-hidden="true"></i> <?= esc(trans('download_template', '', 'Scarica il modello')) ?>
                        </a>
                        <details class="ip-import-headers">
                            <summary><?= esc(trans('expected_headers', '', 'Intestazioni attese')) ?> (<?= count($file['headers']) ?>)</summary>
                            <p><?= esc(implode('; ', $file['headers'])) ?></p>
                        </details>
                    </div>

                    <?php if ($meta['needs'] !== ''): ?>
                        <p class="ip-import-card__needs"><i class="fa fa-solid fa-info-circle" aria-hidden="true"></i> <?= esc($meta['needs']) ?></p>
                    <?php endif; ?>

                    <?php if ($file['exists']): ?>
                    <label class="ip-import-check">
                        <input type="checkbox" name="files[]" value="<?= esc($name) ?>" checked data-import-existing>
                        <span>
                            <?= trans('import_use_existing', '', 'Usa il file già caricato') ?>
                            <span class="ip-import-card__filemeta">
                                <?= esc(number_format($file['size'] / 1024, 1, ',', '.')) ?> KB<?php if ($file['mtime']): ?> · <?= esc(date('d/m/Y H:i', $file['mtime'])) ?><?php endif; ?>
                            </span>
                        </span>
                    </label>
                    <?php endif; ?>

                    <label class="ip-import-drop" for="<?= esc($fid, 'attr') ?>">
                        <span class="ip-import-drop__icon"><i class="fa fa-solid fa-cloud-upload" aria-hidden="true"></i></span>
                        <span class="ip-import-drop__text">
                            <span class="ip-import-drop__title">Trascina <span><?= esc($name) ?></span> qui o <u>sfoglia</u></span>
                            <span class="ip-import-drop__sub">Solo .csv<?= $file['exists'] ? ' · sostituisce il file già caricato' : '' ?></span>
                        </span>
                        <input type="file" name="<?= esc($fid, 'attr') ?>" id="<?= esc($fid, 'attr') ?>" accept=".csv" data-file-name="<?= esc($name, 'attr') ?>">
                    </label>

                    <div class="ip-import-chosen" id="chosen_<?= esc($fid, 'attr') ?>" hidden>
                        <i class="fa fa-regular fa-file-text ip-import-chosen__icon" aria-hidden="true"></i>
                        <span class="ip-import-chosen__name"></span>
                        <span class="ip-import-chosen__meta"></span>
                        <button type="button" class="ip-import-chosen__clear" aria-label="<?= esc(trans('remove', '', 'Rimuovi')) ?>"><i class="fa fa-solid fa-times" aria-hidden="true"></i></button>
                    </div>
                </div>
            </section>
            <?php endforeach; ?>
        </div>

        <?php /* Barra finale non più sticky: sovrapposta alle card copriva proprio
                 l'ultima riga (la zona di caricamento dei pagamenti). In fondo al
                 flusso è il passo conclusivo, con il riepilogo di cosa entrerà. */ ?>
        <div class="ip-import-actions">
            <span class="ip-import-card__n ip-import-card__n--final" aria-hidden="true"><i class="fa fa-solid fa-check"></i></span>
            <div class="ip-import-actions__summary">
                <p class="ip-import-actions__title">Importa</p>
                <p class="ip-import-actions__hint" id="ip-import-summary" aria-live="polite">
                    <?= esc(trans('import_action_hint', '', 'Carica almeno un file o lascia selezionato un file esistente.')) ?>
                </p>
            </div>
            <div class="ip-import-actions__buttons">
                <a href="<?= site_url('import') ?>" class="btn btn-sm btn-secondary"><?= esc(trans('cancel', '', 'Annulla')) ?></a>
                <button type="submit" name="btn_submit" class="btn btn-sm btn-success" id="ip-import-submit">
                    <i class="fa fa-solid fa-upload fa-margin" aria-hidden="true"></i> <?= trans('import', '', 'Importa') ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
(function(){
  var form = document.getElementById('ip-import-form');
  if(!form) return;
  var summary = document.getElementById('ip-import-summary');
  var submit  = document.getElementById('ip-import-submit');
  var cards   = form.querySelectorAll('[data-import-card]');

  function fmtSize(bytes){
    if(bytes < 1024) return bytes + ' B';
    if(bytes < 1024*1024) return (bytes/1024).toFixed(1).replace('.', ',') + ' KB';
    return (bytes/1024/1024).toFixed(1).replace('.', ',') + ' MB';
  }

  // Stato di un passo: "nuovo" (file appena scelto), "esistente" (spunta sul file
  // già su disco) o "saltato". Il riepilogo in fondo elenca i passi in ordine di
  // elaborazione, così l'utente vede prima di premere cosa entrerà e in che sequenza.
  function stateOf(card){
    var input = card.querySelector('input[type="file"]');
    var chk   = card.querySelector('[data-import-existing]');
    if(input.files && input.files[0]) return 'new';
    if(chk && chk.checked) return 'existing';
    return 'skip';
  }
  function refresh(){
    var picked = [];
    cards.forEach(function(card){
      var st = stateOf(card);
      var badge = card.querySelector('[data-import-badge]');
      card.classList.toggle('is-active', st !== 'skip');
      badge.className = 'ip-import-badge ' + (st === 'skip' ? 'is-missing' : 'is-available');
      badge.textContent = st === 'new' ? 'Nuovo file' : (st === 'existing' ? 'File già caricato' : 'Saltato');
      if(st !== 'skip') picked.push(card.querySelector('input[type="file"]').dataset.fileName);
    });
    submit.disabled = picked.length === 0;
    summary.textContent = picked.length === 0
      ? 'Nessun file selezionato: carica almeno un file o spunta un file già caricato.'
      : 'Verranno elaborati in quest’ordine: ' + picked.join(' → ') + '.';
  }

  cards.forEach(function(card){
    var input = card.querySelector('input[type="file"]');
    var drop  = input.closest('.ip-import-drop');
    var chosen = document.getElementById('chosen_' + input.id);
    var nameEl = chosen.querySelector('.ip-import-chosen__name');
    var metaEl = chosen.querySelector('.ip-import-chosen__meta');
    var chk = card.querySelector('[data-import-existing]');

    function showChosen(file){
      nameEl.textContent = file.name;
      metaEl.textContent = '· ' + fmtSize(file.size);
      chosen.hidden = false;
      chosen.classList.add('is-visible');
      // Un file nuovo sostituisce quello su disco: la spunta "usa esistente"
      // non avrebbe più senso e la togliamo per non lasciare due fonti.
      if(chk) chk.checked = false;
    }
    function clearChosen(){
      input.value = '';
      chosen.hidden = true; chosen.classList.remove('is-visible');
      nameEl.textContent = ''; metaEl.textContent = '';
      drop.classList.remove('is-dragover');
    }
    input.addEventListener('change', function(){
      if(input.files && input.files[0]) showChosen(input.files[0]); else clearChosen();
      refresh();
    });
    chosen.querySelector('.ip-import-chosen__clear').addEventListener('click', function(){ clearChosen(); refresh(); });
    if(chk) chk.addEventListener('change', refresh);

    ['dragenter','dragover'].forEach(function(ev){
      drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('is-dragover'); });
    });
    ['dragleave','drop'].forEach(function(ev){
      drop.addEventListener(ev, function(e){
        e.preventDefault();
        if(ev === 'drop' && e.dataTransfer && e.dataTransfer.files[0]){
          input.files = e.dataTransfer.files;
          input.dispatchEvent(new Event('change', {bubbles:true}));
        }
        // tolta dopo un tick per non sfarfallare passando sopra gli elementi figli
        setTimeout(function(){ drop.classList.remove('is-dragover'); }, 80);
      });
    });
  });
  refresh();
})();
</script>

<?= $this->endSection() ?>
