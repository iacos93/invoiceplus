-- Dataset minimo per i test d'integrazione, versione MySQL/MariaDB.
--
-- Stessi dati di ci_seed.sql: l'unica differenza e' la sintassi, perche' lo
-- script originale usa forme solo PostgreSQL (generate_series, UPDATE ... FROM,
-- CURRENT_DATE - n, TRUNCATE ... CASCADE). Le due copie vanno tenute allineate:
-- se cambia un valore in una, cambia anche nell'altra.
--
-- Si applica dopo `php spark migrate`:
--   mysql -u <utente> -p <database> < tests/fixtures/ci_seed_mysql.sql
--
-- Idempotente: svuota le tabelle che popola prima di riscriverle.

-- MySQL non ha TRUNCATE multi-tabella ne' CASCADE: si spengono i controlli
-- sulle chiavi esterne, si svuota una tabella alla volta e si riaccendono.
-- TRUNCATE fa commit implicito, quindi questa parte non e' annullabile: e' un
-- database di prova, ricostruibile dalle migrazioni.
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE ip_companies;
TRUNCATE TABLE ip_users;
TRUNCATE TABLE ip_clients;
TRUNCATE TABLE ip_user_clients;
TRUNCATE TABLE ip_invoice_groups;
TRUNCATE TABLE ip_quotes;
TRUNCATE TABLE ip_quote_items;
TRUNCATE TABLE ip_quote_amounts;
TRUNCATE TABLE ip_invoices;
TRUNCATE TABLE ip_invoice_items;
TRUNCATE TABLE ip_invoice_amounts;
TRUNCATE TABLE ip_payments;
TRUNCATE TABLE ip_expenses;
TRUNCATE TABLE ip_products;
TRUNCATE TABLE ip_projects;
TRUNCATE TABLE ip_tasks;
TRUNCATE TABLE ip_suppliers;
TRUNCATE TABLE ip_delivery_notes;
TRUNCATE TABLE ip_tax_rates;
TRUNCATE TABLE ip_units;
TRUNCATE TABLE ip_payment_methods;
TRUNCATE TABLE ip_families;
TRUNCATE TABLE ip_expense_categories;
TRUNCATE TABLE ip_fiscal_years;
TRUNCATE TABLE ip_bank_accounts;
TRUNCATE TABLE ip_email_templates;
TRUNCATE TABLE ip_settings;

SET FOREIGN_KEY_CHECKS = 1;

-- Anagrafica dell'emittente: serve al tracciato FatturaPA e ai PDF.
INSERT INTO ip_companies
    (company_id, company_name, company_vat_id, company_tax_code,
     company_address_1, company_city, company_state, company_zip, company_country,
     company_phone, company_email, company_form, company_is_default, company_is_active,
     company_date_created, default_invoice_group, draft_invoice_group,
     default_quote_group, draft_quote_group, company_inps_scheme,
     company_forfettario_startup, company_system_sender_id)
VALUES
    (1, 'Azienda di prova S.r.l.', 'IT01234567890', '01234567890',
     'Via di Prova 1', 'Roma', 'RM', '00100', 'IT',
     '0600000000', 'azienda@example.com', 'SRL', true, true,
     CURRENT_TIMESTAMP, 1, 1, 2, 2, 'separata',
     false, '0000000');

-- Un amministratore attivo: il flusso di lavoro fa l'accesso con questa email e
-- poi si riscrive la password dentro una transazione con rollback, quindi
-- l'hash qui sotto non deve corrispondere a nulla di reale.
INSERT INTO ip_users
    (user_id, user_type, user_active, user_date_created, user_language,
     user_name, user_email, user_password, user_psalt, user_all_clients,
     user_passwordreset_token, user_mfa_enabled)
VALUES
    (1, 1, true, CURRENT_TIMESTAMP, 'it',
     'Amministratore', 'admin@example.com',
     '$2y$12$qcIixvxO7i/tnZrHkzrfCOjaNxxVjhzaxrfUZK13habOLV2xJYo.a',
     'abcdefghij0123456789AB', 1, '', false);

INSERT INTO ip_clients
    (client_id, client_date_created, client_name, client_surname, client_address_1,
     client_city, client_state, client_zip, client_country, client_phone,
     client_email, client_active, client_vat_id, client_tax_code, client_language,
     client_gender, company_id, client_type, client_sdi_code, client_sdi_pec,
     client_split_payment, client_default_tax_rate_id,
     client_default_payment_method_id, client_payment_days, client_discount_default)
