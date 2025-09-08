<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Model para gerenciar a opção pagou_logs_api na tabela tbloptions
 */
class Pagou_conf_model extends CI_Model
{
    /**
     * Nome da opção na tabela
     * 
     * @var string
     */
    private $option_name = 'pagou_logs_api';

    /**
     * Indicador para saber se os logs estão ativados
     * 
     * @var bool
     */
    private $logs_enabled = false;

    public function __construct()
    {
        parent::__construct();

        // Consulta inicial para capturar o status dos logs
        $query = $this->db->select('value')
                          ->from(db_prefix() . 'options')
                          ->where('name', $this->option_name)
                          ->get();
        $row = $query->row();
        $value = ($row) ? $row->value : '0';

        // Define o flag de logs conforme o valor obtido
        if ($value === '1') {
            $this->logs_enabled = true;
            log_message('info', '[Pagou_conf_model::__construct] Logging ativado (pagou_logs_api = 1).');
        } else {
            log_message('info', '[Pagou_conf_model::__construct] Logging desativado (pagou_logs_api = 0).');
        }
    }

    /**
     * Retorna o valor atual da opção: '1' (Ativado) ou '0' (Desativado)
     *
     * @return string
     */
    public function get_logs_status()
    {
        if ($this->logs_enabled) {
            log_message('info', '[Pagou_conf_model::get_logs_status] Método chamado.');
        }

        $this->db->select('value');
        $this->db->from(db_prefix() . 'options');
        $this->db->where('name', $this->option_name);

        if ($this->logs_enabled) {
            log_message('info', '[Pagou_conf_model::get_logs_status] Executando query: SELECT value FROM ' . db_prefix() . 'options WHERE name = "' . $this->option_name . '"');
        }

        $row = $this->db->get()->row();

        if ($this->logs_enabled) {
            if ($row) {
                log_message('info', '[Pagou_conf_model::get_logs_status] Query retornou: ' . $row->value);
            } else {
                log_message('info', '[Pagou_conf_model::get_logs_status] Nenhum registro encontrado, retornando "0".');
            }
        }

        return ($row) ? $row->value : '0';
    }

    /**
     * Atualiza ou cria a opção no banco
     *
     * @param string $status '1' para ativar ou '0' para desativar os logs
     */
    public function update_logs_status($status)
    {
        if ($this->logs_enabled) {
            log_message('info', '[Pagou_conf_model::update_logs_status] Método chamado com status: ' . $status);
        }

        // Verifica se o registro já existe
        $this->db->where('name', $this->option_name);
        $query = $this->db->get(db_prefix() . 'options');
        $exists = $query->row();

        if ($this->logs_enabled) {
            if ($exists) {
                log_message('info', '[Pagou_conf_model::update_logs_status] Registro encontrado. Atualizando status.');
            } else {
                log_message('info', '[Pagou_conf_model::update_logs_status] Registro não encontrado. Inserindo novo registro.');
            }
        }

        if ($exists) {
            $this->db->where('name', $this->option_name);
            $this->db->update(db_prefix() . 'options', ['value' => $status]);

            if ($this->logs_enabled) {
                log_message('info', '[Pagou_conf_model::update_logs_status] Registro atualizado com sucesso.');
            }
        } else {
            $this->db->insert(db_prefix() . 'options', [
                'name'     => $this->option_name,
                'value'    => $status,
                'autoload' => 1,
            ]);

            if ($this->logs_enabled) {
                log_message('info', '[Pagou_conf_model::update_logs_status] Novo registro inserido com sucesso.');
            }
        }

        // Atualiza o flag local conforme o status definido
        if ($status === '1') {
            if (!$this->logs_enabled) {
                log_message('info', '[Pagou_conf_model::update_logs_status] Alterando status de logs: agora ativado.');
            }
            $this->logs_enabled = true;
        } else {
            if ($this->logs_enabled) {
                log_message('info', '[Pagou_conf_model::update_logs_status] Alterando status de logs: agora desativado.');
            }
            $this->logs_enabled = false;
        }
    }
}
