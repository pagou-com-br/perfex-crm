<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pix_avulso_model extends App_Model {

    protected $table = 'pix_avulso';

    public function __construct(){
        parent::__construct();
    }
    
    /**
     * Insere uma nova cobrança PIX Avulsa na tabela.
     *
     * @param array $data Dados da cobrança.
     * @return mixed Insert ID se sucesso, false caso contrário.
     */
    public function create_charge($data) {
        $this->db->insert($this->table, $data);
        if ($this->db->affected_rows() > 0) {
            return $this->db->insert_id();
        }
        return false;
    }
    
    /**
     * Retorna todas as cobranças PIX Avulsa, ordenadas pela data de emissão em ordem decrescente.
     *
     * @return array Resultado da consulta.
     */
    public function get_all_charges() {
        $this->db->order_by('data_emissao', 'DESC');
        return $this->db->get($this->table)->result();
    }
    
    /**
     * Retorna uma cobrança PIX Avulsa com base no link único.
     *
     * @param string $link Link único da cobrança.
     * @return object|false Objeto da cobrança ou false se não encontrada.
     */
    public function get_charge_by_link($link) {
        $this->db->where('link', $link);
        return $this->db->get($this->table)->row();
    }
}
