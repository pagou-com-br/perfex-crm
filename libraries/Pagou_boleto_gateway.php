<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* =======================================
 * Definição da Classe Pagou_boleto_gateway
 * =======================================
 * Gateway de pagamento Boleto utilizando a API da Pagou.com.br para o Perfex CRM.
 * Versão: 1.9 (Melhorias no processamento automático e suporte a PIX)
 */
class Pagou_boleto_gateway extends App_gateway
{
    // Instância do CodeIgniter
    protected $ci;
    // Flag para habilitar/desabilitar logs
    private $enable_logs;

    /**
     * Construtor da classe Pagou_boleto_gateway.
     */
    public function __construct()
    {
        parent::__construct();

        $this->ci = &get_instance();
        $this->ci->load->database();

        // Define o ID e nome do gateway
        $this->setId('pagou_boleto');
        $this->setName('Pagou.com.br Boleto');

        // Configuração do sistema de logs
        $option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api'])->row();
        $this->enable_logs = ($option && $option->value === '1');

        $this->log('info', 'Pagou_boleto_gateway (v1.9) inicializado. Logs ' . ($this->enable_logs ? 'ativados' : 'desativados') . '.');

        // Define as configurações
        $this->setSettings([
            // ==== SEÇÃO API ====
            [
                'name'      => 'api_token',
                'encrypted' => true,
                'label'     => 'API | Token Pagou.com.br (Obrigatório)',
                'type'      => 'input'
            ],
            [
                'name'    => 'is_production',
                'label'   => 'AMBIENTE | Produção? (SIM = transações reais, NÃO = ambiente de testes)',
                'type'    => 'yes_no',
                'default_value' => 1
            ],
            [
                'name'          => 'currencies',
                'label'         => 'MOEDA | Códigos aceitos (geralmente apenas BRL para Boleto)',
                'type'          => 'input',
                'default_value' => 'BRL'
            ],
            [
                'name'    => 'enable_logs',
                'label'   => 'LOGS | Ativar registro detalhado de logs da integração',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'    => 'log_detailed',
                'label'   => 'LOGS | Ativar registro super detalhado (incluir payload completo)',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            
            // ==== SEÇÃO DESCONTOS ====
            [
                'name'    => 'enable_discount',
                'label'   => '━━━━━━━━━━━━━━━━ DESCONTOS ━━━━━━━━━━━━━━━━',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'    => 'discount_type',
                'label'   => '     ⤷ Tipo: escolha "fixed" (valor fixo) ou "percentage" (percentual)',
                'type'    => 'select',
                'options' => [
                    'fixed'      => 'Valor Fixo (R$)',
                    'percentage' => 'Percentual (%)'
                ],
                'default_value' => 'fixed'
            ],
            [
                'name'          => 'discount_value',
                'label'         => '     ⤷ Valor: se fixed=reais (5.00=R$5,00), se percentage=% (10=10%)',
                'type'          => 'input',
                'default_value' => '0'
            ],
            [
                'name'          => 'discount_limit_date',
                'label'         => '     ⤷ Número de dias antes do vencimento para aplicar desconto (Ex: 5)',
                'type'          => 'input',
                'default_value' => '0'
            ],
            
            // ==== SEÇÃO MULTAS ====
            [
                'name'    => 'enable_fine',
                'label'   => '━━━━━━━━━━━━━━━━ MULTA POR ATRASO ━━━━━━━━━━━━━━━━',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'          => 'fine_value',
                'label'         => '     ⤷ Valor percentual de multa (0.1 até 10%, padrão: 2%)',
                'type'          => 'input',
                'default_value' => '2'
            ],
            
            // ==== SEÇÃO JUROS ====
            [
                'name'    => 'enable_interest',
                'label'   => '━━━━━━━━━━━━━━━━ JUROS POR ATRASO ━━━━━━━━━━━━━━━━',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'          => 'interest_value',
                'label'         => '     ⤷ Juros: percentual MENSAL, cobrado por dia de atraso (ex.: 1 = 1% ao mês)',
                'type'          => 'input',
                'default_value' => '0.33'
            ],
            
            // ==== SEÇÃO CARÊNCIA ====
            [
                'name'    => 'enable_grace_period',
                'label'   => '━━━━━━━━━━━━━━━━ CARÊNCIA APÓS VENCIMENTO ━━━━━━━━━━━━━━━━',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'          => 'grace_period_days',
                'label'         => '     ⤷ Dias de carência após vencimento (1 até 30, padrão: 1)',
                'type'          => 'input',
                'default_value' => '1'
            ],
            
            // ==== SEÇÃO WEBHOOK ====
            [
                'name'    => 'webhook_section',
                'label'   => '━━━━━━━━━━━━━━━━ CONFIGURAÇÃO DE WEBHOOK ━━━━━━━━━━━━━━━━',
                'type'    => 'info'
            ],
            [
                'name'          => 'webhook_url',
                'label'         => '     ⤷ URL de Webhook (deixe em branco para usar a padrão)',
                'type'          => 'input',
                'default_value' => ''
            ],
            
            // ==== SEÇÃO INTEGRAÇÃO PIX ====
            [
                'name'    => 'pix_section',
                'label'   => '━━━━━━━━━━━━━━━━ INTEGRAÇÃO PIX ━━━━━━━━━━━━━━━━',
                'type'    => 'info'
            ],
            [
                'name'    => 'enable_pix_with_boleto',
                'label'   => '     ⤷ Ativar também PIX junto com Boleto (permitir que cliente escolha)',
                'type'    => 'yes_no',
                'default_value' => 1
            ],
        ]);
        
        // Registra o gateway no Perfex CRM
        hooks()->add_action('before_add_payment_gateway', [$this, 'register_gateway']);
    }

    /**
     * Método para registrar o gateway no Perfex CRM
     */
    public function register_gateway()
    {
        $this->ci->payment_gateways->add($this);
    }

    /**
     * Método para inicializar as configurações
     * Chamado após o salvamento das configurações no Perfex CRM
     */
    public function init_settings()
    {
        parent::init_settings();
        
        // Atualiza a configuração de logs quando alterada na interface
        if ($this->getSetting('enable_logs') === '1') {
            update_option('pagou_logs_api', '1');
            $this->enable_logs = true;
        } else {
            update_option('pagou_logs_api', '0');
            $this->enable_logs = false;
        }
    }

    /**
     * Método melhorado para registrar logs com suporte a objetos e arrays
     * 
     * @param string $level Nível do log (debug, info, error, etc)
     * @param string $message Mensagem a ser registrada
     * @param mixed $data Dados adicionais para o log (opcional)
     */
    public function log($level, $message, $data = null)
    {
        if ($this->enable_logs) {
            $log_message = "[Pagou Boleto Gateway] " . $message;
            
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
            
            // Opcionalmente, salvar também em um arquivo dedicado
            $log_file = APPPATH . 'logs/pagou_boleto_' . date('Y-m-d') . '.log';
            error_log($log_message . PHP_EOL, 3, $log_file);
        }
    }

    /**
     * Método principal para processar o pagamento
     * 
     * @param array $data Dados da transação
     * @return bool|array Sucesso ou falha, ou array com dados da resposta se bem-sucedido
     */
    public function process_payment($data)
    {
        $this->log('debug', 'Método process_payment iniciado.');

        if (empty($data['invoice'])) {
            $this->log('error', 'Nenhuma fatura informada para process_payment.');
            set_alert('danger', _l('invoice_not_found'));
            redirect(site_url());
            return false;
        }

        $invoice = $data['invoice'];
        $this->log('debug', 'Processando pagamento para a Fatura Perfex ID: ' . $invoice->id . ', Número: ' . format_invoice_number($invoice->id));

        // Verifica a existência da tabela no banco de dados
        $this->ensure_table_exists();

        // Verifica boleto existente não pago/expirado
        $this->ci->db->where('invoice_id', $invoice->id);
        $this->ci->db->where_not_in('status', ['paid', 'confirmed', 'expired', 'refunded', 'cancelled']); // Status que indicam finalização ou cancelamento
        $existing_transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_boleto')->row();

        if ($existing_transaction) {
            // Verifica se o boleto está expirado baseado na data de vencimento
            $due_date = new DateTime($existing_transaction->due_date);
            $now = new DateTime();
            
            if ($due_date < $now) {
                $this->log('info', "Transação existente (ID Pagou: {$existing_transaction->transaction_id}) para a Fatura ID: " . $invoice->id . " está vencida. Criando novo boleto.");
                // Atualiza status da transação para expirada
                $this->ci->db->where('id', $existing_transaction->id);
                $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', ['status' => 'expired']);
                // Continua o fluxo para gerar um novo boleto
            } else {
                $this->log('info', "Transação existente (ID Pagou: {$existing_transaction->transaction_id}) encontrada para a Fatura ID: " . $invoice->id . ". Reutilizando boleto.");
                
                // NOVO: Consulta o boleto para obter informações atualizadas
                $updated_data = $this->fetch_boleto_data($existing_transaction->transaction_id);
                
                if ($updated_data && isset($updated_data->status)) {
                    // Atualiza os dados locais com base na consulta
                    $this->update_boleto_from_api($existing_transaction, $updated_data);
                    
                    // Se há dados de PIX, atualiza ou insere na tabela de PIX
                    if (isset($updated_data->payload) && isset($updated_data->payload->qrcode_id)) {
                        $this->save_pix_data_from_boleto($invoice->id, $updated_data);
                    }
                }
                
                // Carrega a view independente de ter conseguido atualizar ou não
                return $this->load_boleto_view($invoice, $existing_transaction);
            }
        }

        // --- Prepara os dados para enviar à API Pagou ---
        $amount_formatted = round($invoice->total, 2);
        
        $description = hooks()->apply_filters(
            'pagou_boleto_description',
            'Pagamento Fatura Perfex CRM #' . format_invoice_number($invoice->id) . ' (Cliente: ' . ($invoice->client->company ?? 'Cliente') . ')',
            $invoice
        );

        // Tratamento seguro para obter dados do cliente
        $client_name = $this->get_client_name($invoice);
        $client_document = $this->get_client_document($invoice);
        $client_email = $this->get_client_email($invoice);
        $client_address = $this->get_client_address($invoice);

        // A URL de notificação deve ser acessível publicamente.
        $custom_webhook_url = trim($this->getSetting('webhook_url'));
        $notification_url = !empty($custom_webhook_url) ? $custom_webhook_url : admin_url('pagou/pagou_webhook/process/');
        
        $this->log('debug', 'URL de Notificação configurada: ' . $notification_url);

        // Gerar identificador externo único para a fatura
        $external_id = $this->generate_external_id($invoice->id);

        // Garantir que o bairro não seja vazio
        $neighborhood = isset($client_address['neighborhood']) && !empty($client_address['neighborhood']) 
            ? $client_address['neighborhood'] 
            : 'Centro';

        $payment_data = [
            'amount'           => $amount_formatted,
            'description'      => mb_substr($description, 0, 140), // API Pagou: max 140 caracteres
            'due_date'         => (!empty($invoice->duedate) && $invoice->duedate > date('Y-m-d')) ? date('Y-m-d', strtotime($invoice->duedate)) : date('Y-m-d'),
            'notification_url' => $notification_url,
            'external_id'      => $external_id,
            'payer'            => [
                'name'         => mb_substr($client_name, 0, 100), // Limitar tamanho
                'document'     => preg_replace('/[^0-9]/', '', $client_document), // Apenas números
                'street'       => $client_address['street'] ?? 'Endereço não especificado',
                'number'       => $client_address['number'] ?? 'S/N',
                'neighborhood' => $neighborhood, // Usando a variável com verificação
                'city'         => $client_address['city'] ?? 'Cidade não especificada',
                'state'        => $client_address['state'] ?? 'SP',
                'zip'          => $client_address['zip'] ?? '00000000'
            ],
        ];
        
        // Verificação adicional de segurança após montar o payload
        if (empty($payment_data['payer']['neighborhood'])) {
            $payment_data['payer']['neighborhood'] = 'Centro';
            $this->log('warning', "Bairro vazio no payload final. Usando valor padrão 'Centro'");
        }
        
        // Adiciona email apenas se disponível
        if (!empty($client_email)) {
            $payment_data['payer']['email'] = $client_email;
        }

        // Metadados adicionais para melhor controle
        $payment_data['metadata'] = [
            [
                'key' => 'invoice_id',
                'value' => (string) $invoice->id
            ],
            [
                'key' => 'invoice_number',
                'value' => format_invoice_number($invoice->id)
            ],
            [
                'key' => 'client_id',
                'value' => (string) $invoice->clientid
            ],
            [
                'key' => 'source',
                'value' => 'perfex_crm'
            ]
        ];

        // Adiciona Desconto se habilitado e valor > 0
        if ($this->getSetting('enable_discount') === '1') {
            $discount_value_input = $this->getSetting('discount_value');
            if (is_numeric($discount_value_input) && $discount_value_input > 0) {
                $discount_type = $this->getSetting('discount_type');
                // Validar se o tipo é válido
                if (!in_array($discount_type, ['fixed', 'percentage'])) {
                    $discount_type = 'fixed'; // Valor padrão se inválido
                    $this->log('warning', 'Tipo de desconto inválido na configuração. Usando "fixed" como padrão.');
                }
                
                // Calcular a data limite do desconto com base nos dias antes do vencimento
                $discount_days_before = (int) $this->getSetting('discount_limit_date');
                $discount_limit_date = date('Y-m-d', strtotime($invoice->duedate . ' -' . $discount_days_before . ' days'));
                
                $api_discount_value = round(floatval(str_replace(',', '.', $discount_value_input)), 2);
                $payment_data['discount'] = [
                    'type'       => $discount_type,
                    'amount'     => $api_discount_value,
                    'limit_date' => $discount_limit_date
                ];
                $this->log('debug', 'Desconto configurado para API:', $payment_data['discount']);
            }
        }

        // Adiciona Multa se habilitada e valor > 0
        if ($this->getSetting('enable_fine') === '1') {
            $fine_value_input = $this->getSetting('fine_value');
            if (is_numeric($fine_value_input) && $fine_value_input > 0) {
                // Garantir que o valor esteja dentro dos limites (0.1 a 10%)
                $fine_value = min(max(floatval(str_replace(',', '.', $fine_value_input)), 0.1), 10);
                $payment_data['fine'] = $fine_value;
                $this->log('debug', 'Multa configurada para API: ' . $fine_value . '%');
            }
        }
        
        // Adiciona Juros se habilitado e valor > 0
        if ($this->getSetting('enable_interest') === '1') {
            $interest_value_input = $this->getSetting('interest_value');
            if (is_numeric($interest_value_input) && $interest_value_input > 0) {
                // Percentual mensal; a API aceita de 0.1 a 100
                $interest_value = min(max(floatval(str_replace(',', '.', $interest_value_input)), 0.1), 100);
                $payment_data['interest'] = $interest_value;
                $this->log('debug', 'Juros configurados para API: ' . $interest_value . '% ao mês');
            }
        }
        
        // grace_period é obrigatório na API (1 a 30 dias); padrão 1 quando a carência está desligada
        $grace_period = 1;
        if ($this->getSetting('enable_grace_period') === '1') {
            $grace_period_input = $this->getSetting('grace_period_days');
            if (is_numeric($grace_period_input) && $grace_period_input > 0) {
                $grace_period = min(max((int) $grace_period_input, 1), 30);
            }
        }
        $payment_data['grace_period'] = $grace_period;
        $this->log('debug', 'Período de carência configurado para API: ' . $grace_period . ' dias');

        $this->log('debug', 'Payload final para API Pagou:', $payment_data);

        // Determinando o ambiente (produção ou sandbox)
        $is_production = $this->getSetting('is_production') === '1';
        $environment = $is_production ? 'production' : 'sandbox';
        
        // Configurando corretamente o endpoint de acordo com o ambiente
        $api_endpoint = pagou_api_base_url($is_production) . '/v1/charges';
        
        $this->log('debug', 'API endpoint selecionado: ' . $api_endpoint . ' (Ambiente: ' . $environment . ')');

        // Adiciona logs detalhados antes da requisição
        $this->log('info', "=== INÍCIO DA REQUISIÇÃO PARA API PAGOU ===");
        $this->log('debug', 'URL da API', $api_endpoint);
        $this->log('debug', 'Método HTTP', 'POST');
        $this->log('debug', 'Cabeçalhos enviados', [
            'Content-Type: application/json',
            'X-API-KEY: [REDACTED]' // Não loga o token real por segurança
        ]);
        $this->log('debug', 'Payload enviado (corpo da requisição)', $payment_data);

        $ch = curl_init($api_endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->decryptSetting('api_token'),
            'User-Agent: PerfexCRM/PagouBoleto/1.9'
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        // Adiciona captura de informações de requisição

        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Logs detalhados após a requisição
        $this->log('debug', 'Tempo total da requisição', $total_time . ' segundos');
        $this->log('debug', 'Código HTTP recebido', $http_code);
        $this->log('debug', 'Tipo de conteúdo recebido', $content_type);
        $this->log('debug', 'Corpo da resposta recebida', $response_body);

        if ($curl_error) {
            $this->log('error', 'Erro cURL ao chamar API Pagou', $curl_error);
        }

        $this->log('info', "=== FIM DA REQUISIÇÃO PARA API PAGOU ===");
        
        if ($curl_error) {
            $this->log('error', 'Erro cURL ao chamar API Pagou: ' . $curl_error);
            set_alert('danger', 'Erro de comunicação com o gateway de pagamento. Detalhes: ' . $curl_error);
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return false;
        }

        if ($http_code === 201) {
            $this->log('info', 'Boleto gerado com sucesso pela Pagou (HTTP 201).');
            $result = json_decode($response_body);

            if (!$result || !isset($result->id)) {
                $this->log('error', 'Resposta da API Pagou em formato inesperado: ' . $response_body);
                set_alert('danger', 'Erro ao processar resposta do gateway de pagamento. Formato inesperado.');
                redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
                return false;
            }
            
            $db_transaction_data = [
                'invoice_id'         => $invoice->id,
                'cliente'            => $client_name,
                'doc'                => $client_document,
                'transaction_id'     => $result->id,
                'payment_method'     => $this->getId(),
                'barcode'            => $result->payload->bar_code ?? '',
                'barcode_url'        => $result->payload->line ?? '',
                'boleto_url'         => $result->boleto_url ?? '',
                'amount'             => $invoice->total,
                'amount_final'       => isset($result->amount) ? $result->amount : $invoice->total,
                'status'             => $result->status ?? 'pending',
                'date_created'       => date('Y-m-d H:i:s'),
                'due_date'           => $invoice->duedate,
                'api_request'        => json_encode($payment_data),
                'api_response'       => $response_body,
                'external_id'        => $external_id,
            ];
            
            $this->ci->db->insert(db_prefix() . 'pagou_transactions_boleto', $db_transaction_data);
            $local_transaction_id = $this->ci->db->insert_id();
            $this->log('info', "Transação Boleto registrada no banco local (ID: {$local_transaction_id}, Pagou ID: {$result->id}).");

            // NOVO: Salvar informações de PIX se disponíveis
            if (isset($result->payload) && isset($result->payload->qrcode_id)) {
                $this->save_pix_data_from_boleto($invoice->id, $result);
                $this->log('info', "Informações de PIX salvas para a fatura ID: {$invoice->id}");
            }

            // NOVO: Se for chamado de forma automática (por hook), retorna apenas os dados
            if (isset($data['auto_generated']) && $data['auto_generated'] === true) {
                return ['success' => true, 'transaction_id' => $result->id];
            }

            // Carrega a view para exibição
            return $this->load_boleto_view($invoice, $local_transaction_id);

        } else {
            $error_message_from_api = 'Erro desconhecido da API Pagou.';
            if ($response_body) {
                $result = json_decode($response_body);
                $error_message_from_api = $this->parsePagouError($result, $response_body);
            }
            $this->log('error', "Erro ao gerar Boleto na Pagou. HTTP Code: {$http_code}. API Message: {$error_message_from_api}. Raw Response: {$response_body}");
            set_alert('danger', 'Erro ao gerar Boleto junto ao gateway: ' . $error_message_from_api . ' (Código Pagou: ' . $http_code . ')');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return false;
        }
    }

    /**
     * NOVA FUNÇÃO: Carrega a view do boleto
     * 
     * @param object $invoice Objeto da fatura
     * @param mixed $transaction ID da transação local ou objeto da transação
     * @return bool True se a view foi carregada com sucesso
     */
    public function load_boleto_view($invoice, $transaction)
    {
        // Se foi passado o ID da transação, busca o objeto completo
        if (is_numeric($transaction)) {
            $this->ci->db->where('id', $transaction);
            $transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
            
            if (!$transaction) {
                $this->log('error', "Transação ID {$transaction} não encontrada ao carregar view.");
                return false;
            }
        }
        
        // Verifica se há dados de PIX relacionados ao boleto
        $this->ci->db->where('invoice_id', $invoice->id);
        $pix_data = $this->ci->db->get(db_prefix() . 'pagou_transactions_pix')->row();
        
        $view_data = [
            'invoice'            => $invoice,
            'barcode'            => $transaction->barcode ?? '',
            'barcode_url'        => $transaction->barcode_url ?? '',
            'boleto_url'         => $transaction->boleto_url ?? '',
            'due_date'           => $transaction->due_date ?? $invoice->duedate,
            'pagou_transaction_id' => $transaction->transaction_id,
            'status'             => $transaction->status ?? 'pending'
        ];
        
        // Adiciona dados de PIX se disponíveis
        if ($pix_data) {
            $view_data['qr_code'] = $pix_data->qr_code ?? '';
            $view_data['qr_code_text'] = $pix_data->qr_code_text ?? '';
            $view_data['qr_code_img'] = $pix_data->qr_code ?? '';
        }
        
        $this->ci->load->view('pagou/payment_boleto', $view_data);
        $this->log('info', 'View de pagamento Boleto/PIX carregada para o cliente.');
        
        return true;
    }
    
    /**
     * NOVA FUNÇÃO: Consulta detalhes do boleto na API
     * 
     * @param string $transaction_id ID da transação na Pagou
     * @return object|null Dados do boleto ou null em caso de erro
     */
    public function fetch_boleto_data($transaction_id)
    {
        $this->log('info', "Consultando detalhes do boleto ID: {$transaction_id}");
        
        // Determina o ambiente
        $is_production = $this->getSetting('is_production') === '1';
        $api_base = pagou_api_base_url($is_production);
        $api_endpoint = $api_base . '/v1/charges/' . $transaction_id;
        
        $this->log('debug', 'URL da API para consulta: ' . $api_endpoint);
        
        $ch = curl_init($api_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->decryptSetting('api_token'),
            'User-Agent: PerfexCRM/PagouBoleto/1.9'
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            $this->log('error', 'Erro cURL ao consultar boleto: ' . $curl_error);
            return null;
        }
        
        if ($http_code !== 200) {
            $this->log('error', "Erro HTTP ao consultar boleto: {$http_code}. Resposta: {$response_body}");
            return null;
        }
        
        $result = json_decode($response_body);
        
        if (!$result) {
            $this->log('error', 'Erro ao decodificar resposta JSON: ' . json_last_error_msg());
            return null;
        }
        
        $this->log('info', 'Consulta de boleto realizada com sucesso. Status: ' . ($result->status ?? 'N/A'));
        return $result;
    }
    
    /**
     * NOVA FUNÇÃO: Atualiza os dados locais do boleto com base na resposta da API
     * 
     * @param object $transaction Transação local
     * @param object $api_data Dados retornados pela API
     * @return bool True se atualizado com sucesso
     */
    public function update_boleto_from_api($transaction, $api_data)
    {
        $this->log('info', "Atualizando dados locais do boleto ID: {$transaction->id}");
        
        $update_data = [
            'status' => $api_data->status ?? $transaction->status,
            'barcode' => $api_data->payload->bar_code ?? $transaction->barcode,
            'barcode_url' => $api_data->payload->line ?? $transaction->barcode_url,
            'boleto_url' => $api_data->boleto_url ?? $transaction->boleto_url,
            'updated_at' => date('Y-m-d H:i:s'),
            'api_response' => json_encode($api_data)
        ];
        
        // Atualiza a data de pagamento se disponível
        if (isset($api_data->paid_at)) {
            $update_data['payment_date'] = date('Y-m-d H:i:s', strtotime($api_data->paid_at));
        }
        
        $this->ci->db->where('id', $transaction->id);
        $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', $update_data);
        
        $affected_rows = $this->ci->db->affected_rows();
        $this->log('info', "Atualização de dados locais " . ($affected_rows > 0 ? "bem-sucedida" : "sem alterações"));
        
        return $affected_rows > 0;
    }
    
    /**
     * NOVA FUNÇÃO: Salva ou atualiza os dados de PIX obtidos da resposta do boleto
     * 
     * @param int $invoice_id ID da fatura
     * @param object $boleto_data Dados do boleto retornados pela API
     * @return bool True se sucesso, False caso contrário
     */
    public function save_pix_data_from_boleto($invoice_id, $boleto_data)
    {
        $this->log('info', "Salvando dados de PIX para a fatura ID: {$invoice_id}");
        
        // Verifica se os dados necessários estão presentes
        if (!isset($boleto_data->payload) || !isset($boleto_data->payload->qrcode_id)) {
            $this->log('error', 'Dados de PIX não encontrados na resposta da API');
            return false;
        }
        
        // Verifica se já existe um registro de PIX para esta fatura
        $this->ci->db->where('invoice_id', $invoice_id);
        $existing_pix = $this->ci->db->get(db_prefix() . 'pagou_transactions_pix')->row();
        
        // Prepara os dados para salvar
        $pix_data = [
            'invoice_id' => $invoice_id,
            'cliente' => $boleto_data->payer->name ?? '',
            'doc' => $boleto_data->payer->document ?? '',
            'transaction_id' => $boleto_data->payload->qrcode_id,
            'payment_method' => 'pix',
            'qr_code' => $boleto_data->payload->image ?? '',
            'qr_code_text' => $boleto_data->payload->data ?? '',
            'amount' => $boleto_data->amount ?? 0,
            'amount_final' => $boleto_data->amount ?? 0,
            'status' => $boleto_data->status ?? 'pending',
            'date_created' => date('Y-m-d H:i:s'),
            'external_id' => $boleto_data->external_id ?? '',
            'api_response' => json_encode($boleto_data)
        ];
        
        if ($existing_pix) {
            // Atualiza o registro existente
            $this->ci->db->where('id', $existing_pix->id);
            $this->ci->db->update(db_prefix() . 'pagou_transactions_pix', $pix_data);
            $this->log('info', "Dados de PIX atualizados para o ID: {$existing_pix->id}");
        } else {
            // Insere um novo registro
            $this->ci->db->insert(db_prefix() . 'pagou_transactions_pix', $pix_data);
            $inserted_id = $this->ci->db->insert_id();
            $this->log('info', "Dados de PIX inseridos com ID: {$inserted_id}");
        }
        
        return true;
    }
    
    /**
     * Gera um ID externo único para a fatura
     * 
     * @param int $invoice_id ID da fatura
     * @return string ID externo único
     */
    private function generate_external_id($invoice_id) {
        // Combina ID da fatura com timestamp e string aleatória para evitar duplicações
        $random_string = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 10);
        $timestamp = time();
        return $random_string . $timestamp . 'invoice' . $invoice_id;
    }
    
    /**
     * Extrai e formata mensagens de erro da API Pagou
     * 
     * @param object $result Objeto de resposta decodificado da API
     * @param string $raw_response Resposta bruta da API
     * @return string Mensagem de erro formatada
     */
    private function parsePagouError($result, $raw_response) {
        if (isset($result->message) && is_string($result->message)) { // Erro principal
            $main_error = $result->message;
            if (isset($result->errors) && is_array($result->errors) && count($result->errors) > 0) {
                 $error_details = [];
                 foreach($result->errors as $err_obj) {
                     if(is_object($err_obj) && isset($err_obj->field) && isset($err_obj->message)){
                        $error_details[] = "{$err_obj->field}: {$err_obj->message}";
                     } elseif (is_string($err_obj)) { // Às vezes pode ser um array de strings
                        $error_details[] = $err_obj;
                     }
                 }
                 if(!empty($error_details)){
                    return $main_error . ' Detalhes: ' . implode('; ', $error_details);
                 }
            }
            return $main_error;
        } elseif (isset($result->error) && is_string($result->error)) { // Outro formato comum de erro
            return $result->error;
        } elseif (isset($result->errors) && is_array($result->errors) && count($result->errors) > 0) { // Apenas o array de erros
             $error_details = [];
             foreach($result->errors as $err_obj) {
                 if(is_object($err_obj) && isset($err_obj->field) && isset($err_obj->message)){
                    $error_details[] = "{$err_obj->field}: {$err_obj->message}";
                 } elseif (is_string($err_obj)) {
                    $error_details[] = $err_obj;
                 }
             }
             return implode('; ', $error_details);
        }
        return substr(strip_tags($raw_response), 0, 250); // Tenta limpar e cortar
    }

    /**
     * Obtém o nome do cliente de forma segura
     * 
     * @param object $invoice Objeto da fatura
     * @return string Nome do cliente
     */
    private function get_client_name($invoice) {
        if (isset($invoice->client) && is_object($invoice->client)) {
            if (!empty($invoice->client->company)) {
                return $invoice->client->company;
            } elseif (!empty($invoice->client->firstname) || !empty($invoice->client->lastname)) {
                return trim($invoice->client->firstname . ' ' . $invoice->client->lastname);
            }
        }
        
        // Tenta obter pelo modelo de clientes
        if (isset($invoice->clientid)) {
            $this->ci->load->model('clients_model');
            $client = $this->ci->clients_model->get($invoice->clientid);
            if ($client) {
                if (!empty($client->company)) {
                    return $client->company;
                } elseif (!empty($client->firstname) || !empty($client->lastname)) {
                    return trim($client->firstname . ' ' . $client->lastname);
                }
            }
        }
        
        return 'Cliente'; // Valor padrão
    }

    /**
     * Obtém o documento do cliente de forma segura
     * 
     * @param object $invoice Objeto da fatura
     * @return string Documento do cliente
     */
    private function get_client_document($invoice) {
        $document = '';
        
        if (isset($invoice->client) && is_object($invoice->client) && isset($invoice->client->vat)) {
            $document = $invoice->client->vat;
        } elseif (isset($invoice->clientid)) {
            $this->ci->load->model('clients_model');
            $client = $this->ci->clients_model->get($invoice->clientid);
            if ($client && isset($client->vat)) {
                $document = $client->vat;
            }
        }
        
        // Remove caracteres não numéricos (apenas para CPF/CNPJ)
        return preg_replace('/[^0-9]/', '', $document);
    }

    /**
     * Obtém o email do cliente de forma segura
     * 
     * @param object $invoice Objeto da fatura
     * @return string Email do cliente
     */
    private function get_client_email($invoice) {
        if (isset($invoice->client) && is_object($invoice->client)) {
            if (isset($invoice->client->email)) {
                return $invoice->client->email;
            } elseif (isset($invoice->client->contact_email)) {
                return $invoice->client->contact_email;
            }
        }
        
        // Tenta obter pelo modelo de clientes
        if (isset($invoice->clientid)) {
            $this->ci->load->model('clients_model');
            $client = $this->ci->clients_model->get($invoice->clientid);
            if ($client && isset($client->email)) {
                return $client->email;
            }
        }
        
        return ''; // Retorna vazio se não encontrar
    }
    
    /**
     * Obtém o endereço completo do cliente de forma segura
     * 
     * @param object $invoice Objeto da fatura
     * @return array Array com os componentes do endereço
     */
    private function get_client_address($invoice) {
        $address = [
            'street' => '',
            'number' => '',
            'neighborhood' => 'Centro', // Alterado de string vazia para 'Centro'
            'city' => '',
            'state' => '',
            'zip' => ''
        ];
        
        // Tenta obter os dados de endereço da fatura primeiro (caso tenha sido customizado)
        if (isset($invoice->billing_street)) {
            $address['street'] = $invoice->billing_street;
        }
        if (isset($invoice->billing_city)) {
            $address['city'] = $invoice->billing_city;
        }
        if (isset($invoice->billing_state)) {
            $address['state'] = $invoice->billing_state;
        }
        if (isset($invoice->billing_zip)) {
            $address['zip'] = preg_replace('/[^0-9]/', '', $invoice->billing_zip);
        }
        
        // Se não encontrou na fatura, tenta obter do cliente
        if (empty($address['street']) && isset($invoice->clientid)) {
            $this->ci->load->model('clients_model');
            $client = $this->ci->clients_model->get($invoice->clientid);
            
            if ($client) {
                if (!empty($client->billing_street)) {
                    $address['street'] = $client->billing_street;
                }
                if (!empty($client->billing_city)) {
                    $address['city'] = $client->billing_city;
                }
                if (!empty($client->billing_state)) {
                    $address['state'] = $client->billing_state;
                }
                if (!empty($client->billing_zip)) {
                    $address['zip'] = preg_replace('/[^0-9]/', '', $client->billing_zip);
                }
                
                // Procura campos customizados para número e bairro
                $this->ci->db->where('fieldto', 'customers');
                $this->ci->db->where('slug', 'customers_numero');
                $number_field = $this->ci->db->get(db_prefix() . 'customfields')->row();
                
                $this->ci->db->where('fieldto', 'customers');
                $this->ci->db->where('slug', 'customers_bairro');
                $neighborhood_field = $this->ci->db->get(db_prefix() . 'customfields')->row();
                
                if ($number_field) {
                    $this->ci->db->where('relid', $client->userid);
                    $this->ci->db->where('fieldid', $number_field->id);
                    $number_value = $this->ci->db->get(db_prefix() . 'customfieldsvalues')->row();
                    
                    if ($number_value && !empty($number_value->value)) {
                        $address['number'] = $number_value->value;
                        $this->log('debug', "Valor do número encontrado: {$number_value->value}");
                    } else {
                        $this->log('warning', "Valor do número não encontrado para cliente ID: {$client->userid}");
                    }
                } else {
                    $this->log('warning', "Campo customizado 'customers_numero' não encontrado na configuração");
                }
                
                if ($neighborhood_field) {
                    $this->ci->db->where('relid', $client->userid);
                    $this->ci->db->where('fieldid', $neighborhood_field->id);
                    $neighborhood_value = $this->ci->db->get(db_prefix() . 'customfieldsvalues')->row();
                    
                    if ($neighborhood_value && !empty($neighborhood_value->value)) {
                        $address['neighborhood'] = $neighborhood_value->value;
                        $this->log('debug', "Valor do bairro encontrado: {$neighborhood_value->value}");
                    } else {
                        $this->log('warning', "Valor do bairro não encontrado para cliente ID: {$client->userid}");
                    }
                } else {
                    $this->log('warning', "Campo customizado 'customers_bairro' não encontrado na configuração");
                }
            }
        }
        
        // Tentar extrair número da rua se estiver no formato "Rua Nome, 123"
        if (empty($address['number']) && !empty($address['street'])) {
            $matches = [];
            if (preg_match('/^(.+),\s*(\d+.*)$/i', $address['street'], $matches)) {
                $address['street'] = trim($matches[1]);
                $address['number'] = trim($matches[2]);
            }
        }
        
        // Tentar extrair bairro da rua se estiver no formato "Rua Nome - Bairro"
        if (empty($address['neighborhood']) && !empty($address['street'])) {
            $matches = [];
            if (preg_match('/^(.+)\s*-\s*(.+)$/i', $address['street'], $matches)) {
                $address['street'] = trim($matches[1]);
                if (empty($address['neighborhood'])) {
                    $address['neighborhood'] = trim($matches[2]);
                }
            }
        }
        
        // Garantir que o estado esteja no formato de 2 letras
        if (!empty($address['state']) && strlen($address['state']) > 2) {
            $states_map = [
                'ACRE' => 'AC',
                'ALAGOAS' => 'AL',
                'AMAPA' => 'AP',
                'AMAZONAS' => 'AM',
                'BAHIA' => 'BA',
                'CEARA' => 'CE',
                'DISTRITO FEDERAL' => 'DF',
                'ESPIRITO SANTO' => 'ES',
                'GOIAS' => 'GO',
                'MARANHAO' => 'MA',
                'MATO GROSSO' => 'MT',
                'MATO GROSSO DO SUL' => 'MS',
                'MINAS GERAIS' => 'MG',
                'PARA' => 'PA',
                'PARAIBA' => 'PB',
                'PARANA' => 'PR',
                'PERNAMBUCO' => 'PE',
                'PIAUI' => 'PI',
                'RIO DE JANEIRO' => 'RJ',
                'RIO GRANDE DO NORTE' => 'RN',
                'RIO GRANDE DO SUL' => 'RS',
                'RONDONIA' => 'RO',
                'RORAIMA' => 'RR',
                'SANTA CATARINA' => 'SC',
                'SAO PAULO' => 'SP',
                'SERGIPE' => 'SE',
                'TOCANTINS' => 'TO'
            ];
            
            $state_normalized = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ã', 'Õ', 'Â', 'Ê', 'Î', 'Ô', 'Û', 'Ç'], 
                                          ['A', 'E', 'I', 'O', 'U', 'A', 'O', 'A', 'E', 'I', 'O', 'U', 'C'], 
                                          mb_strtoupper(trim($address['state']), 'UTF-8'));
            
            if (isset($states_map[$state_normalized])) {
                $address['state'] = $states_map[$state_normalized];
            } else {
                // Se não conseguir mapear, mantém apenas as duas primeiras letras
                $address['state'] = substr($state_normalized, 0, 2);
            }
        }
        
        // Verificação extra para garantir que o bairro nunca seja vazio
        if (empty($address['neighborhood'])) {
            $address['neighborhood'] = 'Centro';
            $this->log('warning', "Bairro vazio após processamento, usando valor padrão 'Centro'");
        }
        
        return $address;
    }

