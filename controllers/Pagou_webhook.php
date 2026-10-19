<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controlador para processar notificações de webhook da Pagou.com.br
 * Versão: 3.2 (Correções completas para processamento de webhooks)
 */
class Pagou_webhook extends CI_Controller {
    
    // Flag para controlar logs
    private $enable_logs;
    
    // Adicionado para compatibilidade com o Perfex
    public $app_object_cache;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        parent::__construct();
        $this->config->set_item('csrf_protection', FALSE);
        // Iniciando em modo silencioso para evitar erros na resposta
        @ini_set('display_errors', 0);
        
        // Objeto cache simulado para compatibilidade
        $this->app_object_cache = new stdClass();
        $this->app_object_cache->staff = [];
        
        // Carrega dependências
        $this->load->database();
        $this->load->model('invoices_model');
        $this->load->model('payments_model');
        $this->load->model('pagou_model');
        
        // Inicializa o sistema de logs
        $option = $this->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api'])->row();
        $this->enable_logs = ($option && $option->value === '1');

        // Desativar proteção CSRF para este controlador
        $this->config->set_item('csrf_protection', FALSE);
        
        $this->log('debug', 'Pagou_webhook v3.2 inicializado. CSRF desativado: ' . ($this->config->item('csrf_protection') ? 'Não' : 'Sim'));
        
