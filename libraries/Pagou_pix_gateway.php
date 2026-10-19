<?php
defined('BASEPATH') or exit('No direct script access allowed');


class Pagou_pix_gateway extends App_gateway
{
    protected $ci;
    private $enable_logs;


    public function __construct()
    {
        parent::__construct();

        $this->ci = &get_instance();
        $this->ci->load->database();

        $this->setId('pagou_pix');
        $this->setName('Pagou.com.br PIX');

        $option = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api'])->row();
        $this->enable_logs = ($option && $option->value === '1');

        $this->log('info', 'Pagou_pix_gateway (v1.8) inicializado. Logs ' . ($this->enable_logs ? 'ativados' : 'desativados') . '.');

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
                'name'          => 'expiration',
                'label'         => 'QR CODE | Validade em minutos (Ex: 60 = 1h, 1440 = 24h, Máx: 43200)',
                'type'          => 'input',
                'default_value' => '1440'
            ],
            [
                'name'          => 'currencies',
                'label'         => 'MOEDA | Códigos aceitos (geralmente apenas BRL para PIX)',
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
                'label'   => '     ⤷ Tipo: digite "fixed" (valor fixo) ou "percentage" (percentual)',
                'type'    => 'input',
                'default_value' => 'fixed'
            ],
            [
                'name'          => 'discount_value',
                'label'         => '     ⤷ Valor: se fixed=centavos (500=R$5,00), se percentage=% (10=10%)',
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
                'name'          => 'fine_days_after_due',
                'label'         => '     ⤷ Aplicar após quantos dias do vencimento (0=mesmo dia, 1=dia seguinte)',
                'type'          => 'input',
                'default_value' => '1'
            ],
            [
                'name'    => 'fine_type',
                'label'   => '     ⤷ Tipo: digite "fixed" (valor fixo) ou "percentage" (percentual)',
                'type'    => 'input',
                'default_value' => 'fixed'
            ],
            [
                'name'          => 'fine_value',
                'label'         => '     ⤷ Valor: se fixed=centavos (200=R$2,00), se percentage=% (2=2%)',
                'type'          => 'input',
                'default_value' => '0'
            ],
            
