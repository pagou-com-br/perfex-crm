<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Funções do Gateway Pagou Boleto
   ============================ */

/**
 * Função principal que será chamada pelo hook quando uma fatura for cancelada.
 *
 * @param int $invoice_id ID da fatura.
 * @return bool Retorna true se o cancelamento for bem-sucedido ou se não houver boleto, false em caso de falha.
 */
function cancel_pagou_boleto($invoice_id)
{
    $CI = &get_instance();
    
    /* ============================
         Verificação da Fatura
       ============================ */
    // Primeiro, verificar se a fatura realmente está cancelada
    $CI->db->where('id', $invoice_id);
    $invoice = $CI->db->get('tblinvoices')->row();
    
    if (!$invoice || $invoice->status != 5) { // 5 = Cancelado
        /*
        log_activity('Tentativa de cancelar boleto para fatura não cancelada #' . $invoice_id);
        */
        return false;
    }

    /* ============================
         Busca de Informações do Cliente
       ============================ */
    // Buscar informações do cliente
    $client = $CI->db->where('userid', $invoice->clientid)
                     ->get('tblclients')
                     ->row();

    if (!$client) {
        /*
        log_activity('Cliente não encontrado para fatura #' . $invoice_id);
        */
        return false;
    }

    /* ============================
         Busca do Número do Endereço
       ============================ */
    // Buscar o número do endereço nos campos personalizados
    $address_number = get_customer_address_number($client->userid);

    /* ============================
         Verificação do Boleto Existente
       ============================ */
    // Verificar se existe um boleto para esta fatura
    $transaction = $CI->db->where('invoice_id', $invoice_id)
                         ->get(db_prefix() . 'pagou_transactions')
                         ->row();

    if (!$transaction) {
        /*
        log_activity('Nenhum boleto encontrado para fatura #' . $invoice_id);
        */
        return true; // Retorna true pois não há erro, apenas não existe boleto
    }

    /* ============================
         Verificação do Status do Boleto
       ============================ */
    // Se o boleto já estiver cancelado ou pago, não precisa fazer nada
    if (in_array($transaction->status, ['cancelled', 'paid'])) {
        /*
        log_activity('Boleto já está cancelado ou pago para fatura #' . $invoice_id);
        */
        return true;
    }

    /* ============================
         Inicialização do Gateway Pagou Boleto
       ============================ */
    // Carregar a classe do gateway
    $CI->load->library('pagou_boleto_gateway');
    $gateway = new Pagou_boleto_gateway();

    /* ============================
         Configuração do Endpoint da API
       ============================ */
    // Configurar endpoint da API
    $api_endpoint = 'https://api.pagou.com.br/v1/charges/' . $transaction->transaction_id;
    $api_token = $gateway->decryptSetting('api_token');

    try {
        /* ============================
             Requisição de Cancelamento à API
           ============================ */
        // Fazer requisição de cancelamento
        $ch = curl_init($api_endpoint);
        
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-KEY: ' . $api_token
            ]
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);

        /* ============================
             Log da Resposta da API
           ============================ */
        /*
        log_activity('Resposta da API Pagou para cancelamento do boleto da fatura #' . $invoice_id . ': HTTP Code: ' . $http_code);
        */

        /* ============================
             Verificação do Cancelamento
           ============================ */
        // Se o cancelamento foi bem sucedido (código 204)
        if ($http_code === 204) {
            /* ============================
                 Atualização da Transação no Banco de Dados
               ============================ */
            // Atualizar o status da transação no banco de dados
            $CI->db->where('invoice_id', $invoice_id)
                   ->update(db_prefix() . 'pagou_transactions', [
                       'status' => 'cancelled',
                       'date_updated' => date('Y-m-d H:i:s')
                   ]);

            /*
            log_activity('Boleto cancelado com sucesso para fatura #' . $invoice_id);
            */
            return true;
        }

        /* ============================
             Tratamento de Erros no Cancelamento
           ============================ */
        // Se houve erro no cancelamento
        $error_message = 'Erro ao cancelar boleto da fatura #' . $invoice_id . ': HTTP Code ' . $http_code;
        if ($response) {
            $response_data = json_decode($response);
            if ($response_data && isset($response_data->error)) {
                $error_message .= ' - ' . $response_data->error;
            }
        }

        /*
        log_activity($error_message);
        */
        return false;

    } catch (Exception $e) {
        /*
        log_activity('Erro ao cancelar boleto da fatura #' . $invoice_id . ': ' . $e->getMessage());
        */
        return false;
    }
}

/**
 * Função auxiliar para buscar o número do endereço do cliente.
 *
 * @param int $client_id ID do cliente.
 * @return string Retorna o número do endereço ou uma string vazia se não encontrado.
 */
function get_customer_address_number($client_id)
{
    $CI = &get_instance();
    
    /* ============================
         Busca do ID do Campo Personalizado
       ============================ */
    // Primeiro, buscar o ID do campo personalizado
    $custom_field = $CI->db->where('fieldto', 'customers')
                          ->where('slug', 'customers_numero')
                          ->get('tblcustomfields')
                          ->row();

    if (!$custom_field) {
        return '';
    }

    /* ============================
         Busca do Valor do Campo Personalizado
       ============================ */
    // Agora buscar o valor para este cliente
    $field_value = $CI->db->where('relid', $client_id)
                         ->where('fieldid', $custom_field->id)
                         ->get('tblcustomfieldsvalues')
                         ->row();

    return $field_value ? $field_value->value : '';
}
?>
