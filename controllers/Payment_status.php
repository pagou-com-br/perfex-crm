<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Status de pagamento consultado pela página pública da fatura (polling).
 * URL: pagou/payment_status/check/{invoice_id}/{hash}
 *
 * Exige o hash da fatura. Quando há Pix ou boleto em aberto, confirma na API
 * (no máximo a cada 15 segundos por cobrança), para a baixa não depender só do webhook.
 */
class Payment_status extends App_Controller
{
    public function check($invoice_id = 0, $hash = '')
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $this->load->model('invoices_model');
        $invoice = $this->invoices_model->get((int) $invoice_id);
        if (!$invoice || !is_string($hash) || $hash === '' || !hash_equals((string) $invoice->hash, $hash)) {
            http_response_code(404);
            echo json_encode(['status' => 'not_found']);
            return;
        }

        if ((int) $invoice->status !== 2) {
            try {
                pagou_load_libraries();
                if (Pagou_schema::ready()) {
                    $this->confirm_open_charges($invoice);
                    $invoice = $this->invoices_model->get($invoice->id);
                }
            } catch (\Throwable $e) {
                pagou_log('webhook', 'error', 'Falha na confirmação sob demanda: ' . $e->getMessage());
            }
        }

        $boleto_ready = $this->db->where('invoice_id', $invoice->id)
            ->where('barcode_url !=', '')
            ->where_in('status', ['pending', 'active', '1', '2'])
            ->count_all_results(db_prefix() . 'pagou_transactions_boleto') > 0;

        echo json_encode([
            'status'       => (int) $invoice->status === 2 ? 'paid' : 'pending',
            'boleto_ready' => $boleto_ready,
        ]);
    }

    private function confirm_open_charges($invoice)
    {
        $events = new Pagou_events();
        $open = ['pending', 'active', '1', '2'];
        $limit = date('Y-m-d H:i:s', time() - 15);

        foreach (['pagou_transactions_pix' => 'settle_pix', 'pagou_transactions_boleto' => 'settle_boleto'] as $table => $method) {
            $this->db->where('invoice_id', $invoice->id)
                ->where_in('status', $open)
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
