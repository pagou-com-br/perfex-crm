<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Pagou Integração Perfex CRM
Description: Gateway de pagamento www.pagou.com.br, PIX & Boleto para Perfex CRM
Version: 2.0.0
Requires at least: 3.2.0
Author: Liquida SP
Author URI: https://liquidasp.com.br/
*/


require_once __DIR__ . '/helpers/pagou_helper.php';


$CI = &get_instance();
$CI->load->helper('url');


hooks()->add_action('admin_init', 'pagou_module_permissions');


function pagou_module_permissions()
{
    $permissions = [];

    $permissions['capabilities'] = [
        'access_pix_avulso' => 'Acessar PIX Avulso',
        'access_reembolso'  => 'Acessar Reembolso de Pagamentos',
    ];

    register_staff_capabilities('pagou_module', $permissions, _l('Pagou Integração'));
}


// Webhooks não carregam token CSRF; a autenticação é feita pela assinatura HMAC
hooks()->add_filter('csrf_exclude_uris', function ($uris) {
    return array_merge((array) $uris, [
        'admin/pagou/pagou_webhook/process.*',
        'pagou/pagou_webhook/process.*',
        'pagou/notification',
        'pagou/webhook',
    ]);
});


register_activation_hook('pagou', 'pagou_activation_hook');
register_payment_gateway('pagou_boleto_gateway', 'pagou');
register_payment_gateway('pagou_pix_gateway', 'pagou');

hooks()->add_action('invoice_marked_as_cancelled', 'cancel_pagou_boleto');
hooks()->add_action('invoice_marked_as_cancelled', 'pagou_cancel_invoice_pix');
hooks()->add_action('after_invoice_added', 'pagou_auto_issue_boleto');
hooks()->add_action('after_invoice_updated', 'pagou_auto_issue_boleto_on_update');
hooks()->add_action('after_cron_run', 'process_pagou_boleto_invoices');
hooks()->add_action('after_cron_run', 'pagou_reconcile_payments');

hooks()->add_action('admin_init', function () {
    $CI = &get_instance();

    pagou_remember_api_tokens();

    if (staff_can('access_pix_avulso', 'pagou_module') || staff_can('access_reembolso', 'pagou_module')) {
        $CI->app_menu->add_sidebar_menu_item('pagou-menu', [
            'name' => 'Pagou',
            "icon" => "fa fa-usd",
            'position' => 10,
        ]);

        if (staff_can('access_pix_avulso', 'pagou_module')) {
            $CI->app_menu->add_sidebar_children_item('pagou-menu', [
                'slug' => 'pagou-pix-avulso',
                'name' => 'PIX Avulso',
                'href' => admin_url('pagou/pix_avulso'),
                'position' => 5,
            ]);
        }

        if (staff_can('access_reembolso', 'pagou_module')) {
            $CI->app_menu->add_sidebar_children_item('pagou-menu', [
                'slug' => 'pagou-cobrancas-emitidas',
                'name' => 'Reembolso de Pagamentos',
                'href' => admin_url('pagou/cobrancas_emitidas'),
                'position' => 10,
            ]);
        }
        
       /* if (staff_can('access_reembolso', 'pagou_module')) {
            $CI->app_menu->add_sidebar_children_item('pagou-menu', [
                'slug' => 'pagou-config',
                'name' => 'Configurações',
                'href' => admin_url('pagou/pagou_conf'),
                'position' => 11,
            ]);
        } */
    }
});


function pagou_activation_hook()
{
    require_once __DIR__ . '/install.php';

    if (!get_option('pagou_logs_api')) {
        add_option('pagou_logs_api', '0', 1);
    }

    pagou_adicionar_rotas();
}


function pagou_adicionar_rotas()
{
    $file_path = APPPATH . 'config/routes.php';

    $conteudo = file_get_contents($file_path);

    $rotas = [
        "\$route['pagou/notification'] = 'notification/index';",
    ];

    $alterado = false;
    foreach ($rotas as $rota) {
        if (strpos($conteudo, $rota) === false) {
            $conteudo .= "\n" . $rota;
            $alterado = true;
        }
    }

    if ($alterado) {
        file_put_contents($file_path, $conteudo);
    }
}

hooks()->add_filter('module_pagou_action_links', 'module_pagou_action_links');


function module_pagou_action_links($actions)
{
    $actions[] = '<a href="https://liquidasp.com.br/shortly/PagouAPI" target="_blank">CONTA GRÁTIS PAGOU.COM.BR</a>';
    return $actions;
}


function pagou_auto_issue_boleto($invoice_id)
{
    $CI = &get_instance();
    $CI->load->model('invoices_model');
    $invoice = $CI->invoices_model->get($invoice_id);
    if (!$invoice || !pagou_invoice_is_open($invoice)) {
        return false;
    }

    $allowed = !empty($invoice->allowed_payment_modes) ? @unserialize($invoice->allowed_payment_modes) : [];
    if (!is_array($allowed) || !in_array('pagou_boleto', $allowed, true)) {
        return false;
    }

    try {
        require_once(__DIR__ . '/libraries/Pagou_boleto_gateway.php');
        $gateway = new Pagou_boleto_gateway();
        // O gateway reaproveita, atualiza ou substitui o boleto existente conforme a consulta à API
        $result = $gateway->process_payment(['invoice' => $invoice, 'auto_generated' => true]);
        return pagou_flag_boleto_result($invoice_id, $result, false);
    } catch (\Throwable $e) {
        log_activity('Pagou: Erro ao gerar boleto automaticamente: ' . $e->getMessage());
        return false;
    }
}

