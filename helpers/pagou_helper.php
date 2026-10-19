<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Funções do Gateway Pagou Boleto
   ============================ */

/**
 * Hook invoice_marked_as_cancelled: cancela o boleto em aberto da fatura (DELETE /v1/charges/{id}).
 * O 204 só indica que o pedido foi aceito; se o boleto (ou o Pix vinculado) já tiver sido pago,
 * a baixa segue pelo webhook e pela reconciliação.
 *
 * @param int $invoice_id ID da fatura.
 * @return bool
 */
function cancel_pagou_boleto($invoice_id)
{
    try {
        pagou_load_libraries();
        if (!Pagou_schema::ready()) {
            return false;
        }
        $api = Pagou_api::for_gateway('pagou_boleto');
        if (!$api) {
            return false;
        }

        $CI = &get_instance();
        $table = db_prefix() . 'pagou_transactions_boleto';
        $rows = $CI->db->where('invoice_id', $invoice_id)
            ->where_in('status', ['pending', 'active', '1', '2'])
            ->get($table)->result();

        foreach ($rows as $row) {
            $response = $api->cancel_charge($row->transaction_id);
            if ($response->http_code === 204) {
                $CI->db->where('id', $row->id)->update($table, ['status' => 'cancelled']);
            } else {
                log_activity('Pagou: não foi possível cancelar o boleto ' . $row->transaction_id . ' da fatura ID ' . $invoice_id . ' (HTTP ' . $response->http_code . ' ' . $response->error_code() . ').');
            }
        }

        return true;
    } catch (\Throwable $e) {
        log_activity('Pagou: erro ao cancelar o boleto da fatura: ' . $e->getMessage());
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
    // Somente para testes automatizados contra um servidor simulado
    if (defined('PAGOU_API_BASE_URL_OVERRIDE')) {
        return PAGOU_API_BASE_URL_OVERRIDE;
    }

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

/* ============================
     2.0: registro de logs, carregamento das bibliotecas
   ============================ */

/**
 * Carrega o cliente da API e o processador de eventos do módulo.
 *
 * @return void
 */
function pagou_load_libraries()
{
    require_once __DIR__ . '/../libraries/pagou/Pagou_api.php';
    require_once __DIR__ . '/../libraries/pagou/Pagou_events.php';
    require_once __DIR__ . '/../libraries/pagou/Pagou_schema.php';
}

/**
 * Mascara dados sensíveis (documentos, dados bancários, chaves, tokens) antes de registrar.
 *
 * @param mixed $data
 * @return mixed
 */
function pagou_redact($data)
{
    $sensitive = [
        'document', 'doc', 'cpf', 'cnpj', 'account', 'agency', 'bank', 'x-api-key', 'api_key',
        'api_token', 'card_token', 'cvv', 'number', 'email', 'phone', 'zip', 'street',
        'qr_code', 'qr_code_img', 'image', 'data_url',
    ];
    if (is_object($data)) {
        $data = json_decode(json_encode($data), true);
    }
    if (!is_array($data)) {
        return $data;
    }
    foreach ($data as $key => $value) {
        if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
            $data[$key] = is_array($value) ? '[mascarado]' : (is_scalar($value) && $value !== '' ? '[mascarado]' : $value);
        } elseif (is_array($value) || is_object($value)) {
            $data[$key] = pagou_redact($value);
        }
    }

    return $data;
}

/**
 * Registra um log do módulo quando "pagou_logs_api" estiver ligado, sempre com dados mascarados.
 *
 * @param string $channel api, webhook, pix, boleto, cron
 * @param string $level   debug, info, error
 * @param string $message
 * @param array  $context
 * @return void
 */
function pagou_log($channel, $level, $message, array $context = [])
{
    if (get_option('pagou_logs_api') !== '1') {
        return;
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [Pagou ' . $channel . '] ' . $message;
    if ($context) {
        $line .= ' | ' . json_encode(pagou_redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    log_message($level === 'debug' ? 'debug' : ($level === 'error' ? 'error' : 'info'), $line);
    @error_log($line . PHP_EOL, 3, APPPATH . 'logs/pagou_' . $channel . '_' . date('Y-m-d') . '.log');
}

/**
 * Data/hora UTC no formato aceito pelos filtros de listagem (YYYY-MM-DDTHH:mm:ssZ).
 *
 * @param int $timestamp
 * @return string
 */
function pagou_utc_filter_time($timestamp)
{
    return gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
}

/**
 * Data de vencimento aceita pela API: a da fatura, ou hoje (piso em UTC) se já passou.
 *
 * @param string|null $duedate
 * @return string YYYY-MM-DD
 */
function pagou_effective_due_date($duedate)
{
    $today = max(date('Y-m-d'), gmdate('Y-m-d'));
    if (!empty($duedate)) {
        $due = date('Y-m-d', strtotime($duedate));
        if ($due > $today) {
            return $due;
        }
    }

    return $today;
}

/**
 * Valor ainda em aberto da fatura (respeita pagamentos parciais).
 *
 * @param object $invoice
 * @return float
 */
function pagou_invoice_amount_due($invoice)
{
    if (function_exists('get_invoice_total_left_to_pay')) {
        return round((float) get_invoice_total_left_to_pay($invoice->id, $invoice->total), 2);
    }

    return round((float) $invoice->total, 2);
}

/**
 * Valida CPF ou CNPJ (somente dígitos).
 *
 * @param string $document
 * @return bool
 */
function pagou_valid_document($document)
{
    $d = preg_replace('/\D/', '', (string) $document);
    if (strlen($d) === 11) {
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            if ((int) $d[$t] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }
        return true;
    }
    if (strlen($d) === 14) {
        if (preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        $weights = [[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]];
        foreach ([12, 13] as $k => $len) {
            $sum = 0;
            for ($i = 0; $i < $len; $i++) {
                $sum += (int) $d[$i] * $weights[$k][$i];
            }
            $rest = $sum % 11;
            if ((int) $d[$len] !== ($rest < 2 ? 0 : 11 - $rest)) {
                return false;
            }
        }
        return true;
    }

    return false;
}
