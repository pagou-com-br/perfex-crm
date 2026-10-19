<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once(__DIR__ . '/../libraries/Picqer/autoload.php');

use Picqer\Barcode\Renderers\PngRenderer;
use Picqer\Barcode\Types\TypeCode128;

/* ============================
     Definição da Classe Pagou
   ============================ */
class Pagou extends AdminController
{
    protected $ci;
    private $log_enabled = false; // Flag para controle dos logs

    /**
     * Método Construtor
     *
     * Inicializa o controlador Pagou com as dependências necessárias.
     */
    public function __construct()
    {
        parent::__construct();
        $this->load->helper(['modules', 'url']); // Carrega o helper de módulos
        $this->load->database();
        $this->load->library('session');
        $this->load->model('invoices_model');
        $this->load->model('payments_model');
        $this->load->model('pagou_model');

        // Verifica configuração de logs na tabela tbloptions
        $query = $this->db->select('value')
                          ->where('name', 'pagou_logs_api')
                          ->get(db_prefix() . 'options');
        if ($query->num_rows() > 0) {
            $row = $query->row();
            $this->log_enabled = ($row->value == '1');
        }

        // Log de inicialização (caso os logs estejam habilitados)
        if ($this->log_enabled) {
            $this->writeLog('debug', 'Pagou controller inicializado com logs ativados.');
        }
    }

    /**
     * Função wrapper para log_message.
     *
     * Registra as mensagens de log apenas se os logs estiverem ativados.
     *
     * @param string $level Nível do log (debug, info, error, warning).
     * @param string $message Mensagem de log.
     */
    private function writeLog($level, $message)
    {
        if ($this->log_enabled) {
            log_message($level, $message);
        }
    }

    /**
     * Função wrapper para log_activity.
     *
     * Registra a atividade apenas se os logs estiverem ativados.
     *
     * @param string $message Mensagem de atividade.
     */
    private function writeActivity($message)
    {
        if ($this->log_enabled) {
            log_activity($message);
        }
    }

    /**
     * Traduz mensagens de erro da API Pagou
     * 
     * @param string $error_message Mensagem de erro original
     * @return string Mensagem traduzida
     */
    private function translateApiError($error_message)
    {
        $error_translations = [
            'refund amount exceeds balance: insufficient balance' => 'Saldo insuficiente para realizar o reembolso',
            'refund amount exceeds payment amount' => 'Valor do reembolso excede o valor do pagamento',
            'payment not eligible for refund' => 'Pagamento não elegível para reembolso',
            'payment not found' => 'Pagamento não encontrado',
            'invalid amount' => 'Valor inválido',
            'invalid reason' => 'Motivo inválido',
            'transaction already refunded' => 'Transação já reembolsada',
            'transaction expired' => 'Transação expirada',
            'transaction cancelled' => 'Transação cancelada',
            'transaction in progress' => 'Transação em andamento',
            'transaction failed' => 'Transação falhou',
            'invalid transaction id' => 'ID de transação inválido',
            'invalid api key' => 'Chave API inválida',
            'unauthorized' => 'Não autorizado',
            'internal server error' => 'Erro interno do servidor',
        ];
        
        // Procura pela mensagem exata
        if (isset($error_translations[$error_message])) {
            return $error_translations[$error_message];
        }
        
        // Procura por palavras-chave na mensagem
        foreach ($error_translations as $key => $value) {
            if (stripos($error_message, $key) !== false) {
                return $value;
            }
        }
        
        // Se não encontrar tradução, retorna a mensagem original
        return $error_message;
    }

    /**
     * Exibe a página de PIX Avulso.
     */
    public function pix_avulso()
    {
        $this->writeLog('info', 'Acesso à página PIX Avulso.');
        set_alert('warning', 'Funcionalidade Em Desenvolvimento');
        redirect(admin_url());
    }

