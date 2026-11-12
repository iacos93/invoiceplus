<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// --- Pubbliche: sessioni (login, logout, reset password) ---
$routes->get('sessions', 'Sessions::index');
$routes->get('sessions/login', 'Sessions::login');
$routes->post('sessions/login', 'Sessions::login');
$routes->get('sessions/logout', 'Sessions::logout');
$routes->get('sessions/passwordreset', 'Sessions::passwordreset');
$routes->get('sessions/passwordreset/(:any)', 'Sessions::passwordreset/$1');
$routes->post('sessions/passwordreset', 'Sessions::passwordreset');
// Primo avvio: creazione del primo utente quando il database e' vuoto.
// Il guard vero resta nel controller: la pagina risponde solo a zero utenti.
$routes->get('sessions/setup', 'Sessions::setup');
$routes->post('sessions/setup', 'Sessions::setup');
// Secondo fattore: passo intermedio tra password e sessione completa.
$routes->get('sessions/mfa', 'Sessions::mfa');
$routes->post('sessions/mfa', 'Sessions::mfa');
// Passkey: endpoint JSON per login senza password e come secondo fattore.
$routes->post('sessions/passkey/options', 'Sessions::passkeyOptions');
$routes->post('sessions/passkey/verify', 'Sessions::passkeyVerify');

// --- Pubbliche: visualizzazione fatture/preventivi tramite URL key ---
$routes->get('guest/view/invoice/(:any)', 'GuestView::invoice/$1');
$routes->post('guest/view/invoice/(:any)', 'GuestView::invoice/$1');
$routes->get('guest/view/quote/(:any)', 'GuestView::quote/$1');
$routes->post('guest/view/quote/(:any)', 'GuestView::quote/$1');
$routes->get('guest/view/generate_invoice_pdf/(:any)', 'GuestView::generateInvoicePdf/$1');
$routes->get('guest/view/generate_quote_pdf/(:any)', 'GuestView::generateQuotePdf/$1');
// Approvare o rifiutare cambia lo stato di un documento: solo POST con CSRF.
// Erano GET, quindi bastava che il browser o un proxy seguisse il link (o che
// qualcuno lo mettesse in un <img src> su una pagina di terzi) perche' un
// preventivo risultasse approvato dal cliente senza un clic vero.
$routes->post('guest/view/approve_quote/(:any)', 'GuestView::approveQuote/$1');
$routes->post('guest/view/reject_quote/(:any)', 'GuestView::rejectQuote/$1');

// --- Pubblica: pagina di benvenuto ---
$routes->get('welcome', 'Welcome::index');

// --- Pubblica: pixel di tracciamento delle aperture email ---
// Lo carica il programma di posta del destinatario, senza sessione. Il token
// è casuale e non contiene l'id del documento.
$routes->get('t/open/(:segment)', 'EmailTracking::open/$1');

