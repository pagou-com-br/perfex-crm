<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Definição da Classe Api_client
   ============================ */
class Api_client
{
    private $api_token;
    private $is_production;
    private $log_enabled = false;
    private $log_detailed = false;

    /**
     * Método Construtor
     *
     * Inicializa o cliente da API com o token fornecido e configura os logs.
     *
     * @param string $api_token Token da API Pagou.com.br
     * @param bool $is_production Indica se o ambiente é de produção
     */
    public function __construct($api_token, $is_production = true)
    {
        $this->api_token = $api_token;
        $this->is_production = $is_production;
        
        // Obtém a instância do CodeIgniter para acessar o banco de dados
        $CI =& get_instance();
        
        // Verifica a configuração de logs
        $log_option = $CI->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api'])->row();
        $this->log_enabled = ($log_option && $log_option->value === '1');
        
        // Verifica se deve fazer log detalhado
        $log_detailed_option = $CI->db->get_where(db_prefix() . 'options', ['name' => 'paymentmethod_pagou_boleto_log_detailed'])->row();
        $this->log_detailed = ($log_detailed_option && $log_detailed_option->value === '1');

        $this->log('debug', 'Api_client inicializado. Ambiente: ' . ($this->is_production ? 'Produção' : 'Sandbox'));
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

        $log_message = "[Pagou Boleto Api] " . $message;
        
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
        $log_file = APPPATH . 'logs/pagou_api_' . date('Y-m-d') . '.log';
        error_log($log_message . PHP_EOL, 3, $log_file);
    }

    /**
     * Obtém a URL base da API com base no ambiente configurado
     * 
     * @return string URL base da API
     */
    private function getApiBaseUrl() {
        return pagou_api_base_url($this->is_production) . '/v1';
    }

    /**
     * Cria uma nova cobrança de boleto
     *
     * @param array $data Dados da cobrança
     * @return array Resposta da API
     */
    public function createBoleto($data)
    {
        $this->log('info', 'Iniciando criação de boleto');
        $this->log('debug', 'Dados da requisição', $data);
        
        $endpoint = $this->getApiBaseUrl() . '/charges';
        
        $result = $this->executeRequest('POST', $endpoint, $data);
        
        if (!$result['success']) {
            $this->log('error', 'Erro ao criar boleto: ' . $result['message']);
        } else {
            $this->log('info', 'Boleto criado com sucesso. ID: ' . ($result['data']->id ?? 'N/A'));
        }
        
        return $result;
    }
    
    /**
     * Consulta detalhes de um boleto
     *
     * @param string $boleto_id ID do boleto
     * @return array Resposta da API
     */
    public function getBoleto($boleto_id)
    {
        $this->log('info', 'Consultando boleto: ' . $boleto_id);
        
        $endpoint = $this->getApiBaseUrl() . '/charges/' . $boleto_id;
        
        $result = $this->executeRequest('GET', $endpoint);
        
        if (!$result['success']) {
            $this->log('error', 'Erro ao consultar boleto: ' . $result['message']);
        } else {
            $this->log('info', 'Boleto consultado com sucesso');
        }
        
        return $result;
    }
    
    /**
     * Cancela um boleto
     *
     * @param string $boleto_id ID do boleto
     * @return array Resposta da API
     */
    public function cancelBoleto($boleto_id)
    {
        $this->log('info', 'Cancelando boleto: ' . $boleto_id);
        
        $endpoint = $this->getApiBaseUrl() . '/charges/' . $boleto_id;
        
        $result = $this->executeRequest('DELETE', $endpoint);
        
        if (!$result['success']) {
            $this->log('error', 'Erro ao cancelar boleto: ' . $result['message']);
        } else {
            $this->log('info', 'Boleto cancelado com sucesso');
        }
        
        return $result;
    }
    
    /**
     * Reembolsa um boleto pago
     *
     * @param string $boleto_id ID do boleto
     * @param array $refund_data Dados do reembolso
     * @return array Resposta da API
     */
    public function refundBoleto($boleto_id, $refund_data)
    {
        $this->log('info', 'Reembolsando boleto: ' . $boleto_id);
        $this->log('debug', 'Dados do reembolso', $refund_data);
        
        $endpoint = $this->getApiBaseUrl() . '/charges/' . $boleto_id . '/refund';
        
        $result = $this->executeRequest('POST', $endpoint, $refund_data);
        
        if (!$result['success']) {
            $this->log('error', 'Erro ao reembolsar boleto: ' . $result['message']);
        } else {
            $this->log('info', 'Boleto reembolsado com sucesso');
        }
        
        return $result;
    }

