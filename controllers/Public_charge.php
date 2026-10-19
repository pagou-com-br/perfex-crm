<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
 * Public_charge Controller
 *
 * Este controller permite a visualização pública (sem login) das cobranças PIX avulsas
 * por meio de um link único.
 */
class Public_charge extends App_Controller {

    public function __construct(){
        parent::__construct();
        // Carrega o model responsável pelas cobranças PIX avulsas
        $this->load->model('pagou/Pix_avulso_model');
        // Não há necessidade de verificação de login aqui.
    }
    
    /**
     * Visualiza a cobrança PIX avulsa a partir do link único.
     * Se o formulário com o CPF não for submetido, exibe o formulário.
     * Se submetido, valida o CPF e, se correto, exibe os detalhes da cobrança.
     *
     * URL exemplo: seusite.com/public_charge/view/{link}
     *
     * @param string $link Link único da cobrança
     */
    public function view($link = '') {
        if(empty($link)){
            echo 'Link inválido.';
            exit;
        }
        
        $charge = $this->Pix_avulso_model->get_charge_by_link($link);
        if(!$charge){
            echo 'Cobrança não encontrada.';
            exit;
        }
        
        // Limite de tentativas de CPF por cobrança, gravado no banco: 5 erros bloqueiam por 15 minutos
        $table = db_prefix() . 'pix_avulso';
        if ($this->input->post('cpf_verify')) {
            if (!empty($charge->cpf_locked_until) && strtotime($charge->cpf_locked_until) > time()) {
                $data['error'] = _l('Muitas tentativas. Aguarde alguns minutos e tente de novo.');
                $data['charge'] = $charge;
                $data['unlocked'] = false;
                $this->load->view('pagou/public_view_charge', $data);
                return;
            }

            $cpf_input_clean = preg_replace('/\D/', '', (string) $this->input->post('cpf_verify', true));
            $charge_cpf_clean = preg_replace('/\D/', '', (string) $charge->cpf);
            if (!hash_equals($charge_cpf_clean, $cpf_input_clean)) {
                $attempts = (int) ($charge->cpf_attempts ?? 0) + 1;
                $update = ['cpf_attempts' => $attempts >= 5 ? 0 : $attempts];
                if ($attempts >= 5) {
                    $update['cpf_locked_until'] = date('Y-m-d H:i:s', time() + 900);
                }
                $this->db->where('id', $charge->id)->update($table, $update);
                $data['error'] = _l('CPF inválido. Por favor, tente novamente.');
                $data['charge'] = $charge;
                $data['unlocked'] = false;
                $this->load->view('pagou/public_view_charge', $data);
                return;
            }
            $this->db->where('id', $charge->id)->update($table, ['cpf_attempts' => 0, 'cpf_locked_until' => null]);
            $data['unlocked'] = true;
        }
        $data['charge'] = $charge;
        $this->load->view('pagou/public_view_charge', $data);
    }
}
