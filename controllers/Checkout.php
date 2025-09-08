<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Definição da Classe Checkout
   ============================ */
class Checkout extends ClientsController
{
    /**
     * Indicador se os logs estão habilitados
     * @var bool
     */
    private $log_enabled = false;
    
    /**
     * Método Construtor
     *
     * Inicializa o controlador Checkout, carrega as bibliotecas necessárias
     * e verifica a configuração da opção "pagou_logs_api" na tabela de opções.
     */
    public function __construct()
    {
        parent::__construct();

        // Verifica na tabela de opções (tbloptions) se a opção "pagou_logs_api" está habilitada
        $log_option = $this->db->get_where(db_prefix().'options', ['name' => 'pagou_logs_api'])->row();
        $this->log_enabled = ($log_option && $log_option->value == '1');

        if ($this->log_enabled) {
            log_message('debug', 'Pagou Controller initialized with API logs enabled.');
        }
        
        $this->load->library(['pagou_boleto_gateway', 'pagou_pix_gateway']);
    }
    
    /**
     * Função auxiliar para registrar logs de mensagem.
     *
     * @param string $level Nível do log (ex: debug, info, error)
     * @param string $message Mensagem a ser registrada.
     */
    private function _log_message($level, $message)
    {
        if ($this->log_enabled) {
            log_message($level, $message);
        }
    }
    
    /**
     * Função auxiliar para registrar logs de atividade.
     *
     * @param string $message Mensagem de atividade.
     */
    private function _log_activity($message)
    {
        if ($this->log_enabled) {
            log_activity($message);
        }
    }
    
    /**
     * Método principal para exibir as opções de pagamento ou processar pagamentos existentes.
     *
     * @param string $hash Hash único da fatura.
     * @return void
     */
    public function index($hash)
    {
        $this->_log_message('debug', 'Método index chamado com hash: ' . $hash);
        
        /* ============================
             Busca da Fatura pelo Hash
           ============================ */
        // Buscar fatura pelo hash
        $this->db->where('hash', $hash);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$invoice) {
            $this->_log_activity('Fatura não encontrada para hash: ' . $hash);
            set_alert('danger', 'Fatura não encontrada.');
            redirect(site_url());
        }

        /* ============================
             Busca da Transação Associada à Fatura
           ============================ */
        // Buscar transação associada à fatura
        $this->db->where('invoice_id', $invoice->id);
        $transaction = $this->db->get(db_prefix() . 'pagou_transactions')->row();

        if ($transaction) {
            $this->_log_message('debug', 'Transação encontrada para a fatura #' . $invoice->id . ', método: ' . $transaction->payment_method);
            // PIX - Mantém funcionalidade existente
            if ($transaction->payment_method === 'pix') {
                $this->process_pix_payment($transaction);
                return;
            }

            // Boleto - Processa a transação
            if ($transaction->payment_method === 'boleto') {
                $this->process_boleto_payment($transaction);
                return;
            }
        }

