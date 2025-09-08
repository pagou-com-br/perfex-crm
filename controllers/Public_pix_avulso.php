<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Classe PerfexDummyModule
 * - Implementa ArrayAccess para que qualquer índice retornado seja o mesmo objeto
 * - Implementa supports_feature(), sempre retornando false
 *
 * Dessa forma, se o Perfex chamar $CI->app_modules['pagou']['public_pix_avulso']['foo']->supports_feature(),
 * ou qualquer outra combinação, sempre terá um objeto (nunca array).
 */
class PerfexDummyModule implements ArrayAccess
{
    // Qualquer índice consultado (offsetGet) retorna $this,
    // impedindo que seja array e cause o erro "Call to a member function supports_feature() on array".

    #[\ReturnTypeWillChange] 
    public function offsetExists($offset) 
    {
        // Sempre "existe"
        return true; 
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        // Retorna este mesmo objeto em qualquer índice
        return $this;
    }

    #[\ReturnTypeWillChange]  
    public function offsetSet($offset, $value) 
    {
        // Não faz nada, pois não precisamos setar nada
    }
    
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset) 
    {
        // Não faz nada
    }

    /**
     * Perfex chama esse método para checar se o módulo suporta algum recurso.
     * Aqui, retornamos false para tudo (ou true, se preferir).
     */
    public function supports_feature($feature) 
    {
        return false;
    }
}

class Public_pix_avulso extends CI_Controller
{
    /**
     * Emula a estrutura $app_modules usada pelo Perfex,
     * definindo 'pagou' (e qualquer sub-índice) como um objeto PerfexDummyModule.
     */
    public $app_modules = [];

    public function __construct()
    {
        parent::__construct();
        
        // Define 'pagou' como uma instância de PerfexDummyModule
        $this->app_modules['pagou'] = new PerfexDummyModule();
        
        // Adiciona um hook de pós-controlador para garantir que 'pagou' sempre seja PerfexDummyModule
        $this->load->add_package_path(APPPATH.'hooks');
        //$this->hooks->add_hook('post_controller', [$this, 'ensure_pagou_is_dummy_module']);
        
        // Carrega o model e a library, necessários para obter a cobrança
        $this->load->model('pagou/Pix_avulso_model');
        $this->load->library('Pagou_pix_gateway');
    }
    
    /**
     * Hook de pós-controlador para garantir que 'pagou' sempre seja uma instância de PerfexDummyModule.
     */
    public function ensure_pagou_is_dummy_module()
    {
        // Obtém a instância do CodeIgniter SuperObject
        $CI =& get_instance();
        
        // Se 'pagou' não for uma instância de PerfexDummyModule, define como tal
        if (!($CI->app_modules['pagou'] instanceof PerfexDummyModule)) {
            $CI->app_modules['pagou'] = new PerfexDummyModule();
        }
    }

    /**
     * Visualiza a cobrança PIX avulsa, sem exigir login ou CPF.  
     * URL de acesso público (exemplo): /public_pix_avulso/view/pix_abc123
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
        // Se o formulário foi submetido com o CPF para desbloqueio...
        if ($this->input->post('cpf_verify')) {
            $cpf_input = $this->input->post('cpf_verify', true);
            $cpf_input_clean = preg_replace('/\D/', '', $cpf_input);
            $charge_cpf_clean = preg_replace('/\D/', '', $charge->cpf);
            if ($cpf_input_clean !== $charge_cpf_clean) {
                set_alert('danger', _l('CPF inválido. Por favor, tente novamente.'));
                redirect(admin_url('pagou/pix_avulso/view/' . $link));
            } else {
                // CPF validado: carrega a view de detalhes
                $data['charge'] = $charge;
                $this->load->view('pagou/view_charge', $data);
            }
        }
    }
}