    /**
     * Executa uma requisição HTTP para a API da Pagou
     *
     * @param string $method Método HTTP (GET, POST, PUT, DELETE)
     * @param string $endpoint Endpoint da API
     * @param array $data Dados da requisição (opcional)
     * @return array Resultado da requisição
     */
    private function executeRequest($method, $endpoint, $data = null)
    {
        $this->log('debug', "Executando requisição {$method} para {$endpoint}");
        
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->api_token,
            'User-Agent: PerfexCRM/PagouBoleto/1.8'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        // Configura para capturar informações da requisição
        
        // Configura o método e payload conforme necessário
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
                
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
                
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
        }
        
        // Executa a requisição
        $start_time = microtime(true);
        $response = curl_exec($ch);
        $end_time = microtime(true);
        
        // Coleta informações sobre a requisição
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $error_code = curl_errno($ch);
        $time_taken = $end_time - $start_time;
        
        curl_close($ch);
        
        // Registra informações detalhadas da requisição
        if ($this->log_detailed) {
            $this->log('debug', "Informações da requisição", [
                'Método' => $method,
                'URL' => $endpoint,
                'Payload' => $data,
                'Resposta HTTP' => $http_code,
                'Tempo' => $time_taken . 's',
                'Erro' => $error ? "{$error_code}: {$error}" : "Nenhum"
            ]);
        }
        
        // Se houver erro no cURL
        if ($error) {
            $this->log('error', "Erro cURL: {$error} ({$error_code})");
            return [
                'success' => false,
                'message' => "Erro de conexão: {$error}",
                'code' => $error_code
            ];
        }
        
        // Processa a resposta
        $body = $response ? json_decode($response) : null;
        $json_error = json_last_error();
        
        if ($json_error !== JSON_ERROR_NONE && $response) {
            $this->log('error', "Erro ao decodificar JSON: " . json_last_error_msg(), $response);
            return [
                'success' => false,
                'message' => 'Erro ao processar resposta da API',
                'code' => $http_code,
                'raw_response' => $response
            ];
        }
        
        // Para métodos como DELETE que podem retornar 204 No Content
        if ($http_code === 204) {
            return [
                'success' => true,
                'code' => 204,
                'message' => 'Operação concluída com sucesso'
            ];
        }
        
        // Verifica se a requisição foi bem-sucedida (2xx)
        if ($http_code >= 200 && $http_code < 300) {
            return [
                'success' => true,
                'data' => $body,
                'code' => $http_code
            ];
        }
        
        // Se chegou aqui, é um erro
        $message = $this->parseErrorMessage($body, $response);
        
        $this->log('error', "Erro na API HTTP {$http_code}: {$message}");
        
        return [
            'success' => false,
            'message' => $message,
            'code' => $http_code,
            'data' => $body
        ];
    }
    
    /**
     * Extrai mensagem de erro da resposta da API
     *
     * @param object $body Corpo da resposta decodificado
     * @param string $raw_response Resposta bruta
     * @return string Mensagem de erro
     */
    private function parseErrorMessage($body, $raw_response)
    {
        if (!$body) {
            return 'Erro desconhecido';
        }
        
        if (isset($body->message)) {
            $error = $body->message;
            
            // Se houver erros detalhados, inclui-os na mensagem
            if (isset($body->errors) && is_array($body->errors)) {
                $details = [];
                foreach ($body->errors as $e) {
                    if (is_object($e) && isset($e->field) && isset($e->message)) {
                        $details[] = "{$e->field}: {$e->message}";
                    } elseif (is_string($e)) {
                        $details[] = $e;
                    }
                }
                
                if (!empty($details)) {
                    $error .= ' - ' . implode('; ', $details);
                }
            }
            
            return $error;
        }
        
        if (isset($body->error)) {
            return $body->error;
        }
        
        return 'Erro desconhecido na API';
    }
}