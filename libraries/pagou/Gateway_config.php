<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ============================
     Definição da Classe Gateway_config
   ============================ */
class Gateway_config
{
    private $log_enabled = false;

    /**
     * Construtor da classe
     */
    public function __construct()
    {
        $CI =& get_instance();
        $query = $CI->db->get_where(db_prefix() . 'options', ['name' => 'pagou_logs_api']);
        
        if ($query->num_rows() > 0) {
            $row = $query->row();
            $this->log_enabled = ($row->value === '1');
        }
        
        if ($this->log_enabled) {
            log_message('debug', 'Gateway_config inicializado. Logs ativados.');
        }
    }
    
    /**
     * Obtém as configurações do gateway
     * 
     * @return array Array com as configurações
     */
    public function getSettings()
    {
        return [
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
                'name'          => 'currencies',
                'label'         => 'MOEDA | Códigos aceitos (geralmente apenas BRL para Boleto)',
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
                'label'   => '     ⤷ Tipo: escolha "fixed" (valor fixo) ou "percentage" (percentual)',
                'type'    => 'select',
                'options' => [
                    'fixed'      => 'Valor Fixo (R$)',
                    'percentage' => 'Percentual (%)'
                ],
                'default_value' => 'fixed'
            ],
            [
                'name'          => 'discount_value',
                'label'         => '     ⤷ Valor: se fixed=centavos (500=R$5,00), se percentage=% (10=10%)',
                'type'          => 'input',
                'default_value' => '0'
            ],
            [
                'name'          => 'discount_limit_date',
                'label'         => '     ⤷ Número de dias antes do vencimento para aplicar desconto (Ex: 5)',
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
                'name'          => 'fine_value',
                'label'         => '     ⤷ Valor percentual de multa (0.1 até 10%, padrão: 2%)',
                'type'          => 'input',
                'default_value' => '2'
            ],
            
            // ==== SEÇÃO JUROS ====
            [
                'name'    => 'enable_interest',
                'label'   => '━━━━━━━━━━━━━━━━ JUROS POR ATRASO ━━━━━━━━━━━━━━━━',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'          => 'interest_value',
                'label'         => '     ⤷ Valor percentual de juros ao dia (0.1 até 1%, padrão: 0.33%)',
                'type'          => 'input',
                'default_value' => '0.33'
            ],
            
            // ==== SEÇÃO CARÊNCIA ====
            [
                'name'    => 'enable_grace_period',
                'label'   => '━━━━━━━━━━━━━━━━ CARÊNCIA APÓS VENCIMENTO ━━━━━━━━━━━━━━━━',
                'type'    => 'yes_no',
                'default_value' => 0
            ],
            [
                'name'          => 'grace_period_days',
                'label'         => '     ⤷ Dias de carência após vencimento (1 até 30, padrão: 1)',
                'type'          => 'input',
                'default_value' => '1'
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
        ];
    }
}