        /* ============================
             Exibição das Opções de Pagamento
           ============================ */
        // Exibir opções de pagamento caso nenhuma transação seja encontrada
        $this->data([
            'invoice' => $invoice,
            'payment_methods' => [
                'boleto' => true,
                'pix' => true,
            ]
        ]);
        $this->view('payment');
        $this->layout();
    }

    /**
     * Processa o pagamento via Boleto.
     *
     * @param object $transaction Objeto da transação.
     * @return void
     */
    private function process_boleto_payment($transaction)
    {
        $this->_log_message('debug', 'Iniciando processamento de boleto para transação: ' . $transaction->transaction_id);
        
        /* ============================
             Determinação do Endpoint da API
           ============================ */
        // Determinar o endpoint correto (sandbox ou produção)
        $environment = $this->pagou_boleto_gateway->getSetting('environment');
        $api_endpoint = $environment == 'sandbox'
            ? 'https://sandbox-api.pagou.com.br/v1/charges/'
            : 'https://api.pagou.com.br/v1/charges/';

        $this->_log_message('debug', 'Endpoint da API definido: ' . $api_endpoint);
        
        /* ============================
             Requisição para Consultar o Boleto
           ============================ */
        // Requisição para a API Pagou para consultar o boleto
        $ch = curl_init($api_endpoint . $transaction->transaction_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $this->pagou_boleto_gateway->getSetting('api_token')
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->_log_message('debug', 'Resposta da API para consulta de boleto: HTTP ' . $http_code . ' | ' . $response);
        
        /* ============================
             Verificação da Resposta da API
           ============================ */
        if ($http_code === 200) {
            $result = json_decode($response);
            if (isset($result->url)) {
                $this->_log_message('info', 'Boleto consultado com sucesso. Redirecionando para URL: ' . $result->url);
                redirect($result->url);
                return;
            }
        }

        $this->_log_message('error', 'Erro ao consultar boleto. HTTP_CODE: ' . $http_code . ' | Resposta: ' . $response);
        set_alert('danger', 'Erro ao consultar o boleto. Tente novamente mais tarde.');
        redirect(site_url());
    }

    /**
     * Gera um novo boleto para uma fatura específica.
     *
     * @param int $invoice_id ID da fatura.
     * @return void
     */
    public function generate_boleto($invoice_id)
    {
        $this->_log_message('debug', 'Método generate_boleto chamado para fatura ID: ' . $invoice_id);
        
        /* ============================
             Busca da Fatura
           ============================ */
        // Buscar fatura
        $this->db->where('id', $invoice_id);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$invoice) {
            $this->_log_activity('Fatura não encontrada para ID: ' . $invoice_id);
            set_alert('danger', 'Fatura não encontrada.');
            redirect(site_url());
        }

        /* ============================
             Montagem dos Dados do Boleto
           ============================ */
        // Montar os dados do boleto a partir da fatura
        $payload = [
            'amount' => (int)($invoice->total * 100), // Valor em centavos
            'description' => $invoice->clientnote ?: 'Pagamento de Fatura',
            'due_date' => $invoice->duedate,
            'fine' => 2, // Multa fixa
            'interest' => 1, // Juros fixo
            'payer' => [
                'name' => $invoice->billing_street ?: 'Cliente Não Identificado',
                'document' => $invoice->clientid, // Documento do cliente
                'street' => $invoice->billing_street ?: 'N/D',
                'number' => '000',
                'neighborhood' => $invoice->billing_city ?: 'N/D',
                'city' => $invoice->billing_city ?: 'N/D',
                'state' => $invoice->billing_state ?: 'N/D',
                'zip' => $invoice->billing_zip ?: '00000-000'
            ],
            'notification_url' => site_url('pagou/webhook') // URL para webhooks
        ];

        $this->_log_message('debug', 'Payload para geração de boleto: ' . json_encode($payload));
        
        /* ============================
             Determinação do Endpoint da API
           ============================ */
        // Determinar o endpoint correto
        $environment = $this->pagou_boleto_gateway->getSetting('environment');
        $api_endpoint = $environment == 'sandbox'
            ? 'https://sandbox-api.pagou.com.br/v1/charges'
            : 'https://api.pagou.com.br/v1/charges';

        $this->_log_message('debug', 'Endpoint da API para geração de boleto: ' . $api_endpoint);
        
        /* ============================
             Requisição para Criar o Boleto
           ============================ */
        // Fazer a requisição para criar o boleto
        $ch = curl_init($api_endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->pagou_boleto_gateway->getSetting('api_token')
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->_log_message('debug', 'Resposta da API para geração de boleto: HTTP ' . $http_code . ' | ' . $response);
        
        /* ============================
             Verificação da Resposta da API
           ============================ */
        if ($http_code === 201) {
            $result = json_decode($response);

            /* ============================
                 Salvamento da Transação no Banco de Dados
               ============================ */
            // Salvar transação na tabela `pagou_transactions`
            $this->db->insert(db_prefix() . 'pagou_transactions', [
                'invoice_id' => $invoice_id,
                'transaction_id' => $result->id,
                'payment_method' => 'boleto',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $this->_log_message('info', 'Boleto gerado com sucesso para fatura ID: ' . $invoice_id . '. Redirecionando para URL: ' . $result->url);
            redirect($result->url); // Redireciona para o boleto gerado
            return;
        }

        $this->_log_message('error', 'Erro ao gerar boleto. HTTP_CODE: ' . $http_code . ' | Resposta: ' . $response);
        set_alert('danger', 'Erro ao gerar o boleto. Tente novamente mais tarde.');
        redirect(site_url());
    }

    /**
     * Cancela um boleto associado a uma fatura específica.
     *
     * @param int $invoice_id ID da fatura.
     * @return void
     */
    public function cancel_boleto($invoice_id)
    {
        $this->_log_message('debug', 'Método cancel_boleto chamado para fatura ID: ' . $invoice_id);
        
        /* ============================
             Busca da Transação Associada à Fatura
           ============================ */
        // Buscar transação associada à fatura
        $this->db->where('invoice_id', $invoice_id);
        $transaction = $this->db->get(db_prefix() . 'pagou_transactions')->row();

        if ($transaction && $transaction->payment_method === 'boleto') {
            /* ============================
                 Determinação do Endpoint da API
               ============================ */
            // Determinar o endpoint correto
            $environment = $this->pagou_boleto_gateway->getSetting('environment');
            $api_endpoint = $environment == 'sandbox'
                ? 'https://sandbox-api.pagou.com.br/v1/charges/'
                : 'https://api.pagou.com.br/v1/charges/';

            $this->_log_message('debug', 'Endpoint da API para cancelamento de boleto: ' . $api_endpoint);
            
            /* ============================
                 Requisição para Cancelar o Boleto
               ============================ */
            $ch = curl_init($api_endpoint . $transaction->transaction_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-API-KEY: ' . $this->pagou_boleto_gateway->getSetting('api_token')
            ]);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->_log_message('debug', 'Resposta da API para cancelamento de boleto: HTTP ' . $http_code . ' | ' . $response);
            
            /* ============================
                 Verificação da Resposta da API
               ============================ */
            if ($http_code === 204) {
                // Deletar a transação da tabela `pagou_transactions`
                $this->db->delete(db_prefix() . 'pagou_transactions', ['id' => $transaction->id]);
                $this->_log_message('info', 'Boleto cancelado com sucesso para fatura #' . $invoice_id);
                set_alert('success', 'Boleto cancelado com sucesso.');
                redirect(site_url());
                return;
            }

            $this->_log_message('error', 'Erro ao cancelar boleto. HTTP_CODE: ' . $http_code . ' | Resposta: ' . $response);
        }

        $this->_log_message('error', 'Erro ao cancelar o boleto. Transação não encontrada ou método de pagamento inválido.');
        set_alert('danger', 'Erro ao cancelar o boleto. Tente novamente mais tarde.');
        redirect(site_url());
    }
    
    /**
     * Processa o pagamento via PIX.
     *
     * @param object $transaction Objeto da transação.
     * @return void
     */
    private function process_pix_payment($transaction)
    {
        // Exemplo de implementação para o processamento via PIX.
        $this->_log_message('debug', 'Processando pagamento via PIX para transação: ' . $transaction->transaction_id);
        
        // A lógica de processamento via PIX pode ser implementada aqui,
        // incluindo chamadas à API, verificação de status e demais tratamentos.
    }
}
?>