// --- Area amministrativa (richiede login, user_type 1) ---
$routes->group('', ['filter' => 'auth'], static function ($routes) {

    $routes->get('dashboard', 'Dashboard::index');

    // --- Modulo: tax_rates (porting da CI3) ---
    $routes->get('tax_rates', 'TaxRates::index');
    $routes->get('tax_rates/form/(:num)', 'TaxRates::form/$1');
    $routes->get('tax_rates/form', 'TaxRates::form');
    $routes->post('tax_rates/form/(:num)', 'TaxRates::form/$1');
    $routes->post('tax_rates/form', 'TaxRates::form');
    $routes->post('tax_rates/delete/(:num)', 'TaxRates::delete/$1');

    // --- Modulo: units (porting da CI3) ---
    $routes->get('units', 'Units::index');
    $routes->get('units/form/(:num)', 'Units::form/$1');
    $routes->get('units/form', 'Units::form');
    $routes->post('units/form/(:num)', 'Units::form/$1');
    $routes->post('units/form', 'Units::form');
    $routes->post('units/delete/(:num)', 'Units::delete/$1');

    // --- Modulo: families (porting da CI3) ---
    $routes->get('families', 'Families::index');
    $routes->get('families/form/(:num)', 'Families::form/$1');
    $routes->get('families/form', 'Families::form');
    $routes->post('families/form/(:num)', 'Families::form/$1');
    $routes->post('families/form', 'Families::form');
    $routes->post('families/delete/(:num)', 'Families::delete/$1');

    // --- Modulo: payment_methods (porting da CI3) ---
    $routes->get('payment_methods', 'PaymentMethods::index');
    $routes->get('payment_methods/form/(:num)', 'PaymentMethods::form/$1');
    $routes->get('payment_methods/form', 'PaymentMethods::form');
    $routes->post('payment_methods/form/(:num)', 'PaymentMethods::form/$1');
    $routes->post('payment_methods/form', 'PaymentMethods::form');
    $routes->post('payment_methods/delete/(:num)', 'PaymentMethods::delete/$1');

    // --- Modulo: companies (multi-azienda) ---
    $routes->get('companies', 'Companies::index');
    $routes->post('companies/switch', 'Companies::switchCompany');
    $routes->get('companies/form/(:num)', 'Companies::form/$1');
    $routes->get('companies/form', 'Companies::form');
    $routes->post('companies/form/(:num)', 'Companies::form/$1');
    $routes->post('companies/form', 'Companies::form');
    $routes->post('companies/toggle/(:num)', 'Companies::toggleActive/$1');
    $routes->post('companies/delete/(:num)', 'Companies::delete/$1');

    // --- Modulo: delivery_notes (documenti di trasporto) ---
    $routes->get('delivery_notes', 'DeliveryNotes::index');
    $routes->get('delivery_notes/form/(:num)', 'DeliveryNotes::form/$1');
    $routes->get('delivery_notes/form', 'DeliveryNotes::form');
    $routes->post('delivery_notes/form/(:num)', 'DeliveryNotes::form/$1');
    $routes->post('delivery_notes/form', 'DeliveryNotes::form');
    $routes->get('delivery_notes/view/(:num)', 'DeliveryNotes::view/$1');
    $routes->get('delivery_notes/preview_sheet/(:num)', 'DeliveryNotes::previewSheet/$1');
    $routes->get('delivery_notes/generate_pdf/(:num)', 'DeliveryNotes::generatePdf/$1');
    $routes->post('delivery_notes/to_invoice/(:num)', 'DeliveryNotes::toInvoice/$1');
    $routes->get('delivery_notes/from/(:segment)/(:num)', 'DeliveryNotes::fromDocument/$1/$2');
    $routes->post('delivery_notes/delete/(:num)', 'DeliveryNotes::delete/$1');

    // --- Modulo: email_senders (mittenti selezionabili) ---
    $routes->get('email_senders', 'EmailSenders::index');
    // Il form "Configurazione email" in testa alla pagina invia qui: senza la
    // rotta POST il salvataggio di casella di sistema e allegato PDF dava 404.
    $routes->post('email_senders', 'EmailSenders::index');
    $routes->get('email_senders/form/(:num)', 'EmailSenders::form/$1');
    $routes->get('email_senders/form', 'EmailSenders::form');
    $routes->post('email_senders/form/(:num)', 'EmailSenders::form/$1');
    $routes->post('email_senders/form', 'EmailSenders::form');
    $routes->post('email_senders/delete/(:num)', 'EmailSenders::delete/$1');

    // --- Modulo: f24 e riba ---
    $routes->get('f24', 'F24::index');
    $routes->get('f24/form/(:num)', 'F24::form/$1');
    $routes->get('f24/form', 'F24::form');
    $routes->post('f24/form/(:num)', 'F24::form/$1');
    $routes->post('f24/form', 'F24::form');
    $routes->get('f24/download/(:num)', 'F24::download/$1');
    $routes->get('f24/receipt/(:num)', 'F24::receipt/$1');
    $routes->get('f24/import', 'F24::import');
    $routes->post('f24/import', 'F24::import');
    $routes->post('f24/delete/(:num)', 'F24::delete/$1');
    $routes->get('riba', 'Riba::index');
    $routes->post('riba/generate', 'Riba::generate');

    // --- Modulo: reconciliation (riconciliazione bancaria) ---
    $routes->get('reconciliation', 'Reconciliation::index');
    $routes->post('reconciliation/import', 'Reconciliation::import');
    $routes->post('reconciliation/match', 'Reconciliation::match');
    $routes->post('reconciliation/unmatch/(:num)', 'Reconciliation::unmatch/$1');

    // --- Modulo: expenses (ciclo passivo) ---
    $routes->get('expenses', 'Expenses::index');
    $routes->get('expenses/form/(:num)', 'Expenses::form/$1');
    $routes->get('expenses/form', 'Expenses::form');
    $routes->post('expenses/form/(:num)', 'Expenses::form/$1');
    $routes->post('expenses/form', 'Expenses::form');
    $routes->post('expenses/delete/(:num)', 'Expenses::delete/$1');
    // Azioni di gruppo dalla lista spese (segna come pagate / da pagare,
    // elimina): POST perché modificano dati, come invoices/bulk.
    $routes->post('expenses/bulk', 'Expenses::bulk');
    $routes->get('expenses/preview_xml/(:num)', 'Expenses::previewXml/$1');
    $routes->get('expenses/download_xml/(:num)', 'Expenses::downloadXml/$1');
    $routes->get('expenses/import', 'Expenses::import');
    $routes->post('expenses/run_import', 'Expenses::runImport');

    // --- Modulo: expense_categories ---
    $routes->get('expense_categories', 'ExpenseCategories::index');
    $routes->get('expense_categories/form/(:num)', 'ExpenseCategories::form/$1');
    $routes->get('expense_categories/form', 'ExpenseCategories::form');
    $routes->post('expense_categories/form/(:num)', 'ExpenseCategories::form/$1');
    $routes->post('expense_categories/form', 'ExpenseCategories::form');
    $routes->post('expense_categories/delete/(:num)', 'ExpenseCategories::delete/$1');

    // --- Modulo: finance (utile e imposte) ---
    $routes->get('finance', 'Finance::index');
    $routes->get('finance/export/(:any)', 'Finance::export/$1');

    // --- Modulo: management (controllo di gestione / bilancino) ---
    $routes->get('management', 'Management::index');
    $routes->get('management/export/(:any)', 'Management::export/$1');
    $routes->get('management/accounts', 'Management::accounts');
    $routes->get('management/accounts/form', 'Management::accountForm');
    $routes->get('management/accounts/form/(:num)', 'Management::accountForm/$1');
    $routes->post('management/accounts/form', 'Management::accountForm');
    $routes->post('management/accounts/form/(:num)', 'Management::accountForm/$1');
    $routes->post('management/accounts/delete/(:num)', 'Management::deleteAccount/$1');
    $routes->post('management/accounts/import_standard', 'Management::importStandard');
    $routes->get('management/budget', 'Management::budget');
    $routes->post('management/budget', 'Management::budget');
    $routes->get('management/cashflow', 'Management::cashflow');
    $routes->get('management/assign', 'Management::assign');
    $routes->post('management/assign', 'Management::assign');

    // --- Modulo: calendar (scadenze) ---
    $routes->get('calendar', 'Calendar::index');
    $routes->get('calendar/(:num)/(:num)', 'Calendar::index/$1/$2');

    // --- Modulo: fiscal_years (parametri fiscali per anno) ---
    $routes->get('fiscal_years', 'FiscalYears::index');
    $routes->get('fiscal_years/form/(:num)', 'FiscalYears::form/$1');
    $routes->get('fiscal_years/form', 'FiscalYears::form');
    $routes->post('fiscal_years/form/(:num)', 'FiscalYears::form/$1');
    $routes->post('fiscal_years/form', 'FiscalYears::form');
    $routes->post('fiscal_years/delete/(:num)', 'FiscalYears::delete/$1');

    // --- Modulo: exports (esportazioni) ---
    $routes->get('exports', 'Exports::index');
    $routes->post('exports/run', 'Exports::run');
    // Esportazioni rapide in coda alle liste: stesso periodo mostrato a
    // schermo (anno + mesi), senza ripassare dal form.
    $routes->get('exports/invoices', 'Exports::quickInvoices');
    $routes->get('exports/expenses', 'Exports::quickExpenses');
    $routes->get('exports/xml', 'Exports::quickXml');

    // --- Modulo: bank_accounts (conti di saldo) ---
    $routes->get('bank_accounts', 'BankAccounts::index');
    $routes->get('bank_accounts/form/(:num)', 'BankAccounts::form/$1');
    $routes->get('bank_accounts/form', 'BankAccounts::form');
    $routes->post('bank_accounts/form/(:num)', 'BankAccounts::form/$1');
    $routes->post('bank_accounts/form', 'BankAccounts::form');
    $routes->post('bank_accounts/delete/(:num)', 'BankAccounts::delete/$1');

    // --- Modulo: invoice_groups (porting da CI3) ---
    $routes->get('invoice_groups', 'InvoiceGroups::index');
    $routes->get('invoice_groups/form/(:num)', 'InvoiceGroups::form/$1');
    $routes->get('invoice_groups/form', 'InvoiceGroups::form');
    $routes->post('invoice_groups/form/(:num)', 'InvoiceGroups::form/$1');
    $routes->post('invoice_groups/form', 'InvoiceGroups::form');
    $routes->post('invoice_groups/delete/(:num)', 'InvoiceGroups::delete/$1');

    // --- Modulo: products (porting da CI3) ---
    $routes->get('products', 'Products::index');
    $routes->get('products/form/(:num)', 'Products::form/$1');
    $routes->get('products/form', 'Products::form');
    $routes->post('products/form/(:num)', 'Products::form/$1');
    $routes->post('products/form', 'Products::form');
    $routes->post('products/delete/(:num)', 'Products::delete/$1');

    // --- Modulo: clients (porting da CI3) ---
    $routes->get('clients', 'Clients::index');
    $routes->get('clients/status/(:any)', 'Clients::status/$1');
    $routes->get('clients/form/(:num)', 'Clients::form/$1');
    $routes->get('clients/form', 'Clients::form');
    $routes->post('clients/form/(:num)', 'Clients::form/$1');
    $routes->post('clients/form', 'Clients::form');
    $routes->get('clients/view/(:num)', 'Clients::view/$1');
    $routes->post('clients/delete/(:num)', 'Clients::delete/$1');
    // Export e import diretti dall'anagrafica clienti
    $routes->get('clients/export', 'Clients::export');
    $routes->get('clients/export_template', 'Clients::exportTemplate');
    $routes->post('clients/import', 'Clients::import');
    $routes->post('clients/ajax/name_query', 'ClientsAjax::nameQuery');
    $routes->post('clients/ajax/get_latest', 'ClientsAjax::getLatest');
    $routes->post('clients/ajax/save_client_note', 'ClientsAjax::saveClientNote');

    // --- Modulo: users (porting da CI3) ---
    $routes->get('users', 'Users::index');
    $routes->get('users/form/(:num)', 'Users::form/$1');
    $routes->get('users/form', 'Users::form');
    $routes->post('users/form/(:num)', 'Users::form/$1');
    $routes->post('users/form', 'Users::form');
    $routes->get('users/change_password/(:num)', 'Users::changePassword/$1');
    $routes->post('users/change_password/(:num)', 'Users::changePassword/$1');
    $routes->post('users/reset_mfa/(:num)', 'Users::resetMfa/$1');
    $routes->post('users/delete/(:num)', 'Users::delete/$1');
    $routes->post('users/delete_user_client/(:num)/(:num)', 'Users::deleteUserClient/$1/$2');
    $routes->post('users/ajax/save_user_client', 'UsersAjax::saveUserClient');
    $routes->post('users/ajax/load_user_client_table', 'UsersAjax::loadUserClientTable');
    $routes->post('users/ajax/modal_add_user_client(/(:num))?', 'UsersAjax::modalAddUserClient/$1');

    // --- Modulo: suppliers (anagrafica fornitori) ---
    $routes->get('suppliers', 'Suppliers::index');
    $routes->get('suppliers/status/(:any)', 'Suppliers::status/$1');
    $routes->get('suppliers/form/(:num)', 'Suppliers::form/$1');
    $routes->get('suppliers/form', 'Suppliers::form');
    $routes->post('suppliers/form/(:num)', 'Suppliers::form/$1');
    $routes->post('suppliers/form', 'Suppliers::form');
    $routes->get('suppliers/view/(:num)', 'Suppliers::view/$1');
    $routes->post('suppliers/delete/(:num)', 'Suppliers::delete/$1');
    // Export e import diretti dall'anagrafica fornitori
    $routes->get('suppliers/export', 'Suppliers::export');
    $routes->get('suppliers/export_template', 'Suppliers::exportTemplate');
    $routes->post('suppliers/import', 'Suppliers::import');

    // --- Modulo: user_clients (porting da CI3) ---
    $routes->get('user_clients/user/(:num)', 'UserClients::user/$1');
    $routes->get('user_clients/create', 'UserClients::create');
    $routes->post('user_clients/create', 'UserClients::create');
    $routes->post('user_clients/delete/(:num)', 'UserClients::delete/$1');

    // --- Modulo: settings (porting da CI3) ---
    $routes->get('settings', 'Settings::index');
    $routes->post('settings', 'Settings::index');
    $routes->get('sdi/settings', 'Settings::sdi');
    $routes->post('sdi/settings', 'Settings::sdi');
    // Cancella un file dal disco e azzera l'impostazione: POST con CSRF.
    // Era una GET, quindi bastava far seguire il link a un browser perche'
    // il logo sparisse senza un clic vero.
    $routes->post('settings/remove_logo/(:any)', 'Settings::removeLogo/$1');
    $routes->post('settings/ajax/get_cron_key', 'SettingsAjax::getCronKey');
    $routes->post('settings/ajax/get_calendar_feed_token', 'SettingsAjax::getCalendarFeedToken');

    // --- Sicurezza account: MFA e passkey per-utente ---
    $routes->get('security', 'Security::index');
    $routes->post('security/totp/setup', 'Security::totpSetup');
    $routes->post('security/totp/confirm', 'Security::totpConfirm');
    $routes->post('security/totp/cancel', 'Security::totpCancel');
    $routes->post('security/totp/disable', 'Security::totpDisable');
    $routes->post('security/backup/ack', 'Security::backupAck');
    $routes->post('security/passkey/options', 'Security::passkeyOptions');
    $routes->post('security/passkey/register', 'Security::passkeyRegister');
    $routes->post('security/passkey/delete/(:num)', 'Security::passkeyDelete/$1');

    // --- Stato del sistema: controlli di salute dell'installazione ---
    // In sola lettura: legge PHP, database, schema, permessi, backup e stato
    // delle automazioni. Serve a vedere i guasti silenziosi (un backup che non
    // viene prodotto) senza dover leggere i log del server.
    $routes->get('system/status', 'SystemStatus::index');
    // Download dell'ultimo backup, per portarlo fuori dal server. GET perché
    // è una lettura: non crea né modifica niente. Il file non si sceglie
    // nell'URL, lo decide il servizio (l'ultimo dump valido).
    $routes->get('system/status/backup', 'SystemStatus::downloadBackup');

    // --- Modulo: integrazione MCP (chiave e perimetro fuori da Impostazioni) ---
    $routes->get('mcp/settings', 'McpSettings::index');
    $routes->post('mcp/settings', 'McpSettings::index');
    $routes->post('mcp/ajax/get_key', 'McpSettings::getKey');

    // --- Modulo: projects (porting da CI3) ---
    $routes->get('projects', 'Projects::index');
    $routes->get('projects/form/(:num)', 'Projects::form/$1');
    $routes->get('projects/form', 'Projects::form');
    $routes->post('projects/form/(:num)', 'Projects::form/$1');
    $routes->post('projects/form', 'Projects::form');
    $routes->get('projects/view/(:num)', 'Projects::view/$1');
    $routes->post('projects/delete/(:num)', 'Projects::delete/$1');

    // --- Modulo: tasks (porting da CI3) ---
    $routes->get('tasks', 'Tasks::index');
    $routes->get('tasks/status/(:any)', 'Tasks::status/$1');
    $routes->get('tasks/form/(:num)', 'Tasks::form/$1');
    $routes->get('tasks/form', 'Tasks::form');
    $routes->post('tasks/form/(:num)', 'Tasks::form/$1');
    $routes->post('tasks/form', 'Tasks::form');
    $routes->post('tasks/delete/(:num)', 'Tasks::delete/$1');
    $routes->get('tasks/ajax/modal_task_lookups(/(:num))?', 'TasksAjax::modalTaskLookups/$1');
    $routes->post('tasks/ajax/process_task_selections', 'TasksAjax::processTaskSelections');

    // --- Modulo: quotes (porting da CI3) ---
    $routes->get('quotes', 'Quotes::index');
    $routes->get('quotes/status/(:any)', 'Quotes::status/$1');
    $routes->get('quotes/form_create', 'Quotes::formCreate');
    $routes->post('quotes/form_create', 'Quotes::formCreate');
    $routes->get('quotes/view/(:num)', 'Quotes::view/$1');
    $routes->get('quotes/edit/(:num)', 'Quotes::edit/$1');
    $routes->post('quotes/toggle_favourite/(:num)', 'Quotes::toggleFavourite/$1');
    $routes->post('quotes/delete/(:num)', 'Quotes::delete/$1');
    // Azioni di gruppo dalla lista preventivi (preferiti, segna come inviato,
    // elimina): la vista le inviava gia' qui, ma la rotta non esisteva e ogni
    // pulsante della barra rispondeva 404.
    $routes->post('quotes/bulk', 'Quotes::bulk');
    $routes->get('quotes/generate_pdf/(:num)', 'Quotes::generatePdf/$1');
    $routes->get('quotes/preview_pdf/(:num)', 'Quotes::previewPdf/$1');
    // POST e non GET: la vista invia gia' un form con csrf_field(), quindi
    // finora il bottone rispondeva 404 e l'imposta preventivo non si cancellava.
    $routes->post('quotes/delete_quote_tax/(:num)/(:num)', 'Quotes::deleteQuoteTax/$1/$2');
    // Ricalcolare riscrive gli importi di tutti i preventivi: mutazione di
    // stato, quindi POST con CSRF. Era una GET, che un prefetch o un link su
    // una pagina di terzi poteva far partire senza che nessuno la chiedesse.
    $routes->post('quotes/recalculate_all_quotes', 'Quotes::recalculateAllQuotes');
    $routes->post('quotes/ajax/save', 'QuotesAjax::save');
    $routes->post('quotes/ajax/create', 'QuotesAjax::create');
    $routes->post('quotes/ajax/get_item', 'QuotesAjax::getItem');
    $routes->post('quotes/ajax/save_quote_tax_rate', 'QuotesAjax::saveQuoteTaxRate');
    $routes->post('quotes/ajax/delete_item', 'QuotesAjax::deleteItem');
    $routes->post('quotes/ajax/reorder_items', 'QuotesAjax::reorderItems');
    $routes->post('quotes/ajax/quote_to_invoice', 'QuotesAjax::quoteToInvoice');
    $routes->post('quotes/ajax/change_client', 'QuotesAjax::changeClient');
    $routes->post('quotes/ajax/copy_quote', 'QuotesAjax::copyQuote');

    // --- Modulo: invoices (porting da CI3) ---
    $routes->get('invoices', 'Invoices::index');
    $routes->get('invoices/status/(:any)', 'Invoices::status/$1');
    $routes->get('invoices/archive', 'Invoices::archive');
    $routes->get('invoices/import', 'Invoices::import');
    $routes->post('invoices/run_import', 'Invoices::runImport');
    $routes->get('invoices/view/(:num)', 'Invoices::view/$1');
    $routes->get('invoices/edit/(:num)', 'Invoices::edit/$1');
    $routes->post('invoices/toggle_favourite/(:num)', 'Invoices::toggleFavourite/$1');
    // Azioni di gruppo dalla lista (preferiti, segna come inviata, elimina):
    // POST perché tutte modificano dati, e passa dal filtro csrf come le altre.
    $routes->post('invoices/bulk', 'Invoices::bulk');
    // Sorgente dell'iframe di anteprima: HTML del documento, stesso template del PDF
    $routes->get('invoices/preview_sheet/(:num)', 'Invoices::previewSheet/$1');
    $routes->get('invoices/form_create', 'Invoices::formCreate');
    $routes->post('invoices/form_create', 'Invoices::formCreate');
    $routes->post('invoices/delete/(:num)', 'Invoices::delete/$1');
    $routes->post('invoices/send_sdi/(:num)', 'Invoices::sendSdi/$1');
    $routes->post('invoices/set_sdi_esito/(:num)', 'Invoices::setSdiEsito/$1');
    $routes->get('invoices/generate_pdf/(:num)', 'Invoices::generatePdf/$1');
    $routes->get('invoices/preview_pdf/(:num)', 'Invoices::previewPdf/$1');
    $routes->get('invoices/generate_xml/(:num)', 'Invoices::generateXml/$1');
    $routes->get('invoices/validate_xml/(:num)', 'Invoices::validateXml/$1');
    $routes->get('invoices/preview_xml/(:num)', 'Invoices::previewXml/$1');
    $routes->get('invoices/generate_zugferd_xml/(:num)', 'Invoices::generateZugferdXml/$1');
    // POST: cancellare un'imposta e ricalcolare il totale della fattura e' una
    // mutazione, e su GET viaggiava senza token CSRF -- bastava far visitare
    // l'indirizzo. La vista e' stata allineata al form con token gia' usato dai
    // preventivi.
    $routes->post('invoices/delete_invoice_tax/(:num)/(:num)', 'Invoices::deleteInvoiceTax/$1/$2');
    // Come per i preventivi: riscrive gli importi di tutte le fatture.
    $routes->post('invoices/recalculate_all_invoices', 'Invoices::recalculateAllInvoices');
    $routes->post('invoices/ajax/save', 'InvoicesAjax::save');
    $routes->post('invoices/ajax/create', 'InvoicesAjax::create');
    $routes->post('invoices/ajax/create_recurring', 'InvoicesAjax::createRecurring');
    $routes->post('invoices/ajax/get_item', 'InvoicesAjax::getItem');
    $routes->post('invoices/ajax/save_invoice_tax_rate', 'InvoicesAjax::saveInvoiceTaxRate');
    $routes->post('invoices/ajax/delete_item', 'InvoicesAjax::deleteItem');
    $routes->post('invoices/ajax/reorder_items', 'InvoicesAjax::reorderItems');
    $routes->post('invoices/ajax/create_credit', 'InvoicesAjax::createCredit');
    $routes->post('invoices/ajax/change_client', 'InvoicesAjax::changeClient');
    $routes->post('invoices/ajax/copy_invoice', 'InvoicesAjax::copyInvoice');
    $routes->get('invoices/recurring', 'RecurringInvoices::index');
    $routes->post('invoices/recurring/stop/(:num)', 'RecurringInvoices::stop/$1');
    $routes->post('invoices/recurring/delete/(:num)', 'RecurringInvoices::delete/$1');

    // --- Modulo: payments (porting da CI3) ---
    $routes->get('payments', 'Payments::index');
    $routes->get('payments/form/(:num)', 'Payments::form/$1');
    $routes->get('payments/form', 'Payments::form');
    $routes->post('payments/form/(:num)', 'Payments::form/$1');
    $routes->post('payments/form', 'Payments::form');
    $routes->get('payments/online_logs', 'Payments::onlineLogs');
    $routes->post('payments/delete/(:num)', 'Payments::delete/$1');
    // Azioni di gruppo dalla lista pagamenti (elimina): POST perché cancella
    // righe e ricalcola i saldi delle fatture, come invoices/bulk.
    $routes->post('payments/bulk', 'Payments::bulk');
    $routes->post('payments/ajax/add', 'PaymentsAjax::add');
    $routes->post('payments/ajax/modal_add_payment', 'PaymentsAjax::modalAddPayment');

    // --- Modulo: email_templates (porting da CI3) ---
    $routes->get('email_templates', 'EmailTemplates::index');
    $routes->get('email_templates/form/(:num)', 'EmailTemplates::form/$1');
    $routes->get('email_templates/form', 'EmailTemplates::form');
    $routes->post('email_templates/form/(:num)', 'EmailTemplates::form/$1');
    $routes->post('email_templates/form', 'EmailTemplates::form');
    $routes->post('email_templates/delete/(:num)', 'EmailTemplates::delete/$1');
    $routes->post('email_templates/ajax/get_content', 'EmailTemplatesAjax::getContent');

    // --- Modulo: mailer (porting da CI3) ---
    $routes->get('mailer/invoice/(:num)', 'Mailer::invoice/$1');
    $routes->get('mailer/quote/(:num)', 'Mailer::quote/$1');
    $routes->post('mailer/send_invoice/(:num)', 'Mailer::sendInvoice/$1');
    $routes->post('mailer/send_quote/(:num)', 'Mailer::sendQuote/$1');

    // --- Modulo: reports (porting da CI3) ---
    $routes->get('reports', 'Reports::index');
    $routes->get('reports/sales_by_client', 'Reports::salesByClient');
    $routes->post('reports/sales_by_client', 'Reports::salesByClient');
    $routes->get('reports/payment_history', 'Reports::paymentHistory');
    $routes->post('reports/payment_history', 'Reports::paymentHistory');
    // Scadenzario (registro scadenze aperte)
    $routes->get('schedule', 'Schedule::index');

    // Filtro anno globale (selettore in topbar)
    $routes->post('year_filter/set', 'YearFilter::set');

    $routes->get('reports/invoice_aging', 'Reports::invoiceAging');
    $routes->get('reports/invoice_aging/pdf', 'Reports::invoiceAgingPdf');
    $routes->get('reports/sales_by_year', 'Reports::salesByYear');
    $routes->post('reports/sales_by_year', 'Reports::salesByYear');

    // --- Modulo: custom_fields (porting da CI3) ---
    $routes->get('custom_fields', 'CustomFields::index');
    $routes->get('custom_fields/form/(:num)', 'CustomFields::form/$1');
    $routes->get('custom_fields/form', 'CustomFields::form');
    $routes->post('custom_fields/form/(:num)', 'CustomFields::form/$1');
    $routes->post('custom_fields/form', 'CustomFields::form');
    $routes->post('custom_fields/delete/(:num)', 'CustomFields::delete/$1');

    // --- Modulo: custom_values (porting da CI3) ---
    $routes->get('custom_values', 'CustomValues::index');
    $routes->get('custom_values/field/(:num)', 'CustomValues::field/$1');
    $routes->get('custom_values/create/(:num)', 'CustomValues::create/$1');
    $routes->post('custom_values/create/(:num)', 'CustomValues::create/$1');
    $routes->get('custom_values/edit/(:num)', 'CustomValues::edit/$1');
    $routes->post('custom_values/edit/(:num)', 'CustomValues::edit/$1');
    $routes->post('custom_values/delete/(:num)', 'CustomValues::delete/$1');

    // --- Modulo: import (porting da CI3) ---
    $routes->get('import', 'Import::index');
    $routes->get('import/form', 'Import::form');
    $routes->post('import/form', 'Import::form');
    $routes->get('import/template/(:segment)', 'Import::template/$1');
    $routes->post('import/delete/(:num)', 'Import::delete/$1');

    // --- Modulo: upload (porting da CI3) ---
    $routes->post('upload/upload_file/(:num)/(:any)', 'Upload::uploadFile/$1/$2');
    $routes->get('upload/show_files/(:any)/(:num)', 'Upload::showFiles/$1/$2');
    $routes->post('upload/delete_file/(:any)', 'Upload::deleteFile/$1');
    $routes->get('upload/get_file/(:any)', 'Upload::getFile/$1');

    // --- Ricerca globale (topbar) ---
    $routes->get('search/global', 'Search::globalSearch');

    // --- Hub importazioni/esportazioni (voce genitore della sidebar) ---
    $routes->get('imports_exports', 'ImportsExports::index');

    // --- Modulo: filter (porting da CI3) ---
    $routes->get('filter/filter_invoices', 'FilterAjax::filterInvoices');
    $routes->get('filter/filter_quotes', 'FilterAjax::filterQuotes');
    $routes->get('filter/filter_clients', 'FilterAjax::filterClients');
    $routes->get('filter/filter_payments', 'FilterAjax::filterPayments');
});

