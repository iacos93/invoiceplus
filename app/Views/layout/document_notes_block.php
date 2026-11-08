<?php
/**
 * Blocco condiviso "Note" per la modifica di fatture e preventivi.
 *
 * Stessa impostura delle due schermate: a sinistra le note del documento
 * (invoice_terms per la fattura, notes per il preventivo), a destra la nota
 * cliente (sempre la stessa, su clients/ajax/save_client_note). Prima il
 * preventivo mostrava una sola colonna "Note" mentre la fattura ne mostrava
 * due, dunque le due pagine davano l'idea di due moduli diversi.
 *
 * Parametri attesi:
 *  $doc_id       int    id del documento
 *  $doc_id_field string nome del campo hidden ("invoice_id"/"quote_id")
 *  $note_action  string URL di salvataggio del documento (invoices/ajax/save /
 *                       quotes/ajax/save)
 *  $note_name    string nome del campo note documento ("invoice_terms"/"notes")
 *  $note_label   string etichetta del campo note documento
 *  $note_value   string valore corrente
 *  $client_id    int    id cliente (per la nota cliente)
 *  $doc_form_id  string id del form testata (invoice_details_form /
 *                       quote_details_form). Se indicato, il campo note fa
 *                       parte di quel form invece di averne uno suo.
 */
// $this->include(view, [...]) in CodeIgniter 4 non estrae il secondo
// parametro come variabili di vista (e' riservato alle render options):
// va letto da renderVars['options'], stesso trucco di layout/headerbar.php.
$_dn         = (array) ($this->renderVars['options'] ?? []);
$doc_id      = $_dn['doc_id']      ?? ($doc_id ?? null);
$doc_id_field = $_dn['doc_id_field'] ?? ($doc_id_field ?? '');
$note_action = $_dn['note_action'] ?? ($note_action ?? '');
$note_name   = $_dn['note_name']   ?? ($note_name ?? '');
$note_label  = $_dn['note_label']  ?? ($note_label ?? '');
$note_value  = $_dn['note_value']  ?? ($note_value ?? '');
$client_id   = $_dn['client_id']   ?? ($client_id ?? null);
$step_number = $_dn['step_number'] ?? ($step_number ?? 6);
$doc_form_id = $_dn['doc_form_id'] ?? ($doc_form_id ?? null);
?>
<section class="ip-doc-section">
    <div class="ip-doc-section__head">
        <span class="ip-doc-step"><?= (int) ($step_number ?? 6) ?></span>
        <h2 class="ip-doc-section__title"><?= trans('notes', '', 'Note') ?></h2>
    </div>
    <div class="ip-doc-section__body">
        <div class="ip-doc-client">
            <div>
                <?php if ($doc_form_id): ?>
                    <?php
                    // Le note del documento appartengono al form testata
                    // (attributo form=, la sezione sta fuori dal suo <form>):
                    // con un form a parte il Salva in testata le ignorava e
                    // il Salva qui sotto ignorava il resto della pagina — chi
                    // modificava entrambi ne perdeva una metà. Stesso difetto
                    // già tolto al blocco della fattura elettronica.
                    ?>
                    <label class="ip-doc-label" for="doc_note"><?= esc($note_label) ?></label>
                    <textarea name="<?= esc($note_name) ?>" id="doc_note" rows="3" form="<?= esc($doc_form_id, 'attr') ?>"
                              class="form-control"><?= esc($note_value) ?></textarea>
                    <div class="ip-doc-newrow">
                        <button type="submit" form="<?= esc($doc_form_id, 'attr') ?>" class="btn btn-sm btn-secondary">
                            <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('save', '', 'Salva') ?>
                        </button>
                    </div>
                <?php else: ?>
                <form method="POST" action="<?= site_url($note_action) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="<?= esc($doc_id_field) ?>" value="<?= (int) $doc_id ?>">
                    <label class="ip-doc-label" for="doc_note"><?= esc($note_label) ?></label>
                    <textarea name="<?= esc($note_name) ?>" id="doc_note" rows="3"
                              class="form-control"><?= esc($note_value) ?></textarea>
                    <div class="ip-doc-newrow">
                        <button type="submit" class="btn btn-sm btn-secondary">
                            <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('save', '', 'Salva') ?>
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <div>
                <form method="POST" action="<?= site_url('clients/ajax/save_client_note') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="client_id" value="<?= (int) $client_id ?>">
                    <label class="ip-doc-label" for="client_note"><?= trans('client_note', '', 'Nota cliente') ?></label>
                    <textarea name="client_note" id="client_note" rows="3" class="form-control"></textarea>
                    <div class="ip-doc-newrow">
                        <button type="submit" class="btn btn-sm btn-secondary">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_note', '', 'Aggiungi nota') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
