<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Recebe o cartão tokenizado no navegador e cria a cobrança no backend.
 * URL: POST pagou/card_checkout/charge/{invoice_id}/{hash} (com token CSRF do Perfex).
 *
 * Recebe somente card_token, holder e expires_at do cartão; número e CVV nunca chegam aqui.
 */
class Card_checkout extends App_Controller
{
    public function charge($invoice_id = 0, $hash = '')
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if ($this->input->method() !== 'post') {
            return $this->reply(405, 'error', 'Método não permitido.');
        }

        $this->load->model('invoices_model');
        $invoice = $this->invoices_model->get((int) $invoice_id);
        if (!$invoice || !is_string($hash) || $hash === '' || !hash_equals((string) $invoice->hash, $hash)) {
            return $this->reply(404, 'error', 'Fatura não encontrada.');
        }

        pagou_load_libraries();
        if (!Pagou_schema::ready()) {
            return $this->reply(503, 'error', 'O pagamento com cartão está indisponível no momento.');
        }

        $card = [
            'card_token' => (string) $this->input->post('card_token'),
            'holder'     => trim((string) $this->input->post('holder', true)),
            'expires_at' => (string) $this->input->post('expires_at', true),
        ];
        if (strlen($card['card_token']) < 64 || strlen($card['card_token']) > 16384 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $card['card_token'])) {
            return $this->reply(422, 'error', 'Não foi possível ler o cartão. Confira os dados e tente de novo.');
        }
        if (mb_strlen($card['holder']) < 2) {
            return $this->reply(422, 'error', 'Informe o nome impresso no cartão.');
        }
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $card['expires_at'], $m) || sprintf('%04d-%02d', $m[1], $m[2]) < date('Y-m')) {
            return $this->reply(422, 'error', 'Validade do cartão inválida ou vencida.');
        }

        require_once __DIR__ . '/../libraries/Pagou_cartao_gateway.php';
        $gateway = new Pagou_cartao_gateway();
        $installments = (int) $this->input->post('installments');
        if (!in_array($installments, $gateway->installment_options(pagou_invoice_amount_due($invoice)), true)) {
            return $this->reply(422, 'error', 'Número de parcelas inválido.');
        }

        // IP real do comprador: cabeçalhos de proxy só valem vindos de proxy confiável
        $ip = pagou_client_ip((string) $gateway->getSetting('client_ip_mode'), (string) $gateway->getSetting('trusted_proxies'));

        $result = (new Pagou_card())->charge($invoice, $card, $installments, [
            'email' => (string) $this->input->post('email', true),
            'phone' => (string) $this->input->post('phone', true),
        ], $ip);

        $http = $result['result'] === 'error' ? 422 : 200;
        return $this->reply($http, $result['result'], $result['message']);
    }

    private function reply($http, $result, $message)
    {
        http_response_code($http);
        echo json_encode([
            'result'  => $result,
            'message' => $message,
            'csrf'    => $this->security->get_csrf_hash(),
        ]);
    }
}
