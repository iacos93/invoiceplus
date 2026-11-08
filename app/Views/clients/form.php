<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
// Titolo come nel form fornitore: dice se si sta creando o modificando. Prima
// restava "Scheda cliente" in entrambi i casi, quindi il pulsante "Nuovo
// cliente" apriva una pagina che non confermava di essere una creazione.
$formTitle = !empty($client_id)
    ? trans('edit_client', '', 'Modifica cliente')
    : trans('new_client', '', 'Nuovo cliente');
$this->setVar('pageTitle', $formTitle);
?>

<form method="post" action="<?= site_url('clients/form/' . ($client_id ?? '')) ?>" class="ip-client-form" autocomplete="on" novalidate>

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => $formTitle, 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="row">
            <div class="col-12">

                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-regular fa-user" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('personal_information') ?></span>
                        </div>

                        <label for="client_active" class="form-badge ip-card-header-badge">
                            <?= trans('active_client') ?>
                            <input id="client_active" name="client_active" type="checkbox" value="1"
                                <?php
                                $active = $model->formValue('client_active');
                                if ($active == 1 || !is_numeric($active)) {
                                    echo 'checked="checked"';
                                }
                                ?>>
                        </label>
                    </div>

                    <div class="card-body">

                        <?php
                        // La tipologia apre la scheda perche' decide come si
                        // legge tutto il resto: una persona fisica ha nome e
                        // cognome separati, un'azienda una ragione sociale
                        // sola, e una PA un codice ufficio invece del codice
                        // destinatario. Nel tracciato XML sono rami diversi.
                        $client_type = old('client_type', $model->formValue('client_type')) ?: 'company';
                        $client_types = [
                            'company' => [trans('client_type_company', '', 'Azienda'), 'fa-regular fa-building',
                                          trans('client_type_company_hint', '', 'Ditta o società con ragione sociale')],
                            'person'  => [trans('client_type_person', '', 'Persona fisica'), 'fa-regular fa-user',
                                          trans('client_type_person_hint', '', 'Privato o professionista, nome e cognome')],
                            'pa'      => [trans('client_type_pa', '', 'Pubblica amministrazione'), 'fa-solid fa-building-columns',
                                          trans('client_type_pa_hint', '', 'Ente pubblico: serve il Codice Univoco Ufficio')],
                        ];
                        ?>
                        <div class="ip-doc-field">
                            <span class="ip-doc-label" id="ip-client-type-label"><?= trans('client_type', '', 'Tipologia cliente') ?></span>
                            <div class="ip-type-choice" id="ip-client-type"
                                 role="radiogroup" aria-labelledby="ip-client-type-label">
                                <?php foreach ($client_types as $value => [$label, $icon, $hint]): ?>
                                    <label class="ip-type-choice__option <?= $client_type === $value ? 'is-active' : '' ?>">
                                        <input type="radio" name="client_type" value="<?= $value ?>"
                                            <?= $client_type === $value ? 'checked' : '' ?>>
                                        <i class="fa <?= $icon ?>" aria-hidden="true"></i>
                                        <span class="ip-type-choice__label"><?= esc($label) ?></span>
                                        <span class="ip-type-choice__hint"><?= esc($hint) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="ip-doc-duo">
                            <div class="ip-doc-field">
                                <label class="ip-doc-label" for="client_name"
                                       data-label-company="<?= esc(trans('company_name', '', 'Ragione sociale'), 'attr') ?>"
                                       data-label-person="<?= esc(trans('first_name', '', 'Nome'), 'attr') ?>"
                                       data-label-pa="<?= esc(trans('office_name', '', 'Denominazione ente'), 'attr') ?>">
                                    <?= trans('client_name') ?>
                                </label>
                                <input id="client_name" name="client_name" type="text" class="form-control" required
                                       autofocus
                                       value="<?= esc(old('client_name', $model->formValue('client_name'))) ?>">
                                <?php if (!empty($errors['client_name'])): ?>
                                    <span class="ip-field-error"><?= esc($errors['client_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php // Il cognome esiste solo per le persone fisiche: affiancato al nome. ?>
                            <div class="ip-doc-field<?= $client_type === 'person' ? ' is-visible' : '' ?>" data-client-type-only="person">
                                <label class="ip-doc-label" for="client_surname">
                                    <?= trans('last_name', '', 'Cognome') ?>
                                </label>
                                <input id="client_surname" name="client_surname" type="text" class="form-control"
                                       value="<?= esc(old('client_surname', $model->formValue('client_surname'))) ?>">
                            </div>
                        </div>

                        <div class="ip-doc-field no-margin">
                            <label class="ip-doc-label" for="client_language">
                                <?= trans('language') ?>
                            </label>
                            <select name="client_language" id="client_language" class="form-control simple-select">
                                <option value="system">
                                    <?= trans('use_system_language') ?>
                                </option>
                                <?php foreach ($languages as $code => $label): ?>
                                    <option value="<?= esc($code) ?>"
                                        <?php check_select($model->formValue('client_language'), $code) ?>>
                                        <?= esc($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <?php
        // Indirizzo, contatti e dati fiscali condividono la stessa riga su tre
        // colonne (col-md-4) su schermi ampi, impilandosi a pieno campo su
        // quelli stretti. Sono le tre "tessere" anagrafiche del cliente.
        ?>
        <div class="row">
            <div class="col-12 col-md-4">

                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-solid fa-location-dot" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('address') ?></span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_address_1"><?= trans('street_address') ?></label>
                            <input type="text" name="client_address_1" id="client_address_1" class="form-control"
                                   value="<?= esc(old('client_address_1', $model->formValue('client_address_1'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_address_2"><?= trans('street_address_2') ?></label>
                            <input type="text" name="client_address_2" id="client_address_2" class="form-control"
                                   value="<?= esc(old('client_address_2', $model->formValue('client_address_2'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_city"><?= trans('city') ?></label>
                            <input type="text" name="client_city" id="client_city" class="form-control"
                                   value="<?= esc(old('client_city', $model->formValue('client_city'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_state"><?= trans('state') ?></label>
                            <input type="text" name="client_state" id="client_state" class="form-control"
                                   value="<?= esc(old('client_state', $model->formValue('client_state'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_zip"><?= trans('zip_code') ?></label>
                            <input type="text" name="client_zip" id="client_zip" class="form-control"
                                   value="<?= esc(old('client_zip', $model->formValue('client_zip'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_country"><?= trans('country') ?></label>
                            <?php $selected_country = $model->formValue('client_country') ?: get_setting('default_country'); ?>
                            <select name="client_country" id="client_country" class="form-control simple-select"
                                    data-placeholder="<?= esc(trans('country')) ?>" data-allow-clear>
                                <option value=""><?= trans('none') ?></option>
                                <?php foreach ($countries as $cldr => $country): ?>
                                    <option value="<?= esc($cldr) ?>"
                                        <?php check_select($selected_country, $cldr) ?>>
                                        <?= esc($country) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?= $this->include('clients/partial_custom_fields', ['location' => 1]) ?>
                    </div>

                </div>

            </div>
            <div class="col-12 col-md-4">

                <div class="card mb-3">

                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-regular fa-address-book" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('contact_information') ?></span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_phone"><?= trans('phone_number') ?></label>
                            <input type="text" name="client_phone" id="client_phone" class="form-control"
                                   value="<?= esc(old('client_phone', $model->formValue('client_phone'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_fax"><?= trans('fax_number') ?></label>
                            <input type="text" name="client_fax" id="client_fax" class="form-control"
                                   value="<?= esc(old('client_fax', $model->formValue('client_fax'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_mobile"><?= trans('mobile_number') ?></label>
                            <input type="text" name="client_mobile" id="client_mobile" class="form-control"
                                   value="<?= esc(old('client_mobile', $model->formValue('client_mobile'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_email"><?= trans('email_address') ?></label>
                            <input type="email" name="client_email" id="client_email" class="form-control" required
                                   value="<?= esc(old('client_email', $model->formValue('client_email'))) ?>">
                            <?php if (!empty($errors['client_email'])): ?>
                                <span class="ip-field-error"><?= esc($errors['client_email']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_web"><?= trans('web_address') ?></label>
                            <input type="text" name="client_web" id="client_web" class="form-control"
                                   value="<?= esc(old('client_web', $model->formValue('client_web'))) ?>">
                        </div>

                        <?= $this->include('clients/partial_custom_fields', ['location' => 2]) ?>
                    </div>

                </div>

            </div>
            <div class="col-12 col-md-4">

                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-regular fa-id-card" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('tax_information') ?></span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="ip-doc-field<?= in_array($client_type, ['company', 'pa'], true) ? ' is-visible' : '' ?>" data-client-type-only="company,pa">
                            <label class="ip-doc-label" for="client_vat_id"><?= trans('vat_id') ?></label>
                            <input type="text" name="client_vat_id" id="client_vat_id" class="form-control"
                                   value="<?= esc(old('client_vat_id', $model->formValue('client_vat_id'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_tax_code"><?= trans('tax_code') ?></label>
                            <input type="text" name="client_tax_code" id="client_tax_code" class="form-control"
                                   value="<?= esc(old('client_tax_code', $model->formValue('client_tax_code'))) ?>">
                        </div>

                        <?php
                        // Tre valori, non una checkbox: la legge (art. 17-ter
                        // DPR 633/72) estende lo split payment anche a
                        // societa' non PA (partecipate pubbliche, quotate),
                        // che non si riconoscono dal tipo cliente — per loro
                        // serve poterlo forzare a "Sempre" anche se il tipo è
                        // "Azienda". "Automatico" segue solo la PA, l'unico
                        // caso in cui l'obbligo discende univocamente dal tipo.
                        $splitPayment = old('client_split_payment', $model->formValue('client_split_payment')) ?: 'auto';
                        ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_split_payment">
                                <?= trans('split_payment', '', 'Scissione dei pagamenti') ?>
                            </label>
                            <select name="client_split_payment" id="client_split_payment" class="form-control simple-select">
                                <option value="auto" <?= $splitPayment === 'auto' ? 'selected' : '' ?>>
                                    <?= trans('split_payment_auto', '', 'Automatico (solo per PA)') ?>
                                </option>
                                <option value="yes" <?= $splitPayment === 'yes' ? 'selected' : '' ?>>
                                    <?= trans('split_payment_yes', '', 'Sempre') ?>
                                </option>
                                <option value="no" <?= $splitPayment === 'no' ? 'selected' : '' ?>>
                                    <?= trans('split_payment_no', '', 'Mai') ?>
                                </option>
                            </select>
                            <p class="ip-field-help">
                                <?= trans('split_payment_help', '', 'Precompila "Esigibilità IVA = Scissione dei pagamenti" a ogni nuova fattura per questo cliente. Forza "Sempre" per le società soggette per legge anche se non sono Pubblica amministrazione.') ?>
                            </p>
                        </div>

                        <?php
                        // Recapito per lo SdI. Sono alternativi: se c'e' il
                        // codice la PEC non serve, e viceversa. Lasciandoli
                        // entrambi vuoti il codice destinatario esce 0000000,
                        // che vale "recapito non noto" e vale solo per i
                        // privati — su una PA la fattura verrebbe scartata.
                        $client_type_current = old('client_type', $model->formValue('client_type')) ?: 'company';
                        ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_sdi_code"
                                   data-label-company="<?= esc(trans('sdi_code', '', 'Codice destinatario SdI'), 'attr') ?>"
                                   data-label-person="<?= esc(trans('sdi_code', '', 'Codice destinatario SdI'), 'attr') ?>"
                                   data-label-pa="<?= esc(trans('ipa_code', '', 'Codice Univoco Ufficio (IPA)'), 'attr') ?>">
                                <?= $client_type_current === 'pa'
                                    ? trans('ipa_code', '', 'Codice Univoco Ufficio (IPA)')
                                    : trans('sdi_code', '', 'Codice destinatario SdI') ?>
                            </label>
                            <input type="text" name="client_sdi_code" id="client_sdi_code" class="form-control"
                                   maxlength="7" autocomplete="off"
                                   value="<?= esc(old('client_sdi_code', $model->formValue('client_sdi_code'))) ?>">
                            <p class="ip-field-help" id="ip-sdi-help"></p>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_sdi_pec"><?= trans('sdi_pec', '', 'PEC per la fattura elettronica') ?></label>
                            <input type="email" name="client_sdi_pec" id="client_sdi_pec" class="form-control"
                                   autocomplete="off"
                                   value="<?= esc(old('client_sdi_pec', $model->formValue('client_sdi_pec'))) ?>">
                        </div>

                        <?= $this->include('clients/partial_custom_fields', ['location' => 4]) ?>
                    </div>

                </div>

            </div>
        </div>

        <?php
        // ---------- Rapporti Commerciali ----------
        // Sezione ispirata a Fatture in Cloud: preferenze di fatturazione
        // per cliente che sovrascrivono i default globali. Ogni campo
        // NULL/vuoto = "usa il default delle impostazioni generali".
        $defaultTaxRateId       = old('client_default_tax_rate_id', $model->formValue('client_default_tax_rate_id'));
        $defaultPaymentMethodId = old('client_default_payment_method_id', $model->formValue('client_default_payment_method_id'));
        $paymentDays            = old('client_payment_days', $model->formValue('client_payment_days'));
        $discountDefault        = old('client_discount_default', $model->formValue('client_discount_default'));
        $clientIban             = old('client_iban', $model->formValue('client_iban'));
        ?>
        <div class="row">
            <div class="col-12">

                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-regular fa-handshake" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('commercial_relations', '', 'Rapporti commerciali') ?></span>
                        </div>
                    </div>

                    <div class="card-body">
                        <p class="ip-field-help mb-3">
                            <?= trans('commercial_relations_help', '', 'Questi valori vengono usati come predefiniti quando crei un nuovo documento per questo cliente. Lasciando vuoto un campo si usa il default globale delle impostazioni.') ?>
                        </p>

                        <div class="row">
                            <div class="col-12 col-md-6 col-lg-3">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="client_default_tax_rate_id">
                                        <?= trans('default_tax_rate', '', 'Aliquota IVA predefinita') ?>
                                    </label>
                                    <select name="client_default_tax_rate_id" id="client_default_tax_rate_id"
                                            class="form-control simple-select" data-allow-clear
                                            data-placeholder="<?= esc(trans('use_global_default', '', 'Default globale'), 'attr') ?>">
                                        <option value=""><?= trans('use_global_default', '', 'Default globale') ?></option>
                                        <?php foreach ($tax_rates as $rate): ?>
                                            <option value="<?= esc($rate['tax_rate_id']) ?>"
                                                <?php check_select($defaultTaxRateId, $rate['tax_rate_id']) ?>>
                                                <?= esc($rate['tax_rate_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-12 col-md-6 col-lg-3">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="client_default_payment_method_id">
                                        <?= trans('default_payment_method') ?>
                                    </label>
                                    <select name="client_default_payment_method_id" id="client_default_payment_method_id"
                                            class="form-control simple-select" data-allow-clear
                                            data-placeholder="<?= esc(trans('use_global_default', '', 'Default globale'), 'attr') ?>">
                                        <option value=""><?= trans('use_global_default', '', 'Default globale') ?></option>
                                        <?php foreach ($payment_methods as $pm): ?>
                                            <option value="<?= esc($pm['payment_method_id']) ?>"
                                                <?php check_select($defaultPaymentMethodId, $pm['payment_method_id']) ?>>
                                                <?= esc($pm['payment_method_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-12 col-md-6 col-lg-3">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="client_payment_days">
                                        <?= trans('payment_days', '', 'Termini di pagamento (giorni)') ?>
                                    </label>
                                    <input type="number" name="client_payment_days" id="client_payment_days"
                                           class="form-control" min="0" step="1"
                                           placeholder="<?= esc(trans('use_global_default', '', 'Default globale'), 'attr') ?>"
                                           value="<?= esc($paymentDays) ?>">
                                </div>
                            </div>

                            <div class="col-12 col-md-6 col-lg-3">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="client_discount_default">
                                        <?= trans('default_discount', '', 'Sconto predefinito (%)') ?>
                                    </label>
                                    <input type="number" name="client_discount_default" id="client_discount_default"
                                           class="form-control" min="0" max="100" step="0.01"
                                           placeholder="<?= esc(trans('none', '', 'Nessuno'), 'attr') ?>"
                                           value="<?= esc($discountDefault) ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 col-md-6 col-lg-3">
                                <div class="ip-doc-field no-margin">
                                    <label class="ip-doc-label" for="client_iban">
                                        <?= trans('iban', '', 'IBAN') ?>
                                    </label>
                                    <input type="text" name="client_iban" id="client_iban"
                                           class="form-control" autocomplete="off"
                                           value="<?= esc($clientIban) ?>">
                                    <p class="ip-field-help">
                                        <?= trans('iban_help', '', 'Necessario per la creazione delle distinte RiBa.') ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <?php
        $has_unlocated_fields = false;
        foreach ($custom_fields as $field) {
            if ((int) ($field['custom_field_location'] ?? 0) === 0) {
                $has_unlocated_fields = true;
                break;
            }
        }
        ?>
        <?php if ($has_unlocated_fields): ?>
            <div class="row">
                <div class="col-12">

                    <div class="card mb-3">

                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-regular fa-list-alt" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('custom_fields') ?></span>
                            </div>
                        </div>

                        <div class="card-body">
                            <?= $this->include('clients/partial_custom_fields', ['location' => 0]) ?>
                        </div>

                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php // Il toggle dei campi per tipologia (radio client_type) lo gestisce
      // scripts.js globale: etichette data-label-*, campi data-client-type-only
      // e aiuto SDI. Un duplicato qui ha fatto da contrappeso allo script
      // globale, che gira dopo e vince: niente doppioni. ?>

<?= $this->endSection() ?>
