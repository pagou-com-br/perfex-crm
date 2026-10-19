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
     * Emite uma nova cobrança PIX Avulsa.
     * A linha local é gravada antes da chamada, para reconciliar um resultado indeterminado.
     */
    public function create()
    {
        if (!$this->input->post()) {
            redirect(admin_url('pagou/pix_avulso'));
        }

        $nome      = trim((string) $this->input->post('nome', true));
        $cpf       = preg_replace('/\D/', '', (string) $this->input->post('cpf', true));
        $descricao = trim((string) $this->input->post('descricao', true));
        $valor     = round((float) str_replace(',', '.', (string) $this->input->post('valor', true)), 2);

        if ($nome === '' || $descricao === '' || $valor <= 0) {
            set_alert('danger', _l('Todos os campos são obrigatórios.'));
            redirect(admin_url('pagou/pix_avulso'));
        }
        if (!pagou_valid_document($cpf)) {
            set_alert('danger', _l('Informe um CPF ou CNPJ válido.'));
            redirect(admin_url('pagou/pix_avulso'));
        }

        pagou_load_libraries();
        $api = Pagou_api::for_gateway('pagou_pix');
        if (!$api || !Pagou_schema::ready()) {
            set_alert('danger', _l('Configure o gateway Pagou PIX e atualize o banco de dados do módulo antes de emitir.'));
            redirect(admin_url('pagou/pix_avulso'));
        }

        $insert_id = $this->Pix_avulso_model->create_charge([
            'nome'             => $nome,
            'cpf'              => $cpf,
            'descricao'        => $descricao,
            'valor'            => $valor,
            'link'             => bin2hex(random_bytes(16)),
            'data_emissao'     => date('Y-m-d H:i:s'),
            'data_modificacao' => date('Y-m-d H:i:s'),
            'status'           => 'emitindo',
            'qr_code'          => '',
            'qr_code_text'     => '',
            'qr_code_img'      => '',
        ]);
        if (!$insert_id) {
            set_alert('danger', _l('Erro ao registrar a cobrança no sistema.'));
            redirect(admin_url('pagou/pix_avulso'));
        }

        $response = $api->create_pix([
            'amount'           => $valor,
            'description'      => mb_substr('Cobrança Avulsa: ' . $descricao, 0, 140),
            'expiration'       => 86400, // 24 horas
            'payer'            => ['name' => mb_substr($nome, 0, 100), 'document' => $cpf],
            'customer_code'    => 'perfex-avulso-' . $insert_id,
            'notification_url' => trim((string) get_option('paymentmethod_pagou_pix_webhook_url')) ?: site_url('pagou/pagou_webhook/process'),
        ]);

        $table = db_prefix() . 'pix_avulso';
        if ($response->http_code === 201 && !empty($response->body['id'])) {
            $this->db->where('id', $insert_id)->update($table, [
                'status'       => 'emitido',
                'qr_code'      => $response->body['id'],
                'qr_code_text' => $response->body['payload']['data'] ?? '',
                'qr_code_img'  => $response->body['payload']['image'] ?? '',
            ]);
            set_alert('success', _l('Cobrança PIX avulsa emitida com sucesso.'));
        } elseif ($response->uncertain()) {
            // Resultado indeterminado: a cobrança pode ter sido criada na Pagou
            $this->db->where('id', $insert_id)->update($table, ['status' => 'incerto']);
            set_alert('warning', _l('A Pagou não confirmou a emissão. Antes de emitir de novo, confira no painel da Pagou se a cobrança perfex-avulso-' . $insert_id . ' já foi criada.'));
        } else {
            $this->db->where('id', $insert_id)->update($table, ['status' => 'erro']);
            set_alert('danger', _l('Erro ao emitir cobrança PIX: ') . html_escape($response->error_message()));
        }

        redirect(admin_url('pagou/pix_avulso'));
    }

    /**
     * Exibe a cobrança PIX avulsa.
     */
    public function view($link = '')
    {
        $charge = $link !== '' ? $this->Pix_avulso_model->get_charge_by_link($link) : null;
        if (!$charge) {
            set_alert('danger', _l('Cobrança não encontrada.'));
            redirect(admin_url('pagou/pix_avulso'));
        }

        $data['charge'] = $charge;
        $this->load->view('pagou/view_charge', $data);
    }
}