        // Verificar e criar tabelas/campos necessários
        $this->check_required_fields();
    }
    
    /**
     * Verifica se os campos e tabelas necessários existem
     */
    private function check_required_fields() {
        // Verifica se a tabela de transações PIX existe
        if (!$this->db->table_exists(db_prefix() . 'pagou_transactions_pix')) {
            $this->log('error', 'Tabela pagou_transactions_pix não existe. Criando tabela...');
            
            // Aqui você pode adicionar a criação da tabela se necessário
            // Mas seria melhor ter isso em um arquivo de instalação/migração separado
        }
        
        // Verifica se há campos necessários na tabela de transações PIX
        $fields = $this->db->list_fields(db_prefix() . 'pagou_transactions_pix');
        
        // Verifica campos essenciais
        $required_fields = [
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => TRUE],
            'data_pagamento' => ['type' => 'DATETIME', 'null' => TRUE],
            'data_estorno' => ['type' => 'DATETIME', 'null' => TRUE],
            'reembolso_log' => ['type' => 'TEXT', 'null' => TRUE]
        ];
        
        foreach ($required_fields as $field => $attributes) {
            if (!in_array($field, $fields)) {
                $this->log('warning', "Campo '{$field}' não encontrado na tabela pagou_transactions_pix. Adicionando...");
                
                // Adiciona o campo à tabela
                try {
                    $this->db->query("ALTER TABLE " . db_prefix() . "pagou_transactions_pix ADD {$field} {$attributes['type']}" . 
                                     ($attributes['type'] == 'VARCHAR' ? "({$attributes['constraint']})" : "") . 
                                     ($attributes['null'] ? " NULL" : " NOT NULL"));
                    
                    $this->log('info', "Campo '{$field}' adicionado com sucesso à tabela pagou_transactions_pix");
                } catch (\Throwable $e) {
                    $this->log('error', "Erro ao adicionar campo '{$field}': " . $e->getMessage());
                }
            }
        }
        
        // Verifica se a tabela de atividades existe (tblinvoiceactivity)
        // Esta tabela é utilizada para registrar atividades nas faturas
        if (!$this->db->table_exists(db_prefix() . 'invoiceactivity')) {
            $this->log('warning', 'Tabela invoiceactivity não existe no sistema. As atividades não serão registradas.');
        }
    }
    
    /**
     * Método para registrar logs
     * 
     * @param string $level Nível do log (debug, info, error, etc)
     * @param string $message Mensagem a ser registrada
     * @param mixed $data Dados adicionais para o log (opcional)
     */
    private function log($level, $message, $data = null)
    {
        if (!$this->enable_logs) {
            return;
        }
        
        $log_message = "[Pagou Webhook] " . $message;
        
        // Adiciona timestamp aos logs
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] " . $log_message;
        
        // Se tiver dados adicionais, adiciona ao log
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $log_message .= " | Dados: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $log_message .= " | Dados: " . $data;
            }
        }
        
        // Registra no sistema de logs do CodeIgniter
        log_message($level, $log_message);
        
        // Salva também em um arquivo dedicado
        $log_file = APPPATH . 'logs/pagou_webhook_' . date('Y-m-d') . '.log';
        error_log($log_message . PHP_EOL, 3, $log_file);
    }
    
    /**
     * Método principal que processa os webhooks da Pagou.com.br
     *
     * O parâmetro da URL é aceito apenas por compatibilidade com notification_url antigas
     * e nunca é usado para localizar faturas.
     *
     * @return void
     */
    public function process($ignored = null) {
        try {
            // Corpo bruto: a assinatura é calculada sobre os bytes exatos recebidos
            $raw_data = file_get_contents('php://input');

            if (!pagou_authenticate_webhook_request($raw_data)) {
                $this->log('error', 'Webhook rejeitado: X-Pagou-Signature ausente ou inválida.');
                $this->safeResponse(401, 'Assinatura inválida.');
                return;
            }

            $data = json_decode($raw_data, true);
            if (!is_array($data)) {
                $this->log('error', 'Erro ao decodificar JSON: ' . json_last_error_msg());
                $this->safeResponse(400, 'Erro na decodificação dos dados JSON.');
                return;
            }

            $this->log('debug', '====== INÍCIO DO PROCESSAMENTO DO WEBHOOK ======', [
                'name' => $data['name'] ?? null,
                'id' => $data['data']['id'] ?? null,
            ]);

            $result = $this->processWebhookData($data);

            $this->log('debug', '====== FIM DO PROCESSAMENTO DO WEBHOOK ======');
            $this->safeResponse($result['code'], $result['message']);

        } catch (\Throwable $e) {
            $this->log('error', 'Exceção não tratada:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // 5xx faz a Pagou reenviar o evento
            $this->safeResponse(500, 'Erro interno ao processar o webhook.');
        }
    }

    /**
     * Processa os dados recebidos no webhook (já autenticado)
     *
     * @param array $data Dados decodificados do webhook
     * @return array Resultado do processamento
     */
    private function processWebhookData($data) {
        $event_name = $data['name'] ?? null;
        $event_data = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];

        $is_pix_payment = ($event_name === 'qrcode.completed');
        $is_pix_refund = ($event_name === 'qrcode.refunded');
        $is_boleto_payment = ($event_name === 'charge.paid');

        if ($event_name === 'charge.created') {
            // Registro do boleto: não é pagamento
            return ['code' => 200, 'message' => 'Registro de boleto recebido.'];
        }

        if (!$is_pix_payment && !$is_pix_refund && !$is_boleto_payment) {
            $this->log('info', "Evento ignorado: {$event_name}");
            return ['code' => 200, 'message' => 'Evento ignorado.'];
        }

        $pagou_transaction_id = $event_data['id'] ?? null;
        if (!$pagou_transaction_id || !is_string($pagou_transaction_id)) {
            $this->log('error', 'ID da cobrança ausente.');
            return ['code' => 400, 'message' => 'Dados incompletos (ID da cobrança não fornecido).'];
        }

        $payment_type = $is_boleto_payment ? 'boleto' : 'pix';
        $amount_from_webhook = pagou_event_amount($event_data);
        $payer_info = $event_data['payer'] ?? null;
        $event_paid_at = $event_data['paid_at'] ?? null;

        $this->log('info', 'Dados extraídos:', [
            'event_name' => $event_name,
            'transaction_id' => $pagou_transaction_id,
            'event_transaction_id' => $event_data['transaction_id'] ?? null,
            'amount' => $amount_from_webhook,
        ]);

        // Só processa cobranças criadas por este módulo
        $transaction = $this->findTransaction($pagou_transaction_id, $payment_type);
        if (!$transaction) {
            $this->log('warning', "Cobrança {$pagou_transaction_id} não pertence a este Perfex. Evento ignorado.");
            return ['code' => 200, 'message' => 'Cobrança desconhecida. Evento ignorado.'];
        }

        if ($is_pix_refund) {
            return $this->processRefundEvent($transaction, $pagou_transaction_id, $amount_from_webhook, $data);
        }

        // Evento de pagamento atrasado não reabre uma cobrança já estornada
        if ($transaction->status === 'refunded') {
            $this->log('info', "Cobrança {$pagou_transaction_id} já estornada. Evento de pagamento ignorado.");
            return ['code' => 200, 'message' => 'Cobrança já estornada. Evento ignorado.'];
        }

        // Confirma na API antes de dar baixa
        $api_data = $this->getPagouTransactionData($pagou_transaction_id, $payment_type);
        if (!$api_data) {
            return ['code' => 503, 'message' => 'Não foi possível confirmar o pagamento na API. Reenvie depois.'];
        }

        $api_status = isset($api_data->status) ? (int) $api_data->status : null;
        $paid_statuses = ($payment_type === 'pix') ? [4] : [4, 8, 9];
        if (!in_array($api_status, $paid_statuses, true)) {
            $this->log('warning', 'Pagamento não confirmado pela API. Status: ' . var_export($api_status, true));
            $this->updateTransaction($transaction, $api_status, null, null, null, $data, $payment_type);
            return ['code' => 200, 'message' => 'Pagamento ainda não confirmado pela API.'];
        }

        $paid_at = $api_data->paid_at ?? $event_paid_at ?? date('Y-m-d H:i:s');
        if ($amount_from_webhook === null && isset($api_data->amount) && is_numeric($api_data->amount)) {
            $amount_from_webhook = (float) $api_data->amount;
        }

        $updated = $this->updateTransaction($transaction, 'paid', $paid_at, null, $amount_from_webhook, $data, $payment_type);
        if (!$updated) {
            return ['code' => 500, 'message' => 'Erro ao atualizar transação.'];
        }

        $payment_processed = $this->processPayment(
            $transaction,
            $pagou_transaction_id,
            'paid',
            $paid_at,
            $amount_from_webhook,
            $payer_info,
            $payment_type
        );

        if (!$payment_processed) {
            return ['code' => 500, 'message' => 'Erro ao registrar o pagamento na fatura.'];
        }

        $this->sendRefreshCommand($transaction->invoice_id);

        return ['code' => 200, 'message' => 'Pagamento processado com sucesso.'];
    }

    /**
     * Processa o evento qrcode.refunded (devolução Pix confirmada)
     *
     * @param object $transaction Transação local
     * @param string $pagou_transaction_id ID da cobrança na Pagou
     * @param float|null $refunded_amount Valor devolvido (pode ser parcial)
     * @param array $data Webhook completo
     * @return array
     */
    private function processRefundEvent($transaction, $pagou_transaction_id, $refunded_amount, $data) {
        $this->log('info', "Processando devolução da cobrança {$pagou_transaction_id}");

        $this->pagou_model->add_refund_log($pagou_transaction_id, [
            'action' => 'refund_webhook',
            'amount' => $refunded_amount,
            'transaction_id' => $data['data']['transaction_id'] ?? null,
        ]);

        // Não altera a data de pagamento nem o valor pago
        $refunded_at = date('Y-m-d H:i:s');
        if (!$this->updateTransaction($transaction, 'refunded', null, $refunded_at, null, $data, 'pix')) {
            return ['code' => 500, 'message' => 'Erro ao atualizar status para reembolso.'];
        }

        $paid_amount = (float) $transaction->amount_final;
        $is_full_refund = ($refunded_amount === null) || ($paid_amount > 0 && $refunded_amount + 0.009 >= $paid_amount);

        if ($is_full_refund) {
            $this->db->where('id', $transaction->invoice_id);
            $this->db->update(db_prefix() . 'invoices', ['status' => 5]);
            $this->log('info', "Fatura ID {$transaction->invoice_id} cancelada após devolução integral.");
        } elseif ($this->db->table_exists(db_prefix() . 'invoiceactivity')) {
            $this->db->insert(db_prefix() . 'invoiceactivity', [
                'invoiceid' => $transaction->invoice_id,
                'description' => 'Devolução Pix parcial de R$ ' . number_format($refunded_amount, 2, ',', '.') .
                                 ' confirmada pela Pagou. Ajuste o pagamento da fatura manualmente.',
                'additional_data' => serialize([]),
                'staffid' => 0,
                'date' => date('Y-m-d H:i:s')
            ]);
        }

        $this->sendRefreshCommand($transaction->invoice_id);

        return ['code' => 200, 'message' => 'Reembolso processado com sucesso.'];
    }

    /**
     * Envia comando para atualizar a página da fatura no navegador
     * 
     * @param int $invoice_id ID da fatura
     * @return void
     */
    private function sendRefreshCommand($invoice_id) {
        $this->log('info', "Enviando comando para atualizar página da fatura {$invoice_id}");
        
        // Implementação para notificar a página da fatura
        // Este é um exemplo usando WebSockets ou Server-Sent Events
        // Na implementação real, você precisaria de um sistema de eventos em tempo real
        
        // Como alternativa, podemos criar um registro na sessão que será verificado pela página
        $this->db->insert(db_prefix() . 'sessions_pagou_refresh', [
            'invoice_id' => $invoice_id,
            'timestamp' => time(),
            'processed' => 0
        ]);
        
        $this->log('info', "Comando de atualização registrado para a fatura {$invoice_id}");
    }
    
    /**
     * Encontra a transação local pelo ID da cobrança na Pagou
     *
     * @param string $pagou_transaction_id ID da cobrança na Pagou
     * @param string $payment_type Tipo de pagamento ('pix' ou 'boleto')
     * @return object|null Transação ou null se não encontrada
     */
    private function findTransaction($pagou_transaction_id, $payment_type = 'pix') {
        $this->db->where('transaction_id', $pagou_transaction_id);
        return $this->db->get(db_prefix() . 'pagou_transactions_' . $payment_type)->row();
    }

