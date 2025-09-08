<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Definição da Classe Transaction_handler
   ============================ */
class Transaction_handler
{
    private $ci;
    private $api_client;
    private $log_enabled = false;
    private $log_detailed = false;

    /**
     * Construtor da classe
     *
     * @param object $ci Instância do CodeIgniter
     * @param object $api_client Instância do cliente de API
     */
    public function __construct($ci, $api_client)
    {
        $this->ci = $ci;
        $this->api_client = $api_client;
        
        // Verifica configurações de log
        $log_option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api'])->row();
        $this->log_enabled = ($log_option && $log_option->value === '1');
        
        $log_detailed_option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'paymentmethod_pagou_boleto_log_detailed'])->row();
        $this->log_detailed = ($log_detailed_option && $log_detailed_option->value === '1');
        
        $this->log('debug', 'Transaction handler inicializado');
    }
    
    /**
     * Registra logs se estiverem habilitados
     *
     * @param string $level Nível do log (debug, info, error)
     * @param string $message Mensagem de log
     * @param mixed $data Dados adicionais para o log (opcional)
     */
    private function log($level, $message, $data = null)
    {
        if (!$this->log_enabled) {
            return;
        }

        $log_message = "[Pagou Boleto Transaction] " . $message;
        
        // Adiciona timestamp aos logs
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] " . $log_message;
        
        // Se tiver dados adicionais e logs detalhados estiverem habilitados, adiciona ao log
        if ($data !== null && $this->log_detailed) {
            if (is_array($data) || is_object($data)) {
                $log_message .= " | Dados: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $log_message .= " | Dados: " . $data;
            }
        }
        
        // Registra no sistema de logs do CodeIgniter
        log_message($level, $log_message);
        
        // Opcionalmente, salvar também em um arquivo dedicado
        $log_file = APPPATH . 'logs/pagou_transaction_' . date('Y-m-d') . '.log';
        error_log($log_message . PHP_EOL, 3, $log_file);
    }

    /**
     * Verifica se já existe uma transação para a fatura
     *
     * @param int $invoice_id ID da fatura
     * @return object|null Transação existente ou null
     */
    public function checkExistingTransaction($invoice_id)
    {
        $this->log('debug', "Verificando transação existente para fatura {$invoice_id}");
        
        // Certifica-se que a tabela existe
        $this->ensureTableExists();
        
        // Busca por transação existente
        $this->ci->db->where('invoice_id', $invoice_id);
        $this->ci->db->order_by('id', 'desc'); // Pega a mais recente primeiro
        $transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
        
        if ($transaction) {
            $this->log('info', "Transação existente encontrada para fatura {$invoice_id}: ID {$transaction->id}, Status: {$transaction->status}");
        } else {
            $this->log('info', "Nenhuma transação encontrada para fatura {$invoice_id}");
        }
        
        return $transaction;
    }
    
    /**
     * Atualiza uma transação com dados da API
     *
     * @param int $invoice_id ID da fatura
     * @param string $transaction_id ID da transação na Pagou
     * @return object|null Transação atualizada ou null em caso de erro
     */
    public function updateTransactionFromApi($invoice_id, $transaction_id)
    {
        $this->log('debug', "Atualizando transação {$transaction_id} da fatura {$invoice_id}");
        
        // Consulta a transação na API
        $response = $this->api_client->getBoleto($transaction_id);
        
        if (!$response['success']) {
            $this->log('error', "Erro ao consultar transação na API: " . $response['message']);
            return null;
        }
        
        $data = $response['data'];
        
        // Prepara dados para atualização
        $update_data = [
            'status' => $data->status ?? 'pending',
            'barcode' => $data->barcode ?? '',
            'barcode_url' => $data->barcode_url ?? '',
            'boleto_url' => $data->boleto_url ?? '',
            'consulta' => '1',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Se houver data de pagamento, atualiza também
        if (isset($data->paid_at)) {
            $update_data['payment_date'] = date('Y-m-d H:i:s', strtotime($data->paid_at));
        }
        
        $this->log('debug', "Dados para atualização:", $update_data);
        
        // Atualiza no banco de dados
        $this->ci->db->where('invoice_id', $invoice_id);
        $this->ci->db->where('transaction_id', $transaction_id);
        $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', $update_data);
        
        // Verifica se atualizou
        if ($this->ci->db->affected_rows() === 0) {
            $this->log('warning', "Nenhuma linha atualizada para transação {$transaction_id}");
        } else {
            $this->log('info', "Transação {$transaction_id} atualizada com sucesso");
        }
        
        // Retorna a transação atualizada
        return $this->checkExistingTransaction($invoice_id);
    }
    
    /**
     * Salva uma nova transação no banco de dados
     *
     * @param int $invoice_id ID da fatura
     * @param object $response_data Dados da resposta da API
     * @return bool Sucesso ou falha na operação
     */
    public function saveNewTransaction($invoice_id, $response_data)
    {
        $this->log('info', "Salvando nova transação para fatura {$invoice_id}");
        
        // Certifica-se que a tabela existe
        $this->ensureTableExists();
        
        // Prepara os dados para inserção
        $transaction_data = [
            'invoice_id' => $invoice_id,
            'transaction_id' => $response_data->id,
            'barcode' => $response_data->barcode ?? '',
            'barcode_url' => $response_data->barcode_url ?? '',
            'boleto_url' => $response_data->boleto_url ?? '',
            'payment_method' => 'boleto',
            'amount' => $response_data->amount ?? 0,
            'amount_final' => $response_data->amount ?? 0,
            'status' => $response_data->status ?? 'pending',
            'date_created' => date('Y-m-d H:i:s'),
            'due_date' => $response_data->due_date ?? date('Y-m-d'),
            'consulta' => '0',
            'api_response' => json_encode($response_data)
        ];
        
        $this->log('debug', "Dados para inserção:", $transaction_data);
        
        // Insere no banco de dados
        $this->ci->db->insert(db_prefix() . 'pagou_transactions_boleto', $transaction_data);
        
        // Verifica se inseriu
        if ($this->ci->db->affected_rows() === 0) {
            $this->log('error', "Erro ao inserir transação para fatura {$invoice_id}");
            return false;
        }
        
        $new_id = $this->ci->db->insert_id();
        $this->log('info', "Transação inserida com sucesso, ID: {$new_id}");
        
        // Marca a fatura como tendo boleto Pagou
        $this->ci->db->where('id', $invoice_id);
        $this->ci->db->update(db_prefix() . 'invoices', ['invoice_pagou' => 1]);
        
        return true;
    }
    
    /**
     * Processa webhook recebido da Pagou
     *
     * @return array Resultado do processamento
     */
    public function handleWebhook()
    {
        $this->log('info', "Processando webhook recebido");
        
        // Lê o conteúdo bruto do webhook
        $raw_content = file_get_contents('php://input');
        $this->log('debug', "Conteúdo do webhook:", $raw_content);
        
        if (empty($raw_content)) {
            $this->log('error', "Webhook vazio recebido");
            return [
                'success' => false,
                'message' => 'Webhook sem conteúdo',
                'code' => 400
            ];
        }
        
        // Tenta decodificar o JSON
        $data = json_decode($raw_content);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', "Erro ao decodificar JSON do webhook: " . json_last_error_msg());
            return [
                'success' => false,
                'message' => 'JSON inválido',
                'code' => 400
            ];
        }
        
        // Valida o webhook
        if (!$this->validateWebhook($data)) {
            $this->log('error', "Webhook inválido ou incompleto");
            return [
                'success' => false,
                'message' => 'Dados do webhook inválidos ou incompletos',
                'code' => 400
            ];
        }
        
        // Processa com base no tipo de evento
        $event_name = $data->name ?? null;
        $this->log('info', "Evento recebido: {$event_name}");
        
        // Processar pagamento
        if ($event_name === 'charge.completed' || $event_name === 'charge.paid') {
            return $this->processPaymentWebhook($data);
        }
        
        // Processar cancelamento
        if ($event_name === 'charge.cancelled') {
            return $this->processCancellationWebhook($data);
        }
        
        // Processar reembolso
        if ($event_name === 'charge.refunded') {
            return $this->processRefundWebhook($data);
        }
        
        // Se chegou aqui, é um evento não suportado
        $this->log('warning', "Evento não suportado: {$event_name}");
        return [
            'success' => true,
            'message' => 'Evento recebido, mas não processado',
            'code' => 200
        ];
    }
    
    /**
     * Valida o formato do webhook recebido
     *
     * @param object $data Dados do webhook
     * @return bool Indica se o webhook é válido
     */
    private function validateWebhook($data)
    {
        // Verifica se tem o nome do evento
        if (!isset($data->name) || !is_string($data->name)) {
            return false;
        }
        
        // Verifica se tem dados do evento
        if (!isset($data->data) || !is_object($data->data)) {
            return false;
        }
        
        // Verifica se tem ID da transação
        if (!isset($data->data->id) || !is_string($data->data->id)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Processa webhook de pagamento
     *
     * @param object $data Dados do webhook
     * @return array Resultado do processamento
     */
    private function processPaymentWebhook($data)
    {
        $this->log('info', "Processando webhook de pagamento");
        
        $transaction_id = $data->data->id;
        
        // Tenta encontrar a transação pelo ID
        $transaction = $this->findTransactionByExternalId($transaction_id);
        
        if (!$transaction) {
            $this->log('error', "Transação não encontrada: {$transaction_id}");
            
            // Tenta encontrar pelo invoice_id nos metadados
            $invoice_id = $this->getInvoiceIdFromMetadata($data->data->metadata ?? []);
            
            if ($invoice_id) {
                $this->log('info', "Fatura ID encontrada nos metadados: {$invoice_id}");
                
                // Carrega a fatura e cliente
                $this->ci->load->model('invoices_model');
                $invoice = $this->ci->invoices_model->get($invoice_id);
                
                if (!$invoice) {
                    $this->log('error', "Fatura não encontrada: {$invoice_id}");
                    return [
                        'success' => false,
                        'message' => 'Fatura não encontrada',
                        'code' => 404
                    ];
                }
                
                // Registrar pagamento diretamente
                $this->registerPayment($invoice, $data->data);
                
                return [
                    'success' => true,
                    'message' => 'Pagamento registrado com sucesso',
                    'code' => 200
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Transação não encontrada',
                'code' => 404
            ];
        }
        
        // Atualiza o status da transação
        $update_data = [
            'status' => 'paid',
            'payment_date' => date('Y-m-d H:i:s', strtotime($data->data->paid_at ?? 'now')),
            'updated_at' => date('Y-m-d H:i:s'),
            'webhook_log' => ($transaction->webhook_log ? $transaction->webhook_log . "\n---\n" : '') . 
                             date('Y-m-d H:i:s') . ":\n" . json_encode($data, JSON_PRETTY_PRINT)
        ];
        
        $this->ci->db->where('id', $transaction->id);
        $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', $update_data);
        
        // Carrega a fatura
        $this->ci->load->model('invoices_model');
        $invoice = $this->ci->invoices_model->get($transaction->invoice_id);
        
        if (!$invoice) {
            $this->log('error', "Fatura não encontrada: {$transaction->invoice_id}");
            return [
                'success' => false,
                'message' => 'Fatura não encontrada',
                'code' => 404
            ];
        }
        
        // Registra o pagamento
        $this->registerPayment($invoice, $data->data);
        
        return [
            'success' => true,
            'message' => 'Pagamento processado com sucesso',
            'code' => 200
        ];
    }
    
    /**
     * Processa webhook de cancelamento
     *
     * @param object $data Dados do webhook
     * @return array Resultado do processamento
     */
    private function processCancellationWebhook($data)
    {
        $this->log('info', "Processando webhook de cancelamento");
        
        $transaction_id = $data->data->id;
        
        // Tenta encontrar a transação pelo ID
        $transaction = $this->findTransactionByExternalId($transaction_id);
        
        if (!$transaction) {
            $this->log('error', "Transação não encontrada: {$transaction_id}");
            return [
                'success' => false,
                'message' => 'Transação não encontrada',
                'code' => 404
            ];
        }
        
        // Atualiza o status da transação
        $update_data = [
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
            'webhook_log' => ($transaction->webhook_log ? $transaction->webhook_log . "\n---\n" : '') . 
                             date('Y-m-d H:i:s') . ":\n" . json_encode($data, JSON_PRETTY_PRINT)
        ];
        
        $this->ci->db->where('id', $transaction->id);
        $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', $update_data);
        
        $this->log('info', "Transação {$transaction_id} marcada como cancelada");
        
        return [
            'success' => true,
            'message' => 'Cancelamento processado com sucesso',
            'code' => 200
        ];
    }
    
    /**
     * Processa webhook de reembolso
     *
     * @param object $data Dados do webhook
     * @return array Resultado do processamento
     */
    private function processRefundWebhook($data)
    {
        $this->log('info', "Processando webhook de reembolso");
        
        $transaction_id = $data->data->id;
        
        // Tenta encontrar a transação pelo ID
        $transaction = $this->findTransactionByExternalId($transaction_id);
        
        if (!$transaction) {
            $this->log('error', "Transação não encontrada: {$transaction_id}");
            return [
                'success' => false,
                'message' => 'Transação não encontrada',
                'code' => 404
            ];
        }
        
        // Atualiza o status da transação
        $update_data = [
            'status' => 'refunded',
            'refund_date' => date('Y-m-d H:i:s', strtotime($data->data->refunded_at ?? 'now')),
            'updated_at' => date('Y-m-d H:i:s'),
            'webhook_log' => ($transaction->webhook_log ? $transaction->webhook_log . "\n---\n" : '') . 
                             date('Y-m-d H:i:s') . ":\n" . json_encode($data, JSON_PRETTY_PRINT)
        ];
        
        $this->ci->db->where('id', $transaction->id);
        $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', $update_data);
        
        // Marca a fatura como cancelada
        $this->ci->db->where('id', $transaction->invoice_id);
        $this->ci->db->update(db_prefix() . 'invoices', ['status' => 5]); // Status 5 = Cancelada
        
        $this->log('info', "Transação {$transaction_id} marcada como reembolsada e fatura {$transaction->invoice_id} como cancelada");
        
        return [
            'success' => true,
            'message' => 'Reembolso processado com sucesso',
            'code' => 200
        ];
    }
    
    /**
     * Encontra uma transação pelo ID externo (ID da Pagou)
     *
     * @param string $external_id ID da transação na Pagou
     * @return object|null Transação encontrada ou null
     */
    private function findTransactionByExternalId($external_id)
    {
        $this->log('debug', "Buscando transação com ID externo: {$external_id}");
        
        $this->ci->db->where('transaction_id', $external_id);
        $transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
        
        if ($transaction) {
            $this->log('info', "Transação encontrada: ID {$transaction->id}, Fatura {$transaction->invoice_id}");
        } else {
            $this->log('info', "Transação não encontrada com ID externo: {$external_id}");
        }
        
        return $transaction;
    }
    
    /**
     * Registra um pagamento no Perfex CRM
     *
     * @param object $invoice Objeto da fatura
     * @param object $payment_data Dados do pagamento
     * @return bool Sucesso ou falha na operação
     */
    private function registerPayment($invoice, $payment_data)
    {
        $this->log('info', "Registrando pagamento para fatura {$invoice->id}");
        
        // Verifica se o pagamento já existe
        $this->ci->db->where('invoiceid', $invoice->id);
        $this->ci->db->where('transactionid', $payment_data->id);
        $existing_payment = $this->ci->db->get(db_prefix() . 'invoicepaymentrecords')->row();
        
        if ($existing_payment) {
            $this->log('info', "Pagamento já registrado anteriormente, ID: {$existing_payment->id}");
            return true;
        }
        
        // Formata as informações do pagador
        $payer_info = '';
        if (isset($payment_data->payer)) {
            $payer_info = "\n\nPagador: " . ($payment_data->payer->name ?? 'N/A');
            if (isset($payment_data->payer->document)) {
                $doc = $payment_data->payer->document;
                if (strlen($doc) === 11) {
                    $payer_info .= "\nCPF: " . substr($doc, 0, 3) . '.' . substr($doc, 3, 3) . '.' . substr($doc, 6, 3) . '-' . substr($doc, 9, 2);
                } else if (strlen($doc) === 14) {
                    $payer_info .= "\nCNPJ: " . substr($doc, 0, 2) . '.' . substr($doc, 2, 3) . '.' . substr($doc, 5, 3) . '/' . substr($doc, 8, 4) . '-' . substr($doc, 12, 2);
                } else {
                    $payer_info .= "\nDocumento: " . $doc;
                }
            }
        }
        
        // Prepara os dados para o registro de pagamento
        $amount = isset($payment_data->amount_paid) ? ($payment_data->amount_paid / 100) : $invoice->total;
        $payment_record = [
            'invoiceid'      => $invoice->id,
            'amount'         => $amount,
            'paymentmode'    => 'pagou_boleto',
            'paymentmethod'  => 'Boleto (Pagou.com.br)',
            'date'           => date('Y-m-d', strtotime($payment_data->paid_at ?? 'now')),
            'daterecorded'   => date('Y-m-d H:i:s'),
            'note'           => "Pagamento via Boleto Bancário (Pagou.com.br)\nID da Transação: {$payment_data->id}\nData do Pagamento: " . 
                                date('d/m/Y H:i:s', strtotime($payment_data->paid_at ?? 'now')) . 
                                $payer_info,
            'transactionid'  => $payment_data->id
        ];
        
        $this->log('debug', "Dados do registro de pagamento:", $payment_record);
        
        // Insere o registro de pagamento
        $this->ci->db->insert(db_prefix() . 'invoicepaymentrecords', $payment_record);
        
        if ($this->ci->db->affected_rows() === 0) {
            $this->log('error', "Erro ao registrar pagamento");
            return false;
        }
        
        $payment_id = $this->ci->db->insert_id();
        $this->log('info', "Pagamento registrado com sucesso, ID: {$payment_id}");
        
        // Atualiza o status da fatura para paga
        $this->ci->db->where('id', $invoice->id);
        $this->ci->db->update(db_prefix() . 'invoices', ['status' => 2]); // Status 2 = Paga
        
        // Registra a atividade na fatura
        $this->ci->db->insert(db_prefix() . 'invoiceactivity', [
            'invoiceid' => $invoice->id,
            'description' => 'Pagamento via Boleto Bancário (Pagou.com.br) registrado',
            'additional_data' => serialize([
                'amount' => $amount,
                'transaction_id' => $payment_data->id
            ]),
            'staffid' => 0, // Sistema
            'date' => date('Y-m-d H:i:s')
        ]);
        
        return true;
    }
    
    /**
     * Obtém o ID da fatura a partir dos metadados
     *
     * @param array $metadata Array de metadados
     * @return int|null ID da fatura ou null se não encontrado
     */
    private function getInvoiceIdFromMetadata($metadata)
    {
        if (empty($metadata) || !is_array($metadata)) {
            return null;
        }
        
        foreach ($metadata as $item) {
            if (isset($item->key) && $item->key === 'invoice_id' && isset($item->value)) {
                return (int) $item->value;
            }
        }
        
        return null;
    }
    
    /**
     * Garante que a tabela de transações exista no banco de dados
     */
    private function ensureTableExists()
    {
        $table_name = db_prefix() . 'pagou_transactions_boleto';
        
        // Verifica se a tabela existe
        $table_exists = $this->ci->db->table_exists($table_name);
        
        if (!$table_exists) {
            $this->log('info', "Tabela {$table_name} não existe, criando...");
            
            // Cria a tabela
            $this->ci->db->query("
                CREATE TABLE IF NOT EXISTS `{$table_name}` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `invoice_id` int(11) NOT NULL,
                    `cliente` varchar(255) DEFAULT NULL,
                    `doc` varchar(30) DEFAULT NULL,
                    `transaction_id` varchar(255) NOT NULL,
                    `external_id` varchar(255) DEFAULT NULL,
                    `payment_method` varchar(50) NOT NULL,
                    `barcode` text NOT NULL,
                    `barcode_url` varchar(255) DEFAULT NULL,
                    `boleto_url` varchar(255) DEFAULT NULL,
                    `amount` decimal(15,2) NOT NULL,
                    `amount_final` decimal(15,2) DEFAULT NULL,
                    `status` varchar(50) NOT NULL DEFAULT 'pending',
                    `date_created` datetime NOT NULL,
                    `due_date` date NOT NULL,
                    `payment_date` datetime DEFAULT NULL,
                    `refund_date` datetime DEFAULT NULL,
                    `updated_at` datetime DEFAULT NULL,
                    `api_request` text DEFAULT NULL,
                    `api_response` text DEFAULT NULL,
                    `webhook_log` text DEFAULT NULL,
                    `consulta` tinyint(1) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `invoice_id` (`invoice_id`),
                    KEY `transaction_id` (`transaction_id`(191)),
                    KEY `external_id` (`external_id`(191))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            ");
            
            $this->log('info', "Tabela {$table_name} criada com sucesso");
        }
    }
}