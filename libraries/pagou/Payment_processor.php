<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Definição da Classe Payment_processor
   ============================ */
class Payment_processor
{
    private $ci;
    private $transaction_handler;
    private $api_client;
    private $gateway;
    private $log_enabled = false;
    private $log_detailed = false;

    /**
     * Construtor da classe
     *
     * @param object $ci Instância do CodeIgniter
     * @param object $transaction_handler Manipulador de transações
     * @param object $api_client Cliente de API
     * @param object $gateway Gateway de pagamento
     */
    public function __construct($ci, $transaction_handler, $api_client, $gateway)
    {
        $this->ci = $ci;
        $this->transaction_handler = $transaction_handler;
        $this->api_client = $api_client;
        $this->gateway = $gateway;
        
        // Verifica configurações de log
        $log_option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api'])->row();
        $this->log_enabled = ($log_option && $log_option->value === '1');
        
        $log_detailed_option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'paymentmethod_pagou_boleto_log_detailed'])->row();
        $this->log_detailed = ($log_detailed_option && $log_detailed_option->value === '1');
        
        $this->log('debug', 'Payment processor inicializado');
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

        $log_message = "[Pagou Boleto Payment] " . $message;
        
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
        $log_file = APPPATH . 'logs/pagou_payment_' . date('Y-m-d') . '.log';
        error_log($log_message . PHP_EOL, 3, $log_file);
    }

    /**
     * Processa o pagamento da fatura
     *
     * @param object $invoice Objeto da fatura
     * @return bool Sucesso ou falha no processamento
     */
    public function process($invoice)
    {
        $this->log('info', "Iniciando processamento de pagamento para fatura ID: {$invoice->id}");
        
        // Verifica transação existente
        $existing_transaction = $this->transaction_handler->checkExistingTransaction($invoice->id);
        
        if ($existing_transaction) {
            $this->log('info', "Transação existente encontrada, ID: {$existing_transaction->id}, Status: {$existing_transaction->status}");
            
            // Se existe ID de transação, consulta status na API
            if (!empty($existing_transaction->transaction_id) && empty($existing_transaction->consulta)) {
                $this->log('debug', "Consultando API para atualizar status da transação");
                
                // Atualiza a transação com dados da API
                $updated_transaction = $this->transaction_handler->updateTransactionFromApi(
                    $invoice->id,
                    $existing_transaction->transaction_id
                );
                
                if ($updated_transaction) {
                    $existing_transaction = $updated_transaction;
                }
            }
            
            // Carrega a view de boleto
            return $this->loadBoletoView($invoice, $existing_transaction);
        }
        
        // Criar nova transação
        $this->log('info', "Nenhuma transação existente, criando nova");
        
        // Obter dados do cliente
        $client = $this->getClientData($invoice->clientid);
        if (!$client) {
            $this->log('error', "Cliente não encontrado, ID: {$invoice->clientid}");
            set_alert('danger', _l('Client not found'));
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return false;
        }
        
        // Preparar dados para a API
        $payment_data = $this->preparePaymentData($invoice, $client);
        
        // Criar boleto via API
        $result = $this->api_client->createBoleto($payment_data);
        
        if (!$result['success']) {
            $this->log('error', "Erro ao criar boleto na API: " . $result['message']);
            set_alert('danger', $result['message']);
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return false;
        }
        
        // Salvar transação no banco
        $this->transaction_handler->saveNewTransaction($invoice->id, $result['data']);
        
        // Buscar dados atualizados (pode ser necessário para obter URL do boleto)
        $new_transaction = $this->transaction_handler->checkExistingTransaction($invoice->id);
        
        // Verificar se temos todos os dados necessários
        if (!$this->hasRequiredFields($new_transaction)) {
            $this->log('warning', "Transação criada, mas faltam dados. Consultando API novamente");
            
            // Tenta atualizar dados da transação
            $updated_transaction = $this->transaction_handler->updateTransactionFromApi(
                $invoice->id,
                $new_transaction->transaction_id
            );
            
            if ($updated_transaction) {
                $new_transaction = $updated_transaction;
            }
        }
        
        // Carrega a view de boleto
        return $this->loadBoletoView($invoice, $new_transaction);
    }
    
    /**
     * Verifica se a transação tem todos os campos necessários preenchidos
     *
     * @param object $transaction Objeto da transação
     * @return bool Se a transação tem todos os campos necessários
     */
    private function hasRequiredFields($transaction)
    {
        return !empty($transaction->barcode) && 
               !empty($transaction->barcode_url) && 
               !empty($transaction->boleto_url);
    }
    
    /**
     * Carrega a view de boleto
     *
     * @param object $invoice Objeto da fatura
     * @param object $transaction Objeto da transação
     * @return bool Sucesso ou falha ao carregar a view
     */
    private function loadBoletoView($invoice, $transaction)
    {
        $this->log('debug', "Carregando view de boleto para fatura ID: {$invoice->id}");
        
        if (!$transaction) {
            $this->log('error', "Nenhuma transação disponível para carregar a view");
            set_alert('danger', _l('Transaction not found'));
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return false;
        }
        
        // Preparar dados para a view
        $currency = get_currency($invoice->currency);
        $view_data = [
            'invoice'        => $invoice,
            'transaction'    => $transaction,
            'transaction_id' => $transaction->transaction_id,
            'barcode'        => $transaction->barcode,
            'barcode_url'    => $transaction->barcode_url,
            'boleto_url'     => $transaction->boleto_url,
            'due_date'       => $transaction->due_date,
            'currency_name'  => $currency->name,
            'status'         => $transaction->status
        ];
        
        // Carregar a view
        $this->ci->load->view('pagou/payment_boleto', $view_data);
        return true;
    }
    
    /**
 * Obtém dados do cliente
 *
 * @param int $client_id ID do cliente
 * @return object|null Objeto do cliente ou null se não encontrado
 */