            // ==== SEÇÃO JUROS ====
            [
                'name'    => 'enable_interest',
                'label'   => '━━━━━━━━━━━━━━━━ JUROS POR ATRASO ━━━━━━━━━━━━━━━━',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'          => 'interest_days_after_due',
                'label'         => '     ⤷ Aplicar após quantos dias do vencimento (0=mesmo dia, 1=dia seguinte)',
                'type'          => 'input',
                'default_value' => '1'
            ],
            [
                'name'    => 'interest_type',
                'label'   => '     ⤷ Tipo: digite "daily_percentage" (percentual ao dia)',
                'type'    => 'input',
                'default_value' => 'daily_percentage'
            ],
            [
                'name'          => 'interest_value',
                'label'         => '     ⤷ Valor: percentual diário (Ex: 0.1 = 0,1% ao dia)',
                'type'          => 'input',
                'default_value' => '0'
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
        ]);
        
        hooks()->add_action('before_add_payment_gateway', [$this, 'register_gateway']);
    }


    public function register_gateway()
    {
        $this->ci->payment_gateways->add($this);
    }

    public function init_settings()
    {
        parent::init_settings();
        
        if ($this->getSetting('enable_logs') === '1') {
            update_option('pagou_logs_api', '1');
            $this->enable_logs = true;
        } else {
            update_option('pagou_logs_api', '0');
            $this->enable_logs = false;
        }
    }


    public function log($level, $message, $data = null)
    {
        if ($this->enable_logs) {
            $log_message = "[Pagou PIX Gateway] " . $message;
            
            $timestamp = date('Y-m-d H:i:s');
            $log_message = "[{$timestamp}] " . $log_message;
            
            if ($data !== null) {
                if (is_array($data) || is_object($data)) {
                    $log_message .= " | Dados: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                } else {
                    $log_message .= " | Dados: " . $data;
                }
            }
            
            log_message($level, $log_message);
            
            $log_file = APPPATH . 'logs/pagou_pix_' . date('Y-m-d') . '.log';
            error_log($log_message . PHP_EOL, 3, $log_file);
        }
    }


    public function process_payment($data)
    {
        $this->log('debug', 'Método process_payment iniciado.');

        if (empty($data['invoice'])) {
            $this->log('error', 'Nenhuma fatura informada para process_payment.');
            set_alert('danger', _l('invoice_not_found'));
            redirect(site_url());
            return;
        }

        $invoice = $data['invoice'];
        $this->log('debug', 'Processando pagamento para a Fatura Perfex ID: ' . $invoice->id . ', Número: ' . format_invoice_number($invoice->id));

        $this->ensure_table_exists();


        $this->ci->db->where('invoice_id', $invoice->id);
        $this->ci->db->where_in('status', ['paid', 'confirmed']);
        $paid_transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_pix')->row();

        if ($paid_transaction) {
            $this->log('info', "A Fatura ID: " . $invoice->id . " já possui um pagamento confirmado (ID Pagou: {$paid_transaction->transaction_id}). Não é permitido criar novo PIX.");
            

            $view_data = [
                'invoice'             => $invoice,
                'qr_code'             => $paid_transaction->qr_code,
                'qr_code_text'        => $paid_transaction->qr_code_text,
                'date_created'        => $paid_transaction->date_created,
                'expiration_seconds'  => $paid_transaction->expiration_seconds,
                'pagou_transaction_id' => $paid_transaction->transaction_id
            ];
            $this->ci->load->view('pagou/payment_pix', $view_data);
            return;
        }

        $this->ci->db->where('invoice_id', $invoice->id);
        $this->ci->db->where_not_in('status', ['paid', 'confirmed', 'expired', 'refunded', 'cancelled']);
        $active_transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_pix')->row();

        if ($active_transaction) {
            $date_created = new DateTime($active_transaction->date_created);
            $expiration_seconds = $active_transaction->expiration_seconds ?? (intval($this->getSetting('expiration')) * 60);
            $date_created->add(new DateInterval('PT' . $expiration_seconds . 'S'));
            $now = new DateTime();
            
            if ($date_created < $now) {
                $this->log('info', "Transação existente (ID Pagou: {$active_transaction->transaction_id}) para a Fatura ID: " . $invoice->id . " está expirada. Criando novo QR Code.");
                $this->ci->db->where('id', $active_transaction->id);
                $this->ci->db->update(db_prefix() . 'pagou_transactions_pix', ['status' => 'expired']);
            } else {
                $this->log('info', "Transação existente e não expirada (ID Pagou: {$active_transaction->transaction_id}) encontrada para a Fatura ID: " . $invoice->id . ". Reutilizando QR Code.");
                $view_data = [
                    'invoice'            => $invoice,
                    'qr_code'            => $active_transaction->qr_code,
                    'qr_code_text'       => $active_transaction->qr_code_text,
                    'date_created'       => $active_transaction->date_created,
                    'expiration_seconds' => $expiration_seconds,
                    'pagou_transaction_id' => $active_transaction->transaction_id
                ];
                $this->ci->load->view('pagou/payment_pix', $view_data);
                return;
            }
        }

        $amount_formatted = round($invoice->total, 2);
        
        $description = hooks()->apply_filters(
            'pagou_pix_description',
            'Pagamento Fatura Perfex CRM #' . format_invoice_number($invoice->id) . ' (Cliente: ' . ($invoice->client->company ?? 'Cliente') . ')',
            $invoice
        );

        $expiration_minutes = (int) $this->getSetting('expiration');
        $expiration_seconds = max(60, min($expiration_minutes * 60, 2592000)); 

        $client_name = $this->get_client_name($invoice);
        $client_document = $this->get_client_document($invoice);
        $client_email = $this->get_client_email($invoice);

        $custom_webhook_url = trim($this->getSetting('webhook_url'));
        $notification_url = !empty($custom_webhook_url) ? $custom_webhook_url : admin_url('pagou/pagou_webhook/process/');
        
        $this->log('debug', 'URL de Notificação configurada: ' . $notification_url);

        $external_id = $this->generate_external_id($invoice->id);

        $payment_data = [
            'amount'           => $amount_formatted,
            'description'      => mb_substr($description, 0, 140),
            'expiration'       => $expiration_seconds,
            'notification_url' => $notification_url,
            'external_id'      => $external_id,
            'payer'            => [
                'name'     => mb_substr($client_name, 0, 100),
                'document' => preg_replace('/[^0-9]/', '', $client_document),
            ],
        ];
        
        if (!empty($client_email)) {
            $payment_data['payer']['email'] = $client_email;
        }

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

        if ($this->getSetting('enable_discount') === '1') {
            $discount_value_input = $this->getSetting('discount_value');
            if (is_numeric($discount_value_input) && $discount_value_input > 0) {
                $discount_type = $this->getSetting('discount_type');
                if (!in_array($discount_type, ['fixed', 'percentage'])) {
                    $discount_type = 'fixed'; 
                    $this->log('warning', 'Tipo de desconto inválido na configuração. Usando "fixed" como padrão.');
                }
                
                $api_discount_value = ($discount_type === 'fixed') ? (int) $discount_value_input : floatval(str_replace(',', '.', $discount_value_input));
                $payment_data['discount'] = [
                    'type'  => $discount_type,
                    'value' => $api_discount_value,
                ];
                $this->log('debug', 'Desconto configurado para API:', $payment_data['discount']);
            }
        }

        if ($this->getSetting('enable_fine') === '1' && !empty($invoice->duedate)) {
            $fine_value_input = $this->getSetting('fine_value');
             if (is_numeric($fine_value_input) && $fine_value_input > 0) {
                $fine_days_after_due = max(0, (int) $this->getSetting('fine_days_after_due'));
                try {
                    $due_date = new DateTime($invoice->duedate);
                    $fine_date_obj = clone $due_date;
                    $fine_date_obj->add(new DateInterval("P{$fine_days_after_due}D")); 
                    
                    $fine_type = $this->getSetting('fine_type');
                    if (!in_array($fine_type, ['fixed', 'percentage'])) {
                        $fine_type = 'fixed'; 
                        $this->log('warning', 'Tipo de multa inválido na configuração. Usando "fixed" como padrão.');
                    }
                    
                    $api_fine_value = ($fine_type === 'fixed') ? (int) $fine_value_input : floatval(str_replace(',', '.', $fine_value_input));

                    $payment_data['fine'] = [
                        'date'  => $fine_date_obj->format('Y-m-d'),
                        'type'  => $fine_type,
                        'value' => $api_fine_value,
                    ];
                    $this->log('debug', 'Multa configurada para API:', $payment_data['fine']);
                } catch (\Throwable $e) {
                    $this->log('error', 'Erro ao calcular data da multa: ' . $e->getMessage());
                }
            }
        }
        
        if ($this->getSetting('enable_interest') === '1' && !empty($invoice->duedate)) {
            $interest_value_input = $this->getSetting('interest_value');
            if (is_numeric($interest_value_input) && $interest_value_input > 0) {
                $interest_days_after_due = max(0, (int) $this->getSetting('interest_days_after_due'));
                try {
                    $due_date = new DateTime($invoice->duedate);
                    $interest_date_obj = clone $due_date;
                    $interest_date_obj->add(new DateInterval("P{$interest_days_after_due}D"));

                    $interest_type = $this->getSetting('interest_type');
                    if ($interest_type !== 'daily_percentage') {
                        $this->log('warning', 'Tipo de juros inválido ou não suportado na configuração. Usando "daily_percentage" como padrão.');
                        $interest_type = 'daily_percentage';
                    }
                    
                    $api_interest_value = floatval(str_replace(',', '.', $interest_value_input));

                    $payment_data['interest'] = [
                        'date'  => $interest_date_obj->format('Y-m-d'),
                        'type'  => $interest_type,
                        'value' => $api_interest_value,
                    ];
                    $this->log('debug', 'Juros configurados para API:', $payment_data['interest']);
                } catch (\Throwable $e) {
                    $this->log('error', 'Erro ao calcular data dos juros: ' . $e->getMessage());
                }
            }
        }

        $this->log('debug', 'Payload final para API Pagou:', $payment_data);

        $is_production = $this->getSetting('is_production') === '1';
        $environment = $is_production ? 'production' : 'sandbox';
        
        $api_endpoint = pagou_api_base_url($is_production) . '/v1/pix';
        
        $this->log('debug', 'API endpoint selecionado: ' . $api_endpoint . ' (Ambiente: ' . $environment . ')');

        $this->log('info', "=== INÍCIO DA REQUISIÇÃO PARA API PAGOU ===");
        $this->log('debug', 'URL da API', $api_endpoint);
        $this->log('debug', 'Método HTTP', 'POST');
        $this->log('debug', 'Cabeçalhos enviados', [
            'Content-Type: application/json',
            'X-API-KEY: [REDACTED]' 
        ]);
        $this->log('debug', 'Payload enviado (corpo da requisição)', $payment_data);

        $ch = curl_init($api_endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-KEY: ' . $this->decryptSetting('api_token'),
    'User-Agent: PerfexCRM/PagouPix/1.8'  
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
            return;
        }

        if ($http_code === 201) {
            $this->log('info', 'PIX gerado com sucesso pela Pagou (HTTP 201).');
            $result = json_decode($response_body);

            if (!$result || !isset($result->payload->image) || !isset($result->payload->data) || !isset($result->id)) {
                $this->log('error', 'Resposta da API Pagou em formato inesperado: ' . $response_body);
                set_alert('danger', 'Erro ao processar resposta do gateway de pagamento. Formato inesperado.');
                redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
                return;
            }
            
            $db_transaction_data = [
                'invoice_id'         => $invoice->id,
                'cliente'            => $client_name,
                'doc'                => $client_document,
                'transaction_id'     => $result->id,
                'payment_method'     => 'pix',  
                'qr_code'            => $result->payload->image,
                'qr_code_text'       => $result->payload->data,
                'amount'             => $invoice->total,
                'amount_final'       => $invoice->total,
                'status'             => $result->status ?? 'pending',
                'date_created'       => date('Y-m-d H:i:s'),
                'expiration_seconds' => $expiration_seconds,
                'api_request'        => json_encode($payment_data),
                'api_response'       => $response_body,
                'external_id'        => $external_id,
            ];
            
            $this->ci->db->insert(db_prefix() . 'pagou_transactions_pix', $db_transaction_data);
            $local_transaction_id = $this->ci->db->insert_id();
            $this->log('info', "Transação PIX registrada no banco local (ID: {$local_transaction_id}, Pagou ID: {$result->id}).");

            $view_data = [
                'invoice'            => $invoice,
                'qr_code'            => $result->payload->image,
                'qr_code_text'       => $result->payload->data,
                'date_created'       => $db_transaction_data['date_created'],
                'expiration_seconds' => $expiration_seconds,
                'pagou_transaction_id' => $result->id
            ];
            $this->ci->load->view('pagou/payment_pix', $view_data);
            $this->log('info', 'View de pagamento PIX carregada para o cliente.');

        } else {
            $error_message_from_api = 'Erro desconhecido da API Pagou.';
            if ($response_body) {
                $result = json_decode($response_body);
                $error_message_from_api = $this->parsePagouError($result, $response_body);
            }
            $this->log('error', "Erro ao gerar PIX na Pagou. HTTP Code: {$http_code}. API Message: {$error_message_from_api}. Raw Response: {$response_body}");
            set_alert('danger', 'Erro ao gerar PIX junto ao gateway: ' . $error_message_from_api . ' (Código Pagou: ' . $http_code . ')');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }
    }
    

    private function generate_external_id($invoice_id) {

        $random_string = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 10);
        $timestamp = time();
        return $random_string . $timestamp . 'invoice' . $invoice_id;
    }
    

    private function parsePagouError($result, $raw_response) {
        if (isset($result->message) && is_string($result->message)) { // Erro principal
            $main_error = $result->message;
            if (isset($result->errors) && is_array($result->errors) && count($result->errors) > 0) {
                 $error_details = [];
                 foreach($result->errors as $err_obj) {
                     if(is_object($err_obj) && isset($err_obj->field) && isset($err_obj->message)){
                        $error_details[] = "{$err_obj->field}: {$err_obj->message}";
                     } elseif (is_string($err_obj)) {
                        $error_details[] = $err_obj;
                     }
                 }
                 if(!empty($error_details)){
                    return $main_error . ' Detalhes: ' . implode('; ', $error_details);
                 }
            }
            return $main_error;
        } elseif (isset($result->error) && is_string($result->error)) {
            return $result->error;
        } elseif (isset($result->errors) && is_array($result->errors) && count($result->errors) > 0) { 
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
        return substr(strip_tags($raw_response), 0, 250);
    }


    private function get_client_name($invoice) {
        if (isset($invoice->client) && is_object($invoice->client)) {
            if (!empty($invoice->client->company)) {
                return $invoice->client->company;
            } elseif (!empty($invoice->client->firstname) || !empty($invoice->client->lastname)) {
                return trim($invoice->client->firstname . ' ' . $invoice->client->lastname);
            }
        }
        
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
        
        return 'Cliente';
    }


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
        
        return preg_replace('/[^0-9]/', '', $document);
    }


    private function get_client_email($invoice) {
        if (isset($invoice->client) && is_object($invoice->client)) {
            if (isset($invoice->client->email)) {
                return $invoice->client->email;
            } elseif (isset($invoice->client->contact_email)) {
                return $invoice->client->contact_email;
            }
        }
        
        if (isset($invoice->clientid)) {
            $this->ci->load->model('clients_model');
            $client = $this->ci->clients_model->get($invoice->clientid);
            if ($client && isset($client->email)) {
                return $client->email;
            }
        }
        
        return ''; 
    }


    public function check_transaction_status($transaction_id)
    {
        $this->log('info', "=== VERIFICANDO STATUS DA TRANSAÇÃO ===", $transaction_id);
        
        $is_production = $this->getSetting('is_production') === '1';
        $api_base = pagou_api_base_url($is_production);
        $api_endpoint = $api_base . '/v1/pix/' . $transaction_id;
        
        $this->log('debug', 'API endpoint de consulta', $api_endpoint);
        
        $ch = curl_init($api_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->decryptSetting('api_token'),
            'User-Agent: PerfexCRM/PagouPix/' . APP_VERSION
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


    public function log_to_db($invoice_id, $transaction_id, $log_type, $log_data)
    {
        if (!$this->enable_logs) {
            return;
        }
        
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
        
        $this->ci->db->where('invoice_id', $invoice_id);
        $this->ci->db->where('transaction_id', $transaction_id);
        $transaction = $this->ci->db->get(db_prefix() . 'pagou_transactions_pix')->row();
        
        if ($transaction) {
            $current_log = $transaction->webhook_log ? json_decode($transaction->webhook_log, true) : [];
            if (!is_array($current_log)) {
                $current_log = [];
            }
            $current_log[] = $log_entry;
            
            $this->ci->db->where('id', $transaction->id);
            $this->ci->db->update(db_prefix() . 'pagou_transactions_pix', [
                'webhook_log' => json_encode($current_log)
            ]);
        }
        
        $this->log('debug', 'Log registrado no banco de dados', $log_entry);
    }


    private function ensure_table_exists() {
        $table_name = db_prefix() . 'pagou_transactions_pix';
        
        $table_exists = $this->ci->db->table_exists($table_name);
        
        if (!$table_exists) {
            $this->log('info', 'Tabela de transações não encontrada. Criando tabela...');
            
            $this->ci->db->query("
                CREATE TABLE IF NOT EXISTS `{$table_name}` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `invoice_id` int(11) NOT NULL,
                    `cliente` varchar(255) DEFAULT NULL,
                    `doc` varchar(30) DEFAULT NULL,
                    `transaction_id` varchar(255) NOT NULL,
                    `external_id` varchar(255) DEFAULT NULL,
                    `payment_method` varchar(50) NOT NULL,
                    `qr_code` text NOT NULL,
                    `qr_code_text` text NOT NULL,
                    `amount` decimal(15,2) NOT NULL,
                    `amount_final` decimal(15,2) DEFAULT NULL,
                    `status` varchar(50) NOT NULL DEFAULT 'pending',
                    `date_created` datetime NOT NULL,
                    `data_pagamento` datetime DEFAULT NULL,
                    `data_estorno` datetime DEFAULT NULL,
                    `expiration_seconds` int(11) DEFAULT 86400,
                    `api_request` text DEFAULT NULL,
                    `api_response` text DEFAULT NULL,
                    `webhook_log` text DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `invoice_id` (`invoice_id`),
                    KEY `transaction_id` (`transaction_id`),
                    KEY `external_id` (`external_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            ");
            
            $this->log('info', 'Tabela de transações criada com sucesso.');
        } else {
            $query = $this->ci->db->query("SHOW COLUMNS FROM `{$table_name}`");
            $existing_columns = [];
            
            foreach ($query->result() as $row) {
                $existing_columns[] = $row->Field;
            }
            
            $required_columns = [
                'id' => "INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
                'invoice_id' => "INT(11) NOT NULL",
                'cliente' => "VARCHAR(255) DEFAULT NULL",
                'doc' => "VARCHAR(30) DEFAULT NULL",
                'transaction_id' => "VARCHAR(255) NOT NULL",
                'external_id' => "VARCHAR(255) DEFAULT NULL",
                'payment_method' => "VARCHAR(50) NOT NULL",
                'qr_code' => "TEXT NOT NULL",
                'qr_code_text' => "TEXT NOT NULL",
                'amount' => "DECIMAL(15,2) NOT NULL",
                'amount_final' => "DECIMAL(15,2) DEFAULT NULL",
                'status' => "VARCHAR(50) NOT NULL DEFAULT 'pending'",
                'date_created' => "DATETIME NOT NULL",
                'data_pagamento' => "DATETIME DEFAULT NULL",
                'data_estorno' => "DATETIME DEFAULT NULL",
                'expiration_seconds' => "INT(11) DEFAULT 86400",
                'api_request' => "TEXT DEFAULT NULL",
                'api_response' => "TEXT DEFAULT NULL",
                'webhook_log' => "TEXT DEFAULT NULL"
            ];
            
            foreach ($required_columns as $column => $definition) {
                if (!in_array($column, $existing_columns)) {
                    $this->log('info', "Coluna {$column} não encontrada. Adicionando coluna...");
                    try {
                        $this->ci->db->query("ALTER TABLE `{$table_name}` ADD COLUMN `{$column}` {$definition}");
                        $this->log('info', "Coluna {$column} adicionada com sucesso.");
                    } catch (\Throwable $e) {
                        $this->log('error', "Erro ao adicionar coluna {$column}: " . $e->getMessage());
                    }
                }
            }
            
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
                    $this->ci->db->query("ALTER TABLE `{$table_name}` ADD INDEX `transaction_id` (`transaction_id`)");
                    $this->log('info', "Índice para transaction_id adicionado com sucesso.");
                } catch (\Throwable $e) {
                    $this->log('error', "Erro ao adicionar índice para transaction_id: " . $e->getMessage());
                }
            }
            
            if (!$this->check_index_exists($table_name, 'external_id')) {
                $this->log('info', "Índice para external_id não encontrado. Adicionando índice...");
                try {
                    $this->ci->db->query("ALTER TABLE `{$table_name}` ADD INDEX `external_id` (`external_id`)");
                    $this->log('info', "Índice para external_id adicionado com sucesso.");
                } catch (\Throwable $e) {
                    $this->log('error', "Erro ao adicionar índice para external_id: " . $e->getMessage());
                }
            }
        }
    }


    private function check_index_exists($table_name, $index_name) {
        $query = $this->ci->db->query("SHOW INDEX FROM `{$table_name}` WHERE Key_name = '{$index_name}'");
        return $query->num_rows() > 0;
    }
}