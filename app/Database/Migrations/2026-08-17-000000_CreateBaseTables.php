<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crea tutte le tabelle base di InvoicePlane (schema originale CI3 1.5.10+it)
 * necessarie prima che le migrazioni incrementali CI4 possano girare.
 * Compatibile PostgreSQL.
 */
class CreateBaseTables extends Migration
{
    public function up(): void
    {
        // ── ip_users ──
        $this->forge->addField([
            'user_id'          => ['type' => 'INT', 'auto_increment' => true],
            'user_type'        => ['type' => 'INT', 'default' => 0],
            'user_active'      => ['type' => 'BOOLEAN', 'default' => true],
            'user_date_created'  => ['type' => 'DATETIME', 'null' => true],
            'user_date_modified' => ['type' => 'DATETIME', 'null' => true],
            'user_language'    => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => 'system'],
            'user_name'        => ['type' => 'TEXT', 'null' => true],
            'user_company'     => ['type' => 'TEXT', 'null' => true],
            'user_address_1'   => ['type' => 'TEXT', 'null' => true],
            'user_address_2'   => ['type' => 'TEXT', 'null' => true],
            'user_city'        => ['type' => 'TEXT', 'null' => true],
            'user_state'       => ['type' => 'TEXT', 'null' => true],
            'user_zip'         => ['type' => 'TEXT', 'null' => true],
            'user_country'     => ['type' => 'TEXT', 'null' => true],
            'user_phone'       => ['type' => 'TEXT', 'null' => true],
            'user_fax'         => ['type' => 'TEXT', 'null' => true],
            'user_mobile'      => ['type' => 'TEXT', 'null' => true],
            'user_email'       => ['type' => 'TEXT', 'null' => true],
            'user_password'    => ['type' => 'VARCHAR', 'constraint' => 60],
            'user_web'         => ['type' => 'TEXT', 'null' => true],
            'user_vat_id'      => ['type' => 'TEXT', 'null' => true],
            'user_tax_code'    => ['type' => 'TEXT', 'null' => true],
            'user_psalt'       => ['type' => 'TEXT', 'null' => true],
            'user_all_clients' => ['type' => 'INT', 'default' => 0],
            'user_passwordreset_token' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'user_subscribernumber' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'user_iban'        => ['type' => 'VARCHAR', 'constraint' => 34, 'null' => true],
            'user_gln'         => ['type' => 'BIGINT', 'null' => true],
            'user_rcc'         => ['type' => 'VARCHAR', 'constraint' => 7, 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->createTable('ip_users', true);

        // ── ip_clients ──
        $this->forge->addField([
            'client_id'          => ['type' => 'INT', 'auto_increment' => true],
            'client_date_created'  => ['type' => 'DATETIME', 'null' => true],
            'client_date_modified' => ['type' => 'DATETIME', 'null' => true],
            'client_name'        => ['type' => 'TEXT', 'null' => true],
            'client_address_1'   => ['type' => 'TEXT', 'null' => true],
            'client_address_2'   => ['type' => 'TEXT', 'null' => true],
            'client_city'        => ['type' => 'TEXT', 'null' => true],
            'client_state'       => ['type' => 'TEXT', 'null' => true],
            'client_zip'         => ['type' => 'TEXT', 'null' => true],
            'client_country'     => ['type' => 'TEXT', 'null' => true],
            'client_phone'       => ['type' => 'TEXT', 'null' => true],
            'client_fax'         => ['type' => 'TEXT', 'null' => true],
            'client_mobile'      => ['type' => 'TEXT', 'null' => true],
            'client_email'       => ['type' => 'TEXT', 'null' => true],
            'client_web'         => ['type' => 'TEXT', 'null' => true],
            'client_active'      => ['type' => 'INT', 'default' => 1],
            'client_vat_id'      => ['type' => 'TEXT', 'null' => true],
            'client_tax_code'    => ['type' => 'TEXT', 'null' => true],
            'client_language'    => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => 'system'],
            'client_surname'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'client_avs'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'client_insurednumber' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'client_veka'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'client_birthdate'   => ['type' => 'DATE', 'null' => true],
            'client_gender'      => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('client_id', true);
        $this->forge->createTable('ip_clients', true);

        // ── ip_client_custom ──
        $this->forge->addField([
            'client_custom_id'         => ['type' => 'INT', 'auto_increment' => true],
            'client_id'                => ['type' => 'INT'],
            'client_custom_fieldid'    => ['type' => 'INT'],
            'client_custom_fieldvalue' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('client_custom_id', true);
        $this->forge->addUniqueKey(['client_id', 'client_custom_fieldid']);
        $this->forge->createTable('ip_client_custom', true);

        // ── ip_client_notes ──
        $this->forge->addField([
            'client_note_id'   => ['type' => 'INT', 'auto_increment' => true],
            'client_id'        => ['type' => 'INT'],
            'client_note_date' => ['type' => 'DATE', 'null' => true],
            'client_note'      => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('client_note_id', true);
        $this->forge->createTable('ip_client_notes', true);

        // ── ip_custom_fields ──
        $this->forge->addField([
            'custom_field_id'       => ['type' => 'INT', 'auto_increment' => true],
            'custom_field_table'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'custom_field_label'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'custom_field_type'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => 'TEXT'],
            'custom_field_location' => ['type' => 'INT', 'default' => 0],
            'custom_field_order'    => ['type' => 'INT', 'default' => 999],
        ]);
        $this->forge->addKey('custom_field_id', true);
        $this->forge->addUniqueKey(['custom_field_table', 'custom_field_label']);
        $this->forge->createTable('ip_custom_fields', true);

        // ── ip_custom_values ──
        $this->forge->addField([
            'custom_values_id'    => ['type' => 'INT', 'auto_increment' => true],
            'custom_values_field' => ['type' => 'INT'],
            'custom_values_value' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('custom_values_id', true);
        $this->forge->createTable('ip_custom_values', true);

        // ── ip_email_templates ──
        $this->forge->addField([
            'email_template_id'           => ['type' => 'INT', 'auto_increment' => true],
            'email_template_title'        => ['type' => 'TEXT', 'null' => true],
            'email_template_type'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'email_template_body'         => ['type' => 'TEXT', 'null' => true],
            'email_template_subject'      => ['type' => 'TEXT', 'null' => true],
            'email_template_from_name'    => ['type' => 'TEXT', 'null' => true],
            'email_template_from_email'   => ['type' => 'TEXT', 'null' => true],
            'email_template_cc'           => ['type' => 'TEXT', 'null' => true],
            'email_template_bcc'          => ['type' => 'TEXT', 'null' => true],
            'email_template_pdf_template' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addKey('email_template_id', true);
        $this->forge->createTable('ip_email_templates', true);

        // ── ip_families ──
        $this->forge->addField([
            'family_id'   => ['type' => 'INT', 'auto_increment' => true],
            'family_name' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('family_id', true);
        $this->forge->createTable('ip_families', true);

        // ── ip_imports ──
        $this->forge->addField([
            'import_id'   => ['type' => 'INT', 'auto_increment' => true],
            'import_date' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('import_id', true);
        $this->forge->createTable('ip_imports', true);

        // ── ip_import_details ──
        $this->forge->addField([
            'import_detail_id' => ['type' => 'INT', 'auto_increment' => true],
            'import_id'        => ['type' => 'INT'],
            'import_lang_key'  => ['type' => 'VARCHAR', 'constraint' => 35, 'null' => true],
            'import_table_name' => ['type' => 'VARCHAR', 'constraint' => 35, 'null' => true],
            'import_record_id' => ['type' => 'INT', 'null' => true],
        ]);
        $this->forge->addKey('import_detail_id', true);
        $this->forge->createTable('ip_import_details', true);

        // ── ip_invoice_groups ──
        $this->forge->addField([
            'invoice_group_id'                => ['type' => 'INT', 'auto_increment' => true],
            'invoice_group_name'              => ['type' => 'TEXT', 'null' => true],
            'invoice_group_identifier_format' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'invoice_group_next_id'           => ['type' => 'INT', 'default' => 1],
            'invoice_group_left_pad'          => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('invoice_group_id', true);
        $this->forge->createTable('ip_invoice_groups', true);

        // ── ip_tax_rates ──
        $this->forge->addField([
            'tax_rate_id'      => ['type' => 'INT', 'auto_increment' => true],
            'tax_rate_name'    => ['type' => 'TEXT', 'null' => true],
            'tax_rate_percent' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
        ]);
        $this->forge->addKey('tax_rate_id', true);
        $this->forge->createTable('ip_tax_rates', true);

        // ── ip_payment_methods ──
        $this->forge->addField([
            'payment_method_id'   => ['type' => 'INT', 'auto_increment' => true],
            'payment_method_name' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('payment_method_id', true);
        $this->forge->createTable('ip_payment_methods', true);

        // ── ip_invoices ──
        $this->forge->addField([
            'invoice_id'              => ['type' => 'INT', 'auto_increment' => true],
            'user_id'                 => ['type' => 'INT'],
            'client_id'               => ['type' => 'INT'],
            'invoice_group_id'        => ['type' => 'INT'],
            'invoice_status_id'       => ['type' => 'SMALLINT', 'default' => 1],
            'is_read_only'            => ['type' => 'SMALLINT', 'default' => 0],
            'invoice_date_created'    => ['type' => 'DATE', 'null' => true],
            'invoice_time_created'    => ['type' => 'TIME', 'default' => '00:00:00'],
            'invoice_date_modified'   => ['type' => 'DATETIME', 'null' => true],
            'invoice_date_due'        => ['type' => 'DATE', 'null' => true],
            'invoice_number'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'invoice_discount_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'invoice_discount_percent' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'invoice_terms'           => ['type' => 'TEXT', 'null' => true],
            'invoice_url_key'         => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
            'payment_method'          => ['type' => 'INT', 'default' => 0],
            'creditinvoice_parent_id' => ['type' => 'INT', 'null' => true],
            'invoice_password'        => ['type' => 'VARCHAR', 'constraint' => 90, 'null' => true],
        ]);
        $this->forge->addKey('invoice_id', true);
        $this->forge->addUniqueKey('invoice_url_key');
        $this->forge->createTable('ip_invoices', true);

        // ── ip_invoice_amounts ──
        $this->forge->addField([
            'invoice_amount_id'       => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'              => ['type' => 'INT'],
            'invoice_sign'            => ['type' => 'VARCHAR', 'constraint' => 2, 'default' => '1'],
            'invoice_item_subtotal'   => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'invoice_item_tax_total'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'invoice_tax_total'       => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'invoice_total'           => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'invoice_paid'            => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'invoice_balance'         => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
        ]);
        $this->forge->addKey('invoice_amount_id', true);
        $this->forge->createTable('ip_invoice_amounts', true);

        // ── ip_invoice_custom ──
        $this->forge->addField([
            'invoice_custom_id'         => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'                => ['type' => 'INT'],
            'invoice_custom_fieldid'    => ['type' => 'INT'],
            'invoice_custom_fieldvalue' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('invoice_custom_id', true);
        $this->forge->addUniqueKey(['invoice_id', 'invoice_custom_fieldid']);
        $this->forge->createTable('ip_invoice_custom', true);

        // ── ip_invoice_items ──
        $this->forge->addField([
            'item_id'               => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'            => ['type' => 'INT'],
            'item_tax_rate_id'      => ['type' => 'INT', 'default' => 0],
            'item_product_id'       => ['type' => 'INT', 'null' => true],
            'item_date_added'       => ['type' => 'DATE', 'null' => true],
            'item_task_id'          => ['type' => 'INT', 'null' => true],
            'item_name'             => ['type' => 'TEXT', 'null' => true],
            'item_description'      => ['type' => 'TEXT', 'null' => true],
            'item_quantity'         => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'item_price'            => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'item_discount_amount'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'item_discount_percent' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'item_order'            => ['type' => 'INT', 'default' => 0],
            'item_is_recurring'     => ['type' => 'SMALLINT', 'default' => 0],
            'item_product_unit'     => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'item_product_unit_id'  => ['type' => 'INT', 'null' => true],
            'item_date'             => ['type' => 'DATE', 'null' => true],
        ]);
        $this->forge->addKey('item_id', true);
        $this->forge->createTable('ip_invoice_items', true);

        // ── ip_invoice_item_amounts ──
        $this->forge->addField([
            'item_amount_id' => ['type' => 'INT', 'auto_increment' => true],
            'item_id'        => ['type' => 'INT'],
            'item_subtotal'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'item_tax_total' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'item_discount'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'item_total'     => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
        ]);
        $this->forge->addKey('item_amount_id', true);
        $this->forge->createTable('ip_invoice_item_amounts', true);

        // ── ip_invoice_tax_rates ──
        $this->forge->addField([
            'invoice_tax_rate_id'     => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'              => ['type' => 'INT'],
            'tax_rate_id'             => ['type' => 'INT'],
            'include_item_tax'        => ['type' => 'INT', 'default' => 0],
            'invoice_tax_rate_amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
        ]);
        $this->forge->addKey('invoice_tax_rate_id', true);
        $this->forge->createTable('ip_invoice_tax_rates', true);

        // ── ip_invoices_recurring ──
        $this->forge->addField([
            'invoice_recurring_id' => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'           => ['type' => 'INT'],
            'recur_start_date'     => ['type' => 'DATE', 'null' => true],
            'recur_end_date'       => ['type' => 'DATE', 'null' => true],
            'recur_frequency'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'recur_next_date'      => ['type' => 'DATE', 'null' => true],
        ]);
        $this->forge->addKey('invoice_recurring_id', true);
        $this->forge->createTable('ip_invoices_recurring', true);

        // ── ip_invoice_sumex (verrà rimossa da DropSumex) ──
        $this->forge->addField([
            'sumex_id'             => ['type' => 'INT', 'auto_increment' => true],
            'sumex_invoice'        => ['type' => 'INT'],
            'sumex_reason'         => ['type' => 'INT', 'null' => true],
            'sumex_diagnosis'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sumex_observations'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sumex_treatmentstart' => ['type' => 'DATE', 'null' => true],
            'sumex_treatmentend'   => ['type' => 'DATE', 'null' => true],
            'sumex_casedate'       => ['type' => 'DATE', 'null' => true],
            'sumex_casenumber'     => ['type' => 'VARCHAR', 'constraint' => 35, 'null' => true],
        ]);
        $this->forge->addKey('sumex_id', true);
        $this->forge->createTable('ip_invoice_sumex', true);

        // ── ip_quotes ──
        $this->forge->addField([
            'quote_id'              => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'            => ['type' => 'INT', 'default' => 0],
            'user_id'               => ['type' => 'INT'],
            'client_id'             => ['type' => 'INT'],
            'invoice_group_id'      => ['type' => 'INT'],
            'quote_status_id'       => ['type' => 'SMALLINT', 'default' => 1],
            'quote_date_created'    => ['type' => 'DATE', 'null' => true],
            'quote_date_modified'   => ['type' => 'DATETIME', 'null' => true],
            'quote_date_expires'    => ['type' => 'DATE', 'null' => true],
            'quote_number'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'quote_discount_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'quote_discount_percent' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'quote_url_key'         => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
            'quote_password'        => ['type' => 'VARCHAR', 'constraint' => 90, 'null' => true],
            'notes'                 => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('quote_id', true);
        $this->forge->createTable('ip_quotes', true);

        // ── ip_quote_amounts ──
        $this->forge->addField([
            'quote_amount_id'       => ['type' => 'INT', 'auto_increment' => true],
            'quote_id'              => ['type' => 'INT'],
            'quote_item_subtotal'   => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'quote_item_tax_total'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'quote_tax_total'       => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'quote_total'           => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
        ]);
        $this->forge->addKey('quote_amount_id', true);
        $this->forge->createTable('ip_quote_amounts', true);

        // ── ip_quote_custom ──
        $this->forge->addField([
            'quote_custom_id'         => ['type' => 'INT', 'auto_increment' => true],
            'quote_id'                => ['type' => 'INT'],
            'quote_custom_fieldid'    => ['type' => 'INT'],
            'quote_custom_fieldvalue' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('quote_custom_id', true);
        $this->forge->addUniqueKey(['quote_id', 'quote_custom_fieldid']);
        $this->forge->createTable('ip_quote_custom', true);

        // ── ip_quote_items ──
        $this->forge->addField([
            'item_id'              => ['type' => 'INT', 'auto_increment' => true],
            'quote_id'             => ['type' => 'INT'],
            'item_tax_rate_id'     => ['type' => 'INT', 'default' => 0],
            'item_product_id'     => ['type' => 'INT', 'null' => true],
            'item_date_added'      => ['type' => 'DATE', 'null' => true],
            'item_name'            => ['type' => 'TEXT', 'null' => true],
            'item_description'     => ['type' => 'TEXT', 'null' => true],
            'item_quantity'        => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'item_price'           => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'item_discount_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'item_order'           => ['type' => 'INT', 'default' => 0],
            'item_product_unit'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'item_product_unit_id' => ['type' => 'INT', 'null' => true],
        ]);
        $this->forge->addKey('item_id', true);
        $this->forge->createTable('ip_quote_items', true);

        // ── ip_quote_item_amounts ──
        $this->forge->addField([
            'item_amount_id' => ['type' => 'INT', 'auto_increment' => true],
            'item_id'        => ['type' => 'INT'],
            'item_subtotal'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'item_tax_total' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'item_discount'  => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'item_total'     => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
        ]);
        $this->forge->addKey('item_amount_id', true);
        $this->forge->createTable('ip_quote_item_amounts', true);

        // ── ip_quote_tax_rates ──
        $this->forge->addField([
            'quote_tax_rate_id'     => ['type' => 'INT', 'auto_increment' => true],
            'quote_id'              => ['type' => 'INT'],
            'tax_rate_id'           => ['type' => 'INT'],
            'include_item_tax'      => ['type' => 'INT', 'default' => 0],
            'quote_tax_rate_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
        ]);
        $this->forge->addKey('quote_tax_rate_id', true);
        $this->forge->createTable('ip_quote_tax_rates', true);

        // ── ip_payments ──
        $this->forge->addField([
            'payment_id'        => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'        => ['type' => 'INT'],
            'payment_method_id' => ['type' => 'INT', 'default' => 0],
            'payment_date'      => ['type' => 'DATE', 'null' => true],
            'payment_amount'    => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'payment_note'      => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('payment_id', true);
        $this->forge->createTable('ip_payments', true);

        // ── ip_payment_custom ──
        $this->forge->addField([
            'payment_custom_id'         => ['type' => 'INT', 'auto_increment' => true],
            'payment_id'                => ['type' => 'INT'],
            'payment_custom_fieldid'    => ['type' => 'INT'],
            'payment_custom_fieldvalue' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('payment_custom_id', true);
        $this->forge->addUniqueKey(['payment_id', 'payment_custom_fieldid']);
        $this->forge->createTable('ip_payment_custom', true);

        // ── ip_products ──
        $this->forge->addField([
            'product_id'          => ['type' => 'INT', 'auto_increment' => true],
            'family_id'           => ['type' => 'INT', 'null' => true],
            'product_sku'         => ['type' => 'TEXT', 'null' => true],
            'product_name'        => ['type' => 'TEXT', 'null' => true],
            'product_description' => ['type' => 'TEXT', 'null' => true],
            'product_price'       => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'purchase_price'      => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            'provider_name'       => ['type' => 'TEXT', 'null' => true],
            'tax_rate_id'         => ['type' => 'INT', 'null' => true],
            'unit_id'             => ['type' => 'INT', 'null' => true],
            'product_tariff'      => ['type' => 'INT', 'null' => true],
        ]);
        $this->forge->addKey('product_id', true);
        $this->forge->createTable('ip_products', true);

        // ── ip_projects ──
        $this->forge->addField([
            'project_id'   => ['type' => 'INT', 'auto_increment' => true],
            'client_id'    => ['type' => 'INT'],
            'project_name' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('project_id', true);
        $this->forge->createTable('ip_projects', true);

        // ── ip_tasks ──
        $this->forge->addField([
            'task_id'          => ['type' => 'INT', 'auto_increment' => true],
            'project_id'       => ['type' => 'INT'],
            'task_name'        => ['type' => 'TEXT', 'null' => true],
            'task_description' => ['type' => 'TEXT', 'null' => true],
            'task_price'       => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
            'task_finish_date' => ['type' => 'DATE', 'null' => true],
            'task_status'      => ['type' => 'SMALLINT', 'default' => 0],
            'tax_rate_id'      => ['type' => 'INT', 'null' => true],
        ]);
        $this->forge->addKey('task_id', true);
        $this->forge->createTable('ip_tasks', true);

        // ── ip_units ──
        $this->forge->addField([
            'unit_id'        => ['type' => 'INT', 'auto_increment' => true],
            'unit_name'      => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'unit_name_plrl' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
        ]);
        $this->forge->addKey('unit_id', true);
        $this->forge->createTable('ip_units', true);

        // ── ip_merchant_responses ──
        $this->forge->addField([
            'merchant_response_id'         => ['type' => 'INT', 'auto_increment' => true],
            'invoice_id'                   => ['type' => 'INT'],
            'merchant_response_successful' => ['type' => 'SMALLINT', 'default' => 1],
            'merchant_response_date'       => ['type' => 'DATE', 'null' => true],
            'merchant_response_driver'     => ['type' => 'VARCHAR', 'constraint' => 35, 'null' => true],
            'merchant_response'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'merchant_response_reference'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addKey('merchant_response_id', true);
        $this->forge->createTable('ip_merchant_responses', true);

        // ── ip_uploads ──
        $this->forge->addField([
            'upload_id'          => ['type' => 'INT', 'auto_increment' => true],
            'client_id'          => ['type' => 'INT'],
            'url_key'            => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
            'file_name_original' => ['type' => 'TEXT', 'null' => true],
            'file_name_new'      => ['type' => 'TEXT', 'null' => true],
            'uploaded_date'      => ['type' => 'DATE', 'null' => true],
        ]);
        $this->forge->addKey('upload_id', true);
        $this->forge->createTable('ip_uploads', true);

        // ── ip_user_clients ──
        $this->forge->addField([
            'user_client_id' => ['type' => 'INT', 'auto_increment' => true],
            'user_id'        => ['type' => 'INT'],
            'client_id'      => ['type' => 'INT'],
        ]);
        $this->forge->addKey('user_client_id', true);
        $this->forge->createTable('ip_user_clients', true);

        // ── ip_user_custom ──
        $this->forge->addField([
            'user_custom_id'         => ['type' => 'INT', 'auto_increment' => true],
            'user_id'                => ['type' => 'INT'],
            'user_custom_fieldid'    => ['type' => 'INT'],
            'user_custom_fieldvalue' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('user_custom_id', true);
        $this->forge->addUniqueKey(['user_id', 'user_custom_fieldid']);
        $this->forge->createTable('ip_user_custom', true);

        // ── ip_settings ──
        $this->forge->addField([
            'setting_id'    => ['type' => 'INT', 'auto_increment' => true],
            'setting_key'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'setting_value' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('setting_id', true);
        $this->forge->createTable('ip_settings', true);

        // ── ip_versions ──
        $this->forge->addField([
            'version_id'           => ['type' => 'INT', 'auto_increment' => true],
            'version_date_applied' => ['type' => 'VARCHAR', 'constraint' => 14, 'null' => true],
            'version_file'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'version_sql_errors'   => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('version_id', true);
        $this->forge->createTable('ip_versions', true);

        // ── ip_item_lookups (legacy, mai rimossa) ──
        $this->forge->addField([
            'item_lookup_id'  => ['type' => 'INT', 'auto_increment' => true],
            'item_name'       => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'item_description' => ['type' => 'TEXT', 'null' => true],
            'item_price'      => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
        ]);
        $this->forge->addKey('item_lookup_id', true);
        $this->forge->createTable('ip_item_lookups', true);
    }

    public function down(): void
    {
        $tables = [
            'ip_item_lookups', 'ip_versions', 'ip_settings', 'ip_user_custom',
            'ip_user_clients', 'ip_uploads', 'ip_merchant_responses', 'ip_units',
            'ip_tasks', 'ip_projects', 'ip_products', 'ip_payment_custom',
            'ip_payments', 'ip_payment_methods', 'ip_quote_tax_rates',
            'ip_quote_item_amounts', 'ip_quote_items', 'ip_quote_custom',
            'ip_quote_amounts', 'ip_quotes', 'ip_invoice_sumex',
            'ip_invoices_recurring', 'ip_invoice_tax_rates',
            'ip_invoice_item_amounts', 'ip_invoice_items', 'ip_invoice_custom',
            'ip_invoice_amounts', 'ip_invoices', 'ip_tax_rates',
            'ip_invoice_groups', 'ip_import_details', 'ip_imports',
            'ip_families', 'ip_email_templates', 'ip_custom_values',
            'ip_custom_fields', 'ip_client_notes', 'ip_client_custom',
            'ip_clients', 'ip_users',
        ];

        foreach ($tables as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
