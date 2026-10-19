<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_121 extends App_module_migration
{
    public function up()
    {
        $CI = &get_instance();

        // Liga o Pix vinculado ao boleto de origem
        $pix_table = db_prefix() . 'pagou_transactions_pix';
        if ($CI->db->table_exists($pix_table) && !$CI->db->field_exists('boleto_transaction_id', $pix_table)) {
            $CI->db->query("ALTER TABLE `{$pix_table}` ADD COLUMN `boleto_transaction_id` varchar(255) DEFAULT NULL");
        }

        // Até a 1.2.0 a emissão automática falhava sempre; sem esta marcação, o primeiro
        // cron da 1.2.1 emitiria (com tarifa) boletos para todas as faturas existentes.
        // As faturas antigas continuam podendo gerar boleto quando o cliente abre a fatura.
        $invoices_table = db_prefix() . 'invoices';
        if ($CI->db->field_exists('invoice_pagou', $invoices_table)) {
            $CI->db->query("UPDATE `{$invoices_table}` SET `invoice_pagou` = 1 WHERE (`invoice_pagou` IS NULL OR `invoice_pagou` = 0) AND `status` <> 6");
        }
    }
}
