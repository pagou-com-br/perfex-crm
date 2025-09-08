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
        
        // Se o formulário foi submetido com o CPF para desbloqueio...
        if ($this->input->post('cpf_verify')) {
            $cpf_input = $this->input->post('cpf_verify', true);
            $cpf_input_clean = preg_replace('/\D/', '', $cpf_input);
            $charge_cpf_clean = preg_replace('/\D/', '', $charge->cpf);
            if ($cpf_input_clean !== $charge_cpf_clean) {
                $data['error'] = _l('CPF inválido. Por favor, tente novamente.');
                $data['charge'] = $charge;
                $this->load->view('pagou/public_view_charge', $data);
                return;
            }
        }
        $data['charge'] = $charge;
        $this->load->view('pagou/public_view_charge', $data);
    }
}
