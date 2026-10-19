<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controller para Configurações do Módulo Pagou
 * Acesso via: /admin/pagou_conf
 */
class Pagou_conf extends AdminController
{
    /**
     * Indicador para ativação dos logs
     * Se true, registra logs em vários pontos do controller.
     *
     * @var bool
     */
    private $logs_ativo = false;

    public function __construct()
    {
        parent::__construct();
        if (!is_admin()) {
            access_denied('Pagou Configurações');
        }
        $this->load->model('pagou_conf_model');

        // Recupera o status dos logs a partir da tabela tbloptions (campo value onde name = "pagou_logs_api")
        $logs_status = $this->pagou_conf_model->get_logs_status();
        if (empty($logs_status)) {
            $logs_status = '0';
        }

        // Se o valor retornado for "1", ativa a captura de logs
        if ($logs_status === '1') {
            $this->logs_ativo = true;
            log_message('info', '[Pagou_conf::__construct] Logs ativados (pagou_logs_api = 1).');
        } else {
            log_message('info', '[Pagou_conf::__construct] Logs desativados (pagou_logs_api = 0).');
        }
    }

    /**
     * Exibe a página de configurações
     * Acesso via: /admin/pagou_conf
     */
    public function index()
    {
        if ($this->logs_ativo) {
            log_message('info', '[Pagou_conf::index] Acessando a página de configurações.');
        }

        $data['logs_pagou_api'] = $this->pagou_conf_model->get_logs_status();
        if (empty($data['logs_pagou_api'])) {
            $data['logs_pagou_api'] = '0';
        }

        if ($this->logs_ativo) {
            log_message('info', '[Pagou_conf::index] Valor de logs_pagou_api: ' . $data['logs_pagou_api']);
            log_message('info', '[Pagou_conf::index] Carregando a view "pagou_conf".');
        }

        // Carrega a view do módulo em /modules/pagou/views/pagou_conf.php
        $this->load->view('pagou_conf', $data);

        if ($this->logs_ativo) {
            log_message('info', '[Pagou_conf::index] View "pagou_conf" carregada com sucesso.');
        }
    }

    /**
     * Salva as configurações enviadas pelo form
     * Acesso via POST: /admin/pagou_conf/save
     */
    public function save()
    {
        if ($this->logs_ativo) {
            log_message('info', '[Pagou_conf::save] Método save chamado.');
        }

        if ($this->input->post()) {
            if ($this->logs_ativo) {
                log_message('info', '[Pagou_conf::save] Dados recebidos via POST: ' . print_r($this->input->post(), true));
            }

            $logs_status = $this->input->post('logs_pagou_api');
            if ($logs_status !== '1' && $logs_status !== '0') {
                $logs_status = '0';
                if ($this->logs_ativo) {
                    log_message('info', '[Pagou_conf::save] Valor inválido recebido para logs_pagou_api. Definido como 0.');
                }
            }

            // Atualiza o status dos logs na tabela tbloptions
            $this->pagou_conf_model->update_logs_status($logs_status);
            if ($this->logs_ativo) {
                log_message('info', '[Pagou_conf::save] logs_pagou_api atualizado para: ' . $logs_status);
            }

            set_alert('success', 'Configurações atualizadas com sucesso.');

            if ($this->logs_ativo) {
                log_message('info', '[Pagou_conf::save] Redirecionando para a página de configurações.');
            }
            redirect(admin_url('pagou/pagou_conf'));
        }

        if ($this->logs_ativo) {
            log_message('info', '[Pagou_conf::save] Nenhum POST detectado. Redirecionando para a página de configurações.');
        }
        // Caso não seja POST, apenas redireciona
        redirect(admin_url('pagou/pagou_conf'));
    }

    /**
     * Descarta as chaves de API anteriores guardadas para validar webhooks.
     * Acesso via POST: /admin/pagou/pagou_conf/forget_keys
     */
    public function forget_keys()
    {
        if ($this->input->post()) {
            pagou_forget_previous_api_tokens();
            log_activity('Pagou: chaves de API anteriores descartadas por ' . get_staff_full_name(get_staff_user_id()));
            set_alert('success', 'Chaves de API anteriores descartadas.');
        }
        redirect(admin_url('pagou/pagou_conf'));
    }
}
