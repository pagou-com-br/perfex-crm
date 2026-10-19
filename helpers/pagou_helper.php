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
                         ->order_by('id', 'DESC')
                         ->get(db_prefix() . 'pagou_transactions_boleto')
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
    if (in_array(pagou_normalize_status($transaction->status, 'boleto'), ['cancelled', 'paid', 'refunded'], true)) {
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
    $api_endpoint = pagou_gateway_api_base_url('pagou_boleto') . '/v1/charges/' . rawurlencode($transaction->transaction_id);
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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: pagou-perfex/1.2.1',
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
            // 204 indica que o pedido foi aceito; o webhook/consulta confirma o estado final
            $CI->db->where('id', $transaction->id)
                   ->update(db_prefix() . 'pagou_transactions_boleto', [
                       'status' => 'cancelled'
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


/* ============================
     Ambiente, credenciais e webhooks
   ============================ */

/**
 * URL base da API Pagou para o ambiente informado.
 * Sandbox: https://api.pagou.cloud · Produção: https://api.pagou.com.br
 *
 * @param bool $is_production
 * @return string
 */
function pagou_api_base_url($is_production)
{
    return $is_production ? 'https://api.pagou.com.br' : 'https://api.pagou.cloud';
}

/**
 * Indica se o gateway (pagou_pix, pagou_boleto) está configurado para produção.
 *
 * @param string $gateway_id
 * @return bool
 */
function pagou_gateway_is_production($gateway_id)
{
    return get_option('paymentmethod_' . $gateway_id . '_is_production') === '1';
}

/**
 * URL base da API para o ambiente configurado no gateway.
 *
 * @param string $gateway_id
 * @return string
 */
function pagou_gateway_api_base_url($gateway_id)
{
    return pagou_api_base_url(pagou_gateway_is_production($gateway_id));
}

/**
 * Chave de API descriptografada do gateway, ou null se não configurada.
 *
 * @param string $gateway_id
 * @return string|null
 */
function pagou_gateway_api_token($gateway_id)
{
    $CI = &get_instance();
    $value = get_option('paymentmethod_' . $gateway_id . '_api_token');
    if (empty($value)) {
        return null;
    }
    $CI->load->library('encryption');
    $token = $CI->encryption->decrypt($value);

    return $token ? trim($token) : null;
}

/**
 * Chaves de API configuradas nos gateways da Pagou, sem repetição.
 * Usadas para autenticar webhooks, que são assinados com a chave da cobrança.
 *
 * @return string[]
 */
function pagou_configured_api_tokens()
{
    $tokens = pagou_current_api_tokens();

    // Chaves anteriores: eventos de cobranças antigas continuam assinados com elas
    foreach (pagou_known_api_tokens() as $token) {
        $tokens[] = $token;
    }

    return array_values(array_unique($tokens));
}

/**
 * Chaves atualmente configuradas nos gateways.
 *
 * @return string[]
 */
function pagou_current_api_tokens()
{
    $tokens = [];
    foreach (['pagou_pix', 'pagou_boleto'] as $gateway_id) {
        $token = pagou_gateway_api_token($gateway_id);
        if ($token) {
            $tokens[] = $token;
        }
    }

    return array_values(array_unique($tokens));
}

/**
 * Chaves já usadas pelos gateways, guardadas criptografadas na opção pagou_known_api_tokens.
 *
 * @return string[]
 */
function pagou_known_api_tokens()
{
    $stored = get_option('pagou_known_api_tokens');
    if (empty($stored)) {
        return [];
    }
    $CI = &get_instance();
    $CI->load->library('encryption');
    $decoded = json_decode((string) $CI->encryption->decrypt($stored), true);

    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
}

/**
 * Acrescenta as chaves atuais ao histórico (no máximo 10), para que a troca de chave
 * não invalide os webhooks das cobranças emitidas antes dela.
 *
 * @return void
 */
function pagou_remember_api_tokens()
{
    $known = pagou_known_api_tokens();
    $current = pagou_current_api_tokens();
    $missing = array_diff($current, $known);
    if (empty($missing)) {
        return;
    }

    $merged = array_slice(array_values(array_unique(array_merge($current, $known))), 0, 10);
    $CI = &get_instance();
    $CI->load->library('encryption');
    $value = $CI->encryption->encrypt(json_encode($merged));

    $exists = $CI->db->where('name', 'pagou_known_api_tokens')->count_all_results(db_prefix() . 'options') > 0;
    if ($exists) {
        update_option('pagou_known_api_tokens', $value);
    } else {
        add_option('pagou_known_api_tokens', $value, 0);
    }
}

/**
 * Valida a assinatura HMAC-SHA256 de um webhook da Pagou.
 * A mensagem assinada é o timestamp concatenado ao corpo bruto, sem separador.
 *
 * @param string      $raw_body  Corpo bruto da requisição
 * @param string|null $timestamp Cabeçalho X-Pagou-Timestamp (segundos Unix)
 * @param string|null $signature Cabeçalho X-Pagou-Signature (64 caracteres hex)
 * @param string[]    $api_keys  Chaves candidatas
 * @param int         $tolerance Diferença máxima, em segundos, entre o timestamp e o relógio local
 * @return bool
 */
function pagou_verify_webhook_signature($raw_body, $timestamp, $signature, array $api_keys, $tolerance = 300)
{
    $timestamp = trim((string) $timestamp);
    $signature = strtolower(trim((string) $signature));

    if ($timestamp === '' || strlen($timestamp) > 16 || !ctype_digit($timestamp)) {
        return false;
    }
    if (abs(time() - (int) $timestamp) > $tolerance) {
        return false;
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $signature)) {
        return false;
    }

    foreach ($api_keys as $key) {
        if ($key !== '' && hash_equals(hash_hmac('sha256', $timestamp . $raw_body, $key), $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * Autentica o webhook da requisição atual com as chaves configuradas.
 *
 * @param string $raw_body Corpo bruto da requisição
 * @return bool
 */
function pagou_authenticate_webhook_request($raw_body)
{
    $timestamp = $_SERVER['HTTP_X_PAGOU_TIMESTAMP'] ?? null;
    $signature = $_SERVER['HTTP_X_PAGOU_SIGNATURE'] ?? null;

    return pagou_verify_webhook_signature($raw_body, $timestamp, $signature, pagou_configured_api_tokens());
}

/**
 * Valor pago informado por um evento de webhook.
 * Em charge.paid, amount é um objeto {paid, original}; nos eventos de Pix, um número em reais.
 *
 * @param array $event_data Conteúdo de data do webhook
 * @return float|null
 */
function pagou_event_amount(array $event_data)
{
    $amount = $event_data['amount'] ?? null;
    if (is_array($amount)) {
        $amount = $amount['paid'] ?? null;
    }

    return is_numeric($amount) ? (float) $amount : null;
}

/**
 * Converte o estado da API para o vocabulário local das tabelas de transação.
 * Pix: 1 pendente, 2 ativo, 3 cancelado, 4 pago, 5 estornado, 6 removido.
 * Boleto: 1 pendente, 2 ativo, 3 cancelado, 4/8/9 pago, 5 estornado, 6 rejeitado, 7 outro.
 *
 * @param mixed  $status       Estado numérico da API ou rótulo local
 * @param string $payment_type 'pix' ou 'boleto'
 * @return string
 */
function pagou_normalize_status($status, $payment_type = 'pix')
{
    if (is_numeric($status)) {
        $map = [
            1 => 'pending',
            2 => 'active',
            3 => 'cancelled',
            4 => 'paid',
            5 => 'refunded',
            6 => ($payment_type === 'pix') ? 'removed' : 'rejected',
            7 => 'other',
            8 => 'paid',
            9 => 'paid',
        ];

        return $map[(int) $status] ?? 'unknown';
    }

    $status = strtolower(trim((string) $status));
    $aliases = [
        'confirmed' => 'paid',
        'refund'    => 'refunded',
        'estornado' => 'refunded',
        'estorno'   => 'refunded',
        'canceled'  => 'cancelled',
    ];

    return $aliases[$status] ?? $status;
}

/**
 * Fatura aberta para cobrança: 1 não paga, 3 parcialmente paga, 4 vencida.
 *
 * @param object $invoice
 * @return bool
 */
function pagou_invoice_is_open($invoice)
{
    return $invoice && in_array((int) $invoice->status, [1, 3, 4], true);
}

/**
 * Indica se a consulta GET /v1/charges/{id} mostra o boleto pago,
 * pelo boleto (4, 8, 9) ou pelo Pix vinculado (qrcode_status 4).
 *
 * @param object $charge
 * @return bool
 */
function pagou_boleto_api_is_paid($charge)
{
    if (!is_object($charge)) {
        return false;
    }
    if (isset($charge->status) && in_array((int) $charge->status, [4, 8, 9], true)) {
        return true;
    }

    return isset($charge->payload->qrcode_status) && (int) $charge->payload->qrcode_status === 4;
}

/**
 * Garante as colunas adicionadas na 1.2.1 (também criadas pela migration 121).
 *
 * @return void
 */
function pagou_ensure_schema_121()
{
    static $done = false;
    if ($done) {
        return;
    }
    $CI = &get_instance();
    $table = db_prefix() . 'pagou_transactions_pix';
    if ($CI->db->table_exists($table) && !$CI->db->field_exists('boleto_transaction_id', $table)) {
        $CI->db->query("ALTER TABLE `{$table}` ADD COLUMN `boleto_transaction_id` varchar(255) DEFAULT NULL");
    }
    $done = true;
}

/**
 * Grava o Pix vinculado a um boleto numa linha própria de pagou_transactions_pix,
 * identificada pelo qrcode_id e ligada ao boleto por boleto_transaction_id.
 * Não altera o Pix avulso da fatura nem regride uma linha já paga ou estornada.
 *
 * @param int    $invoice_id
 * @param object $boleto_data Resposta de criação ou consulta do boleto
 * @return bool
 */
function pagou_save_linked_pix($invoice_id, $boleto_data)
{
    if (!isset($boleto_data->id, $boleto_data->payload->qrcode_id) || empty($boleto_data->payload->data)) {
        return false;
    }

    pagou_ensure_schema_121();
    $CI = &get_instance();
    $table = db_prefix() . 'pagou_transactions_pix';

    $existing = $CI->db->where('transaction_id', $boleto_data->payload->qrcode_id)->get($table)->row();
    $qr_status = isset($boleto_data->payload->qrcode_status)
        ? pagou_normalize_status($boleto_data->payload->qrcode_status, 'pix')
        : 'pending';

    $pix_data = [
        'invoice_id'            => $invoice_id,
        'transaction_id'        => $boleto_data->payload->qrcode_id,
        'boleto_transaction_id' => $boleto_data->id,
        'cliente'               => $boleto_data->payer->name ?? '',
        'doc'                   => $boleto_data->payer->document ?? '',
        'payment_method'        => 'pix',
        'qr_code'               => $boleto_data->payload->image ?? '',
        'qr_code_text'          => $boleto_data->payload->data ?? '',
        'amount'                => $boleto_data->amount ?? 0,
        'amount_final'          => $boleto_data->amount ?? 0,
        'api_response'          => json_encode($boleto_data),
    ];

    if ($existing) {
        if (!in_array($existing->status, ['paid', 'refund_requested', 'refunded'], true)) {
            $pix_data['status'] = $qr_status;
        }
        $CI->db->where('id', $existing->id)->update($table, $pix_data);
    } else {
        $pix_data['status'] = $qr_status;
        $pix_data['date_created'] = date('Y-m-d H:i:s');
        $pix_data['expiration_seconds'] = 86400;
        $CI->db->insert($table, $pix_data);
    }

    return true;
}