    /**
     * Exibe a página de Cobranças Emitidas.
     */
    public function cobrancas_emitidas()
    {
        if (!staff_can('access_reembolso', 'pagou_module')) {
            access_denied('Reembolso de Pagamentos');
        }

        $this->writeLog('info', 'Exibindo a página de Cobranças Emitidas.');
        $data['title'] = 'Cobranças Emitidas Invoice x Pagou';

        // Filtros para cobranças
        $filters = $this->input->post();
        $this->writeLog('debug', 'Filtros aplicados: ' . json_encode($filters));
        
        // Busca transações de PIX e Boletos
        $data['transactions_pix'] = $this->pagou_model->get_transactions_pix($filters);
        $data['transactions_boleto'] = $this->pagou_model->get_transactions_boleto($filters);
        
        // Combina as transações
        $data['transactions'] = array_merge($data['transactions_pix'], $data['transactions_boleto']);
        
        // Ordena por data de criação (mais recente primeiro)
        usort($data['transactions'], function($a, $b) {
            return strtotime($b->date_created) - strtotime($a->date_created);
        });

        $this->load->view('admin/pagou/cobrancas_emitidas', $data);
    }

    /**
     * Processa reembolsos de pagamentos PIX.
     */
    public function reembolso_ajax()
    {
        if ($this->input->method() !== 'delete') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']);
            return;
        }

