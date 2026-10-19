<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Gateway de cartão de crédito da Pagou (checkout próprio com tokenização no navegador).
 * Requer credenciamento de cartão aprovado na conta Pagou (no Sandbox, liberado pelo suporte).
 */
class Pagou_cartao_gateway extends App_gateway
{
    protected $ci;

    public function __construct()
    {
        parent::__construct();
        $this->ci = &get_instance();
        $this->ci->load->database();

        $this->setId('pagou_cartao');
        $this->setName('Pagou.com.br Cartão de Crédito');

        $this->setSettings([
            ['name' => 'api_token', 'encrypted' => true, 'label' => 'API | Chave de API da Pagou (do mesmo ambiente escolhido abaixo; a conta precisa de credenciamento de cartão)', 'type' => 'input'],
            ['name' => 'is_production', 'label' => 'AMBIENTE | Produção? (NÃO = Sandbox, para testes; o cartão é tokenizado no mesmo ambiente)', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'max_installments', 'label' => 'PARCELAS | Número máximo de parcelas oferecidas (1 = só à vista; até 12, limitado pela bandeira)', 'type' => 'input', 'default_value' => '1'],
            ['name' => 'min_installment_value', 'label' => 'PARCELAS | Valor mínimo de cada parcela em reais (0 = sem mínimo)', 'type' => 'input', 'default_value' => '0'],
            ['name' => 'currencies', 'label' => 'MOEDA | Códigos aceitos', 'type' => 'input', 'default_value' => 'BRL'],
            ['name' => 'enable_split', 'label' => 'SPLIT | Aplicar as regras de split da Pagou (exige split habilitado na conta e recebedores autorizados no painel)', 'type' => 'yes_no', 'default_value' => 0],
        ]);

        hooks()->add_action('before_add_payment_gateway', [$this, 'register_gateway']);
    }

    public function register_gateway()
    {
        $this->ci->payment_gateways->add($this);
    }

    /**
     * Parcelas oferecidas para o valor (respeitando máximo e valor mínimo por parcela).
     */
    public function installment_options($amount)
    {
        $max = min(max((int) $this->getSetting('max_installments'), 1), 12);
        $min_value = max(0, (float) str_replace(',', '.', (string) $this->getSetting('min_installment_value')));
        $options = [];
        for ($n = 1; $n <= $max; $n++) {
            if ($n > 1 && $min_value > 0 && $amount / $n < $min_value) {
                break;
            }
            $options[] = $n;
        }

        return $options;
    }

    public function process_payment($data)
    {
        if (empty($data['invoice'])) {
            set_alert('danger', _l('invoice_not_found'));
            redirect(site_url());
            return false;
        }

        pagou_load_libraries();
        $invoice = $data['invoice'];
        $back = site_url('invoice/' . $invoice->id . '/' . $invoice->hash);

        if (!Pagou_schema::ready() || !Pagou_api::for_gateway($this->getId())) {
            set_alert('danger', 'O pagamento com cartão está indisponível no momento.');
            redirect($back);
            return false;
        }
        if (!pagou_invoice_is_open($invoice) || pagou_invoice_amount_due($invoice) <= 0) {
            set_alert('danger', 'Esta fatura não está aberta para pagamento.');
            redirect($back);
            return false;
        }

        $card = new Pagou_card();
        $amount = pagou_invoice_amount_due($invoice);
        $security = $this->ci->security;

        $this->ci->load->view('pagou/payment_card', [
            'invoice'       => $invoice,
            'amount'        => $amount,
            'installments'  => $this->installment_options($amount),
            'contact'       => $card->contact_defaults($invoice),
            'open_charge'   => $card->open_charge($invoice->id),
            'is_production' => $this->getSetting('is_production') === '1',
            'charge_url'    => site_url('pagou/card_checkout/charge/' . (int) $invoice->id . '/' . $invoice->hash),
            'status_url'    => site_url('pagou/payment_status/check/' . (int) $invoice->id . '/' . $invoice->hash),
            'csrf_name'     => $security->get_csrf_token_name(),
            'csrf_hash'     => $security->get_csrf_hash(),
        ]);

        return true;
    }
}
