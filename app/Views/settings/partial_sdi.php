                <!-- Fattura elettronica (SdI) -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-right-left" aria-hidden="true"></i> <?= trans('sdi_settings', '', 'Fattura elettronica (SdI)') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="alert alert-info small">
                            <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                            <?= trans('sdi_settings_note', '', 'La trasmissione via PEC (gratuita) richiede la firma digitale del file prima dell\'invio e NON include la conservazione a norma (serve un conservatore accreditato). Il webservice del trasmittente assorbe firma, esiti e — se previsto dal contratto — la conservazione. Le ricevute dello SdI arrivano come email con allegato RC/NS/NE/EC/MC/DT: importate dal cron o aggiornate a mano dalla pagina fattura.') ?>
                        </div>

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[sdi_channel]">
                                        <?= trans('sdi_channel', '', 'Canale di trasmissione') ?>
                                    </label>
                                    <select name="settings[sdi_channel]" id="settings[sdi_channel]"
                                            class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                        <?php foreach (\App\Libraries\Sdi\SdiSender::CHANNELS as $key => $label): ?>
                                            <option value="<?= esc($key) ?>" <?= check_select(get_setting('sdi_channel', 'manual'), $key) ?>>
                                                <?= esc($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Gruppo PEC: visibile solo col canale PEC -->
                        <div class="sdi-group" data-channels="pec">
                            <h6 class="ip-settings-subtitle"><?= trans('sdi_pec_section', '', 'Invio via PEC') ?></h6>
                            <div class="row">
                                <div class="col-12 col-md-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_pec_recipient]">
                                            <?= trans('sdi_pec_recipient', '', 'Casella destinatario PEC SdI') ?>
                                        </label>
                                        <select name="settings[sdi_pec_recipient]" id="settings[sdi_pec_recipient]"
                                                class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                                <?php $pec = 'sdi' . str_pad((string) $i, 2, '0', STR_PAD_LEFT); ?>
                                                <option value="<?= esc($pec) ?>" <?= check_select(get_setting('sdi_pec_recipient', 'sdi01'), $pec) ?>>
                                                    <?= esc($pec) ?>@pec.fatturapa.it
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                        <p class="ip-field-help">
                                            <?= trans('sdi_pec_recipient_hint', '', 'Usata solo per il primo invio. Dopo la prima trasmissione lo SdI comunica un indirizzo PEC-SdI dedicato: inseriscilo qui sotto.') ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_pec_recipient_full]">
                                            <?= trans('sdi_pec_recipient_full', '', 'Indirizzo PEC-SdI dedicato (dopo il primo invio)') ?>
                                        </label>
                                        <input type="text" class="form-control" name="settings[sdi_pec_recipient_full]"
                                               id="settings[sdi_pec_recipient_full]"
                                               value="<?= esc(get_setting('sdi_pec_recipient_full', '')) ?>"
                                               placeholder="es. sdi01-xxxxxx@pec.fatturapa.it">
                                        <p class="ip-field-help">
                                            <?= trans('sdi_pec_recipient_full_hint', '', 'Se compilato, ha priorita\' sulla casella sopra: e\' l\'indirizzo comunicato via PEC dallo SdI dopo la prima trasmissione.') ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_pec_sender_id]">
                                            <?= trans('sdi_pec_sender_id', '', 'Casella PEC mittente (Mittenti email)') ?>
                                        </label>
                                        <select name="settings[sdi_pec_sender_id]" id="settings[sdi_pec_sender_id]"
                                                class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                            <option value="0"><?= trans('sdi_pec_sender_default', '', 'SMTP predefinito delle impostazioni') ?></option>
                                            <?php foreach ($email_senders as $sender): ?>
                                                <option value="<?= (int) $sender['sender_id'] ?>" <?= check_select(get_setting('sdi_pec_sender_id', 0), (string) $sender['sender_id']) ?>>
                                                    <?= esc($sender['sender_name'] . ' — ' . $sender['sender_email']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Gruppo API: visibile solo col canale API -->
                        <div class="sdi-group" data-channels="api">
                            <h6 class="ip-settings-subtitle"><?= trans('sdi_api_section', '', 'Invio via webservice (API)') ?></h6>
                            <div class="row">
                                <div class="col-12 col-md-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_api_endpoint]">
                                            <?= trans('sdi_api_endpoint', '', 'Endpoint API del trasmittente (URL)') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_api_endpoint]" id="settings[sdi_api_endpoint]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_api_endpoint]', get_setting('sdi_api_endpoint'))) ?>"
                                                placeholder="https://esempio.it/api/fatturapa">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_api_username]">
                                            <?= trans('sdi_api_username', '', 'Utente API') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_api_username]" id="settings[sdi_api_username]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_api_username]', get_setting('sdi_api_username'))) ?>">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_api_password]">
                                            <?= trans('sdi_api_password', '', 'Password API') ?>
                                        </label>
                                        <input type="password" name="settings[sdi_api_password]" id="settings[sdi_api_password]"
                                                class="form-control" autocomplete="new-password" value="">
                                        <input type="hidden" name="settings[sdi_api_password_field_is_password]" value="1">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Gruppo SDICoop: visibile solo col canale SDICoop -->
                        <div class="sdi-group" data-channels="sdicoop">
                            <h6 class="ip-settings-subtitle"><?= trans('sdi_coop_section', '', 'Invio via SDICoop (accreditamento diretto)') ?></h6>
                            <div class="alert alert-warning small">
                                <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
                                <?= trans('sdi_coop_warning', '', 'Richiede accreditamento SOGEI completato (accordo di servizio + test di interoperabilita\'), certificato TLS rilasciato dallo SdI, e questo server raggiungibile pubblicamente 24/7 sull\'endpoint /sdi-coop/{token} per ricevere le notifiche di esito. Il file XML deve inoltre arrivare gia\' firmato digitalmente (CAdES-BES): questo canale non firma per conto dell\'utente.') ?>
                            </div>
                            <div class="row">
                                <div class="col-12 col-md-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_coop_endpoint]">
                                            <?= trans('sdi_coop_endpoint', '', 'Endpoint SdIRiceviFile (comunicato da SOGEI)') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_coop_endpoint]" id="settings[sdi_coop_endpoint]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_coop_endpoint]', get_setting('sdi_coop_endpoint'))) ?>"
                                                placeholder="https://...sogei.it/SdIRiceviFile">
                                    </div>

                                    <div class="ip-doc-field">
                                        <?php // Il WSDL non serve piu' a runtime (richiesta MTOM costruita a mano, vedi SdiCoopChannel): al suo posto la CA del server SdI, che in collaudo non e' tra quelle di sistema. ?>
                                        <label class="ip-doc-label" for="settings[sdi_coop_ca_path]">
                                            <?= trans('sdi_coop_ca_path', '', 'Certificato CA del server SdI (facoltativo)') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_coop_ca_path]" id="settings[sdi_coop_ca_path]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_coop_ca_path]', get_setting('sdi_coop_ca_path'))) ?>"
                                                placeholder="/percorso/sicuro/fuori/dalla/webroot/ca-sdi.pem">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_coop_cert_path]">
                                            <?= trans('sdi_coop_cert_path', '', 'Percorso certificato client (PEM)') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_coop_cert_path]" id="settings[sdi_coop_cert_path]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_coop_cert_path]', get_setting('sdi_coop_cert_path'))) ?>"
                                                placeholder="/percorso/sicuro/fuori/dalla/webroot/client.pem">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_coop_key_path]">
                                            <?= trans('sdi_coop_key_path', '', 'Percorso chiave privata client (PEM)') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_coop_key_path]" id="settings[sdi_coop_key_path]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_coop_key_path]', get_setting('sdi_coop_key_path'))) ?>"
                                                placeholder="/percorso/sicuro/fuori/dalla/webroot/client.key">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_coop_key_passphrase]">
                                            <?= trans('sdi_coop_key_passphrase', '', 'Passphrase chiave privata (se presente)') ?>
                                        </label>
                                        <input type="password" name="settings[sdi_coop_key_passphrase]" id="settings[sdi_coop_key_passphrase]"
                                                class="form-control" autocomplete="new-password" value="">
                                        <input type="hidden" name="settings[sdi_coop_key_passphrase_field_is_password]" value="1">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_coop_inbound_token]">
                                            <?= trans('sdi_coop_inbound_token', '', 'Token endpoint notifiche in ingresso') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_coop_inbound_token]" id="settings[sdi_coop_inbound_token]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_coop_inbound_token]', decrypt_secret((string) get_setting('sdi_coop_inbound_token', '')))) ?>"
                                                placeholder="stringa lunga e casuale">
                                        <p class="ip-field-help">
                                            <?= trans('sdi_coop_inbound_token_hint', '', 'Va comunicato a SOGEI come URL del webservice TrasmissioneFatture: ') . base_url('sdi-coop/') ?><code><?= trans('sdi_coop_inbound_token_placeholder', '', 'TOKEN') ?></code>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Gruppo SDICoop-Ricezione: fatture di ACQUISTO in arrivo dal SdI.
                             Accreditamento SOGEI distinto da quello di trasmissione qui sopra,
                             ma agganciato allo stesso canale "sdicoop" nel selettore per
                             semplicita' (in pratica chi richiede l'uno spesso richiede anche
                             l'altro, e certificato/chiave sono condivisi). Vedi
                             SdiRicezioneEndpoint — è uno scheletro, non testato end-to-end
                             senza un accreditamento reale. -->
                        <div class="sdi-group" data-channels="sdicoop">
                            <h6 class="ip-settings-subtitle"><?= trans('sdi_ricezione_section', '', 'Ricezione fatture di acquisto via SDICoop (accreditamento separato)') ?></h6>
                            <div class="alert alert-warning small">
                                <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
                                <?= trans('sdi_ricezione_warning', '', 'Richiede un accreditamento SOGEI specifico come canale di ricezione (distinto da quello di trasmissione) e l\'endpoint pubblico /sdi-ricezione/{token} raggiungibile 24/7 per il webservice RicezioneFatture. Implementazione non ancora validata nei test di interoperabilita\' SOGEI.') ?>
                            </div>
                            <div class="row">
                                <div class="col-12 col-md-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_ricezione_notifica_endpoint]">
                                            <?= trans('sdi_ricezione_notifica_endpoint', '', 'Endpoint SdIRiceviNotifica (comunicato da SOGEI)') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_ricezione_notifica_endpoint]" id="settings[sdi_ricezione_notifica_endpoint]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_ricezione_notifica_endpoint]', get_setting('sdi_ricezione_notifica_endpoint'))) ?>"
                                                placeholder="https://...sogei.it/SdIRiceviNotifica">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_ricezione_notifica_wsdl]">
                                            <?= trans('sdi_ricezione_notifica_wsdl', '', 'Percorso locale del WSDL SdIRiceviNotifica') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_ricezione_notifica_wsdl]" id="settings[sdi_ricezione_notifica_wsdl]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_ricezione_notifica_wsdl]', get_setting('sdi_ricezione_notifica_wsdl'))) ?>"
                                                placeholder="/percorso/sicuro/fuori/dalla/webroot/SdIRiceviNotifica_v1.0.wsdl">
                                        <p class="ip-field-help">
                                            <?= trans('sdi_ricezione_cert_hint', '', 'Certificato e chiave client sono quelli già configurati sopra per SDICoop (invio): il canale di ricezione, quando accreditato insieme, li riusa.') ?>
                                        </p>
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_ricezione_default_expense_category_id]">
                                            <?= trans('sdi_ricezione_default_expense_category_id', '', 'Categoria spesa predefinita per l\'import automatico') ?>
                                        </label>
                                        <select name="settings[sdi_ricezione_default_expense_category_id]" id="settings[sdi_ricezione_default_expense_category_id]"
                                                class="form-control simple-select">
                                            <option value=""><?= trans('none', '', 'Nessuna') ?></option>
                                            <?php foreach ((new \App\Models\ExpenseCategoryModel())->getAllOrdered() as $category): ?>
                                                <option value="<?= esc($category['expense_category_id']) ?>"
                                                    <?= check_select((string) get_setting('sdi_ricezione_default_expense_category_id'), (string) $category['expense_category_id']) ?>>
                                                    <?= esc($category['expense_category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="ip-field-help">
                                            <?= trans('sdi_ricezione_category_hint', '', 'Le fatture importate in automatico non passano dal form: senza una categoria qui, restano non categorizzate.') ?>
                                        </p>
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_ricezione_inbound_token]">
                                            <?= trans('sdi_ricezione_inbound_token', '', 'Token endpoint fatture in ingresso') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_ricezione_inbound_token]" id="settings[sdi_ricezione_inbound_token]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_ricezione_inbound_token]', decrypt_secret((string) get_setting('sdi_ricezione_inbound_token', '')))) ?>"
                                                placeholder="stringa lunga e casuale">
                                        <p class="ip-field-help">
                                            <?= trans('sdi_ricezione_inbound_token_hint', '', 'Va comunicato a SOGEI come URL del webservice RicezioneFatture: ') . base_url('sdi-ricezione/') ?><code><?= trans('sdi_coop_inbound_token_placeholder', '', 'TOKEN') ?></code>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Gruppo IMAP: visibile con PEC e API (ricezione esiti) -->
                        <div class="sdi-group" data-channels="pec,api">
                            <h6 class="ip-settings-subtitle"><?= trans('sdi_imap_section', '', 'Ricezione esiti (IMAP)') ?></h6>
                            <div class="row">
                                <div class="col-12 col-md-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_imap_host]">
                                            <?= trans('sdi_imap_host', '', 'Server IMAP della PEC (ricezione esiti)') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_imap_host]" id="settings[sdi_imap_host]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_imap_host]', get_setting('sdi_imap_host'))) ?>"
                                                placeholder="imaps.pec.provider.it">
                                        <p class="ip-field-help">
                                            <?= trans('sdi_imap_hint', '', 'Richiede l\'estensione php-imap: il cron /cron/sdi_poll/{chiave} importa le ricevute. Senza polling, aggiorna gli esiti a mano dalla pagina fattura.') ?>
                                        </p>
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_imap_username]">
                                            <?= trans('sdi_imap_username', '', 'Utente IMAP') ?>
                                        </label>
                                        <input type="text" name="settings[sdi_imap_username]" id="settings[sdi_imap_username]"
                                                class="form-control"
                                                value="<?= esc(old('settings[sdi_imap_username]', get_setting('sdi_imap_username'))) ?>">
                                    </div>

                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="settings[sdi_imap_password]">
                                            <?= trans('sdi_imap_password', '', 'Password IMAP') ?>
                                        </label>
                                        <input type="password" name="settings[sdi_imap_password]" id="settings[sdi_imap_password]"
                                                class="form-control" autocomplete="new-password" value="">
                                        <input type="hidden" name="settings[sdi_imap_password_field_is_password]" value="1">
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </section>
