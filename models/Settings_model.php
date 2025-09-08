<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Settings_model extends CI_Model {

    public function __construct(){
        parent::__construct();
    }

    /**
     * Retorna o valor da opção a partir da tabela tbloptions.
     *
     * @param string $option_key
     * @return mixed|null
     */
    public function get_option($option_key) {
        $this->db->where('option_key', $option_key);
        $query = $this->db->get('tbloptions');
        if($query->num_rows() > 0) {
            return $query->row()->option_val;
        }
        return null;
    }

    /**
     * Salva ou atualiza o valor da opção na tabela tbloptions.
     * Se a opção não existir, ela é criada com o valor informado.
     *
     * @param string $option_key
     * @param mixed  $option_val
     * @return bool
     */
    public function save_option($option_key, $option_val) {
        // Verifica se a opção já existe
        $this->db->where('option_key', $option_key);
        $exists = $this->db->count_all_results('tbloptions') > 0;

        if($exists) {
            // Atualiza a opção existente
            $this->db->where('option_key', $option_key);
            return $this->db->update('tbloptions', array('option_val' => $option_val));
        } else {
            // Insere a nova opção
            return $this->db->insert('tbloptions', array(
                'option_key' => $option_key,
                'option_val' => $option_val
            ));
        }
    }
}
