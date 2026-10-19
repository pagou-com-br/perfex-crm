<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Esquema da versão 2.x, idempotente: usado pela instalação e pelas migrations 200 e 210.
 */
class Pagou_schema
{
    const VERSION = 210;

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
                `locked_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `event_key` (`event_key`),
                KEY `charge_id` (`charge_id`(64))
            ) ENGINE=InnoDB DEFAULT CHARSET=" . $db->char_set . ';');
        }

        self::add_column($events, 'locked_at', 'datetime DEFAULT NULL');

        // Uma baixa por cobrança: impede pagamento duplicado entre webhook, reconciliação e versões antigas
        $settlements = db_prefix() . 'pagou_settlements';
        if (!$db->table_exists($settlements)) {
            $db->query("CREATE TABLE `{$settlements}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `invoice_id` int(11) NOT NULL,
                `charge_id` varchar(191) NOT NULL,
                `payment_id` int(11) DEFAULT NULL,
                `status` varchar(20) NOT NULL,
                `amount` decimal(15,2) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `charge_id` (`charge_id`),
                KEY `invoice_id` (`invoice_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . $db->char_set . ';');
        }

        // 2.1: cobranças de cartão (uma linha por tentativa de compra, com a chave de idempotência)
        $card = db_prefix() . 'pagou_card_charges';
        if (!$db->table_exists($card)) {
            $db->query("CREATE TABLE `{$card}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `invoice_id` int(11) NOT NULL,
                `clientid` int(11) NOT NULL DEFAULT 0,
                `pagou_charge_id` varchar(64) DEFAULT NULL,
                `pagou_customer_id` varchar(64) DEFAULT NULL,
                `pagou_card_id` varchar(64) DEFAULT NULL,
                `value_cents` bigint(20) NOT NULL,
                `installments` tinyint(4) NOT NULL DEFAULT 1,
                `status` varchar(20) NOT NULL,
                `failure_reason` varchar(255) DEFAULT NULL,
                `idempotency_key` varchar(100) NOT NULL,
                `request_enc` mediumtext,
                `buyer_ip` varchar(45) DEFAULT NULL,
                `brand` varchar(30) DEFAULT NULL,
                `last4` varchar(4) DEFAULT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `last_checked_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idempotency_key` (`idempotency_key`),
                UNIQUE KEY `pagou_charge_id` (`pagou_charge_id`),
                KEY `invoice_status` (`invoice_id`, `status`)
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
        self::add_column($pix, 'last_checked_at', 'datetime DEFAULT NULL');
        self::add_column($boleto, 'last_checked_at', 'datetime DEFAULT NULL');
        self::add_column(db_prefix() . 'pix_avulso', 'qr_code', 'text NULL');
        self::add_column(db_prefix() . 'pix_avulso', 'cpf_attempts', 'int(11) NOT NULL DEFAULT 0');
        self::add_column(db_prefix() . 'pix_avulso', 'cpf_locked_until', 'datetime DEFAULT NULL');

        // Pix vinculado das versões anteriores: liga a linha ao boleto pelo qrcode_id (em lotes)
        if ($db->table_exists($boleto) && $db->table_exists($pix)) {
            $offset = 0;
            do {
                $batch = $db->select('transaction_id, api_response')
                    ->like('api_response', 'qrcode_id')
                    ->order_by('id', 'ASC')->limit(200, $offset)
                    ->get($boleto)->result();
                foreach ($batch as $row) {
                    $data = json_decode((string) $row->api_response);
                    if (!empty($data->payload->qrcode_id)) {
                        $db->where('transaction_id', $data->payload->qrcode_id)
                            ->where('boleto_transaction_id IS NULL', null, false)
                            ->update($pix, ['boleto_transaction_id' => $row->transaction_id]);
                    }
                }
                $offset += 200;
            } while (count($batch) === 200);
        }

        self::backfill_settlements();

        // Uma única vez, na primeira aplicação de qualquer esquema 2.x (instalação ou atualização):
        // em instalação nova as opções não existem e nada é convertido
        $first_apply = !get_option('pagou_schema_version');
        if ($first_apply) {
            self::convert_legacy_settings();
        }

        // Faturas que já existiam não entram na emissão automática (evita emissão em massa)
        $invoices = db_prefix() . 'invoices';
        if ($db->field_exists('invoice_pagou', $invoices) && $first_apply) {
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
                $converted = (string) round((float) $value / 100, 2);
                update_option($value_option, $converted);
                log_activity('Pagou 2.0: ' . $value_option . ' convertido de centavos para reais (' . $value . ' → ' . $converted . '). Confira nas configurações do gateway.');
            }
        }

        if (get_option('paymentmethod_pagou_pix_interest_type') === 'daily_percentage') {
            update_option('paymentmethod_pagou_pix_interest_type', 'percentage_calendar_days');
        }
    }

    /**
     * Pagamentos já lançados para cobranças da Pagou (inclusive pelo ID do Pix vinculado,
     * como fazia a 1.x) viram baixas concluídas, para a reconciliação não lançar de novo.
     */
    private static function backfill_settlements()
    {
        $CI = &get_instance();
        $db = $CI->db;
        $records = db_prefix() . 'invoicepaymentrecords';
        $settlements = db_prefix() . 'pagou_settlements';
        $now = date('Y-m-d H:i:s');

        foreach (['pagou_transactions_pix', 'pagou_transactions_boleto'] as $table) {
            $full = db_prefix() . $table;
            if (!$db->table_exists($full)) {
                continue;
            }
            $charge_column = $table === 'pagou_transactions_pix' ? 'COALESCE(t.boleto_transaction_id, t.transaction_id)' : 't.transaction_id';
            $db->query("INSERT IGNORE INTO `{$settlements}` (`invoice_id`, `charge_id`, `payment_id`, `status`, `amount`, `created_at`, `updated_at`)
                SELECT t.invoice_id, {$charge_column}, MIN(r.id), 'done', MIN(r.amount), ?, ?
                FROM `{$full}` t
                JOIN `{$records}` r ON r.invoiceid = t.invoice_id AND r.transactionid = t.transaction_id
                GROUP BY t.invoice_id, {$charge_column}", [$now, $now]);
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
     * O esquema atual já foi aplicado (cron, webhooks e gateways só operam depois disso).
     */
    public static function ready()
    {
        return (int) get_option('pagou_schema_version') >= self::VERSION;
    }
}
