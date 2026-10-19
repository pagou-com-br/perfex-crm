<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Esquema da versão 2.0, idempotente: usado pela instalação e pela migration 200.
 */
class Pagou_schema
{
    const VERSION = 200;

    /**
     * @param bool $is_upgrade true quando vem de uma versão anterior (migration)
     * @return void
     */
    public static function apply($is_upgrade)
    {
        $CI = &get_instance();
        $db = $CI->db;
        $pix = db_prefix() . 'pagou_transactions_pix';
        $boleto = db_prefix() . 'pagou_transactions_boleto';
        $events = db_prefix() . 'pagou_webhook_events';

        // Eventos de webhook com chave única (nome + cobrança + transação)
        if (!$db->table_exists($events)) {
            $db->query("CREATE TABLE `{$events}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `event_key` char(64) NOT NULL,
                `name` varchar(50) NOT NULL,
                `charge_id` varchar(255) NOT NULL,
                `transaction_id` varchar(255) NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL,
                `attempts` int(11) NOT NULL DEFAULT 0,
                `result` varchar(255) DEFAULT NULL,
                `payload` mediumtext,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `event_key` (`event_key`),
                KEY `charge_id` (`charge_id`(64))
            ) ENGINE=InnoDB DEFAULT CHARSET=" . $db->char_set . ';');
        }

        self::add_column($pix, 'boleto_transaction_id', 'varchar(255) DEFAULT NULL');
        self::add_column($pix, 'refunded_amount', 'decimal(15,2) NOT NULL DEFAULT 0');
        self::add_column($pix, 'e2e_id', 'varchar(100) DEFAULT NULL');
        self::add_column($pix, 'customer_code', 'varchar(100) DEFAULT NULL');
        self::add_column($pix, 'data_estorno', 'datetime DEFAULT NULL');
        self::add_column($pix, 'reembolso_log', 'text NULL');
        self::add_column($pix, 'payment_method', "varchar(20) DEFAULT 'pix'");
        self::add_column($pix, 'data_pagamento', 'datetime DEFAULT NULL');
        self::add_column($boleto, 'customer_code', 'varchar(100) DEFAULT NULL');
        self::add_column(db_prefix() . 'pix_avulso', 'qr_code', 'text NULL');

        // Pix vinculado das versões anteriores: liga a linha ao boleto pelo qrcode_id
        if ($db->table_exists($boleto) && $db->table_exists($pix)) {
            foreach ($db->select('transaction_id, api_response')->get($boleto)->result() as $row) {
                $data = json_decode((string) $row->api_response);
                if (!empty($data->payload->qrcode_id)) {
                    $db->where('transaction_id', $data->payload->qrcode_id)
                        ->where('boleto_transaction_id IS NULL', null, false)
                        ->update($pix, ['boleto_transaction_id' => $row->transaction_id]);
                }
            }
        }

        // Uma única vez, na primeira aplicação do esquema 2.0
        if ($is_upgrade && !self::ready()) {
            self::convert_legacy_settings();
        }

        // Faturas que já existiam não entram na emissão automática (evita emissão em massa)
        $invoices = db_prefix() . 'invoices';
        if ($db->field_exists('invoice_pagou', $invoices) && !get_option('pagou_schema_version')) {
            $db->query("UPDATE `{$invoices}` SET `invoice_pagou` = 1 WHERE (`invoice_pagou` IS NULL OR `invoice_pagou` = 0) AND `status` <> 6");
        }

        self::set_option('pagou_schema_version', (string) self::VERSION);
    }

    /**
     * Converte valores que as versões antigas rotulavam em centavos para reais,
     * e o tipo de juros do Pix para um código documentado.
     */
    private static function convert_legacy_settings()
    {
        $to_reais = [
            ['paymentmethod_pagou_pix_discount_value', 'paymentmethod_pagou_pix_discount_type'],
            ['paymentmethod_pagou_pix_fine_value', 'paymentmethod_pagou_pix_fine_type'],
            ['paymentmethod_pagou_boleto_discount_value', 'paymentmethod_pagou_boleto_discount_type'],
        ];
        foreach ($to_reais as $pair) {
            list($value_option, $type_option) = $pair;
            $value = get_option($value_option);
            if (get_option($type_option) !== 'percentage' && is_numeric($value) && (float) $value > 0) {
                update_option($value_option, (string) round((float) $value / 100, 2));
            }
        }

        if (get_option('paymentmethod_pagou_pix_interest_type') === 'daily_percentage') {
            update_option('paymentmethod_pagou_pix_interest_type', 'percentage_calendar_days');
        }
    }

    private static function add_column($table, $column, $definition)
    {
        $CI = &get_instance();
        if ($CI->db->table_exists($table) && !$CI->db->field_exists($column, $table)) {
            $CI->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private static function set_option($name, $value)
    {
        $CI = &get_instance();
        $exists = $CI->db->where('name', $name)->count_all_results(db_prefix() . 'options') > 0;
        if ($exists) {
            update_option($name, $value);
        } else {
            add_option($name, $value, 1);
        }
    }

    /**
     * O esquema 2.0 já foi aplicado (cron e webhooks só operam depois disso).
     */
    public static function ready()
    {
        return (int) get_option('pagou_schema_version') >= self::VERSION;
    }
}
