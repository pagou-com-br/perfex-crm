<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Status de pagamento consultado pela página pública da fatura (polling).
 * URL: pagou/payment_status/check/{invoice_id}/{hash}
 *
 * Exige o hash da fatura. Quando há Pix, boleto ou cartão em aberto, confirma na API
 * (no máximo a cada 15 segundos por cobrança), para a baixa não depender só do webhook.
 */
class Payment_status extends App_Controller
{
    public function check($invoice_id = 0, $hash = '')
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        // Validação leve antes de qualquer outra consulta
        $invoice = $this->db->select('id, status, hash')
            ->where('id', (int) $invoice_id)
            ->get(db_prefix() . 'invoices')->row();
        if (!$invoice || !is_string($hash) || $hash === '' || !hash_equals((string) $invoice->hash, $hash)) {
            http_response_code(404);
            echo json_encode(['status' => 'not_found']);
            return;
        }

        $status = (int) $invoice->status;
        if (in_array($status, [1, 3, 4], true)) {
            try {
                pagou_load_libraries();
                if (Pagou_schema::ready()) {
                    $this->confirm_open_charges((int) $invoice->id);
                    $status = (int) $this->db->select('status')->where('id', $invoice->id)->get(db_prefix() . 'invoices')->row()->status;
                }
            } catch (\Throwable $e) {
                pagou_log('webhook', 'error', 'Falha na confirmação sob demanda: ' . $e->getMessage());
            }
        }

        $response = ['status' => $status === 2 ? 'paid' : 'pending'];

        // Boleto registrado depois que a página abriu: dados para exibir sem recarregar
        $boleto = $this->db->select('transaction_id, barcode_url')
            ->where('invoice_id', $invoice->id)
            ->where('barcode_url !=', '')
            ->where_in('status', ['pending', 'active', '1', '2'])
            ->order_by('id', 'DESC')->limit(1)
            ->get(db_prefix() . 'pagou_transactions_boleto')->row();
        $response['boleto_ready'] = (bool) $boleto;
        if ($boleto) {
            $response['boleto'] = [
                'line'     => (string) $boleto->barcode_url,
                'html_url' => 'https://fatura.pagou.com.br/boleto/' . rawurlencode($boleto->transaction_id),
                'pdf_url'  => 'https://fatura.pagou.com.br/boleto/pdf/' . rawurlencode($boleto->transaction_id),
            ];
        }

        // Última tentativa com cartão: o checkout mostra a recusa sem precisar recarregar
        if ($this->db->table_exists(db_prefix() . 'pagou_card_charges')) {
            $card = $this->db->select('status, failure_reason')
                ->where('invoice_id', $invoice->id)
                ->order_by('id', 'DESC')->limit(1)
                ->get(db_prefix() . 'pagou_card_charges')->row();
            if ($card) {
                $response['card'] = [
                    'status' => (string) $card->status,
                    'reason' => in_array($card->status, ['failed', 'canceled'], true) ? (string) $card->failure_reason : '',
                ];
            }
        }

        echo json_encode($response);
    }

    private function confirm_open_charges($invoice_id)
    {
        $events = new Pagou_events();
        $limit = date('Y-m-d H:i:s', time() - 15);
        $jobs = [
            'pagou_transactions_pix'    => ['settle_pix', ['pending', 'active', '1', '2']],
            'pagou_transactions_boleto' => ['settle_boleto', ['pending', 'active', '1', '2']],
            'pagou_card_charges'        => ['settle_card', Pagou_card::OPEN],
        ];

        foreach ($jobs as $table => $job) {
            list($method, $statuses) = $job;
            $this->db->where('invoice_id', $invoice_id)
                ->where_in('status', $statuses)
                ->group_start()
                ->where('last_checked_at IS NULL', null, false)
                ->or_where('last_checked_at <', $limit)
                ->group_end();
            if ($table === 'pagou_transactions_pix') {
                $this->db->where('boleto_transaction_id IS NULL', null, false);
            }
            $rows = $this->db->order_by('id', 'DESC')->limit(2)->get(db_prefix() . $table)->result();

            foreach ($rows as $row) {
                $this->db->where('id', $row->id)->update(db_prefix() . $table, ['last_checked_at' => date('Y-m-d H:i:s')]);
                $events->$method($row);
            }
        }
    }
}