VALUES
    (1, CURRENT_TIMESTAMP, 'Cliente Uno S.p.A.', '', 'Via del Cliente 2',
     'Milano', 'MI', '20100', 'IT', '0200000000',
     'cliente1@example.com', 1, 'IT09876543210', '09876543210', 'it',
     0, 1, 'company', '0000000', 'cliente1@pec.example.com',
     '0', 1, 1, 30, 0),
    (2, CURRENT_TIMESTAMP, 'Cliente Due', '', 'Via del Cliente 3',
     'Torino', 'TO', '10100', 'IT', '0110000000',
     'cliente2@example.com', 1, '', '', 'it',
     0, 1, 'individual', '', '',
     '0', 1, 1, 30, 0);

-- Altri clienti: l'esportazione CSV deve superare i 500 byte che il test
-- considera il minimo di un documento vero, e con due righe non ci arriva.
-- Altri clienti: l'esportazione CSV deve superare i 500 byte che il test
-- considera il minimo di un documento vero, e con due righe non ci arriva.
-- La serie e' esplicita perche' generate_series() esiste solo in PostgreSQL.
INSERT INTO ip_clients
    (client_id, client_date_created, client_name, client_type, client_active,
     client_language, client_gender, company_id, client_payment_days,
     client_split_payment, client_discount_default)
SELECT 100 + n, CURRENT_TIMESTAMP, CONCAT('Cliente ', n), 'individual', 1,
       'it', 0, 1, 30, '0', 0
FROM (SELECT 1
       UNION ALL SELECT 2
       UNION ALL SELECT 3
       UNION ALL SELECT 4
       UNION ALL SELECT 5
       UNION ALL SELECT 6
       UNION ALL SELECT 7
       UNION ALL SELECT 8
       UNION ALL SELECT 9
       UNION ALL SELECT 10
       UNION ALL SELECT 11
       UNION ALL SELECT 12) AS seq (n);

-- L'utente 1 vede tutti i clienti; la riga esiste comunque perche' il test sul
-- portale guest la riscrive e la ripristina.
INSERT INTO ip_user_clients (user_id, client_id) VALUES (1, 1), (1, 2);

INSERT INTO ip_tax_rates (tax_rate_id, tax_rate_name, tax_rate_percent, tax_rate_natura)
VALUES (1, 'IVA 22%', 22, ''), (2, 'IVA 10%', 10, '');

INSERT INTO ip_units (unit_id, unit_name, unit_name_plrl, unit_type)
VALUES (1, 'pz', 'pz', 'pezzi'), (2, 'ora', 'ore', 'tempo');

INSERT INTO ip_payment_methods
    (payment_method_id, payment_method_name, payment_method_requires_bank_account, payment_method_sdi_code)
VALUES (1, 'Bonifico', false, 'MP05');

INSERT INTO ip_families (family_id, family_name) VALUES (1, 'Servizi');

INSERT INTO ip_expense_categories
    (expense_category_id, expense_category_name, expense_category_deductible)
VALUES (1, 'Spese generali', true);

-- L'anno corrente: la Panoramica apre l'anno in corso e "tutti gli anni".
INSERT INTO ip_fiscal_years (fiscal_id, fiscal_year, fiscal_is_projection, company_id)
VALUES (1, YEAR(CURRENT_DATE), false, 1);

INSERT INTO ip_bank_accounts
    (bank_account_id, bank_account_name, bank_account_is_default)
VALUES (1, 'Conto di prova', true);

INSERT INTO ip_email_templates
    (email_template_id, email_template_title, email_template_type,
     email_template_body, email_template_subject, email_template_from_name,
     email_template_from_email, email_template_cc, email_template_bcc,
     email_template_pdf_template)
VALUES
    (1, 'Fattura', 'invoice', '<p>In allegato la fattura.</p>', 'Fattura',
     'Azienda di prova', 'azienda@example.com', '', '', 'InvoicePlane');

-- Numerazione: l'identificativo e' un modello con anno, mese e progressivo.
INSERT INTO ip_invoice_groups
    (invoice_group_id, invoice_group_name, invoice_group_identifier_format,
     invoice_group_next_id, invoice_group_left_pad, company_id)
VALUES
    (1, 'Fattura', 'FAT-{YYYY}-{NNN}', 1, 3, 1),
    (2, 'Preventivo', 'PRE-{YYYY}-{NNN}', 1, 3, 1);

INSERT INTO ip_products
    (product_id, product_sku, product_name, product_description, product_price,
     family_id, tax_rate_id, unit_id, company_id)
VALUES
    (1, 'SKU-1', 'Prodotto di prova', 'Voce di catalogo usata dai test.',
     50, 1, 1, 1, 1);

