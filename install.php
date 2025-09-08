<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Obtém a instância do CodeIgniter
$CI = &get_instance();

/*
 * ============================
 * Configurações Iniciais
 * ============================
 */

// Nome da tabela para transações gerais
$table_name = db_prefix() . 'pagou_transactions';

// Nome da tabela para transações PIX
$pix_table_name = db_prefix() . 'pagou_transactions_pix';

// Nome da tabela para transações Boleto
$boleto_table_name = db_prefix() . 'pagou_transactions_boleto';

// Nome da tabela para cobranças PIX Avulso
$pix_avulso_table = db_prefix() . 'pix_avulso';

// Nome da tabela para notificações de atualização de status de pagamento
$refresh_table_name = db_prefix() . 'sessions_pagou_refresh';

/*
 * ============================
 * Criação e Atualização de Tabelas
 * ============================
 */

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

// Criação ou atualização da tabela geral
if (!$CI->db->table_exists($table_name)) {
    // Criação da tabela
    $columns = [];
    foreach ($table_structure as $name => $definition) {
        $columns[] = "`$name` $definition";
    }
    $columns_sql = implode(", ", $columns);
    $CI->db->query("CREATE TABLE `$table_name` ($columns_sql) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ";");
} else {
    // Atualização da tabela
    $existing_fields = $CI->db->list_fields($table_name);
    foreach ($table_structure as $column => $definition) {
        if (!in_array($column, $existing_fields)) {
            $CI->db->query("ALTER TABLE `$table_name` ADD COLUMN `$column` $definition;");
        }
    }
}

// Estrutura da tabela PIX
$pix_table_structure = [
    'id' => 'int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'invoice_id' => 'int(11) NOT NULL',
    'cliente' => 'varchar(255) NOT NULL',
    'doc' => 'varchar(20) NOT NULL',
    'transaction_id' => 'varchar(255) NOT NULL',
    'payment_method' => "enum('pix') NOT NULL DEFAULT 'pix'",
    'qr_code' => 'text NULL',
    'qr_code_text' => 'text NULL',
    'amount' => 'decimal(15,2) NOT NULL',
    'amount_final' => 'decimal(15,2) NOT NULL',
    'status' => 'varchar(50) NOT NULL',
    'date_created' => 'datetime NOT NULL',
    'data_pagamento' => 'datetime DEFAULT NULL',
    'data_estorno' => 'datetime DEFAULT NULL',
    'webhook_log' => 'text NULL',
    'external_id' => 'varchar(255) DEFAULT NULL',
    'expiration_seconds' => 'int(11) DEFAULT 86400',
    'api_request' => 'text DEFAULT NULL',
    'api_response' => 'text DEFAULT NULL'
];

// Criação ou atualização da tabela PIX
if (!$CI->db->table_exists($pix_table_name)) {
    // Criação da tabela
    $columns = [];
    foreach ($pix_table_structure as $name => $definition) {
        $columns[] = "`$name` $definition";
    }
    $columns_sql = implode(", ", $columns);
    $CI->db->query("CREATE TABLE `$pix_table_name` ($columns_sql) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ";");
} else {
    // Atualização da tabela
    $existing_fields = $CI->db->list_fields($pix_table_name);
    foreach ($pix_table_structure as $column => $definition) {
        if (!in_array($column, $existing_fields)) {
            $CI->db->query("ALTER TABLE `$pix_table_name` ADD COLUMN `$column` $definition;");
        }
    }
}

if ($CI->db->table_exists($pix_table_name)) {
    $CI->db->query("ALTER TABLE `$pix_table_name` MODIFY COLUMN `payment_method` enum('pix') NOT NULL DEFAULT 'pix';");
}

// Estrutura da tabela Boleto
$boleto_table_structure = [
    'id' => 'int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'invoice_id' => 'int(11) NOT NULL',
    'cliente' => 'varchar(255) DEFAULT NULL',
    'doc' => 'varchar(30) DEFAULT NULL',
    'transaction_id' => 'varchar(255) NOT NULL',
    'external_id' => 'varchar(255) DEFAULT NULL',
    'payment_method' => 'varchar(50) NOT NULL',
    'barcode' => 'text NOT NULL',
    'barcode_url' => 'varchar(255) DEFAULT NULL',
    'boleto_url' => 'varchar(255) DEFAULT NULL',
    'amount' => 'decimal(15,2) NOT NULL',
    'amount_final' => 'decimal(15,2) DEFAULT NULL',
    'status' => 'varchar(50) NOT NULL DEFAULT "pending"',
    'date_created' => 'datetime NOT NULL',
    'due_date' => 'date NOT NULL',
    'payment_date' => 'datetime DEFAULT NULL',
    'refund_date' => 'datetime DEFAULT NULL',
    'updated_at' => 'datetime DEFAULT NULL',
    'api_request' => 'text DEFAULT NULL',
    'api_response' => 'text DEFAULT NULL',
    'webhook_log' => 'text DEFAULT NULL',
    'consulta' => 'tinyint(1) NOT NULL DEFAULT 0'
];

// Criação ou atualização da tabela Boleto
if (!$CI->db->table_exists($boleto_table_name)) {
    // Criação da tabela
    $columns = [];
    foreach ($boleto_table_structure as $name => $definition) {
        $columns[] = "`$name` $definition";
    }
    $columns_sql = implode(", ", $columns);
    $CI->db->query("CREATE TABLE `$boleto_table_name` ($columns_sql) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ";");
    
    // Adiciona índices para otimização
    $CI->db->query("ALTER TABLE `$boleto_table_name` ADD INDEX `invoice_id` (`invoice_id`);");
    $CI->db->query("ALTER TABLE `$boleto_table_name` ADD INDEX `transaction_id` (`transaction_id`(191));");
    $CI->db->query("ALTER TABLE `$boleto_table_name` ADD INDEX `external_id` (`external_id`(191));");
} else {
    // Atualização da tabela
    $existing_fields = $CI->db->list_fields($boleto_table_name);
    foreach ($boleto_table_structure as $column => $definition) {
        if (!in_array($column, $existing_fields)) {
            $CI->db->query("ALTER TABLE `$boleto_table_name` ADD COLUMN `$column` $definition;");
        }
    }
    
    // Verifica e adiciona índices se não existirem
    $result = $CI->db->query("SHOW INDEX FROM `$boleto_table_name` WHERE Key_name = 'invoice_id'");
    if ($result->num_rows() == 0) {
        $CI->db->query("ALTER TABLE `$boleto_table_name` ADD INDEX `invoice_id` (`invoice_id`);");
    }
    
    $result = $CI->db->query("SHOW INDEX FROM `$boleto_table_name` WHERE Key_name = 'transaction_id'");
    if ($result->num_rows() == 0) {
        $CI->db->query("ALTER TABLE `$boleto_table_name` ADD INDEX `transaction_id` (`transaction_id`(191));");
    }
    
    $result = $CI->db->query("SHOW INDEX FROM `$boleto_table_name` WHERE Key_name = 'external_id'");
    if ($result->num_rows() == 0) {
        $CI->db->query("ALTER TABLE `$boleto_table_name` ADD INDEX `external_id` (`external_id`(191));");
    }
}

// Estrutura da tabela PIX Avulso (ATUALIZADA COM A COLUNA 'status')
$pix_avulso_structure = [
    'id' => 'int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'nome' => 'varchar(255) NOT NULL',
    'cpf' => 'varchar(50) NOT NULL',
    'descricao' => 'text NOT NULL',
    'valor' => 'decimal(15,2) NOT NULL',
    'link' => 'varchar(255) NOT NULL',
    'status' => 'varchar(50) NOT NULL', // Coluna adicionada para armazenar o status da cobrança
    'qr_code' => 'text NULL',
    'qr_code_text' => 'text NULL',
    'qr_code_img' => 'longtext NULL',
    'data_emissao' => 'datetime NOT NULL',
    'data_modificacao' => 'datetime NOT NULL'
];

// Criação ou atualização da tabela PIX Avulso
if (!$CI->db->table_exists($pix_avulso_table)) {
    // Criação da tabela
    $columns = [];
    foreach ($pix_avulso_structure as $name => $definition) {
        $columns[] = "`$name` $definition";
    }
    $columns_sql = implode(", ", $columns);
    $CI->db->query("CREATE TABLE `$pix_avulso_table` ($columns_sql) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ";");
} else {
    // Atualização da tabela
    $existing_fields = $CI->db->list_fields($pix_avulso_table);
    foreach ($pix_avulso_structure as $column => $definition) {
        if (!in_array($column, $existing_fields)) {
            $CI->db->query("ALTER TABLE `$pix_avulso_table` ADD COLUMN `$column` $definition;");
        }
    }
}

// Estrutura da tabela de notificações de atualização
$refresh_table_structure = [
    'id' => 'int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'invoice_id' => 'int(11) NOT NULL',
    'timestamp' => 'int(11) NOT NULL',
    'processed' => 'tinyint(1) NOT NULL DEFAULT 0'
];

// Criação ou atualização da tabela de notificações de atualização
if (!$CI->db->table_exists($refresh_table_name)) {
    // Criação da tabela
    $columns = [];
    foreach ($refresh_table_structure as $name => $definition) {
        $columns[] = "`$name` $definition";
    }
    $columns_sql = implode(", ", $columns);
    $CI->db->query("CREATE TABLE `$refresh_table_name` ($columns_sql) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ";");
    
    // Adiciona índices para melhorar a performance
    $CI->db->query("ALTER TABLE `$refresh_table_name` ADD INDEX `invoice_id` (`invoice_id`);");
    $CI->db->query("ALTER TABLE `$refresh_table_name` ADD INDEX `processed` (`processed`);");
} else {
    // Atualização da tabela
    $existing_fields = $CI->db->list_fields($refresh_table_name);
    foreach ($refresh_table_structure as $column => $definition) {
        if (!in_array($column, $existing_fields)) {
            $CI->db->query("ALTER TABLE `$refresh_table_name` ADD COLUMN `$column` $definition;");
        }
    }
    
    // Verifica e adiciona índices se não existirem
    $result = $CI->db->query("SHOW INDEX FROM `$refresh_table_name` WHERE Key_name = 'invoice_id'");
    if ($result->num_rows() == 0) {
        $CI->db->query("ALTER TABLE `$refresh_table_name` ADD INDEX `invoice_id` (`invoice_id`);");
    }
    
    $result = $CI->db->query("SHOW INDEX FROM `$refresh_table_name` WHERE Key_name = 'processed'");
    if ($result->num_rows() == 0) {
        $CI->db->query("ALTER TABLE `$refresh_table_name` ADD INDEX `processed` (`processed`);");
    }
}

/*
 * ============================
 * Atualização da Tabela de Faturas
 * ============================
 */

// Verifica e adiciona a coluna 'invoice_pagou' à tabela tblinvoices
$invoice_table = db_prefix() . 'invoices';
$invoice_column = 'invoice_pagou';

if (!$CI->db->field_exists($invoice_column, $invoice_table)) {
    $CI->db->query("ALTER TABLE `$invoice_table` ADD COLUMN `$invoice_column` TINYINT(1) NOT NULL DEFAULT 0;");
}

/*
 * ============================
 * Adição de Campos Personalizados para Endereço
 * ============================
 */
$custom_fields = [
    [
        'fieldto' => 'customers',
        'name' => 'Número do Endereço',
        'slug' => 'customers_numero',
        'required' => '1',
        'type' => 'input',
        'display_inline' => '0',
        'field_order' => '0',
        'active' => '1',
        'show_on_pdf' => '0',
        'show_on_ticket_form' => '0',
        'only_admin' => '1',
        'show_on_table' => '1',
        'show_on_client_portal' => '0',
        'disalow_client_to_edit' => '0',
        'bs_column' => '12'
    ],
    [
        'fieldto' => 'customers',
        'name' => 'Bairro',
        'slug' => 'customers_bairro',
        'required' => '1',
        'type' => 'input',
        'display_inline' => '0',
        'field_order' => '0',
        'active' => '1',
        'show_on_pdf' => '0',
        'show_on_ticket_form' => '0',
        'only_admin' => '1',
        'show_on_table' => '1',
        'show_on_client_portal' => '0',
        'disalow_client_to_edit' => '0',
        'bs_column' => '12'
    ]
];

foreach ($custom_fields as $field) {
    $CI->db->where('slug', $field['slug']);
    $exists = $CI->db->get(db_prefix() . 'customfields')->row();

    if (!$exists) {
        $CI->db->insert(db_prefix() . 'customfields', $field);
    }
}

/*
 * ============================
 * Opções Padrão
 * ============================
 */
$default_options = [
    'pagou_logs_api' => '0',                 // Desativado por padrão
    'pagou_webhook_url' => '',               // URL padrão do webhook (vazio para usar a padrão)
];

foreach ($default_options as $option_name => $option_value) {
    $CI->db->where('name', $option_name);
    $existing_option = $CI->db->get(db_prefix() . 'options')->row();

    // Se não existir, insere com o valor padrão
    if (!$existing_option) {
        $CI->db->insert(db_prefix() . 'options', [
            'name'     => $option_name,
            'value'    => $option_value,
            'autoload' => 1,
        ]);
    }
}