// --- Area guest (richiede login, user_type 2) ---
$routes->group('guest', ['filter' => 'guest'], static function ($routes) {
    $routes->get('', 'Guest::index');
    $routes->get('invoices', 'GuestInvoices::index');
    $routes->get('invoices/status/(:any)', 'GuestInvoices::status/$1');
    $routes->get('invoices/view/(:num)', 'GuestInvoices::view/$1');
    $routes->get('invoices/generate_pdf/(:num)', 'GuestInvoices::generatePdf/$1');
    $routes->get('quotes', 'GuestQuotes::index');
    $routes->get('quotes/status/(:any)', 'GuestQuotes::status/$1');
    $routes->get('quotes/view/(:num)', 'GuestQuotes::view/$1');
    $routes->get('quotes/generate_pdf/(:num)', 'GuestQuotes::generatePdf/$1');
    // Come per l'area pubblica: mutazione di stato, quindi POST con CSRF.
    $routes->post('quotes/approve/(:num)', 'GuestQuotes::approve/$1');
    $routes->post('quotes/reject/(:num)', 'GuestQuotes::reject/$1');
    $routes->get('payments', 'GuestPayments::index');
});

// --- Cron (chiave pubblica) ---
$routes->get('cron/recur/(:any)', 'Cron::recur/$1');
$routes->get('cron/sdi_poll/(:any)', 'Cron::sdiPoll/$1');
$routes->get('cron/digest/(:any)', 'Cron::deadlineDigest/$1');
$routes->get('cron/backup/(:any)', 'Cron::backup/$1');

// --- Endpoint MCP (Streamable HTTP, bearer token) ---
$routes->post('mcp', 'Mcp::handle');
$routes->get('mcp', 'Mcp::handle');

// --- Feed calendario (token pubblico, letto da Google Calendar/CalDAV) ---
$routes->get('calendar/feed/(:any)', 'Calendar::feed/$1');

// --- Webservice SDICoop (token pubblico, richiamato dal Sistema di Interscambio) ---
$routes->post('sdi-coop/(:any)', 'SdiCoopEndpoint::endpoint/$1');

// --- Webservice SDICoop-Ricezione (fatture di acquisto in arrivo dal SdI;
//     scheletro, richiede accreditamento separato — vedi SdiRicezioneEndpoint) ---
$routes->post('sdi-ricezione/(:any)', 'SdiRicezioneEndpoint::endpoint/$1');