/**
 * Atualiza os dados da transação
 * 
 * @param object $transaction Transação a ser atualizada
 * @param string $new_status Novo status
 * @param string $paid_at Data de pagamento
 * @param string $refunded_at Data de estorno
 * @param float $amount Valor do pagamento
 * @param array $raw_data Dados brutos do webhook
 * @param string $payment_type Tipo de pagamento ('pix' ou 'boleto')
 * @return bool True se atualizado com sucesso, false caso contrário
 */
private function updateTransaction($transaction, $new_status, $paid_at = null, $refunded_at = null, $amount = null, $raw_data = null, $payment_type = 'pix') {
    try {
        $table_name = db_prefix() . 'pagou_transactions_' . $payment_type;
        $amount_final = $transaction->amount_final;

        if ($amount !== null) {
            $amount_final = $amount;
            $this->log('debug', "Valor recebido: R$ {$amount}, salvando como: R$ {$amount_final}");
        }

        // Normalização do status para as tabelas de transações
        $original_status = $new_status;
        
        // Estados numéricos da API viram o vocabulário local (ver pagou_normalize_status)
        $new_status = pagou_normalize_status($new_status, $payment_type);
        $this->log('debug', "Status convertido: '{$original_status}' → '{$new_status}'");

        // Prepara dados para atualização
        $update_data = [
            'status' => $new_status,
            'payment_method' => $payment_type, // Garante que o método de pagamento está correto
            'webhook_log' => ($transaction->webhook_log ? $transaction->webhook_log . "\n---\n" : '') . 
                             date('Y-m-d H:i:s') . ":\n" . json_encode($raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];
        
        if ($paid_at) {
            // Campo específico com base no tipo de pagamento
            if ($payment_type === 'pix') {
                $update_data['data_pagamento'] = date('Y-m-d H:i:s', strtotime($paid_at));
            } else {
                $update_data['payment_date'] = date('Y-m-d H:i:s', strtotime($paid_at));
            }
        }
        
        if ($refunded_at) {
            // Campo específico com base no tipo de pagamento
            if ($payment_type === 'pix') {
                $update_data['data_estorno'] = date('Y-m-d H:i:s', strtotime($refunded_at));
            } else {
                $update_data['refund_date'] = date('Y-m-d H:i:s', strtotime($refunded_at));
            }
        }
        
        if ($amount_final) {
            $update_data['amount_final'] = $amount_final;
        }
        
        $this->log('debug', "Dados de atualização para {$payment_type}:", $update_data);
        
        // Atualiza os dados da transação
        $this->db->where('id', $transaction->id);
        $result = $this->db->update($table_name, $update_data);
        
        if ($result) {
            $this->log('info', "Transação {$payment_type} ID {$transaction->id} (Fatura ID {$transaction->invoice_id}) atualizada para status '{$new_status}'. Status original: '{$original_status}'.");
            return true;
        } else {
            $error = $this->db->error();
            $this->log('error', "Erro SQL ao atualizar transação: " . $error['message']);
            return false;
        }
    } catch (\Throwable $e) {
        $this->log('error', "Erro ao atualizar transação {$payment_type}: " . $e->getMessage());
        return false;
    }
}
    /**
     * Obtém os dados da cobrança na API da Pagou
     *
     * @param string $transaction_id ID da cobrança
     * @param string $payment_type 'pix' ou 'boleto'
     * @return object|null Dados da cobrança ou null em caso de erro
     */
    private function getPagouTransactionData($transaction_id, $payment_type = 'pix') {
        $gateway_id = ($payment_type === 'pix') ? 'pagou_pix' : 'pagou_boleto';
        $api_token = pagou_gateway_api_token($gateway_id);
        if (!$api_token) {
            $this->log('error', "Chave da API não configurada no gateway {$gateway_id}");
            return null;
        }

        $api_endpoint = ($payment_type === 'pix') ? '/v1/pix/' : '/v1/charges/';
        $api_url = pagou_gateway_api_base_url($gateway_id) . $api_endpoint . rawurlencode($transaction_id);

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: pagou-perfex/1.2.1',
            'X-API-KEY: ' . $api_token
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error || $http_code !== 200) {
            $this->log('error', "Falha ao consultar a cobrança {$transaction_id}: HTTP {$http_code} {$curl_error}");
            return null;
        }

        $data = json_decode($response_body);
        return is_object($data) ? $data : null;
    }

    /**
     * Formata as informações do pagador para incluir na nota
     * 
     * @param object|array $payer_info Informações do pagador
     * @return string Informações formatadas
     */
    private function formatPayerInfo($payer_info) {
        if (empty($payer_info)) {
            return '';
        }
        
        $formatted = "\n\nInformações do Pagador:";
        
        if (isset($payer_info['name']) || isset($payer_info->name)) {
            $name = isset($payer_info['name']) ? $payer_info['name'] : $payer_info->name;
            $formatted .= "\nNome: " . $name;
        }
        
        if (isset($payer_info['document']) || isset($payer_info->document)) {
            $document = isset($payer_info['document']) ? $payer_info['document'] : $payer_info->document;
            // Formata CPF/CNPJ
            if (strlen($document) === 11) {
                $formatted_doc = substr($document, 0, 3) . '.' . substr($document, 3, 3) . '.' . substr($document, 6, 3) . '-' . substr($document, 9, 2);
                $formatted .= "\nCPF: " . $formatted_doc;
            } else if (strlen($document) === 14) {
                $formatted_doc = substr($document, 0, 2) . '.' . substr($document, 2, 3) . '.' . substr($document, 5, 3) . '/' . substr($document, 8, 4) . '-' . substr($document, 12, 2);
                $formatted .= "\nCNPJ: " . $formatted_doc;
            } else {
                $formatted .= "\nDocumento: " . $document;
            }
        }
        
        // Adiciona informações do banco
        $bank = null;
        if (isset($payer_info['bank'])) {
            $bank = $payer_info['bank'];
        } else if (isset($payer_info->bank)) {
            $bank = $payer_info->bank;
        }
        
        if ($bank) {
            $formatted .= "\n\nDados Bancários:";
            
            if (isset($bank['name']) || isset($bank->name)) {
                $bank_name = isset($bank['name']) ? $bank['name'] : $bank->name;
                $formatted .= "\nTitular: " . $bank_name;
            }
            
            if (isset($bank['code']) || isset($bank->code)) {
                $bank_code = isset($bank['code']) ? $bank['code'] : $bank->code;
                $formatted .= "\nCódigo do Banco: " . $bank_code;
            }
            
            if (isset($bank['agency']) || isset($bank->agency)) {
                $agency = isset($bank['agency']) ? $bank['agency'] : $bank->agency;
                $formatted .= "\nAgência: " . $agency;
            }
            
            if (isset($bank['account']) || isset($bank->account)) {
                $account = isset($bank['account']) ? $bank['account'] : $bank->account;
                $formatted .= "\nConta: " . $account;
            }
            
            if (isset($bank['account_type']) || isset($bank->account_type)) {
                $account_type = isset($bank['account_type']) ? $bank['account_type'] : $bank->account_type;
                $formatted .= "\nTipo de Conta: " . $account_type;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Processa o pagamento no Perfex CRM
     * 
     * @param object $transaction Dados da transação
     * @param string $pagou_transaction_id ID da transação na Pagou
     * @param string $new_status Novo status da transação
     * @param string $paid_at Data do pagamento
     * @param float $amount_final Valor final do pagamento
     * @param array|object $payer_info Informações do pagador
     * @param string $payment_type Tipo de pagamento ('pix' ou 'boleto')
     * @return bool True se processado com sucesso, false caso contrário
     */
    private function processPayment($transaction, $pagou_transaction_id, $new_status, $paid_at, $amount_final, $payer_info = null, $payment_type = 'pix') {
        try {
            $this->log('info', "=== PROCESSAMENTO DE PAGAMENTO ===", [
                'invoice_id' => $transaction->invoice_id, 
                'pagou_id' => $pagou_transaction_id,
                'payment_type' => $payment_type
            ]);
            
            // Verifica se a fatura existe
            $invoice = $this->invoices_model->get($transaction->invoice_id);
            if (!$invoice) {
                $this->log('error', "Fatura ID {$transaction->invoice_id} não encontrada");
                return false;
            }

            // Verifica se o pagamento já existe
            $already_exists = $this->checkPaymentExists($transaction->invoice_id, $pagou_transaction_id);
            if ($already_exists) {
                $this->log('info', "Pagamento já registrado anteriormente");
                $this->safeUpdateInvoiceStatus($transaction->invoice_id);
                return true;
            }
            
            // Se o valor é inválido, use o valor da fatura
            if ($amount_final === null || $amount_final <= 0) {
                $amount_final = $invoice->total;
                $this->log('debug', "Valor não fornecido ou inválido, usando total da fatura: {$amount_final}");
            }
            
            // Registra o pagamento
            return $this->registerPayment($transaction->invoice_id, $pagou_transaction_id, $new_status, $paid_at, $amount_final, $payer_info, $payment_type);
            
        } catch (\Throwable $e) {
            $this->log('error', "Erro no processamento: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica se um pagamento já existe
     * 
     * @param int $invoice_id ID da fatura
     * @param string $transaction_id ID da transação
     * @return bool True se o pagamento já existe, false caso contrário
     */
    private function checkPaymentExists($invoice_id, $transaction_id) {
        return $this->db
            ->where('invoiceid', $invoice_id)
            ->where('transactionid', $transaction_id)
            ->count_all_results('tblinvoicepaymentrecords') > 0;
    }
    
    /**
     * Registra o pagamento no Perfex CRM
     * 
     * @param int $invoice_id ID da fatura
     * @param string $transaction_id ID da transação
     * @param string $status Status da transação
     * @param string $paid_at Data do pagamento
     * @param float $amount Valor do pagamento
     * @param array|object $payer_info Informações do pagador
     * @param string $payment_type Tipo de pagamento ('pix' ou 'boleto')
     * @return bool True se registrado com sucesso, false caso contrário
     */
    private function registerPayment($invoice_id, $transaction_id, $status, $paid_at = null, $amount = null, $payer_info = null, $payment_type = 'pix') {
        try {
            // Formata as informações do pagador
            $payer_formatted = $this->formatPayerInfo($payer_info);
            
            // Define o modo de pagamento com base no tipo
            $payment_mode = ($payment_type === 'pix') ? 'pagou_pix' : 'pagou_boleto';
            $payment_method = ($payment_type === 'pix') ? 'PIX (Pagou.com.br)' : 'Boleto (Pagou.com.br)';
            
            // Prepara os dados para registro
            $payment_data = [
                'amount'        => $amount,
                'invoiceid'     => $invoice_id,
                'paymentmode'   => $payment_mode,
                'paymentmethod' => $payment_method,
                'transactionid' => $transaction_id,
                'date'          => $paid_at ? date('Y-m-d', strtotime($paid_at)) : date('Y-m-d'),
                'daterecorded'  => date('Y-m-d H:i:s'),
                'note'          => 'Pagamento ' . strtoupper($payment_type) . ' via Pagou.com.br. Transação ID: ' . $transaction_id . 
                                  '. Status: ' . $status . 
                                  '. Valor: R$ ' . number_format($amount, 2, ',', '.') . 
                                  $payer_formatted,
            ];
            
            $this->log('debug', "Dados de pagamento:", $payment_data);
            
            // Insere o pagamento no banco
            $this->db->insert('tblinvoicepaymentrecords', $payment_data);
            $payment_id = $this->db->insert_id();
            
            if (!$payment_id) {
                $this->log('error', "Falha ao inserir pagamento no banco");
                return false;
            }
            
            $this->log('info', "Pagamento inserido com ID: {$payment_id}");
            
            // Atualiza o total pago e status da fatura
            $this->updateInvoiceTotalAndStatus($invoice_id, $amount);
            
            // Tenta registrar a atividade se a tabela existir
            if ($this->db->table_exists(db_prefix() . 'invoiceactivity')) {
                $this->registerInvoiceActivity($invoice_id, $amount, $transaction_id, $payment_type, $payer_info);
            } else {
                $this->log('warning', 'Tabela invoiceactivity não existe, atividade não registrada');
            }
            
            return true;
            
        } catch (\Throwable $e) {
            $this->log('error', "Erro ao registrar pagamento: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Atualiza o status da fatura para pago
     * 
     * @param int $invoice_id ID da fatura
     * @param float $amount Valor pago (apenas para registro em log)
     * @return void
     */
    private function updateInvoiceTotalAndStatus($invoice_id, $amount) {
        try {
            // Obtém informações da fatura
            $invoice = $this->db->select('id, status')
                ->from('tblinvoices')
                ->where('id', $invoice_id)
                ->get()
                ->row();
            
            if (!$invoice) {
                $this->log('error', "Fatura ID {$invoice_id} não encontrada");
                return;
            }
            
            // Verifica se a fatura já está marcada como paga
            if ($invoice->status == 2) {
                $this->log('info', "Fatura ID {$invoice_id} já está marcada como paga");
                return;
            }
            
            // Atualiza apenas o status da fatura para PAGO (2)
            $this->db->where('id', $invoice_id)
                ->update('tblinvoices', [
                    'status' => 2 // Status "Pago"
                ]);
            
            $this->log('info', "Fatura ID {$invoice_id} marcada como paga. Valor do pagamento: R$ {$amount}");
            
            // Registra a mudança no histórico se a tabela existir
            if ($this->db->table_exists(db_prefix() . 'invoiceactivity')) {
                $this->db->insert('tblinvoiceactivity', [
                    'invoiceid' => $invoice_id,
                    'description' => 'Fatura marcada como paga via Pagou.com.br',
                    'additional_data' => serialize(['amount' => $amount]),
                    'staffid' => 0, // Sistema
                    'date' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->log('warning', 'Tabela invoiceactivity não existe, atividade de atualização de status não registrada');
            }
            
        } catch (\Throwable $e) {
            $this->log('error', "Erro ao atualizar status da fatura: " . $e->getMessage());
        }
    }
    
    /**
     * Registra atividade na fatura
     * 
     * @param int $invoice_id ID da fatura
     * @param float $amount Valor pago
     * @param string $transaction_id ID da transação
     * @param string $payment_type Tipo de pagamento ('pix' ou 'boleto')
     * @param array|object $payer_info Informações do pagador
     * @return void
     */
    private function registerInvoiceActivity($invoice_id, $amount, $transaction_id, $payment_type, $payer_info = null) {
        try {
            // Verifica se a tabela existe
            if (!$this->db->table_exists(db_prefix() . 'invoiceactivity')) {
                $this->log('warning', 'Tabela invoiceactivity não existe, atividade não registrada');
                return;
            }
            
            // Formata as informações do pagador para o log de atividade
            $payer_name = '';
            if ($payer_info) {
                if (isset($payer_info['name'])) {
                    $payer_name = ' por ' . $payer_info['name'];
                } else if (isset($payer_info->name)) {
                    $payer_name = ' por ' . $payer_info->name;
                }
            }
            
            $payment_type_label = ($payment_type === 'pix') ? 'PIX' : 'Boleto';
            
            $this->db->insert('tblinvoiceactivity', [
                'invoiceid' => $invoice_id,
                'description' => "Pagamento {$payment_type_label} registrado via webhook: R$ " . number_format($amount, 2, ',', '.') . $payer_name,
                'additional_data' => serialize([
                    'amount' => $amount, 
                    'transaction_id' => $transaction_id,
                    'payment_type' => $payment_type,
                    'payer' => $payer_info
                ]),
                'staffid' => 0, // Sistema
                'date' => date('Y-m-d H:i:s')
            ]);
            
            $this->log('debug', "Atividade registrada para fatura ID: {$invoice_id}");
        } catch (\Throwable $e) {
            $this->log('error', "Erro ao registrar atividade: " . $e->getMessage());
        }
    }
    
    /**
     * Atualiza o status da fatura de forma segura
     * 
     * @param int $invoice_id ID da fatura
     * @return void
     */
    private function safeUpdateInvoiceStatus($invoice_id) {
        try {
            // Verifica o status atual
            $invoice = $this->db->select('id, status')
                ->from('tblinvoices')
                ->where('id', $invoice_id)
                ->get()
                ->row();
                
            if (!$invoice) {
                $this->log('error', "Fatura ID {$invoice_id} não encontrada");
                return;
            }
            
            // Se o status não for 2 (Pago), atualiza
            if ($invoice->status != 2) {
                $this->log('info', "Atualizando status da fatura para Pago (atual: {$invoice->status})");
                
                $this->db->where('id', $invoice_id)
                    ->update('tblinvoices', ['status' => 2]);
                
                // Registra a mudança no histórico se a tabela existir
                if ($this->db->table_exists(db_prefix() . 'invoiceactivity')) {
                    $this->db->insert('tblinvoiceactivity', [
                        'invoiceid' => $invoice_id,
                        'description' => 'Fatura marcada como paga automaticamente pelo webhook',
                        'additional_data' => serialize([]),
                        'staffid' => 0,
                        'date' => date('Y-m-d H:i:s')
                    ]);
                }
                
                // Envia comando para atualizar a página
                $this->sendRefreshCommand($invoice_id);
            }
        } catch (\Throwable $e) {
            $this->log('error', "Erro ao atualizar status: " . $e->getMessage());
        }
    }
    
    /**
     * Obtém os cabeçalhos da requisição
     * 
     * @return array Lista de cabeçalhos
     */
    private function getRequestHeaders() {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
    
    /**
     * Envia uma resposta HTTP segura
     * 
     * @param int $code Código HTTP
     * @param string $message Mensagem
     * @return void
     */
    private function safeResponse($code, $message) {
        // Limpa qualquer saída anterior
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Define o código de status
        http_response_code($code);
        
        // Envia cabeçalhos
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: application/json; charset=utf-8');
        
        // Responde com JSON
        echo json_encode([
            'status' => $code,
            'message' => $message
        ]);
        exit;
    }
}