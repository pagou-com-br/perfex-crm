<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Processamento único dos eventos da Pagou: webhooks autenticados e reconciliação pelo cron.
 *
 * Regras (docs.pagou.com.br):
 * - assinatura HMAC validada antes de qualquer efeito;
 * - deduplicação durável por nome do evento + cobrança + transação;
 * - pagamento confirmado por consulta à API antes da baixa;
 * - um único registro de pagamento por pedido comercial (Pix ou boleto), via payments_model;
 * - 200 só depois de aceitar o evento; 5xx quando a aplicação precisa do reenvio.
 */
class Pagou_events
{
    const RESULT_PROCESSED = 'processed';
    const RESULT_IGNORED = 'ignored';
    const RESULT_RETRY = 'retry';

    private $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->database();
        $this->ci->load->model('invoices_model');
        $this->ci->load->model('payments_model');
    }

    // ================================================================ webhook

    /**
     * @param string $raw_body Corpo bruto da requisição
     * @return array [int $http_code, string $message]
     */
    public function handle_webhook($raw_body)
    {
        if (!pagou_authenticate_webhook_request($raw_body)) {
            pagou_log('webhook', 'error', 'Webhook rejeitado: assinatura ausente ou inválida.');
            return [401, 'Assinatura inválida.'];
        }

        if (!Pagou_schema::ready()) {
            // Banco ainda não atualizado: a Pagou reenvia depois
            return [503, 'Módulo aguardando atualização do banco de dados.'];
        }

        $event = json_decode($raw_body, true);
        if (!is_array($event) || empty($event['name']) || !isset($event['data']) || !is_array($event['data'])) {
            return [400, 'Payload inválido.'];
        }

        $name = (string) $event['name'];
        $data = $event['data'];
        $charge_id = isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '';
        $transaction_id = isset($data['transaction_id']) && is_scalar($data['transaction_id']) ? (string) $data['transaction_id'] : '';

        if (!in_array($name, ['qrcode.completed', 'qrcode.refunded', 'charge.created', 'charge.paid'], true)) {
            pagou_log('webhook', 'info', "Evento ignorado: {$name}");
            return [200, 'Evento ignorado.'];
        }
        if ($charge_id === '') {
            return [400, 'ID da cobrança ausente.'];
        }

        $stored = $this->store_event($name, $charge_id, $transaction_id, $raw_body);
        if ($stored === null) {
            return [503, 'Não foi possível registrar o evento.'];
        }
        if (in_array($stored->status, [self::RESULT_PROCESSED, self::RESULT_IGNORED], true)) {
            return [200, 'Evento já processado.'];
        }

        try {
            list($result, $message) = $this->apply_event($name, $data);
        } catch (\Throwable $e) {
            pagou_log('webhook', 'error', 'Falha ao processar evento: ' . $e->getMessage(), ['name' => $name, 'id' => $charge_id]);
            $result = self::RESULT_RETRY;
            $message = 'Erro interno ao processar o evento.';
        }

        $this->finish_event($stored->id, $result, $message);
        pagou_log('webhook', 'info', "{$name} {$charge_id}: {$result} ({$message})");

        return [$result === self::RESULT_RETRY ? 503 : 200, $message];
    }

    /**
     * Grava o evento (ou devolve o já existente) com chave única.
     *
     * @return object|null
     */
    private function store_event($name, $charge_id, $transaction_id, $raw_body)
    {
        $table = db_prefix() . 'pagou_webhook_events';
        $key = hash('sha256', $name . '|' . $charge_id . '|' . $transaction_id);

        $this->ci->db->query(
            'INSERT IGNORE INTO `' . $table . '` (`event_key`, `name`, `charge_id`, `transaction_id`, `status`, `attempts`, `payload`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
            [$key, $name, $charge_id, $transaction_id, 'received', json_encode(pagou_redact(json_decode($raw_body, true))), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        );

        $row = $this->ci->db->where('event_key', $key)->get($table)->row();
        if ($row) {
            $this->ci->db->where('id', $row->id)->set('attempts', 'attempts + 1', false)->update($table);
        }

        return $row ?: null;
    }

    private function finish_event($id, $result, $message)
    {
        $this->ci->db->where('id', $id)->update(db_prefix() . 'pagou_webhook_events', [
            'status'     => $result,
            'result'     => mb_substr((string) $message, 0, 255),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array [string $result, string $message]
     */
    private function apply_event($name, array $data)
    {
        $id = (string) $data['id'];

        if ($name === 'charge.created') {
            $boleto = $this->find_boleto($id);
            if (!$boleto) {
                return [self::RESULT_IGNORED, 'Boleto desconhecido.'];
            }
            $this->refresh_boleto($boleto);
            return [self::RESULT_PROCESSED, 'Registro do boleto atualizado.'];
        }

        if ($name === 'charge.paid') {
            $boleto = $this->find_boleto($id);
            if (!$boleto) {
                return [self::RESULT_IGNORED, 'Boleto desconhecido.'];
            }
            return $this->settle_boleto($boleto, pagou_event_amount($data));
        }

        // Eventos de Pix: Pix próprio da fatura, Pix vinculado a boleto ou Pix avulso
        $pix = $this->find_pix($id);

        if ($name === 'qrcode.refunded') {
            if (!$pix || !empty($pix->boleto_transaction_id)) {
                return [self::RESULT_IGNORED, 'Devolução de cobrança desconhecida.'];
            }
            return $this->apply_refund($pix, pagou_event_amount($data), $data['transaction_id'] ?? null);
        }

        // qrcode.completed
        if ($pix && !empty($pix->boleto_transaction_id)) {
            $boleto = $this->find_boleto($pix->boleto_transaction_id);
            return $boleto
                ? $this->settle_boleto($boleto, pagou_event_amount($data))
                : [self::RESULT_IGNORED, 'Boleto do Pix vinculado não encontrado.'];
        }
        if ($pix) {
            return $this->settle_pix($pix, pagou_event_amount($data), $data['e2e_id'] ?? null);
        }

        $avulso = $this->ci->db->where('qr_code', $id)->get(db_prefix() . 'pix_avulso')->row();
        if ($avulso) {
            return $this->settle_avulso($avulso);
        }

        return [self::RESULT_IGNORED, 'Cobrança desconhecida.'];
    }

    // ================================================================ localização

    private function find_boleto($id)
    {
        return $this->ci->db->where('transaction_id', $id)
            ->order_by('id', 'DESC')
            ->get(db_prefix() . 'pagou_transactions_boleto')->row();
    }

    /**
     * Linha Pix pelo ID na Pagou; liga ao boleto as linhas antigas de Pix vinculado sem vínculo.
     */
    private function find_pix($id)
    {
        $table = db_prefix() . 'pagou_transactions_pix';
        $pix = $this->ci->db->where('transaction_id', $id)->order_by('id', 'DESC')->get($table)->row();

        if ($pix && empty($pix->boleto_transaction_id)) {
            $boleto = $this->ci->db->like('api_response', '"qrcode_id":"' . $id . '"')
                ->get(db_prefix() . 'pagou_transactions_boleto')->row();
            if ($boleto) {
                $this->ci->db->where('id', $pix->id)->update($table, ['boleto_transaction_id' => $boleto->transaction_id]);
                $pix->boleto_transaction_id = $boleto->transaction_id;
            }
        }

        return $pix;
    }

    // ================================================================ confirmação e baixa

    /**
     * Confirma um Pix próprio da fatura na API e dá baixa.
     */
    public function settle_pix($pix, $event_amount = null, $e2e_id = null)
    {
        if (in_array($pix->status, ['refund_requested', 'partially_refunded', 'refunded'], true)) {
            return [self::RESULT_IGNORED, 'Cobrança já estornada ou em estorno.'];
        }

        $api = Pagou_api::for_gateway('pagou_pix');
        if (!$api) {
            return [self::RESULT_RETRY, 'Chave do gateway Pix não configurada.'];
        }
        $response = $api->get_pix($pix->transaction_id);
        if ($response->not_found()) {
            return [self::RESULT_IGNORED, 'Cobrança não encontrada na conta configurada.'];
        }
        if (!$response->ok() || !is_array($response->body)) {
            return [self::RESULT_RETRY, 'Não foi possível consultar a cobrança.'];
        }

        $status = isset($response->body['status']) ? (int) $response->body['status'] : 0;
        if ($status !== 4) {
            $local = pagou_normalize_status($status, 'pix');
            if ($pix->status !== 'paid') {
                $this->update_pix($pix->id, ['status' => $local]);
            }
            // 1 e 2 ainda podem virar pago: pedir reenvio; 3, 5, 6 são finais
            return in_array($status, [1, 2], true)
                ? [self::RESULT_RETRY, 'Pagamento ainda não refletido na API.']
                : [self::RESULT_IGNORED, 'Cobrança em estado final ' . $local . '.'];
        }

        $amount = $event_amount ?: (isset($response->body['amount']) ? (float) $response->body['amount'] : 0.0);
        $paid_at = $response->body['paid_at'] ?? null;

        $this->update_pix($pix->id, array_filter([
            'status'         => 'paid',
            'amount_final'   => $amount ?: null,
            'data_pagamento' => date('Y-m-d H:i:s', $paid_at ? strtotime($paid_at) : time()),
            'e2e_id'         => $e2e_id,
        ], function ($v) { return $v !== null; }));

        $ok = $this->register_payment($pix->invoice_id, $amount, 'pagou_pix', $pix->transaction_id, $paid_at,
            'Pix confirmado pela Pagou. Cobrança ' . $pix->transaction_id . ($e2e_id ? ' · E2E ' . $e2e_id : ''));

        return $ok ? [self::RESULT_PROCESSED, 'Pagamento Pix registrado.'] : [self::RESULT_RETRY, 'Falha ao registrar o pagamento.'];
    }

    /**
     * Confirma um boleto (pago pelo boleto ou pelo Pix vinculado) e dá baixa.
     */
    public function settle_boleto($boleto, $event_amount = null)
    {
        $api = Pagou_api::for_gateway('pagou_boleto');
        if (!$api) {
            return [self::RESULT_RETRY, 'Chave do gateway Boleto não configurada.'];
        }
        $response = $api->get_charge($boleto->transaction_id);
        if ($response->not_found()) {
            return [self::RESULT_IGNORED, 'Boleto não encontrado na conta configurada.'];
        }
        if (!$response->ok() || !is_array($response->body)) {
            return [self::RESULT_RETRY, 'Não foi possível consultar o boleto.'];
        }

        $charge = json_decode(json_encode($response->body));
        $this->store_boleto_snapshot($boleto, $charge);

        if (!pagou_boleto_api_is_paid($charge)) {
            $status = isset($charge->status) ? (int) $charge->status : 0;
            return in_array($status, [1, 2], true)
                ? [self::RESULT_RETRY, 'Pagamento ainda não refletido na API.']
                : [self::RESULT_IGNORED, 'Boleto em estado final ' . pagou_normalize_status($status, 'boleto') . '.'];
        }

        $amount = $event_amount ?: (isset($charge->amount) ? (float) $charge->amount : 0.0);
        $paid_at = $charge->paid_at ?? null;
        $via_pix = !in_array((int) ($charge->status ?? 0), [4, 8, 9], true);

        $ok = $this->register_payment($boleto->invoice_id, $amount, 'pagou_boleto', $boleto->transaction_id, $paid_at,
            ($via_pix ? 'Pago pelo Pix vinculado ao boleto' : 'Boleto pago') . ' · Cobrança ' . $boleto->transaction_id);

        return $ok ? [self::RESULT_PROCESSED, 'Pagamento do boleto registrado.'] : [self::RESULT_RETRY, 'Falha ao registrar o pagamento.'];
    }

    /**
     * Confirma um Pix avulso (sem fatura).
     */
    public function settle_avulso($avulso)
    {
        if ($avulso->status === 'pago') {
            return [self::RESULT_IGNORED, 'Cobrança avulsa já paga.'];
        }
        $api = Pagou_api::for_gateway('pagou_pix');
        if (!$api) {
            return [self::RESULT_RETRY, 'Chave do gateway Pix não configurada.'];
        }
        $response = $api->get_pix($avulso->qr_code);
        if ($response->not_found()) {
            return [self::RESULT_IGNORED, 'Cobrança avulsa não encontrada na conta configurada.'];
        }
        if (!$response->ok() || !is_array($response->body)) {
            return [self::RESULT_RETRY, 'Não foi possível consultar a cobrança avulsa.'];
        }

        $status = (int) ($response->body['status'] ?? 0);
        $table = db_prefix() . 'pix_avulso';
        if ($status === 4) {
            $this->ci->db->where('id', $avulso->id)->update($table, ['status' => 'pago', 'data_modificacao' => date('Y-m-d H:i:s')]);
            log_activity('Pagamento PIX Avulso confirmado pela Pagou [ID: ' . $avulso->id . ']');
            return [self::RESULT_PROCESSED, 'Pix avulso pago.'];
        }
        if (in_array($status, [3, 5, 6], true)) {
            $this->ci->db->where('id', $avulso->id)->update($table, ['status' => $status === 5 ? 'estornado' : 'cancelado', 'data_modificacao' => date('Y-m-d H:i:s')]);
            return [self::RESULT_IGNORED, 'Pix avulso em estado final.'];
        }

        return [self::RESULT_RETRY, 'Pagamento ainda não refletido na API.'];
    }

    /**
     * Devolução Pix confirmada (qrcode.refunded). O evento já foi deduplicado pela chave única,
     * então cada devolução soma uma única vez.
     */
    private function apply_refund($pix, $amount, $refund_transaction_id)
    {
        $refunded = round((float) ($pix->refunded_amount ?? 0) + (float) $amount, 2);
        $paid = (float) $pix->amount_final;
        $full = $amount === null || ($paid > 0 && $refunded + 0.009 >= $paid);

        $this->update_pix($pix->id, [
            'status'          => $full ? 'refunded' : 'partially_refunded',
            'refunded_amount' => $amount === null ? $paid : $refunded,
            'data_estorno'    => date('Y-m-d H:i:s'),
        ]);

        $description = $full
            ? 'Devolução Pix integral confirmada pela Pagou'
            : 'Devolução Pix parcial de R$ ' . number_format((float) $amount, 2, ',', '.') . ' confirmada pela Pagou. Ajuste o pagamento da fatura manualmente';
        $this->add_invoice_activity($pix->invoice_id, $description . ($refund_transaction_id ? ' (' . $refund_transaction_id . ')' : '') . '.');

        if ($full) {
            if (method_exists($this->ci->invoices_model, 'mark_as_cancelled')) {
                $this->ci->invoices_model->mark_as_cancelled($pix->invoice_id);
            } else {
                $this->ci->db->where('id', $pix->invoice_id)->update(db_prefix() . 'invoices', ['status' => 5]);
            }
        }

        return [self::RESULT_PROCESSED, $full ? 'Devolução integral registrada.' : 'Devolução parcial registrada.'];
    }

    /**
     * Registra o pagamento uma única vez por (fatura, pedido comercial), sob trava da fatura.
     */
    private function register_payment($invoice_id, $amount, $payment_mode, $transaction_id, $paid_at, $note)
    {
        $invoice = $this->ci->invoices_model->get($invoice_id);
        if (!$invoice) {
            pagou_log('webhook', 'error', "Fatura {$invoice_id} não encontrada para registrar pagamento.");
            return false;
        }
        if ($amount <= 0) {
            $amount = pagou_invoice_amount_due($invoice);
        }

        $db = $this->ci->db;
        $db->trans_begin();
        try {
            $db->query('SELECT id FROM ' . db_prefix() . 'invoices WHERE id = ? FOR UPDATE', [$invoice_id]);

            $exists = $db->where('invoiceid', $invoice_id)
                ->where('transactionid', $transaction_id)
                ->count_all_results(db_prefix() . 'invoicepaymentrecords') > 0;
            if ($exists) {
                $db->trans_commit();
                return true;
            }

            $payment = [
                'amount'        => round((float) $amount, 2),
                'invoiceid'     => $invoice_id,
                'paymentmode'   => $payment_mode,
                'transactionid' => $transaction_id,
                'note'          => $note,
            ];
            if ($paid_at && function_exists('_d')) {
                $payment['date'] = _d(date('Y-m-d', strtotime($paid_at)));
            }

            $payment_id = $this->ci->payments_model->add($payment);

            if ($payment_id && $db->trans_status() !== false) {
                $db->trans_commit();
                return true;
            }
            $db->trans_rollback();
        } catch (\Throwable $e) {
            $db->trans_rollback();
            pagou_log('webhook', 'error', 'Erro ao registrar pagamento: ' . $e->getMessage());
        }

        return false;
    }

    // ================================================================ boleto: snapshot

    /**
     * Atualiza a linha local do boleto e o Pix vinculado a partir da consulta.
     */
    public function refresh_boleto($boleto)
    {
        $api = Pagou_api::for_gateway('pagou_boleto');
        if (!$api) {
            return null;
        }
        $response = $api->get_charge($boleto->transaction_id);
        if (!$response->ok() || !is_array($response->body)) {
            return null;
        }
        $charge = json_decode(json_encode($response->body));
        $this->store_boleto_snapshot($boleto, $charge);

        return $charge;
    }

    private function store_boleto_snapshot($boleto, $charge)
    {
        $status = pagou_boleto_api_is_paid($charge) ? 'paid' : pagou_normalize_status($charge->status ?? null, 'boleto');
        $update = [
            'status'       => $status,
            'api_response' => json_encode($charge),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        if (!empty($charge->payload->line)) {
            $update['barcode_url'] = $charge->payload->line;
        }
        if (!empty($charge->payload->bar_code)) {
            $update['barcode'] = $charge->payload->bar_code;
        }
        if (!empty($charge->paid_at)) {
            $update['payment_date'] = date('Y-m-d H:i:s', strtotime($charge->paid_at));
        }
        $this->ci->db->where('id', $boleto->id)->update(db_prefix() . 'pagou_transactions_boleto', $update);

        pagou_save_linked_pix($boleto->invoice_id, $charge);
        if ($status === 'paid' && !empty($charge->payload->qrcode_id)) {
            $this->ci->db->where('transaction_id', $charge->payload->qrcode_id)
                ->update(db_prefix() . 'pagou_transactions_pix', ['status' => 'paid']);
        }
    }

    // ================================================================ reconciliação (cron)

    /**
     * Consulta cobranças em aberto e dá baixa no que já foi pago, cobrindo webhooks perdidos.
     * Executa no máximo a cada 15 minutos e até 40 consultas por execução.
     *
     * @return void
     */
    public function reconcile()
    {
        $last = (int) get_option('pagou_last_reconcile');
        if ($last && time() - $last < 900) {
            return;
        }
        update_option('pagou_last_reconcile', (string) time());

        $budget = 40;
        $since = date('Y-m-d H:i:s', strtotime('-3 days'));

        $pix_rows = $this->ci->db->where('date_created >=', $since)
            ->where_in('status', ['pending', 'active', '1', '2'])
            ->where('boleto_transaction_id IS NULL', null, false)
            ->order_by('id', 'DESC')->limit($budget)
            ->get(db_prefix() . 'pagou_transactions_pix')->result();
        foreach ($pix_rows as $pix) {
            $this->settle_pix($pix);
            $budget--;
        }

        if ($budget > 0) {
            $boletos = $this->ci->db->where('date_created >=', date('Y-m-d H:i:s', strtotime('-60 days')))
                ->where_in('status', ['pending', 'active', '1', '2'])
                ->order_by('id', 'DESC')->limit($budget)
                ->get(db_prefix() . 'pagou_transactions_boleto')->result();
            foreach ($boletos as $boleto) {
                $this->settle_boleto($boleto);
            }
        }
    }

    // ================================================================ utilitários

    private function update_pix($id, array $data)
    {
        $this->ci->db->where('id', $id)->update(db_prefix() . 'pagou_transactions_pix', $data);
    }

    private function add_invoice_activity($invoice_id, $description)
    {
        if (method_exists($this->ci->invoices_model, 'log_invoice_activity')) {
            $this->ci->invoices_model->log_invoice_activity($invoice_id, $description);
        } else {
            log_activity($description . ' [Fatura ID: ' . $invoice_id . ']');
        }
    }
}