        if (!staff_can('access_reembolso', 'pagou_module')) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Sem permissão para esta ação.']);
            return;
        }

        // Início do processamento de reembolso
        $this->writeLog('info', 'Iniciando método reembolso_ajax.');

        // Lê o transaction_id via GET
        $transaction_id = $this->input->get('transaction_id');
        $payment_type = $this->input->get('payment_type', true);
        
        // Por padrão, assumimos que é um pagamento PIX
        if (!$payment_type) {
            $payment_type = 'pix';
        }
        
        $this->writeLog('info', "Processando reembolso via AJAX DELETE para transaction_id: {$transaction_id}, tipo: {$payment_type}");

        // Lê o payload JSON do corpo da requisição DELETE
        $raw_input = file_get_contents('php://input');
        $this->writeLog('debug', "Raw input recebido: {$raw_input}");
        $payload = json_decode($raw_input, true);

        // Verifica se transaction_id e payload estão presentes
        if (!$transaction_id || !$payload) {
            $this->writeLog('error', 'Dados insuficientes para reembolso. transaction_id ou payload ausentes.');
            echo json_encode(['status' => 'error', 'message' => 'Dados insuficientes para reembolso']);
            return;
        }

        // Extrai informações do payload
        $refund_amount = isset($payload['amount']) ? floatval($payload['amount']) : 0.0;
        $description   = isset($payload['description']) ? $payload['description'] : 'Sem motivo informado';
        $reason        = isset($payload['reason']) ? intval($payload['reason']) : 1; // optional

        $this->writeLog('debug', "Payload DELETE -> refund_amount: {$refund_amount}, description: {$description}, reason: {$reason}");

        // Busca detalhes da transação PIX
        $this->writeLog('info', "Buscando detalhes da transação PIX com ID: {$transaction_id}");
        
        $transaction = $this->pagou_model->get_transaction_pix_by_id($transaction_id);
        $table_name = db_prefix() . 'pagou_transactions_pix';
        
        if (!$transaction) {
            $this->writeLog('error', "Transação PIX não encontrada para ID: {$transaction_id}");
            echo json_encode(['status' => 'error', 'message' => 'Transação PIX não encontrada!']);
            return;
        }
        $this->writeLog('info', "Transação PIX encontrada: " . json_encode($transaction));

        // Verifica se a transação já foi reembolsada
        if (in_array($transaction->status, ['refunded', 'partially_refunded'], true)) {
            $this->writeLog('warning', "Transação já reembolsada: {$transaction_id}");
            echo json_encode(['status' => 'error', 'message' => 'Esta transação já foi reembolsada.']);
            return;
        }

        // Determina o identificador do QR Code / transaction_id
        $transaction_id_to_use = $transaction->transaction_id;

        if (!empty($transaction->boleto_transaction_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Este Pix pertence a um boleto e não pode ser estornado por aqui.']);
            return;
        }

        // Valida o valor no servidor: positivo, até 2 casas e sem superar o valor pago
        $refund_amount = round($refund_amount, 2);
        $paid_amount = (float) $transaction->amount_final;
        if ($refund_amount <= 0 || ($paid_amount > 0 && $refund_amount > $paid_amount)) {
            echo json_encode(['status' => 'error', 'message' => 'Valor de estorno inválido.']);
            return;
        }
        if (!in_array($reason, [1, 2, 3, 4], true)) {
            $reason = 3; // Devolução solicitada pelo cliente final
        }
        if (trim((string) $description) === '') {
            $description = 'Devolução solicitada pelo cliente';
        }

        $apiToken = pagou_gateway_api_token('pagou_pix');
        if (!$apiToken) {
            $this->writeLog('error', 'API key Pagou PIX não configurada.');
            echo json_encode(['status' => 'error', 'message' => 'Configuração de API key PIX ausente.']);
            return;
        }
        $api_base = pagou_gateway_api_base_url('pagou_pix');
        $resource_url = $api_base . '/v1/pix/' . rawurlencode($transaction_id_to_use);

        // Confere na API: só cobrança paga (status 4) e sem solicitação de estorno registrada
        $current = $this->pagouRequest('GET', $resource_url, $apiToken);
        if ($current['http_code'] !== 200 || !is_array($current['body'])) {
            echo json_encode(['status' => 'error', 'message' => 'Não foi possível consultar a cobrança na Pagou. Tente novamente em instantes.']);
            return;
        }
        // refund.status: 1 registro vazio, 2 pendente, 3 confirmada
        $refund = $current['body']['refund'] ?? null;
        $refund_status = is_array($refund) ? (int) ($refund['status'] ?? 0) : 0;
        $charge_status = (int) ($current['body']['status'] ?? 0);

        if ($refund_status === 3 || $charge_status === 5) {
            $refunded_amount = is_array($refund) && isset($refund['amount']) ? (float) $refund['amount'] : (float) $transaction->amount_final;
            $full = $refunded_amount + 0.009 >= (float) $transaction->amount_final;
            $this->pagou_model->update_transaction_pix_status($transaction_id_to_use, $full ? 'refunded' : 'partially_refunded', []);
            echo json_encode(['status' => 'warning', 'message' => 'A Pagou já registra a devolução desta cobrança. O status local foi atualizado.']);
            return;
        }
        if ($refund_status === 2) {
            $this->pagou_model->update_transaction_pix_status($transaction_id_to_use, 'refund_requested', []);
            echo json_encode(['status' => 'warning', 'message' => 'Já existe uma devolução pendente na Pagou para esta cobrança. Aguarde a confirmação.']);
            return;
        }
        if ($charge_status !== 4) {
            echo json_encode(['status' => 'error', 'message' => 'A cobrança não está paga na Pagou e não pode ser estornada.']);
            return;
        }
        if ($transaction->status === 'refund_requested') {
            // Sem devolução registrada na Pagou: a solicitação anterior não foi efetivada
            $this->pagou_model->update_transaction_pix_status($transaction_id_to_use, 'paid', []);
        }

        $payload_refund = [
            'amount'      => $refund_amount,
            'reason'      => $reason,
            'description' => mb_substr($description, 0, 255),
        ];

        $this->pagou_model->add_refund_log($transaction_id_to_use, [
            'action' => 'refund_requested',
            'amount' => $refund_amount,
            'description' => $description,
            'reason' => $reason,
            'requested_by' => get_staff_user_id(),
            'staff_name' => get_staff_full_name(get_staff_user_id())
        ]);

        $result = $this->pagouRequest('DELETE', $resource_url . '/refund', $apiToken, $payload_refund);
        $http_code = $result['http_code'];

        if ($http_code === 204 || $http_code === 200) {
            // 204 indica apenas que a solicitação foi aceita; a conclusão vem pelo qrcode.refunded
            $this->pagou_model->update_transaction_pix_status($transaction_id_to_use, 'refund_requested', []);
            $this->pagou_model->add_refund_log($transaction_id_to_use, [
                'action' => 'refund_accepted',
                'amount' => $refund_amount,
                'http_code' => $http_code,
            ]);

            $this->writeActivity("Estorno PIX solicitado para a transação {$transaction_id} no valor de R$ " .
                                number_format($refund_amount, 2, ',', '.') .
                                " | Fatura: #{$transaction->invoice_prefix}{$transaction->invoice_number}");

            echo json_encode([
                'status' => 'success',
                'message' => 'Estorno solicitado à Pagou. A fatura será atualizada quando a devolução for confirmada.',
                'transaction' => [
                    'id' => $transaction_id,
                    'refund_amount' => $refund_amount,
                    'refund_date' => date('d/m/Y H:i:s')
                ]
            ]);
            return;
        }

        $uncertain = ($http_code === 0 || $http_code >= 500 || $http_code === 409);
        $api_error = '';
        if (is_array($result['body'])) {
            $api_error = $result['body']['error'] ?? $result['body']['message'] ?? '';
            if (is_array($api_error)) {
                $api_error = implode(', ', $api_error);
            }
        }

        $this->pagou_model->add_refund_log($transaction_id_to_use, [
            'action' => $uncertain ? 'refund_uncertain' : 'refund_failed',
            'amount' => $refund_amount,
            'http_code' => $http_code,
            'api_error' => $api_error,
        ]);

        if ($uncertain) {
            // Resultado indeterminado: não repetir sem conferir a cobrança
            $this->pagou_model->update_transaction_pix_status($transaction_id_to_use, 'refund_requested', []);
            echo json_encode(['status' => 'warning', 'message' => 'A Pagou não confirmou o recebimento da solicitação. Não repita o estorno: confira a cobrança no painel da Pagou antes.']);
            return;
        }

        echo json_encode(['status' => 'error', 'message' => 'Erro ao solicitar estorno PIX. ' . $this->translateApiError($api_error)]);
    }

    /**
     * Requisição à API Pagou com timeout e sem seguir redirecionamentos.
     *
     * @param string     $method
     * @param string     $url
     * @param string     $api_token
     * @param array|null $body
     * @return array ['http_code' => int, 'body' => array|null]
     */
    private function pagouRequest($method, $url, $api_token, $body = null)
    {
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'User-Agent: pagou-perfex/2.0.0',
            'X-API-KEY: ' . $api_token,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => $http_code,
            'body' => ($response !== false && $response !== '') ? json_decode($response, true) : null,
        ];
    }

    /**
     * Ajax handler para dados de transações PIX
     */
    public function get_pix_transactions()
    {
        if (!staff_can('access_reembolso', 'pagou_module')) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Sem permissão para esta ação.']);
            return;
        }

        $this->writeLog('debug', 'Buscando transações PIX para DataTables');
        
        // Verifica se é uma requisição AJAX
        if (!$this->input->is_ajax_request()) {
            show_404();
            return;
        }

        // Obtém parâmetros do DataTables
        $draw = $this->input->post('draw') ? intval($this->input->post('draw')) : 1;
        $start = $this->input->post('start') ? intval($this->input->post('start')) : 0;
        $length = $this->input->post('length') ? intval($this->input->post('length')) : 25;
        $search = $this->input->post('search');
        $search_value = isset($search['value']) ? $search['value'] : '';
        
        // Busca transações com paginação
        $transactions = $this->pagou_model->get_transactions_pix_paginated($start, $length, $search_value);
        $total_transactions = $this->pagou_model->count_transactions_pix();
        $filtered_transactions = $this->pagou_model->count_transactions_pix($search_value);
        
        // Formata dados para DataTables
        $data = [];
        foreach ($transactions as $transaction) {
            // Data de criação
            $data_criacao = !empty($transaction->date_created) ? _d($transaction->date_created) : '---';
            
            // Formatação da fatura
            $invoice_number = '';
            if (isset($transaction->invoice_prefix) && isset($transaction->invoice_number)) {
                $invoice_number = $transaction->invoice_prefix . $transaction->invoice_number;
            } else {
                $invoice_number = '#' . $transaction->invoice_id;
            }
            
            $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

            // Link da invoice
            $invoice_link = admin_url('invoices/list_invoices/' . (int) $transaction->invoice_id);
            $invoice_html = '<a href="' . $esc($invoice_link) . '" target="_blank">' . $esc($invoice_number) . '</a>';

            // Cliente e link
            $client_name = isset($transaction->client_company) ? $transaction->client_company : '---';
            $client_link = admin_url('clients/client/' . (int) ($transaction->clientid ?? 0));
            $client_html = '<a href="' . $esc($client_link) . '" target="_blank">' . $esc($client_name) . '</a>';

            // Valor formatado
            $valor_formatado = number_format(
                isset($transaction->amount_final) ? $transaction->amount_final : 0, 
                2, ',', '.'
            );
            
            // Status
            $status_db = (string)$transaction->status;
            $status_class = '';
            $status_icon = '';
            
            if ($status_db === 'paid' || $status_db === '4') {
                $status_display = 'Pago';
                $status_class = 'success';
                $status_icon = 'fa-check-circle';
            } elseif (in_array($status_db, ['pending', 'active', '1', '2'], true)) {
                $status_display = 'Pendente';
                $status_class = 'warning';
                $status_icon = 'fa-clock-o';
            } elseif ($status_db === 'refund_requested') {
                $status_display = 'Estorno solicitado';
                $status_class = 'info';
                $status_icon = 'fa-hourglass-half';
            } elseif ($status_db === 'partially_refunded') {
                $status_display = 'Reembolso parcial';
                $status_class = 'warning';
                $status_icon = 'fa-undo';
            } elseif ($status_db === 'refunded') {
                $status_display = 'Reembolsado';
                $status_class = 'danger';
                $status_icon = 'fa-undo';
            } else {
                $status_display = ucfirst($status_db);
                $status_class = 'default';
                $status_icon = 'fa-question-circle';
            }
            
            $status_html = '<span class="label label-' . $status_class . '"><i class="fa ' . $status_icon . '"></i> ' . $esc($status_display) . '</span>';
            
            // Método de pagamento
            $payment_method = isset($transaction->payment_method) ? $transaction->payment_method : '';
            if ($payment_method == 'pix') {
                $method_display = '<span class="label label-info"><i class="fa fa-qrcode"></i> PIX</span>';
            } elseif ($payment_method == 'creditcard') {
                $method_display = '<span class="label label-primary"><i class="fa fa-credit-card"></i> Cartão</span>';
            } elseif ($payment_method == 'boleto') {
                $method_display = '<span class="label label-default"><i class="fa fa-barcode"></i> Boleto</span>';
            } else {
                $method_display = '<span class="label label-default">' . $esc(ucfirst($payment_method)) . '</span>';
            }
            
            // ID da Transação
            $transaction_id_display = isset($transaction->transaction_id) ? 
                substr($transaction->transaction_id, 0, 10) . '...' : 
                '---';
            $transaction_id_full = isset($transaction->transaction_id) ? 
                $transaction->transaction_id : 
                '';
                
            $transaction_id_html = '<span class="transaction-id" title="' . $esc($transaction_id_full) . '" data-toggle="tooltip">' . $esc($transaction_id_display) . '</span>';

            $details_button = '<button class="btn btn-info btn-sm" data-transaction="' . $esc($transaction->transaction_id) . '" onclick="viewTransactionDetails(this.dataset.transaction)"><i class="fa fa-eye"></i></button>';
            
            // Data Pagamento
            $data_pagamento = !empty($transaction->data_pagamento) ? _dt($transaction->data_pagamento) : '---';
            
            // Botão de ação
            if (in_array($status_db, ['paid', '4', 'refund_requested'], true) && ($payment_method === 'pix') && empty($transaction->boleto_transaction_id)) {
                $action_html = '<div class="btn-group">'
                    . '<button class="btn btn-success btn-sm"'
                    . ' data-transaction="' . $esc($transaction->transaction_id) . '"'
                    . ' data-client="' . $esc($client_name) . '"'
                    . ' data-invoice="' . $esc($invoice_number) . '"'
                    . ' data-amount="' . $esc((float) ($transaction->amount_final ?? 0)) . '"'
                    . ' onclick="refundTransaction(this.dataset.transaction, this.dataset.client, this.dataset.invoice, parseFloat(this.dataset.amount))">'
                    . '<i class="fa fa-undo"></i> Reembolsar</button>'
                    . $details_button . '</div>';
            } elseif (in_array($status_db, ['refunded', 'partially_refunded'], true)) {
                $data_estorno = !empty($transaction->data_estorno) ? _dt($transaction->data_estorno) : '';
                $label = $status_db === 'refunded' ? 'Reembolsado' : 'Reembolso parcial';
                $action_html = '<div class="btn-group"><span class="label label-danger"><i class="fa fa-undo"></i> ' . $label
                    . ($data_estorno ? '<br>em ' . $esc($data_estorno) : '') . '</span>' . $details_button . '</div>';
            } else {
                $label = !empty($transaction->boleto_transaction_id) ? 'Pix do boleto' : ($payment_method !== 'pix' ? 'Não Elegível' : 'Não Pago');
                $action_html = '<div class="btn-group"><span class="text-muted">' . $label . '</span>' . $details_button . '</div>';
            }

            // Adiciona linha de dados
            $row = [
                $data_criacao,
                $invoice_html,
                $client_html,
                $valor_formatado,
                $status_html,
                $method_display,
                $transaction_id_html,
                $data_pagamento,
                $action_html
            ];
            
            $data[] = $row;
        }
        
        // Retorna os dados no formato esperado pelo DataTables
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $total_transactions,
            'recordsFiltered' => $filtered_transactions,
            'data' => $data
        ]);
    }

    /**
     * Obtém detalhes de uma transação PIX para exibição em modal
     * 
     * @return void
     */
    public function get_transaction_details() {
        if (!staff_can('access_reembolso', 'pagou_module')) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Sem permissão para esta ação.']);
            return;
        }

        // Verifica se é uma requisição AJAX
        if (!$this->input->is_ajax_request()) {
            show_404();
            return;
        }
        
        $transaction_id = $this->input->post('transaction_id');
        if (!$transaction_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID da transação não fornecido']);
            return;
        }
        
        // Obtém a transação
        $transaction = $this->pagou_model->get_transaction_pix_by_id($transaction_id);
        if (!$transaction) {
            echo json_encode(['status' => 'error', 'message' => 'Transação não encontrada']);
            return;
        }
        
        // Obtém histórico de reembolso
        $refund_history = $this->pagou_model->get_refund_history($transaction_id);
        
        // Logs exibidos sem dados pessoais
        $webhook_logs = [];
        if (!empty($transaction->webhook_log)) {
            $json_log = json_decode($transaction->webhook_log, true);
            $entries = is_array($json_log) ? [$json_log] : explode("\n---\n", $transaction->webhook_log);
            foreach ($entries as $entry) {
                $decoded = is_array($entry) ? $entry : json_decode((string) $entry, true);
                $webhook_logs[] = is_array($decoded) ? pagou_redact($decoded) : '[registro textual omitido]';
            }
        }
        $public = (array) $transaction;
        foreach (['api_request', 'api_response', 'webhook_log', 'reembolso_log', 'qr_code', 'qr_code_text'] as $field) {
            unset($public[$field]);
        }
        $transaction_public = pagou_redact($public);

        // Prepara resposta
        $response = [
            'status' => 'success',
            'transaction' => $transaction_public,
            'refund_history' => $refund_history,
            'webhook_logs' => $webhook_logs,
            'formatted' => [
                'date_created' => !empty($transaction->date_created) ? _dt($transaction->date_created) : '---',
                'data_pagamento' => !empty($transaction->data_pagamento) ? _dt($transaction->data_pagamento) : '---',
                'data_estorno' => !empty($transaction->data_estorno) ? _dt($transaction->data_estorno) : '---',
                'amount_final' => number_format(
                    isset($transaction->amount_final) ? $transaction->amount_final : 0, 
                    2, ',', '.'
                )
            ]
        ];
        
        echo json_encode($response);
    }
}