INSERT INTO ip_projects (project_id, client_id, project_name)
VALUES (1, 1, 'Progetto di prova');

INSERT INTO ip_tasks (task_id, project_id, task_name, task_description, task_price, task_status)
VALUES (1, 1, 'Attivita'' di prova', 'Voce da fatturare.', 100, 1);

INSERT INTO ip_suppliers (supplier_id, supplier_name, supplier_active)
VALUES (1, 'Fornitore di prova', true);

-- Fattura 1: emessa (stato 3), con righe e saldo aperto. E' quella che i test
-- prendono per i PDF, per l'XML e per il link pubblico.
INSERT INTO ip_invoices
    (invoice_id, user_id, client_id, invoice_group_id, invoice_status_id,
     is_read_only, invoice_date_created, invoice_time_created, invoice_date_due,
     invoice_number, invoice_discount_amount, invoice_discount_percent,
     invoice_terms, invoice_url_key, payment_method, invoice_number_is_draft,
     bank_account_id, invoice_is_favourite, company_id, invoice_carta_docente)
VALUES
    (1, 1, 1, 1, 3,
     0, CURRENT_DATE - INTERVAL 10 DAY, '10:00:00', CURRENT_DATE + INTERVAL 20 DAY,
     'FAT-2026-001', 0, 0,
     'Pagamento a 30 giorni.', 'd41d8cd98f00b204e9800998ecf84201', 1, false,
     1, false, 1, false);

INSERT INTO ip_invoice_items
    (item_id, invoice_id, item_tax_rate_id, item_product_id, item_date_added,
     item_name, item_description, item_quantity, item_price, item_discount_amount,
     item_discount_percent, item_order, item_is_recurring, item_product_unit,
     item_product_unit_id, item_date, item_family_id, item_code)
VALUES
    (1, 1, 1, 1, CURRENT_DATE - INTERVAL 10 DAY,
     'Consulenza', 'Giornate di consulenza.', 2, 100, 0,
     0, 1, 0, 'ora',
     2, CURRENT_DATE - INTERVAL 10 DAY, 1, 'C-001'),
    (2, 1, 1, 1, CURRENT_DATE - INTERVAL 10 DAY,
     'Materiale', 'Materiale di consumo.', 1, 50, 0,
     0, 2, 0, 'pz',
     1, CURRENT_DATE - INTERVAL 10 DAY, 1, 'M-001');

-- Importi coerenti con le righe: 250 imponibile, 55 di IVA, 305 totale.
INSERT INTO ip_invoice_amounts
    (invoice_amount_id, invoice_id, invoice_sign, invoice_item_subtotal,
     invoice_item_tax_total, invoice_tax_total, invoice_total, invoice_paid,
     invoice_balance, invoice_discount_total, invoice_fund_amount,
     invoice_withholding_amount)
VALUES
    (1, 1, '1', 250, 55, 55, 305, 0, 305, 0, 0, 0);

-- Fattura 2: incassata (stato 4), saldo zero. Serve alla Panoramica, che
-- controlla il tasso di incasso, e alla pagina dei pagamenti.
INSERT INTO ip_invoices
    (invoice_id, user_id, client_id, invoice_group_id, invoice_status_id,
     is_read_only, invoice_date_created, invoice_time_created, invoice_date_due,
     invoice_number, invoice_discount_amount, invoice_discount_percent,
     invoice_terms, invoice_url_key, payment_method, invoice_number_is_draft,
     bank_account_id, invoice_is_favourite, company_id, invoice_carta_docente)
VALUES
    (2, 1, 2, 1, 4,
     0, CURRENT_DATE - INTERVAL 40 DAY, '11:00:00', CURRENT_DATE - INTERVAL 10 DAY,
     'FAT-2026-002', 0, 0,
     'Pagamento a 30 giorni.', 'd41d8cd98f00b204e9800998ecf84202', 1, false,
     1, false, 1, false);

INSERT INTO ip_invoice_items
    (item_id, invoice_id, item_tax_rate_id, item_product_id, item_date_added,
     item_name, item_description, item_quantity, item_price, item_discount_amount,
     item_discount_percent, item_order, item_is_recurring, item_product_unit,
     item_product_unit_id, item_date, item_family_id, item_code)
VALUES
    (3, 2, 1, 1, CURRENT_DATE - INTERVAL 40 DAY,
     'Assistenza', 'Canone mensile.', 1, 100, 0,
     0, 1, 0, 'pz',
     1, CURRENT_DATE - INTERVAL 40 DAY, 1, 'A-001');