    /**
     * Verifica o status de uma transação na API da Pagou
     * 
     * @param string $transaction_id ID da transação na Pagou
     * @return object Resposta da API
     */
    public function check_transaction_status($transaction_id)
    {
        $this->log('info', "=== VERIFICANDO STATUS DA TRANSAÇÃO ===", $transaction_id);
        
        // Determinando o ambiente (produção ou sandbox)
        $is_production = $this->getSetting('is_production') === '1';
        $api_base = pagou_api_base_url($is_production);
        $api_endpoint = $api_base . '/v1/charges/' . $transaction_id;
        
        $this->log('debug', 'API endpoint de consulta', $api_endpoint);
        
        $ch = curl_init($api_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->decryptSetting('api_token'),
            'User-Agent: PerfexCRM/PagouBoleto/1.9'
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        $this->log('debug', 'Tempo total da consulta', $total_time . ' segundos');
        $this->log('debug', 'Código HTTP recebido', $http_code);
        $this->log('debug', 'Resposta da consulta', $response_body);
        
        if ($curl_error) {
            $this->log('error', 'Erro cURL ao consultar status', $curl_error);
        }
        
        $this->log('info', "=== FIM DA VERIFICAÇÃO DE STATUS ===");
        
        return json_decode($response_body);
    }

    /**
     * Registra logs de transações no banco de dados
     * 
     * @param int $invoice_id ID da fatura
     * @param string $transaction_id ID da transação na Pagou
     * @param string $log_type Tipo de log (request, response, webhook, etc)
     * @param mixed $log_data Dados do log
     */
    public function log_to_db($invoice_id, $transaction_id, $log_type, $log_data)
    {
        if (!$this->enable_logs) {
            return;
        }
        
        // Converte dados para string se necessário
        if (is_array($log_data) || is_object($log_data)) {
            $log_data = json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        
        $log_entry = [
            'date' => date('Y-m-d H:i:s'),
            'invoice_id' => $invoice_id,
            'transaction_id' => $transaction_id,
            'type' => $log_type,
            'data' => $log_data
        ];
        
        // Adiciona ao campo webhook_log na tabela de transações
        $this->ci->db->where('invoice_id', $invoice_id);
        $this->ci->db->where('transaction_id', $transaction_id);
        $transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_boleto')->row();
        
        if ($transaction) {
            $current_log = $transaction->webhook_log ? json_decode($transaction->webhook_log, true) : [];
            if (!is_array($current_log)) {
                $current_log = [];
            }
            $current_log[] = $log_entry;
            
            $this->ci->db->where('id', $transaction->id);
            $this->ci->db->update(db_prefix() . 'pagou_transactions_boleto', [
                'webhook_log' => json_encode($current_log)
            ]);
        }
        
        $this->log('debug', 'Log registrado no banco de dados', $log_entry);
    }

    /**
     * Certifica-se de que a tabela de transações existe no banco de dados
     * e que possui todas as colunas necessárias
     */
    private function ensure_table_exists() {
        $table_name = db_prefix() . 'pagou_transactions_boleto';
        
        // Verifica se a tabela existe
        $table_exists = $this->ci->db->table_exists($table_name);
        
        if (!$table_exists) {
            $this->log('info', 'Tabela de transações não encontrada. Criando tabela...');
            
            // Cria a tabela se não existir
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
            
            $this->log('info', 'Tabela de transações criada com sucesso.');
        } else {
            // Obtém as colunas existentes
            $query = $this->ci->db->query("SHOW COLUMNS FROM `{$table_name}`");
            $existing_columns = [];
            
            foreach ($query->result() as $row) {
                $existing_columns[] = $row->Field;
            }
            
            // Define as colunas necessárias e seus tipos
            $required_columns = [
                'id' => "INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
                'invoice_id' => "INT(11) NOT NULL",
                'cliente' => "VARCHAR(255) DEFAULT NULL",
                'doc' => "VARCHAR(30) DEFAULT NULL",
                'transaction_id' => "VARCHAR(255) NOT NULL",
                'external_id' => "VARCHAR(255) DEFAULT NULL",
                'payment_method' => "VARCHAR(50) NOT NULL",
                'barcode' => "TEXT NOT NULL",
                'barcode_url' => "VARCHAR(255) DEFAULT NULL",
                'boleto_url' => "VARCHAR(255) DEFAULT NULL",
                'amount' => "DECIMAL(15,2) NOT NULL",
                'amount_final' => "DECIMAL(15,2) DEFAULT NULL",
                'status' => "VARCHAR(50) NOT NULL DEFAULT 'pending'",
                'date_created' => "DATETIME NOT NULL",
                'due_date' => "DATE NOT NULL",
                'payment_date' => "DATETIME DEFAULT NULL",
                'refund_date' => "DATETIME DEFAULT NULL",
                'api_request' => "TEXT DEFAULT NULL",
                'api_response' => "TEXT DEFAULT NULL",
                'webhook_log' => "TEXT DEFAULT NULL"
            ];
            
            // Verifica e adiciona colunas ausentes
            foreach ($required_columns as $column => $definition) {
                if (!in_array($column, $existing_columns)) {
                    $this->log('info', "Coluna {$column} não encontrada. Adicionando coluna...");
                    try {
                        $this->ci->db->query("ALTER TABLE `{$table_name}` ADD COLUMN `{$column}` {$definition}");
                        $this->log('info', "Coluna {$column} adicionada com sucesso.");
                    } catch (\Throwable $e) {
                        $this->log('error', "Erro ao adicionar coluna {$column}: " . $e->getMessage());
                        // Continue para tentar adicionar outras colunas
                    }
                }
            }
            
            // Verifica se os índices necessários existem
            if (!$this->check_index_exists($table_name, 'invoice_id')) {
                $this->log('info', "Índice para invoice_id não encontrado. Adicionando índice...");
                try {
                    $this->ci->db->query("ALTER TABLE `{$table_name}` ADD INDEX `invoice_id` (`invoice_id`)");
                    $this->log('info', "Índice para invoice_id adicionado com sucesso.");
                } catch (\Throwable $e) {
                    $this->log('error', "Erro ao adicionar índice para invoice_id: " . $e->getMessage());
                }
            }
            
            if (!$this->check_index_exists($table_name, 'transaction_id')) {
                $this->log('info', "Índice para transaction_id não encontrado. Adicionando índice...");
                try {
                    $this->ci->db->query("ALTER TABLE `{$table_name}` ADD INDEX `transaction_id` (`transaction_id`(191))");
                    $this->log('info', "Índice para transaction_id adicionado com sucesso.");
                } catch (\Throwable $e) {
                    $this->log('error', "Erro ao adicionar índice para transaction_id: " . $e->getMessage());
                }
            }
            
            if (!$this->check_index_exists($table_name, 'external_id')) {
                $this->log('info', "Índice para external_id não encontrado. Adicionando índice...");
                try {
                    $this->ci->db->query("ALTER TABLE `{$table_name}` ADD INDEX `external_id` (`external_id`(191))");
                    $this->log('info', "Índice para external_id adicionado com sucesso.");
                } catch (\Throwable $e) {
                    $this->log('error', "Erro ao adicionar índice para external_id: " . $e->getMessage());
                }
            }
        }
        
        // Verifica se a tabela de PIX existe
        $pix_table_name = db_prefix() . 'pagou_transactions_pix';
        $pix_table_exists = $this->ci->db->table_exists($pix_table_name);
        
        if (!$pix_table_exists) {
            $this->log('info', 'Tabela de transações PIX não encontrada. Criando tabela...');
            
            // Cria a tabela de PIX se não existir
            $this->ci->db->query("
                CREATE TABLE IF NOT EXISTS `{$pix_table_name}` (
                    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `invoice_id` int(11) NOT NULL,
                    `cliente` varchar(255) NOT NULL,
                    `doc` varchar(20) NOT NULL,
                    `transaction_id` varchar(255) NOT NULL,
                    `payment_method` enum('pix') NOT NULL,
                    `qr_code` text NULL,
                    `qr_code_text` text NULL,
                    `amount` decimal(15,2) NOT NULL,
                    `amount_final` decimal(15,2) NOT NULL,
                    `status` varchar(50) NOT NULL,
                    `date_created` datetime NOT NULL,
                    `data_pagamento` datetime DEFAULT NULL,
                    `data_estorno` datetime DEFAULT NULL,
                    `webhook_log` text NULL,
                    `external_id` varchar(255) DEFAULT NULL,
                    `expiration_seconds` int(11) DEFAULT 86400,
                    `api_request` text DEFAULT NULL,
                    `api_response` text DEFAULT NULL,
                    KEY `invoice_id` (`invoice_id`),
                    KEY `transaction_id` (`transaction_id`(191))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            ");
            
            $this->log('info', 'Tabela de transações PIX criada com sucesso.');
        }
    }

    /**
     * Verifica se um índice existe na tabela
     * 
     * @param string $table_name Nome da tabela
     * @param string $index_name Nome do índice
     * @return bool True se o índice existir, false caso contrário
     */
    private function check_index_exists($table_name, $index_name) {
        $query = $this->ci->db->query("SHOW INDEX FROM `{$table_name}` WHERE Key_name = '{$index_name}'");
        return $query->num_rows() > 0;
    }
}