<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_110 extends App_module_migration
{
    public function up()
    {
        $CI = &get_instance();

        /*
         * ============================
         * Criação e Atualização das Tabelas
         * ============================
         */

        // Nome da tabela para transações gerais
        $table_name = db_prefix() . 'pagou_transactions';

        // Estrutura da tabela geral
        $table_structure = [
            'id' => 'int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'invoice_id' => 'int(11) NOT NULL',
            'charge_id' => 'varchar(255) NOT NULL',
            'status' => 'varchar(50) NOT NULL',
            'payment_method' => "enum('boleto', 'pix') NOT NULL",
            'created_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            'transaction_id' => 'varchar(100) NOT NULL',
            'amount' => 'decimal(15,2) NOT NULL',
            'qr_code' => 'text NULL',
            'qr_code_text' => 'text NULL',
            'date_created' => 'datetime NOT NULL',
            'date_updated' => 'datetime DEFAULT NULL',
            'barcode' => 'varchar(255) NULL',
            'consulta' => 'tinyint(1) NOT NULL DEFAULT 0',
            'qr_code_img' => 'longtext NULL',
        ];

        if (!$CI->db->table_exists($table_name)) {
            $columns = [];
            foreach ($table_structure as $name => $definition) {
                $columns[] = "`$name` $definition";
            }
            $columns_sql = implode(", ", $columns);
            $CI->db->query("CREATE TABLE `$table_name` ($columns_sql) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ";");
        } else {
            $existing_fields = $CI->db->list_fields($table_name);
            foreach ($table_structure as $column => $definition) {
                if (!in_array($column, $existing_fields)) {
                    $CI->db->query("ALTER TABLE `$table_name` ADD COLUMN `$column` $definition;");
                }
            }
        }

        // Nome da tabela para transações PIX
        $pix_table_name = db_prefix() . 'pagou_transactions_pix';

        // Estrutura da tabela PIX
        $pix_table_structure = [
            'id' => 'int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'invoice_id' => 'int(11) NOT NULL',
            'cliente' => 'varchar(255) NOT NULL',
            'doc' => 'varchar(20) NOT NULL',
            'transaction_id' => 'varchar(255) NOT NULL',
            'payment_method' => "enum('pix') NOT NULL",
            'qr_code' => 'text NULL',
            'qr_code_text' => 'text NULL',
            'amount' => 'decimal(15,2) NOT NULL',
            'amount_final' => 'decimal(15,2) NOT NULL',
            'status' => 'varchar(50) NOT NULL',
            'date_created' => 'datetime NOT NULL',
            'data_pagamento' => 'datetime DEFAULT NULL'
        ];

        if (!$CI->db->table_exists($pix_table_name)) {
            $columns = [];
            foreach ($pix_table_structure as $name => $definition) {
                $columns[] = "`$name` $definition";
            }
            $columns_sql = implode(", ", $columns);
            $CI->db->query("CREATE TABLE `$pix_table_name` ($columns_sql) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ";");
        } else {
            $existing_fields = $CI->db->list_fields($pix_table_name);
            foreach ($pix_table_structure as $column => $definition) {
                if (!in_array($column, $existing_fields)) {
                    $CI->db->query("ALTER TABLE `$pix_table_name` ADD COLUMN `$column` $definition;");
                }
            }
        }

        /*
         * ============================
         * Atualização da Tabela de Faturas
         * ============================
         */

        $invoice_table = db_prefix() . 'invoices';
        $invoice_column = 'invoice_pagou';

        if (!$CI->db->field_exists($invoice_column, $invoice_table)) {
            $CI->db->query("ALTER TABLE `$invoice_table` ADD COLUMN `$invoice_column` TINYINT(1) NOT NULL DEFAULT 0;");
        }

        /*
         * ============================
         * Adição de Campos Personalizados
         * ============================
         */

        $custom_fields = [
            [
                'fieldto' => 'customers',
                'name' => 'Número do Endereço',
                'slug' => 'customers_numero',
                'required' => '1',
                'type' => 'input',
                'active' => '1',
                'show_on_table' => '1',
                'only_admin' => '1'
            ],
            [
                'fieldto' => 'customers',
                'name' => 'Bairro',
                'slug' => 'customers_bairro',
                'required' => '1',
                'type' => 'input',
                'active' => '1',
                'show_on_table' => '1',
                'only_admin' => '1'
            ]
        ];

        foreach ($custom_fields as $field) {
            $CI->db->where('slug', $field['slug']);
            $exists = $CI->db->get(db_prefix() . 'customfields')->row();

            if (!$exists) {
                $CI->db->insert(db_prefix() . 'customfields', $field);
            }
        }
    }
}