INSERT INTO ip_invoice_amounts
    (invoice_amount_id, invoice_id, invoice_sign, invoice_item_subtotal,
     invoice_item_tax_total, invoice_tax_total, invoice_total, invoice_paid,
     invoice_balance, invoice_discount_total, invoice_fund_amount,
     invoice_withholding_amount)
VALUES
    (2, 2, '1', 100, 22, 22, 122, 122, 0, 0, 0, 0);

INSERT INTO ip_payments
    (payment_id, invoice_id, payment_method_id, payment_date, payment_amount, payment_note)
VALUES
    (1, 2, 1, CURRENT_DATE - INTERVAL 35 DAY, 122, 'Incasso a saldo.');

-- Preventivo aperto e pubblico: il portale guest lo mostra e lo approva.
INSERT INTO ip_quotes
    (quote_id, invoice_id, user_id, client_id, invoice_group_id, quote_status_id,
     quote_date_created, quote_date_expires, quote_number, quote_discount_amount,
     quote_discount_percent, quote_url_key, quote_password, notes,
     quote_number_is_draft, quote_is_favourite, company_id, quote_time_created)
VALUES
    (1, 0, 1, 1, 2, 2,
     CURRENT_DATE - INTERVAL 5 DAY, CURRENT_DATE + INTERVAL 15 DAY, 'PRE-2026-001', 0,
     0, 'd41d8cd98f00b204e9800998ecf84203', '', 'Preventivo di prova.',
     false, false, 1, '09:00:00');

INSERT INTO ip_quote_items
    (item_id, quote_id, item_tax_rate_id, item_product_id, item_date_added,
     item_name, item_description, item_quantity, item_price, item_discount_amount,
     item_order, item_product_unit, item_product_unit_id, item_discount_percent,
     item_code)
VALUES
    (1, 1, 1, 1, CURRENT_DATE - INTERVAL 5 DAY,
     'Consulenza', 'Giornate di consulenza.', 1, 150, 0,
     1, 'ora', 2, 0, 'C-001');

INSERT INTO ip_quote_amounts
    (quote_amount_id, quote_id, quote_item_subtotal, quote_item_tax_total,
     quote_tax_total, quote_total)
VALUES
    (1, 1, 150, 33, 33, 183);

INSERT INTO ip_delivery_notes
    (delivery_note_id, company_id, client_id, invoice_group_id,
     delivery_note_date, delivery_note_number, delivery_note_url_key)
VALUES
    (1, 1, 1, 1, CURRENT_DATE - INTERVAL 3 DAY, 'DDT-2026-001', 'd41d8cd98f00b204e9800998ecf84204');

INSERT INTO ip_expenses
    (expense_id, expense_date, expense_number, supplier_name, supplier_id,
     expense_amount, expense_tax, expense_total, expense_paid, expense_source,
     expense_category_id, company_id, bank_account_id)
VALUES
    (1, CURRENT_DATE - INTERVAL 6 DAY, 'SPE-2026-001', 'Fornitore di prova', 1, 50, 11, 61, 0,
     'manual', 1, 1, 1);

-- Impostazioni con i valori che l'applicazione si aspetta di trovare: le
-- mancanti hanno gia' un ripiego nel codice, queste no (formato valuta e date,
-- numerazione, metodo di pagamento predefinito).
INSERT INTO ip_settings (setting_key, setting_value) VALUES
    ('currency_code', 'EUR'),
    ('currency_symbol', '€'),
    ('currency_symbol_placement', 'afterspace'),
    ('date_format', 'd/m/Y'),
    ('decimal_point', ','),
    ('thousands_separator', '.'),
    ('number_format', 'it_IT'),
    ('default_language', 'it'),
    ('default_country', 'IT'),
    ('default_list_limit', '15'),
    ('invoices_due_after', '30'),
    ('quotes_expire_after', '15'),
    ('generate_invoice_number_for_draft', '1'),
    ('generate_quote_number_for_draft', '1'),
    ('invoice_default_payment_method', '1'),
    ('sdi_channel', 'manual'),
    ('first_day_of_week', '0'),
    ('mark_invoices_sent_pdf', '0'),
    ('mark_quotes_sent_pdf', '0'),
    ('enable_invoice_deletion', '0'),
    ('projects_enabled', '0'),
    ('read_only_toggle', '0'),
    ('default_include_item_tax', '0'),
    ('automatic_email_on_recur', '0'),
    ('email_pdf_attachment', '0'),
    ('monospace_amounts', '0'),
    ('include_zugferd', '0'),
    ('mcp_company_id', '0');

-- Gli id sono scritti a mano: in MySQL il contatore AUTO_INCREMENT si porta
-- da solo sopra al massimo inserito, quindi non serve altro.

COMMIT;
