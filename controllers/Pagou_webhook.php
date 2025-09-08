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
                } catch (Exception $e) {
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
     * @param int $invoice_id ID da fatura no Perfex CRM (opcional)
     * @return void
     */
    public function process($invoice_id = null) {
        try {
            // Define o timezone
            date_default_timezone_set('America/Sao_Paulo');
            
            $this->log('debug', '====== INÍCIO DO PROCESSAMENTO DO WEBHOOK ======');
            $this->log('debug', 'Webhook Pagou acionado. Invoice ID (URL): ' . ($invoice_id ?? 'N/A'));
            
            // Log dos cabeçalhos recebidos
            $headers = $this->getRequestHeaders();
            $this->log('debug', 'Headers recebidos:', $headers);
            
            // Obter dados brutos da requisição
            $raw_data = file_get_contents('php://input');
            $this->log('debug', 'Dados brutos recebidos:', $raw_data);
            
            // Se não tem dados, tenta pegar de $_POST
            if (empty($raw_data) && !empty($_POST)) {
                $this->log('debug', 'Tentando ler dados do $_POST:', $_POST);
                $raw_data = json_encode($_POST);
            }
            
            // Verifica se há dados no webhook
            if (empty($raw_data)) {
                $this->log('info', 'Nenhum dado recebido. Consultando API diretamente...');
                
                // Se temos invoice_id, consultamos a API Pagou diretamente
                if ($invoice_id) {
                    $this->verifyTransactionStatusFromApi($invoice_id);
                    return;
                }
                
                $this->safeResponse(400, "Nenhum dado recebido no webhook.");
                return;
            }

            // Decodifica os dados JSON
            $data = json_decode($raw_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log('error', 'Erro ao decodificar JSON: ' . json_last_error_msg());
                $this->safeResponse(400, "Erro na decodificação dos dados JSON.");
                return;
            }
            
            // Processa os dados do webhook
            $result = $this->processWebhookData($data, $invoice_id);
            
            $this->log('debug', '====== FIM DO PROCESSAMENTO DO WEBHOOK ======');
            $this->safeResponse($result['code'], $result['message']);
            
        } catch (Exception $e) {
            // Registra exceção no log
            $this->log('error', 'Exceção não tratada:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Retorna resposta de sucesso para evitar retentativas
            $this->safeResponse(200, "Webhook processado, mas ocorreram erros internos.");
        }
    }
    
    /**
     * Processa os dados recebidos no webhook
     * 
     * @param array $data Dados decodificados do webhook
     * @param int $invoice_id ID da fatura (opcional)
     * @return array Resultado do processamento
     */
    private function processWebhookData($data, $invoice_id = null) {
        // Determina o tipo de evento e pagamento
        $event_name = $data['name'] ?? null;
        $is_pix_payment = ($event_name === 'qrcode.completed');
        $is_pix_refund = ($event_name === 'qrcode.refunded');
        $is_boleto_payment = ($event_name === 'charge.completed' || $event_name === 'charge.paid');
        
        // Log do tipo de evento recebido
        $this->log('info', "Processando evento: {$event_name}, PIX payment: " . ($is_pix_payment ? 'Sim' : 'Não') . 
                          ", PIX refund: " . ($is_pix_refund ? 'Sim' : 'Não') . 
                          ", Boleto payment: " . ($is_boleto_payment ? 'Sim' : 'Não'));
        
        // Extrai os dados principais - Suporta estrutura aninhada
        $event_data = $data['data'] ?? $data;
        
        $pagou_transaction_id = $event_data['id'] ?? null;
        $external_id_from_webhook = $event_data['external_id'] ?? $event_data['client_code'] ?? null;
        $transaction_id_secondary = $event_data['transaction_id'] ?? null; // ID secundário da transação
        
        // Define o status com base no tipo de evento
        $new_status = $event_data['status'] ?? null;
        if ($is_pix_payment && !$new_status) {
            $new_status = 'paid';
        } elseif ($is_pix_refund && !$new_status) {
            $new_status = 'refunded';
        } elseif ($is_boleto_payment && !$new_status) {
            $new_status = 'paid';
        }
        
        // Dados adicionais
        $paid_at = $event_data['paid_at'] ?? $event_data['completed_at'] ?? date('Y-m-d H:i:s');
        $refunded_at = $event_data['refunded_at'] ?? ($is_pix_refund ? date('Y-m-d H:i:s') : null);
        $amount_from_webhook = $event_data['amount_paid'] ?? $event_data['amount'] ?? null;
        
        // Extrair informações do pagador
        $payer_info = $event_data['payer'] ?? null;
        
        $this->log('info', "Dados extraídos:", [
            'event_name' => $event_name,
            'transaction_id' => $pagou_transaction_id,
            'transaction_id_secondary' => $transaction_id_secondary,
            'external_id' => $external_id_from_webhook,
            'new_status' => $new_status,
            'paid_at' => $paid_at,
            'refunded_at' => $refunded_at,
            'amount' => $amount_from_webhook,
            'payer' => $payer_info
        ]);
        
        // Validações básicas - para eventos de reembolso, apenas o ID da transação é obrigatório
        if (!$pagou_transaction_id) {
            $this->log('error', 'ID da transação ausente.');
            return [
                'code' => 400,
                'message' => "Dados incompletos (ID da transação não fornecido)."
            ];
        }
        
        // Para eventos que não são de reembolso, o status também é obrigatório
        if (!$is_pix_refund && !$new_status) {
            $this->log('error', 'Status ausente para evento não-reembolso.');
            return [
                'code' => 400,
                'message' => "Dados incompletos (status não fornecido para evento não-reembolso)."
            ];
        }

        // Determina o tipo de pagamento (PIX ou Boleto)
        $payment_type = ($is_pix_payment || $is_pix_refund) ? 'pix' : 'boleto';
        
        // Busca a transação no banco de dados - tentativa com ID primário
        $transaction = $this->findTransaction($pagou_transaction_id, $external_id_from_webhook, $invoice_id, $payment_type);
        
        // Se não encontrou com ID primário, tenta com ID secundário caso exista
        if (!$transaction && $transaction_id_secondary) {
            $this->log('info', "Transação não encontrada com ID primário, tentando com ID secundário: {$transaction_id_secondary}");
            $transaction = $this->findTransactionBySecondary($transaction_id_secondary, $payment_type);
        }

        // Se ainda não encontrou a transação, tenta criar um novo registro
        if (!$transaction) {
            $this->log('warning', "Transação não encontrada localmente para ID: {$pagou_transaction_id}");
            
            // Se temos invoice_id ou external_id, podemos tentar criar um novo registro
            if ($invoice_id || $external_id_from_webhook) {
                $invoice_to_use = $invoice_id ?: $this->getInvoiceIdFromExternalId($external_id_from_webhook);
                
                if ($invoice_to_use) {
                    $this->log('info', "Tentando criar/processar transação para fatura ID: {$invoice_to_use}");
                    
                    // Para eventos de PIX, podemos criar uma nova transação
                    if ($is_pix_payment || $is_pix_refund) {
                        $transaction = $this->createNewTransaction(
                            $pagou_transaction_id,
                            $invoice_to_use,
                            $external_id_from_webhook,
                            $payment_type,
                            $amount_from_webhook,
                            $new_status,
                            $paid_at,
                            $transaction_id_secondary
                        );
                        
                        if ($transaction) {
                            $this->log('info', "Nova transação criada para ID: {$pagou_transaction_id}, Fatura: {$invoice_to_use}");
                            
                            // Agora podemos prosseguir com o processamento normal
                        } else {
                            $this->log('error', "Falha ao criar nova transação para ID: {$pagou_transaction_id}");
                            return [
                                'code' => 500,
                                'message' => "Erro ao criar registro de transação."
                            ];
                        }
                    }
                    // Para outros tipos de eventos, tentamos o processamento direto
                    else {
                        $result = $this->processDirectPayment(
                            $invoice_to_use, 
                            $pagou_transaction_id, 
                            $new_status, 
                            $paid_at, 
                            $amount_from_webhook,
                            $payer_info,
                            $payment_type
                        );
                        
                        if ($result) {
                            // Adiciona script para atualizar a página da fatura no navegador
                            $this->sendRefreshCommand($invoice_to_use);
                            
                            return [
                                'code' => 200,
                                'message' => "Pagamento processado diretamente para a fatura ID: {$invoice_to_use}."
                            ];
                        }
                    }
                } else {
                    // Não conseguimos identificar a fatura
                    $this->log('error', "Não foi possível identificar a fatura para a transação: {$pagou_transaction_id}");
                    return [
                        'code' => 200, // Ainda retorna 200 para evitar retentativas
                        'message' => "Transação recebida, mas fatura não identificada."
                    ];
                }
            }
            
            // Se ainda não temos a transação neste ponto, retornamos um erro
            if (!$transaction) {
                return [
                    'code' => 200, // Retorna 200 para evitar retentativas
                    'message' => "Transação não encontrada localmente, mas webhook recebido."
                ];
            }
        }

        // Tratamento específico para reembolsos
        if ($is_pix_refund) {
            $this->log('info', "Processando evento de reembolso para transação ID: {$pagou_transaction_id}");
            
            // Define campos para atualização
            $update_data = [
                'status' => 'refunded',
                'payment_method' => 'pix',
                'data_estorno' => $refunded_at ?: date('Y-m-d H:i:s')
            ];
            
            // Atualiza o log de reembolso
            $this->pagou_model->add_refund_log($pagou_transaction_id, [
                'action' => 'refund_webhook',
                'amount' => $amount_from_webhook,
                'webhook_data' => $data
            ]);
            
            // Atualiza a transação com os dados do reembolso
            $updated = $this->updateTransaction($transaction, 'refunded', $paid_at, $refunded_at, $amount_from_webhook, $data, $payment_type);
            
            if ($updated) {
                $this->log('info', "Status da transação atualizado para 'refunded' com sucesso.");
                
                // Atualiza a fatura -> status=5 (Cancelado)
                $this->db->where('id', $transaction->invoice_id);
                $this->db->update(db_prefix() . 'invoices', ['status' => 5]);
                $this->log('info', "Fatura ID {$transaction->invoice_id} atualizada para status=5 (Cancelado).");
                
                // Envia comando para atualizar a página da fatura
                $this->sendRefreshCommand($transaction->invoice_id);
                
                return [
                    'code' => 200,
                    'message' => "Reembolso processado com sucesso para transação ID: {$pagou_transaction_id}"
                ];
            } else {
                $this->log('error', "Falha ao atualizar status para 'refunded'");
                return [
                    'code' => 500,
                    'message' => "Erro ao atualizar status para reembolso."
                ];
            }
        }

        // Para eventos de pagamento, atualiza a transação com os dados recebidos
        $updated = $this->updateTransaction($transaction, $new_status, $paid_at, $refunded_at, $amount_from_webhook, $data, $payment_type);
        
        if (!$updated) {
            return [
                'code' => 500,
                'message' => "Erro ao atualizar transação."
            ];
        }

        // Verifica se o status é de pagamento confirmado
        $confirmed_statuses = ['paid', 'confirmed', '4']; // Adicionado '4' conforme exemplo de API
        
        if (in_array(strtolower($new_status), $confirmed_statuses) || 
            (is_numeric($new_status) && intval($new_status) === 4) || 
            $is_pix_payment || 
            $is_boleto_payment) {
            
            $this->log('info', "Status alterado para pagamento confirmado. Processando pagamento...");
            
            $payment_processed = $this->processPayment(
                $transaction, 
                $pagou_transaction_id, 
                $new_status, 
                $paid_at, 
                $amount_from_webhook,
                $payer_info,
                $payment_type
            );
            
            if ($payment_processed) {
                // Envia comando para atualizar a página da fatura
                $this->sendRefreshCommand($transaction->invoice_id);
                
                return [
                    'code' => 200,
                    'message' => "Pagamento processado com sucesso."
                ];
            }
            
            return [
                'code' => 200, // Ainda retorna 200 para evitar retentativas
                'message' => "Atualização de status recebida, mas houve problemas ao processar o pagamento."
            ];
        }
        
        return [
            'code' => 200,
            'message' => "Webhook processado com sucesso. Status atualizado para: {$new_status}."
        ];
    }
    
    /**
     * Cria um novo registro de transação quando não encontrada
     *
     * @param string $transaction_id ID da transação
     * @param int $invoice_id ID da fatura
     * @param string $external_id ID externo
     * @param string $payment_type Tipo de pagamento
     * @param float $amount Valor
     * @param string $status Status
     * @param string $paid_at Data de pagamento
     * @param string $transaction_id_secondary ID secundário da transação
     * @return object|null Objeto da transação criada ou null se falhar
     */
    private function createNewTransaction($transaction_id, $invoice_id, $external_id, $payment_type, $amount, $status, $paid_at, $transaction_id_secondary = null) {
        try {
            $table = db_prefix() . 'pagou_transactions_' . $payment_type;
            
            // Preparar dados para inserção
            $data = [
                'transaction_id' => $transaction_id,
                'invoice_id' => $invoice_id,
                'external_id' => $external_id,
                'payment_method' => $payment_type,
                'status' => $status,
                'amount_final' => $amount,
                'date_created' => date('Y-m-d H:i:s'),
                'webhook_log' => json_encode([
                    'created_at' => date('Y-m-d H:i:s'),
                    'transaction_id' => $transaction_id,
                    'transaction_id_secondary' => $transaction_id_secondary,
                    'status' => $status,
                    'amount' => $amount
                ])
            ];
            
            // Se for um pagamento e tiver data de pagamento
            if ($status === 'paid' && $paid_at) {
                $data['data_pagamento'] = date('Y-m-d H:i:s', strtotime($paid_at));
            }
            
            // Insere os dados na tabela
            $this->db->insert($table, $data);
            
            if ($this->db->affected_rows() > 0) {
                // Busca a transação que acabamos de criar
                $this->db->where('transaction_id', $transaction_id);
                $transaction = $this->db->get($table)->row();
                
                return $transaction;
            }
            
            return null;
        } catch (Exception $e) {
            $this->log('error', "Erro ao criar nova transação: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Busca o ID da fatura usando o ID externo
     *
     * @param string $external_id ID externo
     * @return int|null ID da fatura ou null se não encontrado
     */
    private function getInvoiceIdFromExternalId($external_id) {
        if (!$external_id) {
            return null;
        }
        
        // Tenta extrair o ID da fatura do ID externo formatado
        $pattern = '/([0-9]+)$/'; // Busca número no final da string
        if (preg_match($pattern, $external_id, $matches)) {
            $possible_invoice_id = $matches[1];
            
            // Verifica se a fatura existe
            $this->db->where('id', $possible_invoice_id);
            $invoice = $this->db->get(db_prefix() . 'invoices')->row();
            
            if ($invoice) {
                return $invoice->id;
            }
        }
        
        return null;
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
     * Encontra uma transação no banco de dados
     * 
     * @param string $pagou_transaction_id ID da transação na Pagou
     * @param string $external_id ID externo da fatura
     * @param int $invoice_id ID da fatura
     * @param string $payment_type Tipo de pagamento ('pix' ou 'boleto')
     * @return object|null Transação ou null se não encontrada
     */
    private function findTransaction($pagou_transaction_id, $external_id = null, $invoice_id = null, $payment_type = 'pix') {
        $table_name = db_prefix() . 'pagou_transactions_' . $payment_type;
        
        // Sempre tentamos encontrar pelo transaction_id primeiro
        $this->db->where('transaction_id', $pagou_transaction_id);
        $transaction = $this->db->get($table_name)->row();
        
        if ($transaction) {
            return $transaction;
        }
        
        // Não encontramos pelo transaction_id, vamos tentar por invoice_id e external_id
        $this->db->reset_query();
        
        if ($invoice_id) {
            $this->db->where('invoice_id', $invoice_id);
            $transaction = $this->db->get($table_name)->row();
            
            if ($transaction) {
                $this->log('info', "Transação encontrada por invoice_id: {$invoice_id}");
                return $transaction;
            }
        }
        
        if ($external_id) {
            $this->db->reset_query();
            $this->db->where('external_id', $external_id);
            $transaction = $this->db->get($table_name)->row();
            
            if ($transaction) {
                $this->log('info', "Transação encontrada por external_id: {$external_id}");
                return $transaction;
            }
        }
        
        return null;
    }
    
    /**
     * Busca uma transação pelo ID secundário (transaction_id no webhook)
     *
     * @param string $transaction_id_secondary ID secundário da transação
     * @param string $payment_type Tipo de pagamento
     * @return object|null Transação ou null se não encontrada
     */
    private function findTransactionBySecondary($transaction_id_secondary, $payment_type = 'pix') {
        if (!$transaction_id_secondary) {
            return null;
        }
        
        $table_name = db_prefix() . 'pagou_transactions_' . $payment_type;
        
        // Tenta buscar na coluna webhook_log
        $this->db->like('webhook_log', $transaction_id_secondary);
        $transaction = $this->db->get($table_name)->row();
        
        if ($transaction) {
            $this->log('info', "Transação encontrada por transaction_id_secondary: {$transaction_id_secondary}");
            return $transaction;
        }
        
        return null;
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
        
        // Status pagos (confirmados) - normalizar para "paid"
        $paid_statuses = ['paid', 'confirmed', '2', '4', 2, 4];
        if (in_array($new_status, $paid_statuses, true)) {
            $new_status = 'paid';
            $this->log('debug', "Status convertido: '{$original_status}' → 'paid'");
        }
        
        // Status em processamento - normalizar para "processing" se necessário
        $processing_statuses = ['processing', '3', 3];
        if (in_array($new_status, $processing_statuses, true)) {
            $new_status = 'processing';
            $this->log('debug', "Status convertido: '{$original_status}' → 'processing'");
        }
        
        // Status reembolsados - normalizar para "refunded"
        $refunded_statuses = ['refunded', 'refund', 'estornado', 'estorno'];
        if (in_array(strtolower($new_status), $refunded_statuses, true)) {
            $new_status = 'refunded';
            $this->log('debug', "Status convertido: '{$original_status}' → 'refunded'");
        }

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
    } catch (Exception $e) {
        $this->log('error', "Erro ao atualizar transação {$payment_type}: " . $e->getMessage());
        return false;
    }
}
    /**
     * Consulta o status da transação diretamente na API
     * 
     * @param int $invoice_id ID da fatura
     * @return void
     */
    private function verifyTransactionStatusFromApi($invoice_id) {
        $this->log('info', "=== VERIFICANDO STATUS VIA API ===", $invoice_id);
        
        try {
            // Busca a transação PIX primeiro
            $this->db->where('invoice_id', $invoice_id);
            $this->db->order_by('id', 'desc');
            $transaction_pix = $this->db->get(db_prefix() . 'pagou_transactions_pix')->row();
            
            // Se não encontrou PIX, busca transação de boleto
            if (!$transaction_pix) {
                $this->db->where('invoice_id', $invoice_id);
                $this->db->order_by('id', 'desc');
                $transaction_boleto = $this->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
                
                if (!$transaction_boleto) {
                    $this->log('error', "Nenhuma transação encontrada para a fatura {$invoice_id}");
                    $this->safeResponse(404, "Nenhuma transação encontrada para esta fatura.");
                    return;
                }
                
                $transaction = $transaction_boleto;
                $payment_type = 'boleto';
            } else {
                $transaction = $transaction_pix;
                $payment_type = 'pix';
            }
            
            $pagou_transaction_id = $transaction->transaction_id;
            $this->log('info', "Consultando status da transação {$pagou_transaction_id} ({$payment_type}) na API");
            
            // Consulta a API com o endpoint correto baseado no tipo de pagamento
            $api_endpoint = ($payment_type === 'pix') ? '/v1/pix/' : '/v1/charges/';
            $api_data = $this->getPagouTransactionData($pagou_transaction_id, $api_endpoint);
            
            if (!$api_data) {
                $this->log('error', "Falha ao consultar API para transação {$pagou_transaction_id}");
                $this->safeResponse(500, "Erro ao consultar API da Pagou.");
                return;
            }
            
            $this->log('debug', "Resposta da API:", $api_data);
            
            // Verifica o status retornado
            $api_status = $api_data->status ?? null;
            $paid_at = $api_data->paid_at ?? null;
            $amount = $api_data->amount ?? null;
            $payer_info = $api_data->payer ?? null;
            
            if ($api_status === null) {
                $this->log('error', "Status não encontrado na resposta da API");
                $this->safeResponse(500, "Status não encontrado na resposta da API.");
                return;
            }
            
            $this->log('info', "Status na API: {$api_status}");
            
            // Atualiza o status da transação local
            $update_data = [
                'status' => $api_status,
                'payment_method' => $payment_type,
                'webhook_log' => ($transaction->webhook_log ? $transaction->webhook_log . "\n---\n" : '') . 
                                date('Y-m-d H:i:s') . ":\n[VERIFICAÇÃO API] Status: {$api_status}" .
                                ($paid_at ? ", Pago em: {$paid_at}" : "") .
                                ($amount ? ", Valor: {$amount}" : "")
            ];
            
            // Campo específico para data de pagamento baseado no tipo
            if ($paid_at) {
                if ($payment_type === 'pix') {
                    $update_data['data_pagamento'] = date('Y-m-d H:i:s', strtotime($paid_at));
                } else {
                    $update_data['payment_date'] = date('Y-m-d H:i:s', strtotime($paid_at));
                }
            }
            
            if ($amount !== null) {
                $update_data['amount_final'] = $amount;
            }
            
            $this->db->where('id', $transaction->id);
            $this->db->update(db_prefix() . 'pagou_transactions_' . $payment_type, $update_data);
            
            // Lista de status que indicam pagamento confirmado
            $confirmed_statuses = ['paid', 'confirmed', '4'];
            
            // Verifica se o status é de pagamento confirmado
            if (in_array(strtolower($api_status), $confirmed_statuses) || (is_numeric($api_status) && intval($api_status) === 4)) {
                $this->log('info', "Pagamento confirmado pela API. Processando pagamento...");
                
                $payment_processed = $this->processPayment(
                    $transaction, 
                    $pagou_transaction_id, 
                    $api_status, 
                    $paid_at, 
                    $amount,
                    $payer_info,
                    $payment_type
                );
                
                if ($payment_processed) {
                    // Envia comando para atualizar a página da fatura
                    $this->sendRefreshCommand($invoice_id);
                }
                
                $this->safeResponse(200, $payment_processed ? 
                    "Pagamento confirmado pela API e processado com sucesso." : 
                    "Pagamento confirmado pela API, mas houve um erro ao processar."
                );
            } else {
                $this->log('info', "Pagamento não confirmado pela API. Status: {$api_status}");
                $this->safeResponse(200, "Verificação realizada. Pagamento ainda não confirmado.");
            }
            
        } catch (Exception $e) {
            $this->log('error', "Erro na verificação via API:", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->safeResponse(500, "Erro ao verificar status do pagamento.");
        }
    }
    
    /**
     * Obtém dados da transação na API da Pagou
     * 
     * @param string $transaction_id ID da transação
     * @param string $api_endpoint Endpoint base da API ('/v1/pix/' ou '/v1/charges/')
     * @return object|null Dados da transação ou null em caso de erro
     */
    private function getPagouTransactionData($transaction_id, $api_endpoint = '/v1/pix/') {
        $this->log('debug', "Consultando API para transação:", $transaction_id);
        
        // Obtém o token da API
        $api_token = $this->getApiToken();
        if (!$api_token) {
            $this->log('error', "Token da API não encontrado");
            return null;
        }
        
        // Endpoint da API - determina o ambiente (produção ou sandbox)
        $option = $this->db->get_where(db_prefix() . 'options', ['name' => 'paymentmethod_pagou_pix_is_production'])->row();
        $is_production = ($option && $option->value === '1');
        $api_base = $is_production ? 'https://api.pagou.com.br' : 'https://sandbox-api.pagou.com.br';
        $api_url = $api_base . $api_endpoint . $transaction_id;
        
        try {
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $api_token
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            $response_body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            $this->log('debug', "Resposta HTTP: {$http_code}");
            
            if ($curl_error) {
                $this->log('error', "Erro cURL: " . $curl_error);
                return null;
            }
            
            if ($http_code !== 200) {
                $this->log('error', "Erro HTTP: {$http_code}");
                return null;
            }
            
            // Decodifica a resposta JSON
            $data = json_decode($response_body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log('error', "Erro ao decodificar JSON: " . json_last_error_msg());
                return null;
            }
            
            return $data;
            
        } catch (Exception $e) {
            $this->log('error', "Exceção ao consultar API: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém o token da API das configurações
     * 
     * @return string|null Token da API ou null se não encontrado
     */
    private function getApiToken() {
        $this->load->library('encryption');
        
        // Tenta obter o token do PIX primeiro
        $token_option = $this->db->get_where(db_prefix() . 'options', ['name' => 'paymentmethod_pagou_pix_api_token'])->row();
        
        // Se não encontrou, tenta obter o token do boleto
        if (!$token_option || empty($token_option->value)) {
            $token_option = $this->db->get_where(db_prefix() . 'options', ['name' => 'paymentmethod_pagou_boleto_api_token'])->row();
        }
        
        if ($token_option && !empty($token_option->value)) {
            try {
                $token = $this->encryption->decrypt($token_option->value);
                return $token;
            } catch (Exception $e) {
                $this->log('error', "Erro ao descriptografar token: " . $e->getMessage());
            }
        }
        
        $this->log('error', "Token da API não encontrado nas configurações");
        return null;
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
     * Processa um pagamento diretamente a partir do ID da fatura
     * 
     * @param int $invoice_id ID da fatura
     * @param string $transaction_id ID da transação
     * @param string $status Status da transação
     * @param string $paid_at Data do pagamento
     * @param float $amount Valor do pagamento
     * @param array|object $payer_info Informações do pagador
     * @param string $payment_type Tipo de pagamento ('pix' ou 'boleto')
     * @return bool True se processado com sucesso, false caso contrário
     */
    private function processDirectPayment($invoice_id, $transaction_id, $status, $paid_at = null, $amount = null, $payer_info = null, $payment_type = 'pix') {
        try {
            $this->log('info', "=== PROCESSAMENTO DIRETO DE PAGAMENTO ===", [
                'invoice_id' => $invoice_id,
                'transaction_id' => $transaction_id,
                'status' => $status,
                'payment_type' => $payment_type
            ]);
            
            // Verifica se a fatura existe
            $invoice = $this->invoices_model->get($invoice_id);
            if (!$invoice) {
                $this->log('error', "Fatura ID {$invoice_id} não encontrada");
                return false;
            }
            
            // Verifica se o pagamento já foi processado
            $already_exists = $this->checkPaymentExists($invoice_id, $transaction_id);
            if ($already_exists) {
                $this->log('info', "Pagamento já registrado anteriormente");
                $this->safeUpdateInvoiceStatus($invoice_id);
                return true;
            }
            
            // Se não temos o valor, usamos o total da fatura
            if ($amount === null || $amount <= 0) {
                $amount = $invoice->total;
                $this->log('debug', "Valor não fornecido ou inválido, usando total da fatura: {$amount}");
            }
            
            // Registra o pagamento no Perfex
            $payment_registered = $this->registerPayment($invoice_id, $transaction_id, $status, $paid_at, $amount, $payer_info, $payment_type);
            
            if ($payment_registered) {
                $this->log('info', "Pagamento registrado com sucesso");
                return true;
            } else {
                $this->log('error', "Falha ao registrar pagamento");
                return false;
            }
            
        } catch (Exception $e) {
            $this->log('error', "Erro no processamento direto: " . $e->getMessage());
            return false;
        }
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
            
        } catch (Exception $e) {
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
            
        } catch (Exception $e) {
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
            
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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