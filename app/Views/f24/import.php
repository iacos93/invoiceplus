<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('import_f24_receipts', '', 'Importa quietanze F24')); ?>
<?php
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('f24') . '">' . trans('back', '', 'Torna agli F24') . '</a>'
    . '<button type="submit" form="f24_import_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-upload" aria-hidden="true"></i> ' . trans('import', '', 'Importa') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('import_f24_receipts', '', 'Importa quietanze F24'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form">
    <?php if ($results !== null): ?>
        <?php
        /* L'esito resta sulla pagina invece di finire in un toast: chi
           importa dieci quietanze deve poter vedere quali sono rimaste fuori
           e perché, per allegarle a mano alla delega giusta. */
        $labels = [
            'matched'   => ['text-bg-success', trans('f24_receipt_matched', '', 'Abbinata')],
            'created'   => ['text-bg-info', trans('f24_receipt_created', '', 'F24 creato')],
            'duplicate' => ['text-bg-secondary', trans('f24_receipt_duplicate', '', 'Già importata')],
            'unmatched' => ['text-bg-warning', trans('f24_receipt_unmatched', '', 'Da abbinare a mano')],
        ];
        ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-check" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title">
                    <?= trans('import_result', '', 'Esito') ?>:
                    <?= (int) $matched ?> / <?= count($results) ?> <?= trans('f24_receipts_matched', '', 'quietanze registrate') ?>
                </h2>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr>
                        <th scope="col"><?= trans('file', '', 'File') ?></th>
                        <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
                        <th scope="col"><?= trans('paid_on', '', 'Versato il') ?></th>
                        <th scope="col"><?= trans('protocol', '', 'Protocollo') ?></th>
                        <th scope="col"><?= trans('status', '', 'Esito') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td><?= esc($r['name']) ?></td>
                            <td class="amount"><?= isset($r['data']['amount']) ? format_currency($r['data']['amount']) : '—' ?></td>
                            <td><?= !empty($r['data']['date']) ? esc(date_from_mysql($r['data']['date'])) : '—' ?></td>
                            <td><?= esc($r['data']['protocol'] ?? '—') ?></td>
                            <td>
                                <span class="badge ip-status <?= $labels[$r['status']][0] ?>"><?= $labels[$r['status']][1] ?></span>
                                <?php if (in_array($r['status'], ['matched', 'created'], true)): ?>
                                    <a href="<?= site_url('f24/form/' . (int) $r['f24_id']) ?>"><?= trans('view', '', 'Apri F24') ?></a>
                                <?php elseif (!empty($r['reason'])): ?>
                                    <small class="text-muted"><?= esc($r['reason']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($results)): ?>
                        <tr><td colspan="5" class="text-muted"><?= trans('no_files', '', 'Nessun file caricato.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <form method="POST" action="<?= site_url('f24/import') ?>" enctype="multipart/form-data" id="f24_import_form">
        <?= csrf_field() ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('select_files', '', 'Quietanze da importare') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-field ip-import-drop">
                    <label class="ip-doc-label" for="receipts"><?= trans('f24_receipts_pdf', '', 'Quietanze (PDF)') ?></label>
                    <input type="file" name="receipts[]" id="receipts" class="ip-file-input" multiple
                           accept=".pdf,application/pdf" aria-describedby="receipts_help">
                    <p class="ip-field-help" id="receipts_help"><?= trans('f24_import_help', '', 'Trascina qui le quietanze scaricate da Entratel/Fisconline o dall\'home banking. Ogni quietanza viene allegata all\'F24 con lo stesso importo ancora senza quietanza (pagato o da pagare); se non ce n\'è uno, l\'F24 viene creato dalla quietanza. Quelle già importate (stesso protocollo) vengono saltate.') ?></p>
                </div>
            </div>
        </section>
    </form>
</div>

<?= $this->endSection() ?>
