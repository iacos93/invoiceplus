<?php
/**
 * Blocco condiviso "① Cliente" per la modifica di fatture e preventivi.
 *
 * Prima la ① era diversa nei due schermi: il preventivo mostrava solo
 * indirizzo e telefono/email mancavano, la fattura non aveva il controllo
 * "Cambia cliente" (che invece il preventivo esponeva). Così lo stesso
 * documento sembrava gestito in due programmi diversi. Ora entrambi
 * renderizzano da qui — stesso intestatario, stessa anagrafica completa
 * (indirizzo + recapiti) e stessa anteprima totali — e si differenziano
 * solo per i dati che passano (le righe di totali, che sono proprie di
 * ciascun documento) e per l'URL di cambio cliente.
 *
 * Parametri attesi:
 *  $doc_id              int     id del documento (invoice_id / quote_id)
 *  $doc_id_field        string  nome del campo hidden ("invoice_id"/"quote_id")
 *  $entity              array   riga documento; porta i campi client_* (dalla join)
 *  $clients             array   lista clienti per il select "Cambia cliente"
 *  $change_client_action string URL del cambio cliente (invoices/ajax/change_client
 *                                  o quotes/ajax/change_client)
 *  $preview_title       string  titolo del box totali ("Totali" / "Totale")
 *  $totals              array   righe del box totali: ['label'=>, 'value'=>,
 *                                  'is_total'=>bool]
 */
// $this->include(view, [...]) in CodeIgniter 4 non estrae il secondo
// parametro come variabili di vista (e' riservato alle render options):
// va letto da renderVars['options'], stesso trucco di layout/headerbar.php.
$_dc                  = (array) ($this->renderVars['options'] ?? []);
$doc_id               = $_dc['doc_id']               ?? ($doc_id ?? null);
$doc_id_field         = $_dc['doc_id_field']         ?? ($doc_id_field ?? '');
$entity               = $_dc['entity']               ?? ($entity ?? []);
$clients              = $_dc['clients']              ?? ($clients ?? []);
$change_client_action = $_dc['change_client_action'] ?? ($change_client_action ?? '');
$preview_title        = $_dc['preview_title']        ?? ($preview_title ?? '');
$totals               = $_dc['totals']               ?? ($totals ?? []);
?>
<section class="ip-doc-section">
    <div class="ip-doc-section__head">
        <span class="ip-doc-step">1</span>
        <h2 class="ip-doc-section__title"><?= trans('client', '', 'Cliente') ?></h2>
    </div>
    <div class="ip-doc-section__body">
        <div class="ip-doc-client">
            <div class="ip-doc-client__field">
                <p class="ip-doc-client__name">
                    <a href="<?= site_url('clients/view/' . (int) $entity['client_id']) ?>">
                        <?= esc(format_client($entity)) ?>
                    </a>
                </p>
                <p class="ip-doc-client__address">
                    <?php
                    $lines = array_filter([
                        $entity['client_address_1'] ?? '',
                        $entity['client_address_2'] ?? '',
                        trim(($entity['client_zip'] ?? '') . ' ' . ($entity['client_city'] ?? '') . ' ' . ($entity['client_state'] ?? '')),
                        $entity['client_country'] ?? '',
                    ]);
                    echo esc(implode(' · ', $lines));
                    ?>
                </p>
                <?php if (!empty($entity['client_phone']) || !empty($entity['client_email'])): ?>
                    <p class="ip-doc-client__meta">
                        <?= esc(trim(implode(' · ', array_filter([
                            $entity['client_phone'] ?? '',
                            $entity['client_email'] ?? '',
                        ])))) ?>
                    </p>
                <?php endif; ?>

                <form method="POST" action="<?= site_url($change_client_action) ?>" class="ip-doc-newrow">
                    <?= csrf_field() ?>
                    <input type="hidden" name="<?= esc($doc_id_field) ?>" value="<?= (int) $doc_id ?>">
                    <select name="client_id" class="form-control input-sm simple-select" aria-label="<?= esc(trans('client', '', 'Cliente')) ?>">
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['client_id'] ?>"
                                <?= (int) $entity['client_id'] === (int) $client['client_id'] ? 'selected' : '' ?>>
                                <?= esc(format_client($client)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-secondary">
                        <?= trans('change_client', '', 'Cambia cliente') ?>
                    </button>
                </form>
            </div>

            <div class="ip-doc-client__preview">
                <p class="ip-doc-client__name"><?= esc($preview_title) ?></p>
                <dl class="ip-preview__totals">
                    <?php foreach ($totals as $row): ?>
                        <dt class="<?= !empty($row['is_total']) ? 'is-total' : '' ?>"><?= esc($row['label']) ?></dt>
                        <dd class="<?= !empty($row['is_total']) ? 'is-total' : '' ?>"><?= esc($row['value']) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    </div>
</section>
