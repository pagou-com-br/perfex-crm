<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Pagou Integração Perfex CRM
Description: Gateway de pagamento www.pagou.com.br, PIX & Boleto para Perfex CRM
Version: 1.2.0
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


if ($CI->uri->segment(1) === 'pagou' && $CI->uri->segment(2) === 'webhook') {
    require_once __DIR__ . '/controllers/Pagou.php';
    $pagou = new Pagou();
    $pagou->webhook();
    exit;
}


register_activation_hook('pagou', 'pagou_activation_hook');
register_payment_gateway('pagou_boleto_gateway', 'pagou');
register_payment_gateway('pagou_pix_gateway', 'pagou');

hooks()->add_action('invoice_marked_as_cancelled', 'cancel_pagou_boleto');
hooks()->add_action('after_invoice_added', 'pagou_auto_issue_boleto');
hooks()->add_action('after_invoice_updated', 'pagou_auto_issue_boleto_on_update');
hooks()->add_action('after_cron_run', 'process_pagou_boleto_invoices');

hooks()->add_action('admin_init', function () {
    $CI = &get_instance();

    if (has_permission('pagou', '', 'view')) {
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
        "\$route['pagou_gateway/make_payment/(:num)/(:any)'] = 'pagou_gateway/make_payment/\$1/\$2';",
        "\$route['pagou_gateway/pay'] = 'pagou_gateway/pay';",
        "\$route['pagou_gateway/callback'] = 'pagou_gateway/callback';",
        "\$route['pagou/webhook'] = 'pagou/webhook';",
        "\$route['pagou/notification'] = 'notification/index';",
        "\$route['pagou/check_payment/(:any)'] = 'pagou/check_payment/\$1';"
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
    
    if (!$invoice) {
        log_activity('Pagou: Fatura ID ' . $invoice_id . ' não encontrada para emissão automática de boleto.');
        return false;
    }
    

    $allowed_payment_modes = [];
    if (!empty($invoice->allowed_payment_modes)) {
        $allowed_payment_modes = unserialize($invoice->allowed_payment_modes);
    }
    
    if (!is_array($allowed_payment_modes)) {
        log_activity('Pagou: Fatura ID ' . $invoice_id . ' - Modos de pagamento não definidos ou inválidos.');
        return false;
    }
    
    if (!in_array('pagou_boleto', $allowed_payment_modes)) {
        return false;
    }
    
    $CI->db->where('invoice_id', $invoice_id);
    $existing_transaction = $CI->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
    
    if ($existing_transaction) {
        return pagou_update_boleto_info($existing_transaction);
    }
    
    try {
        require_once(__DIR__ . '/libraries/Pagou_boleto_gateway.php');
        $gateway = new Pagou_boleto_gateway();
        
        $data = ['invoice' => $invoice];
        
        $result = $gateway->process_payment($data);
        
        if ($result) {
            log_activity('Pagou: Boleto gerado automaticamente para a fatura ID ' . $invoice_id);
            return true;
        } else {
            log_activity('Pagou: Falha ao gerar boleto automaticamente para a fatura ID ' . $invoice_id);
            return false;
        }
    } catch (Exception $e) {
        log_activity('Pagou: Erro ao gerar boleto automaticamente: ' . $e->getMessage());
        return false;
    }
}


function pagou_auto_issue_boleto_on_update($invoice_id)
{
    $CI = &get_instance();
    
    $CI->db->where('invoice_id', $invoice_id);
    $existing = $CI->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
    
    if (!$existing) {
        return pagou_auto_issue_boleto($invoice_id);
    }
    
    if (!in_array($existing->status, ['paid', 'confirmed'])) {
        return pagou_update_boleto_info($existing);
    }
    
    return false;
}


function pagou_update_boleto_info($transaction)
{
    $CI = &get_instance();
    
    try {
        require_once(__DIR__ . '/libraries/pagou/Api_client.php');
        
        $api_token = get_option('paymentmethod_pagou_boleto_api_token');
        if (empty($api_token)) {
            log_activity('Pagou: API Token não configurado.');
            return false;
        }
        
        $CI->load->library('encryption');
        $api_token = $CI->encryption->decrypt($api_token);
        
        if (empty($api_token)) {
            log_activity('Pagou: Não foi possível descriptografar o API Token.');
            return false;
        }
        
        $is_production = get_option('paymentmethod_pagou_boleto_is_production') === '1';
        
        $api_client = new Api_client($api_token, $is_production);
        
        $result = $api_client->getBoleto($transaction->transaction_id);
        
        if (!$result['success']) {
            log_activity('Pagou: Falha ao consultar boleto na API: ' . $result['message']);
            return false;
        }
        
        $update_data = [
            'status' => $result['data']->status ?? $transaction->status,
            'updated_at' => date('Y-m-d H:i:s'),
            'api_response' => json_encode($result['data'])
        ];
        
        $CI->db->where('id', $transaction->id);
        $CI->db->update(db_prefix() . 'pagou_transactions_boleto', $update_data);
        
        if (isset($result['data']->payload) && isset($result['data']->payload->qrcode_id)) {
            pagou_save_pix_info_from_boleto($transaction->invoice_id, $result['data']);
        }
        
        return true;
    } catch (Exception $e) {
        log_activity('Pagou: Erro ao atualizar informações do boleto: ' . $e->getMessage());
        return false;
    }
}


function pagou_save_pix_info_from_boleto($invoice_id, $boleto_data)
{
    $CI = &get_instance();
    
    try {
        if (!isset($boleto_data->payload) || 
            !isset($boleto_data->payload->qrcode_id) || 
            !isset($boleto_data->payload->data)) {
            return false;
        }
        
        $CI->db->where('invoice_id', $invoice_id);
        $existing_pix = $CI->db->get(db_prefix() . 'pagou_transactions_pix')->row();
        
        $pix_data = [
            'invoice_id' => $invoice_id,
            'transaction_id' => $boleto_data->payload->qrcode_id,
            'cliente' => $boleto_data->payer->name ?? '',
            'doc' => $boleto_data->payer->document ?? '',
            'payment_method' => 'pix',
            'qr_code' => $boleto_data->payload->image ?? '',
            'qr_code_text' => $boleto_data->payload->data ?? '',
            'amount' => $boleto_data->amount ?? 0,
            'amount_final' => $boleto_data->amount ?? 0,
            'status' => $boleto_data->status ?? 'pending',
            'date_created' => date('Y-m-d H:i:s'),
            'external_id' => $boleto_data->external_id ?? '',
            'expiration_seconds' => 86400, // 24 horas
            'api_response' => json_encode($boleto_data)
        ];
        
        if ($existing_pix) {
            $CI->db->where('id', $existing_pix->id);
            $CI->db->update(db_prefix() . 'pagou_transactions_pix', $pix_data);
        } else {
            $CI->db->insert(db_prefix() . 'pagou_transactions_pix', $pix_data);
        }
        
        return true;
    } catch (Exception $e) {
        log_activity('Pagou: Erro ao salvar informações de PIX do boleto: ' . $e->getMessage());
        return false;
    }
}

function process_pagou_boleto_invoices()
{
    $CI = &get_instance();

    $CI->db->where("allowed_payment_modes LIKE '%\"pagou_boleto\"%' ");
    $CI->db->where("(invoice_pagou IS NULL OR invoice_pagou = 0)");
    $invoices = $CI->db->get(db_prefix() . 'invoices')->result();

    if ($invoices) {
        $gateway = new Pagou_boleto_gateway();

        foreach ($invoices as $invoice) {
            $data = ['invoice' => $invoice];
            $boleto_created = $gateway->process_payment($data);

            if ($boleto_created) {
                $CI->db->where('id', $invoice->id);
                $CI->db->update(db_prefix() . 'invoices', ['invoice_pagou' => 1]);
            }
        }
    }
}