private function getClientData($client_id)
{
    $this->log('debug', "Obtendo dados do cliente ID: {$client_id}");
    
    // Busca dados básicos do cliente
    $this->ci->db->select('
        userid, company, vat, phonenumber, country, billing_street,
        billing_city, billing_state, billing_zip, email
    ');
    $this->ci->db->where('userid', $client_id);
    $client = $this->ci->db->get(db_prefix() . 'clients')->row();
    
    if (!$client) {
        $this->log('error', "Cliente não encontrado, ID: {$client_id}");
        return null;
    }
    
    // Busca campos customizados (número do endereço e bairro)
    $custom_fields = $this->getClientCustomFields($client_id);
    
    // Adiciona campos customizados ao objeto cliente
    if (!empty($custom_fields)) {
        foreach ($custom_fields as $field => $value) {
            $client->$field = $value;
        }
    }
    
    $this->log('debug', "Dados do cliente obtidos com sucesso:" . 
        (isset($client->neighborhood) ? " Bairro: {$client->neighborhood}" : " Sem bairro") . 
        (isset($client->address_number) ? " Número: {$client->address_number}" : " Sem número")
    );
    
    return $client;
}
    
  /**
 * Obtém campos customizados do cliente com logging detalhado
 *
 * @param int $client_id ID do cliente
 * @return array Array de campos customizados
 */
private function getClientCustomFields($client_id)
{
    $custom_fields = [];
    $this->log('debug', "Buscando campos customizados para cliente ID: {$client_id}");
    
    // Busca o ID do campo customizado "Número do Endereço"
    $this->ci->db->where('fieldto', 'customers');
    $this->ci->db->where('slug', 'customers_numero');
    $number_field = $this->ci->db->get(db_prefix() . 'customfields')->row();
    
    if ($number_field) {
        $this->log('debug', "Campo 'Número do Endereço' encontrado com ID: {$number_field->id}");
        
        // Busca o valor para este cliente específico
        $this->ci->db->where('relid', $client_id);
        $this->ci->db->where('fieldid', $number_field->id);
        $number_value = $this->ci->db->get(db_prefix() . 'customfieldsvalues')->row();
        
        if ($number_value && !empty($number_value->value)) {
            $custom_fields['address_number'] = $number_value->value;
            $this->log('debug', "Valor do número encontrado: {$number_value->value}");
        } else {
            $this->log('warning', "Valor do número não encontrado para cliente ID: {$client_id}");
        }
    } else {
        $this->log('warning', "Campo customizado 'customers_numero' não encontrado na configuração");
    }
    
    // Busca o ID do campo customizado "Bairro"
    $this->ci->db->where('fieldto', 'customers');
    $this->ci->db->where('slug', 'customers_bairro');
    $neighborhood_field = $this->ci->db->get(db_prefix() . 'customfields')->row();
    
    if ($neighborhood_field) {
        $this->log('debug', "Campo 'Bairro' encontrado com ID: {$neighborhood_field->id}");
        
        // Busca o valor para este cliente específico
        $this->ci->db->where('relid', $client_id);
        $this->ci->db->where('fieldid', $neighborhood_field->id);
        $neighborhood_value = $this->ci->db->get(db_prefix() . 'customfieldsvalues')->row();
        
        if ($neighborhood_value && !empty($neighborhood_value->value)) {
            $custom_fields['neighborhood'] = $neighborhood_value->value;
            $this->log('debug', "Valor do bairro encontrado: {$neighborhood_value->value}");
        } else {
            $this->log('warning', "Valor do bairro não encontrado para cliente ID: {$client_id}");
        }
    } else {
        $this->log('warning', "Campo customizado 'customers_bairro' não encontrado na configuração");
    }
    
    return $custom_fields;
}
    
/**
 * Prepara os dados para criação do boleto
 *
 * @param object $invoice Objeto da fatura
 * @param object $client Objeto do cliente
 * @return array Dados formatados para a API
 */
private function preparePaymentData($invoice, $client)
{
    $this->log('debug', "Preparando dados para criação de boleto");
    
    // Calcula o valor total
    $amount = $this->calculateAmount($invoice);
    
    // Processa dados de endereço do cliente
    $address = $this->processClientAddress($client);
    
    // Gera um identificador externo único
    $external_id = $this->generateExternalId($invoice->id);
    
    // Prepara os dados do boleto
    $payment_data = [
        'amount'           => $amount,
        'description'      => mb_substr('Fatura #' . format_invoice_number($invoice->id), 0, 140),
        'due_date'         => date('Y-m-d', strtotime($invoice->duedate)),
        'notification_url' => admin_url('pagou/pagou_webhook/process'),
        'external_id'      => $external_id,
        'payer'            => [
            'name'         => mb_substr($client->company, 0, 100),
            'document'     => preg_replace('/[^0-9]/', '', $client->vat),
            'email'        => $client->email,
            'street'       => $address['street'],
            'number'       => $address['number'],
            'neighborhood' => $address['neighborhood'], // Garantido não estar vazio
            'city'         => $address['city'],
            'state'        => $address['state'],
            'zip'          => $address['zip']
        ],
        'metadata'         => [
            [
                'key'   => 'invoice_id',
                'value' => (string) $invoice->id
            ],
            [
                'key'   => 'invoice_number',
                'value' => format_invoice_number($invoice->id)
            ],
            [
                'key'   => 'client_id',
                'value' => (string) $client->userid
            ],
            [
                'key'   => 'source',
                'value' => 'perfex_crm'
            ]
        ],
        // Campo obrigatório para a API
        'grace_period'    => 1 // Valor padrão mínimo
    ];
    
    // Atualiza grace_period conforme configuração
    if ($this->getSetting('enable_grace_period') === '1') {
        $grace_period_input = (int) $this->getSetting('grace_period_days');
        if ($grace_period_input > 0) {
            $payment_data['grace_period'] = min(max($grace_period_input, 1), 30); // Entre 1 e 30
        }
    }
        
    // Adiciona configurações de desconto se habilitadas
    if ($this->gateway->getSetting('enable_discount') === '1') {
        $discount_value = floatval($this->gateway->getSetting('discount_value'));
        
        if ($discount_value > 0) {
            $discount_type = $this->gateway->getSetting('discount_type');
            $discount_days = (int) $this->gateway->getSetting('discount_limit_date');
            
            // Calcula a data limite para o desconto
            $discount_limit_date = date('Y-m-d', strtotime($invoice->duedate . " -{$discount_days} days"));
            
            $payment_data['discount'] = [
                'type'       => $discount_type,
                'amount'     => $discount_value,
                'limit_date' => $discount_limit_date
            ];
            
            $this->log('debug', "Desconto configurado: {$discount_type}, valor: {$discount_value}, data limite: {$discount_limit_date}");
        }
    }
    
    // Adiciona configurações de multa se habilitadas
    if ($this->gateway->getSetting('enable_fine') === '1') {
        $fine_value = floatval($this->gateway->getSetting('fine_value'));
        
        if ($fine_value > 0) {
            // Certifica-se de que está dentro dos limites (0.1 a 10%)
            $fine_value = min(max($fine_value, 0.1), 10);
            $payment_data['fine'] = $fine_value;
            
            $this->log('debug', "Multa configurada: {$fine_value}%");
        }
    }
    
    // Adiciona configurações de juros se habilitadas
    if ($this->gateway->getSetting('enable_interest') === '1') {
        $interest_value = floatval($this->gateway->getSetting('interest_value'));
        
        if ($interest_value > 0) {
            // Certifica-se de que está dentro dos limites (0.1 a 1%)
            $interest_value = min(max($interest_value, 0.1), 1);
            $payment_data['interest'] = $interest_value;
            
            $this->log('debug', "Juros configurados: {$interest_value}% ao dia");
        }
    }
    
    // Adiciona período de carência se habilitado
    if ($this->gateway->getSetting('enable_grace_period') === '1') {
        $grace_period = (int) $this->gateway->getSetting('grace_period_days');
        
        if ($grace_period > 0) {
            // Certifica-se de que está dentro dos limites (1 a 30 dias)
            $grace_period = min(max($grace_period, 1), 30);
            $payment_data['grace_period'] = $grace_period;
            
            $this->log('debug', "Período de carência configurado: {$grace_period} dias");
        }
    }
    
    // Verificação adicional para garantir que campos obrigatórios não estejam vazios
    if (empty($payment_data['payer']['neighborhood'])) {
        $payment_data['payer']['neighborhood'] = 'Centro';
        $this->log('warning', "Bairro vazio no payload final, usando valor padrão 'Centro'");
    }
    
    // Garantir que número do endereço nunca seja vazio
    if (empty($payment_data['payer']['number'])) {
        $payment_data['payer']['number'] = 'S/N';
        $this->log('warning', "Número do endereço vazio no payload final, usando valor padrão 'S/N'");
    }
    
    $this->log('debug', "Dados preparados para API", $payment_data);
    return $payment_data;
}
    
    /**
     * Calcula o valor total com desconto se aplicável
     *
     * @param object $invoice Objeto da fatura
     * @return float Valor calculado
     */
    private function calculateAmount($invoice)
    {
        $amount = $invoice->total;
        
        // Aplica desconto se configurado
        if ($this->gateway->getSetting('enable_discount') === '1') {
            $discount_value = floatval($this->gateway->getSetting('discount_value'));
            $discount_type = $this->gateway->getSetting('discount_type');
            
            if ($discount_value > 0) {
                if ($discount_type === 'percentage') {
                    // Desconto percentual
                    $amount_discount = $amount * ($discount_value / 100);
                    $amount -= $amount_discount;
                    
                    $this->log('debug', "Aplicando desconto percentual de {$discount_value}%: R$ {$amount_discount}");
                } else {
                    // Desconto fixo
                    $amount -= $discount_value;
                    
                    $this->log('debug', "Aplicando desconto fixo de R$ {$discount_value}");
                }
                
                // Garante que o valor mínimo seja respeitado
                $amount = max($amount, 5); // Mínimo de R$ 5,00
            }
        }
        
        // Arredonda para 2 casas decimais
        $amount = round($amount, 2);
        
        $this->log('debug', "Valor total calculado: R$ {$amount}");
        return $amount;
    }
    
   /**
 * Processa o endereço do cliente
 *
 * @param object $client Objeto do cliente
 * @return array Dados de endereço processados
 */
private function processClientAddress($client)
{
    $address = [
        'street'       => 'Endereço não especificado',
        'number'       => 'S/N',
        'neighborhood' => 'Centro', // Valor padrão obrigatório
        'city'         => 'São Paulo',
        'state'        => 'SP',
        'zip'          => '00000000'
    ];
    
    // Preenche com os dados disponíveis
    if (!empty($client->billing_street)) {
        $address['street'] = $this->extractStreet($client->billing_street);
    }
    
    if (!empty($client->billing_city)) {
        $address['city'] = $client->billing_city;
    }
    
    if (!empty($client->billing_state)) {
        $address['state'] = $this->normalizeState($client->billing_state);
    }
    
    if (!empty($client->billing_zip)) {
        $address['zip'] = preg_replace('/[^0-9]/', '', $client->billing_zip);
    }
    
    // Adiciona número do endereço se disponível no campo customizado
    if (isset($client->address_number) && !empty($client->address_number)) {
        $address['number'] = $client->address_number;
    } else {
        // Tenta extrair o número do endereço
        $extracted_number = $this->extractNumber($client->billing_street);
        if ($extracted_number) {
            $address['number'] = $extracted_number;
        }
    }
    
    // Adiciona bairro se disponível no campo customizado
    if (isset($client->neighborhood) && !empty($client->neighborhood)) {
        $address['neighborhood'] = $client->neighborhood;
    } else {
        // Tenta extrair o bairro do endereço
        $extracted_neighborhood = $this->extractNeighborhood($client->billing_street);
        if ($extracted_neighborhood) {
            $address['neighborhood'] = $extracted_neighborhood;
        }
        // O valor padrão 'Centro' já foi definido acima
    }
    
    // Verificação extra para garantir que campos obrigatórios não estejam vazios
    if (empty($address['neighborhood'])) {
        $address['neighborhood'] = 'Centro';
        $this->log('warning', "Bairro vazio após processamento, usando valor padrão 'Centro'");
    }
    
    return $address;
}
    
    /**
     * Extrai o nome da rua do endereço completo
     *
     * @param string $full_address Endereço completo
     * @return string Nome da rua
     */
    private function extractStreet($full_address)
    {
        // Remove o número e complemento, se houver
        $address_parts = explode(',', $full_address);
        $street = trim($address_parts[0]);
        
        // Remove o bairro se estiver no formato "Rua X - Bairro Y"
        $street_parts = explode('-', $street);
        if (count($street_parts) > 1) {
            $street = trim($street_parts[0]);
        }
        
        return mb_substr($street, 0, 100);
    }
    
    /**
     * Extrai o número do endereço completo
     *
     * @param string $full_address Endereço completo
     * @return string|null Número extraído ou null se não encontrado
     */
    private function extractNumber($full_address)
    {
        // Tenta extrair o número após a vírgula
        $matches = [];
        if (preg_match('/,\s*(\d+[a-zA-Z]?(-\d+)?)\s*,?/u', $full_address, $matches)) {
            return $matches[1];
        }
        
        // Tenta extrair o número no final do endereço
        if (preg_match('/\s(\d+[a-zA-Z]?(-\d+)?)\s*$/u', $full_address, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Extrai o bairro do endereço completo
     *
     * @param string $full_address Endereço completo
     * @return string|null Bairro extraído ou null se não encontrado
     */
    private function extractNeighborhood($full_address)
    {
        // Tenta extrair o bairro após o traço
        $matches = [];
        if (preg_match('/\s-\s([^,]+)/u', $full_address, $matches)) {
            return trim($matches[1]);
        }
        
        // Tenta extrair o bairro após a segunda vírgula
        $parts = explode(',', $full_address);
        if (count($parts) > 2) {
            return trim($parts[2]);
        }
        
        return null;
    }
    
    /**
     * Normaliza o estado para o formato de 2 letras
     *
     * @param string $state Estado em formato longo ou abreviado
     * @return string Estado normalizado
     */
    private function normalizeState($state)
    {
        // Se já for 2 letras, retorna como está
        if (strlen($state) === 2) {
            return strtoupper($state);
        }
        
        // Mapeamento de nomes de estados para siglas
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
        
        // Normaliza o nome do estado
        $normalized = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ã', 'Õ', 'Â', 'Ê', 'Î', 'Ô', 'Û', 'Ç'], 
            ['A', 'E', 'I', 'O', 'U', 'A', 'O', 'A', 'E', 'I', 'O', 'U', 'C'], 
            mb_strtoupper(trim($state), 'UTF-8')
        );
        
        // Busca pelo nome normalizado
        if (isset($states_map[$normalized])) {
            return $states_map[$normalized];
        }
        
        // Se não encontrou no mapa, usa as primeiras 2 letras
        return mb_substr($normalized, 0, 2);
    }
    
    /**
     * Gera um identificador externo único para a fatura
     *
     * @param int $invoice_id ID da fatura
     * @return string Identificador externo
     */
    private function generateExternalId($invoice_id)
    {
        // Combina timestamp + ID da fatura + string aleatória
        $random_string = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
        $timestamp = time();
        
        return "perfex_{$invoice_id}_{$timestamp}_{$random_string}";
    }
}
