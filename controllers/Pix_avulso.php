<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pix_avulso extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (!staff_can('access_pix_avulso', 'pagou_module')) {
            access_denied('PIX Avulso');
        }
        // Carrega o model responsável pelas cobranças PIX avulsas
        $this->load->model('pagou/Pix_avulso_model');
        // Carrega a biblioteca do gateway Pagou para PIX
        $this->load->library('Pagou_pix_gateway');
    }

    /**
     * Exibe a página de emissão de PIX Avulso e a listagem das cobranças já emitidas.
     */
    public function index()
    {
        $data['charges'] = $this->Pix_avulso_model->get_all_charges();
        $this->load->view('pagou/pix_avulso', $data);
    }

    /**
     * Processa o formulário para emitir uma nova cobrança PIX Avulsa.
     * A cobrança só é registrada na tabela "pix_avulso" se a emissão junto à API do Pagou for bem-sucedida.
     */
    public function create()
    {
        if ($this->input->post()) {
            // Obtém os dados enviados pelo formulário (com XSS cleaning)
            $nome      = $this->input->post('nome', true);
            $cpf       = $this->input->post('cpf', true);
            $descricao = $this->input->post('descricao', true);
            $valor     = $this->input->post('valor', true);

            // Validação básica
            if (empty($nome) || empty($cpf) || empty($descricao) || empty($valor)) {
                set_alert('danger', _l('Todos os campos são obrigatórios.'));
                redirect(admin_url('pagou/pix_avulso'));
            }

            // Calcula o valor final
            $formatted_amount = round((float)$valor, 2);

            // Prepara os dados para envio à API do Pagou
            $payment_data = [
                'amount'          => $formatted_amount,
                'description'     => 'Cobrança Avulsa: ' . $descricao,
                'expiration'      => 86400, // 24 horas
                'notification_url'=> site_url('pagou/notification'),
                'payer'           => [
                    'name'     => $nome,
                    'document' => preg_replace('/\D/', '', $cpf)
                ]
            ];

            // Instancia o gateway e utiliza seus métodos
            $gateway = new Pagou_pix_gateway();
            $gateway->log('debug', 'Processando cobrança PIX avulsa.');
            
            // Endpoint do ambiente configurado no gateway Pix
            $api_endpoint = pagou_gateway_api_base_url('pagou_pix') . '/v1/pix';
            $gateway->log('debug', 'API endpoint selecionado: ' . $api_endpoint);

            // Realiza a requisição cURL para emitir a cobrança
            $ch = curl_init($api_endpoint);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'User-Agent: pagou-perfex/1.2.1',
                'X-API-KEY: ' . $gateway->decryptSetting('api_token')
            ]);

            $gateway->log('debug', 'Iniciando requisição cURL para gerar cobrança PIX avulsa.');
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $gateway->log('debug', 'Código HTTP: ' . $http_code);
            curl_close($ch);

            if ($http_code === 201) {
                $gateway->log('info', 'Cobrança PIX avulsa emitida com sucesso.');
                // Decodifica a resposta em JSON
                $result = json_decode($response);

                // Gera um link único para a cobrança
                $link = uniqid('pix_', true);

                // Prepara os dados para inserção na tabela "pix_avulso"
                $data_insert = [
                    'nome'             => $nome,
                    'cpf'              => $cpf,
                    'descricao'        => $descricao,
                    'valor'            => $formatted_amount,
                    'link'             => $link,
                    'data_emissao'     => date('Y-m-d H:i:s'),
                    'data_modificacao' => date('Y-m-d H:i:s'),
                    'status'           => 'emitido'
                ];

                // Novos campos para salvar o retorno do Pagou:
                //   - qr_code      => $result->id
                //   - qr_code_text => $result->payload->data
                //   - qr_code_img  => $result->payload->image
                $data_insert['qr_code']      = isset($result->id)                ? $result->id                : '';
                $data_insert['qr_code_text'] = isset($result->payload->data)     ? $result->payload->data     : '';
                $data_insert['qr_code_img']  = isset($result->payload->image)    ? $result->payload->image    : '';

                $insert_id = $this->Pix_avulso_model->create_charge($data_insert);
                if ($insert_id) {
                    set_alert('success', _l('Cobrança PIX avulsa emitida com sucesso.'));
                } else {
                    set_alert('danger', _l('Erro ao registrar a cobrança no sistema.'));
                }
                redirect(admin_url('pagou/pix_avulso'));
            } else {
                $gateway->log('error', 'Erro ao emitir cobrança PIX avulsa. Código HTTP: ' . $http_code . '. Resposta: ' . $response);
                if ($http_code === 0 || $http_code >= 500) {
                    // Resultado indeterminado: a cobrança pode ter sido criada na Pagou
                    set_alert('warning', _l('A Pagou não confirmou a emissão. Antes de emitir de novo, confira no painel da Pagou se a cobrança já foi criada.'));
                } else {
                    set_alert('danger', _l('Erro ao emitir cobrança PIX. Confira os dados informados.'));
                }
                redirect(admin_url('pagou/pix_avulso'));
            }
        } else {
            redirect(admin_url('pagou/pix_avulso'));
        }
    }

    /**
     * Exibe a visualização da cobrança PIX avulsa, protegida por CPF.
     * Se o CPF informado no formulário fosse usado, mas agora exibimos direto.
     */
    public function view($link = '')
    {
        if (empty($link)) {
            set_alert('danger', _l('Link inválido.'));
            redirect(admin_url('pagou/pix_avulso'));
        }
        $charge = $this->Pix_avulso_model->get_charge_by_link($link);
        if (!$charge) {
            set_alert('danger', _l('Cobrança não encontrada.'));
            redirect(admin_url('pagou/pix_avulso'));
        }

        // Carrega a view de detalhes (sem a necessidade de CPF no momento)
        $data['charge'] = $charge;
        $this->load->view('pagou/view_charge', $data);
    }
}