/**
 * Grava em invoice_pagou o resultado da emissão automática:
 * 1 = emitido ou indeterminado (não reemitir às cegas), 2 = recusado pela API (só no cron);
 * 'later' (sem chave, banco não atualizado, trava ocupada) não grava nada.
 */
function pagou_flag_boleto_result($invoice_id, $result, $from_cron)
{
    $CI = &get_instance();
    if ($result === 'later') {
        return false;
    }
    if ($result === 'uncertain') {
        log_activity('Pagou: emissão do boleto da fatura ID ' . $invoice_id . ' sem confirmação da API. Confira no painel da Pagou antes de emitir outro.');
        $flag = 1;
    } elseif ($result) {
        $flag = 1;
    } else {
        if (!$from_cron) {
            return false;
        }
        $flag = 2;
    }
    $CI->db->where('id', $invoice_id)->update(db_prefix() . 'invoices', ['invoice_pagou' => $flag]);

    return $flag === 1 && $result !== 'uncertain';
}


function pagou_auto_issue_boleto_on_update($invoice_id)
{
    return pagou_auto_issue_boleto($invoice_id);
}


function pagou_update_boleto_info($transaction)
{
    try {
        pagou_load_libraries();
        if (!Pagou_schema::ready()) {
            return false;
        }
        $events = new Pagou_events();
        return $events->refresh_boleto($transaction) !== null;
    } catch (\Throwable $e) {
        log_activity('Pagou: Erro ao atualizar informações do boleto: ' . $e->getMessage());
        return false;
    }
}


function pagou_save_pix_info_from_boleto($invoice_id, $boleto_data)
{
    try {
        return pagou_save_linked_pix($invoice_id, $boleto_data);
    } catch (\Throwable $e) {
        log_activity('Pagou: Erro ao salvar informações de PIX do boleto: ' . $e->getMessage());
        return false;
    }
}

function process_pagou_boleto_invoices()
{
    $CI = &get_instance();

    // Só depois da atualização do banco (migration 200), que protege as faturas existentes
    pagou_load_libraries();
    if (!Pagou_schema::ready()) {
        return;
    }

    // Só faturas abertas (1 não paga, 3 parcial, 4 vencida) ainda não processadas
    $CI->db->where("allowed_payment_modes LIKE '%\"pagou_boleto\"%' ");
    $CI->db->where("(invoice_pagou IS NULL OR invoice_pagou = 0)");
    $CI->db->where_in('status', [1, 3, 4]);
    // Lote pequeno por execução: cada emissão pode esperar a API
    $CI->db->order_by('id', 'ASC')->limit(20);
    $invoices = $CI->db->get(db_prefix() . 'invoices')->result();

    if ($invoices) {
        require_once(__DIR__ . '/libraries/Pagou_boleto_gateway.php');
        $gateway = new Pagou_boleto_gateway();

        foreach ($invoices as $invoice) {
            $data = ['invoice' => $invoice, 'auto_generated' => true];
            try {
                $result = $gateway->process_payment($data);
            } catch (\Throwable $e) {
                log_activity('Pagou: erro na emissão automática do boleto da fatura ID ' . $invoice->id . ': ' . $e->getMessage());
                $result = 'uncertain';
            }
            pagou_flag_boleto_result($invoice->id, $result, true);
        }
    }
}


/**
 * Reconciliação pelo cron: dá baixa em cobranças pagas cujo webhook não chegou.
 */
function pagou_reconcile_payments()
{
    try {
        pagou_load_libraries();
        if (!Pagou_schema::ready()) {
            return;
        }
        $events = new Pagou_events();
        $events->reconcile();
    } catch (\Throwable $e) {
        log_activity('Pagou: erro na reconciliação de pagamentos: ' . $e->getMessage());
    }
}


/**
 * Fatura cancelada: cancela o Pix próprio ainda em aberto (DELETE /v1/pix/{id}).
 * 204 só indica que o pedido foi aceito; se o Pix já tiver sido pago, a baixa segue pelo webhook.
 */
function pagou_cancel_invoice_pix($invoice_id)
{
    try {
        pagou_load_libraries();
        if (!Pagou_schema::ready()) {
            return;
        }
        $api = Pagou_api::for_gateway('pagou_pix');
        if (!$api) {
            return;
        }
        $CI = &get_instance();
        $table = db_prefix() . 'pagou_transactions_pix';
        $rows = $CI->db->where('invoice_id', $invoice_id)
            ->where('boleto_transaction_id IS NULL', null, false)
            ->where_in('status', ['pending', 'active', '1', '2'])
            ->get($table)->result();
        foreach ($rows as $row) {
            $response = $api->cancel_pix($row->transaction_id);
            if ($response->http_code === 204) {
                // Pedido aceito; a reconciliação acompanha até o estado final
                $CI->db->where('id', $row->id)->update($table, ['status' => 'cancel_requested']);
            } else {
                log_activity('Pagou: não foi possível cancelar o Pix ' . $row->transaction_id . ' da fatura ID ' . $invoice_id . ' (HTTP ' . $response->http_code . ').');
            }
        }
    } catch (\Throwable $e) {
        log_activity('Pagou: erro ao cancelar o Pix da fatura: ' . $e->getMessage());
    }
}
