<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Definição da Classe Webhook_handler
   ============================ */
class Webhook_handler
{
    private $ci;
    private $log_enabled = false;
    private $log_detailed = false;

    /**
     * Construtor da classe
     */
    public function __construct()
    {
        $this->ci = &get_instance();
        
        // Verifica configurações de log
        $log_option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api'])->row();
        $this->log_enabled = ($log_option && $log_option->value === '1');
        
        $log_detailed_option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'paymentmethod_pagou_boleto_log_detailed'])->row();
        $this->log_detailed = ($log_detailed_option && $log_detailed_option->value === '1');
        
        $this->log('debug', 'Webhook handler inicializado');
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

        $log_message = "[Pagou Boleto Webhook] " . $message;
        
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
        $log_file = APPPATH . 'logs/pagou_webhook_' . date('Y-m-d') . '.log';
        error_log($log_message . PHP_EOL, 3, $log_file);
    }
    
    /**
     * Processa o webhook recebido
     */
    public function processWebhook()
    {
        $this->log('info', '=== INÍCIO DO PROCESSAMENTO DO WEBHOOK ===');
        
        // Desabilita proteção CSRF
        $this->ci->config->set_item('csrf_protection', FALSE);
        
        // Lê o conteúdo bruto do webhook
        $raw_content = file_get_contents('php://input');
        
        if (empty($raw_content)) {
            $this->log('error', 'Nenhum dado recebido no webhook');
            $this->sendResponse(400, 'Nenhum dado recebido');
            return;
        }
        
        $this->log('debug', 'Dados brutos recebidos', $raw_content);
        
        // Tenta decodificar o JSON
        $data = json_decode($raw_content);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'Erro ao decodificar JSON: ' . json_last_error_msg());
            $this->sendResponse(400, 'Formato JSON inválido');
            return;
        }
        
        // Valida o webhook
        if (!$this->validateWebhook($data)) {
            $this->log('error', 'Dados do webhook inválidos ou insuficientes');
            $this->sendResponse(400, 'Dados inválidos ou incompletos');
            return;
        }
        
        // Inicia a transação no banco de dados
        $this->ci->db->trans_begin();
        
        try {
            // Identifica o tipo de evento
            $event_name = $data->name ?? '';
            $this->log('info', "Evento recebido: {$event_name}");
            
            // Processa com base no tipo de evento
            switch ($event_name) {
                case 'charge.paid':
                case 'charge.completed':
                    $result = $this->processPaidEvent($data);
                    break;
                    
                case 'charge.cancelled':
                    $result = $this->processCancelledEvent($data);
                    break;
                    
                case 'charge.refunded':
                    $result = $this->processRefundedEvent($data);
                    break;
                    
                default:
                    $this->log('warning', "Tipo de evento não tratado: {$event_name}");
                    $result = [
                        'success' => true,
                        'message' => 'Evento não processado: tipo desconhecido'
                    ];
            }
            
            // Verifica o status da transação no banco
            if ($this->ci->db->trans_status() === FALSE) {
                throw new Exception('Falha na transação do banco de dados');
            }
            
            // Confirma a transação no banco
            $this->ci->db->trans_commit();
            
            $this->log('info', 'Webhook processado com sucesso: ' . $result['message']);
            $this->sendResponse(200, $result['message']);
            
        } catch (Exception $e) {
            // Reverte a transação em caso de erro
            $this->ci->db->trans_rollback();
            
            $this->log('error', 'Erro ao processar webhook: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->sendResponse(500, 'Erro interno ao processar webhook');
        }
        
        $this->log('info', '=== FIM DO PROCESSAMENTO DO WEBHOOK ===');
    }
    
    /**
     * Valida os dados do webhook
     *
     * @param object $data Dados do webhook
     * @return bool Se os dados são válidos
     */
    private function validateWebhook($data)
    {
        // Verificações básicas
        if (!isset($data->name) || !is_string($data->name)) {
            $this->log('error', 'Nome do evento ausente ou inválido');
            return false;
        }
        
        if (!isset($data->data) || !is_object($data->data)) {
            $this->log('error', 'Dados do evento ausentes ou inválidos');
            return false;
        }
        
        // Verificação específica para cada tipo de evento
        switch ($data->name) {
            case 'charge.paid':
            case 'charge.completed':
                if (!isset($data->data->id) || !isset($data->data->status)) {
                    $this->log('error', 'Dados do evento de pagamento incompletos');
                    return false;
                }
                break;
                
            case 'charge.cancelled':
                if (!isset($data->data->id) || !isset($data->data->status)) {
                    $this->log('error', 'Dados do evento de cancelamento incompletos');
                    return false;
                }
                break;
                
            case 'charge.refunded':
                if (!isset($data->data->id) || !isset($data->data->status)) {
                    $this->log('error', 'Dados do evento de reembolso incompletos');
                    return false;
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Processa evento de pagamento confirmado
     *
     * @param object $data Dados do webhook
     * @return array Resultado do processamento
     */
    private function processPaidEvent($data)
    {
        $transaction_id = $data->data->id;
        $this->log('info', "Processando pagamento para transação: {$transaction_id}");
        
        // Busca a transação no banco
        $transaction = $this->findTransaction($transaction_id);
        
        if (!$transaction) {
            $this->log('warning', "Transação {$transaction_id} não encontrada no banco local");
            
            // Tenta buscar pela invoice_id nos metadados
            $invoice_id = $this->extractInvoiceId($data->data->metadata ?? []);
            
            if ($invoice_id) {
                $this->log('info', "Invoice ID {$invoice_id} encontrada nos metadados");
                
                // Carrega a fatura
                $this->ci->load->model('invoices_model');
                $invoice = $this->ci->invoices_model->get($invoice_id);
                
                if ($invoice) {
                    // Registra o pagamento diretamente
                    if ($this->registerPaymentFromWebhook($invoice, $data->data)) {
                        return [
                            'success' => true,
                            'message' => "Pagamento registrado para fatura {$invoice_id}"
                        ];
                    }
                } else {
                    $this->log('error', "Fatura {$invoice_id} não encontrada");
                }
            }
            
            return [
                'success' => true, // Retorna sucesso para não reprocessar
                'message' => "Transação não encontrada no banco local"
            ];
        }
        
        // Atualiza o status da transação
        $this->updateTransactionStatus($transaction, 'paid', $data);
        
        // Carrega a fatura
        $this->ci->load->model('invoices_model');
        $invoice = $this->ci->invoices_model->get($transaction->invoice_id);
        
        if (!$invoice) {
            $this->log('error', "Fatura {$transaction->invoice_id} não encontrada");
            return [
                'success' => false,
                'message' => "Fatura não encontrada"
            ];
        }
        
        // Registra o pagamento
        $this->registerPaymentFromWebhook($invoice, $data->data);
        
        return [
            'success' => true,
            'message' => "Pagamento processado para fatura {$transaction->invoice_id}"
        ];
    }
    
    /**
     * Processa evento de boleto cancelado
     *
     * @param object $data Dados do webhook
     * @return array Resultado do processamento
     */
    private function processCancelledEvent($data)
    {
        $transaction_id = $data->data->id;
        $this->log('info', "Processando cancelamento para transação: {$transaction_id}");
        
        // Busca a transação no banco
        $transaction = $this->findTransaction($transaction_id);
        
        if (!$transaction) {
            $this->log('warning', "Transação {$transaction_id} não encontrada no banco local");
            return [
                'success' => true, // Retorna sucesso para não reprocessar
                'message' => "Transação não encontrada no banco local"
            ];
        }
        
        // Atualiza o status da transação
        $this->updateTransactionStatus($transaction, 'cancelled', $data);
        
        return [
            'success' => true,
            'message' => "Cancelamento processado para transação {$transaction_id}"
        ];
    }
    
    /**
     * Processa evento de boleto reembolsado
     *
     * @param object $data Dados do webhook
     * @return array Resultado do processamento
     */
    private function processRefundedEvent($data)
    {
        $transaction_id = $data->data->id;
        $this->log('info', "Processando reembolso para transação: {$transaction_id}");
        
        // Busca a transação no banco
        $transaction = $this->findTransaction($transaction_id);
        
        if (!$transaction) {
            $this->log('warning', "Transação {$transaction_id} não encontrada no banco local");
            return [
                'success' => true, // Retorna sucesso para não reprocessar
                'message' => "Transação não encontrada no banco local"
            ];
        }
        
        // Atualiza o status da transação
        $this->updateTransactionStatus($transaction, 'refunded', $data);
        
        // Marca a fatura como cancelada
        $this->ci->db->where('id', $transaction->invoice_id);
        $this->ci->db->update(db_prefix() . 'invoices', [
            'status' => 5 // Status 5 = Cancelada
        ]);
        
        // Registra a atividade na fatura
        $this->ci->db->insert(db_prefix() . 'invoiceactivity', [
            'invoiceid' => $transaction->invoice_id,
            'description' => 'Fatura cancelada devido a reembolso do boleto',
            'additional_data' => serialize([
                'transaction_id' => $transaction_id,
                'refund_date' => date('Y-m-d H:i:s')
            ]),
            'staffid' => 0,
            'date' => date('Y-m-d H:i:s')
        ]);
        
        return [
            'success' => true,
            'message' => "Reembolso processado para transação {$transaction_id}"
        ];
    }
    
    /**
     * Encontra uma transação pelo ID externo (Pagou)
     *
     * @param string $transaction_id ID da transação
     * @return object|null Objeto da transação ou null se não encontrada
     */
    private function findTransaction($transaction_id)
    {
        $this->ci->db->where('transaction_id', $transaction_id);
        $transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
        
        return $transaction;
    }
    
    /**
     * Extrai o ID da fatura dos metadados
     *
     * @param array $metadata Metadados da transação
     * @return int|null ID da fatura ou null se não encontrada
     */
    private function extractInvoiceId($metadata)
    {
        if (empty($metadata)) {
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
     * Atualiza o status de uma transação
     *
     * @param object $transaction Objeto da transação
     * @param string $new_status Novo status
     * @param object $webhook_data Dados completos do webhook
     */
    private function updateTransactionStatus($transaction, $new_status, $webhook_data)
    {
        $update_data = [
            'status' => $new_status,
            'updated_at' => date('Y-m-d H:i:s'),
            'webhook_log' => ($transaction->webhook_log ? $transaction->webhook_log . "\n---\n" : '') . 
                              date('Y-m-d H:i:s') . ":\n" . json_encode($webhook_data, JSON_PRETTY_PRINT)
        ];
        
        // Adiciona data específica com base no status
        switch ($new_status) {
            case 'paid':
                if (isset($webhook_data->data->paid_at)) {
                    $update_data['payment_date'] = date('Y-m-d H:i:s', strtotime($webhook_data->data->paid_at));
                } else {
                    $update_data['payment_date'] = date('Y-m-d H:i:s');
                }
                break;
                
            case 'refunded':
                if (isset($webhook_data->data->refunded_at)) {
                    $update_data['refund_date'] = date('Y-m-d H:i:s', strtotime($webhook_data->data->refunded_at));
                } else {
                    $update_data['refund_date'] = date('Y-m-d H:i:s');
                }
                break;
        }
        
        $this->ci->db->where('id', $transaction->id);
        $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', $update_data);
        
        $this->log('info', "Status da transação {$transaction->id} atualizado para '{$new_status}'");
    }
    
    /**
     * Registra um pagamento a partir dos dados do webhook
     *
     * @param object $invoice Objeto da fatura
     * @param object $payment_data Dados do pagamento
     * @return bool Se o pagamento foi registrado com sucesso
     */
    private function registerPaymentFromWebhook($invoice, $payment_data)
    {
        $this->log('info', "Registrando pagamento para fatura {$invoice->id}");
        
        // Verifica se o pagamento já foi registrado
        $this->ci->db->where('invoiceid', $invoice->id);
        $this->ci->db->where('transactionid', $payment_data->id);
        $existing_payment = $this->ci->db->get(db_prefix() . 'invoicepaymentrecords')->row();
        
        if ($existing_payment) {
            $this->log('info', "Pagamento já registrado anteriormente, ID: {$existing_payment->id}");
            
            // Certifica-se de que a fatura está marcada como paga
            $this->ci->db->where('id', $invoice->id);
            $this->ci->db->where('status !=', 2); // Se não estiver paga
            $this->ci->db->update(db_prefix() . 'invoices', ['status' => 2]); // Status 2 = Paga
            
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
        
        // Define o valor do pagamento
        $amount = $invoice->total;
        if (isset($payment_data->amount)) {
            // A API Pagou pode retornar o valor em centavos ou em reais, dependendo do campo
            $amount = ($payment_data->amount > 1000) ? ($payment_data->amount / 100) : $payment_data->amount;
        }
        
        // Prepara os dados do pagamento
        $payment_data_db = [
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
        
        $this->log('debug', "Dados do pagamento:", $payment_data_db);
        
        // Insere o pagamento
        $this->ci->db->insert(db_prefix() . 'invoicepaymentrecords', $payment_data_db);
        
        if ($this->ci->db->affected_rows() === 0) {
            $this->log('error', "Erro ao inserir pagamento no banco");
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
        
        // Cria ou atualiza o registro de atualização para notificar a interface
        $this->ci->db->insert(db_prefix() . 'sessions_pagou_refresh', [
            'invoice_id' => $invoice->id,
            'timestamp' => time(),
            'processed' => 0
        ]);
        
        return true;
    }
    
    /**
     * Envia resposta HTTP para o webhook
     *
     * @param int $status_code Código de status HTTP
     * @param string $message Mensagem a ser enviada
     */
    private function sendResponse($status_code, $message)
    {
        http_response_code($status_code);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $status_code,
            'message' => $message
        ]);
        exit;
    }
}