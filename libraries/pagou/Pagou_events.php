<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Processamento único dos eventos da Pagou: webhooks autenticados e reconciliação pelo cron.
 *
 * Regras (docs.pagou.com.br):
 * - assinatura HMAC validada antes de qualquer efeito;
 * - deduplicação durável por nome do evento + cobrança + transação, com reivindicação atômica;
 * - todo efeito vem da consulta à API (pagamento e devolução), nunca só do corpo do evento;
 * - um único registro de pagamento por cobrança (pagou_settlements, chave única), e nenhum
 *   pagamento em fatura já quitada ou cancelada;
 * - 200 só depois de aceitar o evento; 503 quando é preciso reenvio.
 */
class Pagou_events
{
    const RESULT_PROCESSED = 'processed';
    const RESULT_IGNORED = 'ignored';
    const RESULT_RETRY = 'retry';

    const OPEN_STATUSES = ['pending', 'active', '1', '2'];

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

        list($state, $event_id) = $this->claim_event($name, $charge_id, $transaction_id, $event);
        if ($state === 'done') {
            return [200, 'Evento já processado.'];
        }
        if ($state !== 'claimed') {
            // Outra entrega do mesmo evento está em processamento, ou o registro falhou
            return [503, 'Evento em processamento. Reenvie depois.'];
        }

        try {
            list($result, $message) = $this->apply_event($name, $data);
        } catch (\Throwable $e) {
            pagou_log('webhook', 'error', 'Falha ao processar evento: ' . $e->getMessage(), ['name' => $name, 'id' => $charge_id]);
            $result = self::RESULT_RETRY;
            $message = 'Erro interno ao processar o evento.';
        }

        $this->finish_event($event_id, $result, $message);
        pagou_log('webhook', 'info', "{$name} {$charge_id}: {$result} ({$message})");

        return [$result === self::RESULT_RETRY ? 503 : 200, $message];
    }

    /**
     * Registra o evento e o reivindica de forma atômica.
     *
     * @return array [string $state ('claimed', 'done', 'busy', 'error'), int|null $event_id]
     */
    private function claim_event($name, $charge_id, $transaction_id, array $event)
    {
        $db = $this->ci->db;
        $table = db_prefix() . 'pagou_webhook_events';
        $key = hash('sha256', $name . '|' . $charge_id . '|' . $transaction_id);
        $now = date('Y-m-d H:i:s');

        $inserted = $db->insert($table, [
            'event_key'      => $key,
            'name'           => $name,
            'charge_id'      => mb_substr($charge_id, 0, 255),
            'transaction_id' => mb_substr($transaction_id, 0, 255),
            'status'         => 'received',
            'attempts'       => 0,
            'payload'        => json_encode(pagou_redact($event), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        if (!$inserted && (int) ($db->error()['code'] ?? 0) !== 1062) {
            pagou_log('webhook', 'error', 'Falha ao gravar evento: ' . ($db->error()['message'] ?? ''));
            return ['error', null];
        }

        $row = $db->where('event_key', $key)->get($table)->row();
        if (!$row) {
            return ['error', null];
        }
        if (in_array($row->status, [self::RESULT_PROCESSED, self::RESULT_IGNORED], true)) {
            return ['done', (int) $row->id];
        }

        $db->query(
            'UPDATE `' . $table . '` SET `status` = ?, `locked_at` = ?, `attempts` = `attempts` + 1, `updated_at` = ?'
            . ' WHERE `id` = ? AND (`status` IN (?, ?) OR (`status` = ? AND `locked_at` < ?))',
            ['processing', $now, $now, $row->id, 'received', self::RESULT_RETRY, 'processing', date('Y-m-d H:i:s', time() - 300)]
        );

        return [$db->affected_rows() === 1 ? 'claimed' : 'busy', (int) $row->id];
    }

    private function finish_event($id, $result, $message)
    {
        $this->ci->db->where('id', $id)->update(db_prefix() . 'pagou_webhook_events', [
            'status'     => $result,
            'result'     => mb_substr((string) $message, 0, 255),
            'locked_at'  => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array [string $result, string $message]
     */
    private function apply_event($name, array $data)
    {
        $id = (string) $data['id'];

        if ($name === 'charge.created' || $name === 'charge.paid') {
            $boleto = $this->find_boleto($id);
            if (!$boleto) {
                return [self::RESULT_IGNORED, 'Boleto desconhecido.'];
            }
            if ($name === 'charge.created') {
                return $this->refresh_boleto($boleto)
                    ? [self::RESULT_PROCESSED, 'Registro do boleto atualizado.']
                    : [self::RESULT_RETRY, 'Não foi possível consultar o boleto.'];
            }
            return $this->settle_boleto($boleto, pagou_event_amount($data));
        }

        // Eventos de Pix: Pix próprio da fatura, Pix vinculado a boleto ou Pix avulso
        $pix = $this->find_pix($id);

        if ($pix && !empty($pix->boleto_transaction_id)) {
            $boleto = $this->find_boleto($pix->boleto_transaction_id);
            if (!$boleto) {
                return [self::RESULT_IGNORED, 'Boleto do Pix vinculado não encontrado.'];
            }
            if ($name === 'qrcode.refunded') {
                $this->add_invoice_activity($boleto->invoice_id, 'A Pagou informou uma devolução no Pix vinculado ao boleto ' . $boleto->transaction_id . '. Confira o valor no painel da Pagou e ajuste o pagamento da fatura.');
                return [self::RESULT_PROCESSED, 'Devolução do Pix vinculado registrada na atividade.'];
            }
            return $this->settle_boleto($boleto, pagou_event_amount($data));
        }

        if ($pix) {
            // Pagamento e devolução: o estado vem da consulta, o evento só dispara a sincronização
            return $this->settle_pix($pix, $name === 'qrcode.completed' ? pagou_event_amount($data) : null, $data['e2e_id'] ?? null);
        }

        if ($name === 'qrcode.completed') {
            $avulso = $this->ci->db->where('qr_code', $id)->get(db_prefix() . 'pix_avulso')->row();
            if ($avulso) {
                return $this->settle_avulso($avulso);
            }
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
            $boleto = $this->ci->db->where('invoice_id', $pix->invoice_id)
                ->like('api_response', '"qrcode_id":"' . $id . '"')
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
     * Sincroniza um Pix próprio da fatura com a API: registra o pagamento (status 4, ou 5 que
     * implica pagamento anterior) e a devolução confirmada (refund.status 3).
     */
    public function settle_pix($pix, $event_amount = null, $e2e_id = null)
    {
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

        $body = $response->body;
        if (isset($body['split'])) {
            (new Pagou_split())->track('pix', $pix->transaction_id, $body['split'], $pix->invoice_id);
        }
        $status = isset($body['status']) ? (int) $body['status'] : 0;
        $refund = isset($body['refund']) && is_array($body['refund']) ? $body['refund'] : null;
        $was_paid = $status === 4 || $status === 5 || ($refund !== null && !empty($body['paid_at']));

        if (!$was_paid) {
            if (!$this->has_settlement($pix->transaction_id)) {
                $this->update_pix($pix->id, ['status' => pagou_normalize_status($status, 'pix')]);
            }
            // 1 e 2 ainda podem virar pago: pedir reenvio; 3 e 6 são finais
            return in_array($status, [1, 2], true)
                ? [self::RESULT_RETRY, 'Pagamento ainda não refletido na API.']
                : [self::RESULT_IGNORED, 'Cobrança em estado final ' . pagou_normalize_status($status, 'pix') . '.'];
        }

        $amount = $event_amount ?: (isset($body['amount']) ? (float) $body['amount'] : 0.0);
        $paid_at = $body['paid_at'] ?? null;
        $outcome = $this->register_payment($pix->invoice_id, $amount, 'pagou_pix', $pix->transaction_id, [], $paid_at,
            'Pix confirmado pela Pagou. Cobrança ' . $pix->transaction_id . ($e2e_id ? ' · E2E ' . $e2e_id : ''));
        if ($outcome === false) {
            return [self::RESULT_RETRY, 'Falha ao registrar o pagamento.'];
        }

        $update = ['data_pagamento' => date('Y-m-d H:i:s', $paid_at ? strtotime($paid_at) : time())];
        if ($amount > 0) {
            $update['amount_final'] = $amount;
        }
        if ($e2e_id) {
            $update['e2e_id'] = $e2e_id;
        }
        $update['status'] = 'paid';

        // Devolução: só a confirmada pela API (refund.status 3), pelo valor informado na consulta
        if ($refund !== null && (int) ($refund['status'] ?? 0) === 3) {
            $refunded = round((float) ($refund['amount'] ?? $amount), 2);
            $paid = $amount > 0 ? $amount : (float) $pix->amount_final;
            $update['status'] = ($paid > 0 && $refunded + 0.009 >= $paid) ? 'refunded' : 'partially_refunded';
            if ($refunded > (float) ($pix->refunded_amount ?? 0) + 0.009) {
                $update['refunded_amount'] = $refunded;
                $update['data_estorno'] = date('Y-m-d H:i:s');
                $this->add_invoice_activity($pix->invoice_id, 'Devolução Pix de R$ ' . number_format($refunded, 2, ',', '.')
                    . ' confirmada pela Pagou (cobrança ' . $pix->transaction_id . '). Ajuste o pagamento da fatura manualmente.');
            }
        } elseif ($refund !== null && (int) ($refund['status'] ?? 0) === 2) {
            $update['status'] = 'refund_requested';
        }
        $this->update_pix($pix->id, $update);

        return [self::RESULT_PROCESSED, $outcome === 'recorded' ? 'Pagamento Pix registrado.' : 'Cobrança sincronizada.'];
    }

    /**
     * Confirma um boleto (pago pelo boleto ou pelo Pix vinculado) e dá baixa.
     */
    public function settle_boleto($boleto, $event_amount = null)
    {
        $charge = $this->refresh_boleto($boleto, $http_code);
        if (!$charge) {
            return $http_code === 404
                ? [self::RESULT_IGNORED, 'Boleto não encontrado na conta configurada.']
                : [self::RESULT_RETRY, 'Não foi possível consultar o boleto.'];
        }

        if (!pagou_boleto_api_is_paid($charge)) {
            $status = isset($charge->status) ? (int) $charge->status : 0;
            return in_array($status, [1, 2], true)
                ? [self::RESULT_RETRY, 'Pagamento ainda não refletido na API.']
                : [self::RESULT_IGNORED, 'Boleto em estado final ' . pagou_normalize_status($status, 'boleto') . '.'];
        }

        $amount = $event_amount ?: (isset($charge->amount) ? (float) $charge->amount : 0.0);
        $paid_at = $charge->paid_at ?? null;
        $via_pix = !in_array((int) ($charge->status ?? 0), [4, 8, 9], true);
        $alternate = !empty($charge->payload->qrcode_id) ? [(string) $charge->payload->qrcode_id] : [];

        $outcome = $this->register_payment($boleto->invoice_id, $amount, 'pagou_boleto', $boleto->transaction_id, $alternate, $paid_at,
            ($via_pix ? 'Pago pelo Pix vinculado ao boleto' : 'Boleto pago') . ' · Cobrança ' . $boleto->transaction_id);
        if ($outcome === false) {
            return [self::RESULT_RETRY, 'Falha ao registrar o pagamento.'];
        }
        if (!empty($charge->payload->qrcode_id)) {
            $this->ci->db->where('transaction_id', $charge->payload->qrcode_id)
                ->update(db_prefix() . 'pagou_transactions_pix', ['status' => 'paid']);
        }

        return [self::RESULT_PROCESSED, $outcome === 'recorded' ? 'Pagamento do boleto registrado.' : 'Boleto sincronizado.'];
    }

    /**
     * Sincroniza uma cobrança de cartão com a API; dá baixa só em captured/settled.
     * Cobrança sem ID (criação indeterminada) é recuperada repetindo a mesma requisição.
     */
    public function settle_card($row)
    {
        $card = new Pagou_card();
        if (empty($row->pagou_charge_id)) {
            $row = $card->recover($row);
            if (empty($row->pagou_charge_id)) {
                return $row->status === 'needs_review'
                    ? [self::RESULT_IGNORED, 'Cobrança de cartão marcada para revisão.']
                    : [self::RESULT_RETRY, 'Resultado da cobrança de cartão ainda indeterminado.'];
            }
        }

        $api = Pagou_api::for_gateway(Pagou_card::GATEWAY);
        if (!$api) {
            return [self::RESULT_RETRY, 'Chave do gateway de cartão não configurada.'];
        }
        $response = $api->get_card_charge($row->pagou_charge_id);
        if ($response->not_found()) {
            return [self::RESULT_IGNORED, 'Cobrança de cartão não encontrada na conta configurada.'];
        }
        if (!$response->ok() || !is_array($response->body)) {
            return [self::RESULT_RETRY, 'Não foi possível consultar a cobrança de cartão.'];
        }

        if (isset($response->body['split'])) {
            (new Pagou_split())->track('cartao', $row->pagou_charge_id, $response->body['split'], $row->invoice_id);
        }
        $status = Pagou_card::status($response->body['status'] ?? 'unknown');
        $previous = $row->status;
        $update = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if (!empty($response->body['failure_reason'])) {
            $update['failure_reason'] = mb_substr((string) $response->body['failure_reason'], 0, 255);
        }
        if (in_array($status, Pagou_card::FINAL_STATUSES, true)) {
            $update['request_enc'] = null;
        }

        if (in_array($status, ['captured', 'settled'], true)) {
            $value = isset($response->body['value']) ? (int) $response->body['value'] : (int) $row->value_cents;
            $outcome = $this->register_payment($row->invoice_id, $value / 100, Pagou_card::GATEWAY, $row->pagou_charge_id, [], $response->body['updated_at'] ?? null,
                'Cartão de crédito confirmado pela Pagou · Cobrança ' . $row->pagou_charge_id . ' · ' . (int) $row->installments . 'x');
            if ($outcome === false) {
                return [self::RESULT_RETRY, 'Falha ao registrar o pagamento.'];
            }
            $this->ci->db->where('id', $row->id)->update(db_prefix() . 'pagou_card_charges', $update);
            return [self::RESULT_PROCESSED, 'Pagamento com cartão registrado.'];
        }

        $this->ci->db->where('id', $row->id)->update(db_prefix() . 'pagou_card_charges', $update);

        if (in_array($status, ['reversed', 'charged_back'], true) && $previous !== $status) {
            $this->add_invoice_activity($row->invoice_id, ($status === 'reversed' ? 'Estorno' : 'Chargeback')
                . ' da cobrança de cartão ' . $row->pagou_charge_id . ' informado pela Pagou. Ajuste o pagamento da fatura manualmente.');
        }

        return in_array($status, ['pending', 'authorized', 'unknown'], true)
            ? [self::RESULT_RETRY, 'Cobrança de cartão em ' . $status . '.']
            : [self::RESULT_IGNORED, 'Cobrança de cartão em estado final ' . $status . '.'];
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
        if ($status === 4 || $status === 5) {
            $this->ci->db->where('id', $avulso->id)->update($table, ['status' => $status === 5 ? 'estornado' : 'pago', 'data_modificacao' => date('Y-m-d H:i:s')]);
            log_activity('Pagamento PIX Avulso confirmado pela Pagou [ID: ' . $avulso->id . ']');
            return [self::RESULT_PROCESSED, 'Pix avulso pago.'];
        }
        if (in_array($status, [3, 6], true)) {
            $this->ci->db->where('id', $avulso->id)->update($table, ['status' => 'cancelado', 'data_modificacao' => date('Y-m-d H:i:s')]);
            return [self::RESULT_IGNORED, 'Pix avulso em estado final.'];
        }

        return [self::RESULT_RETRY, 'Pagamento ainda não refletido na API.'];
    }

    /**
     * Registra o pagamento uma única vez por cobrança.
     *
     * A cobrança é reivindicada em pagou_settlements (chave única) e o payments_model->add()
     * roda fora de transação explícita (ele gera PDF, envia e-mail e dispara hooks).
     *
     * @param string[] $alternate_ids IDs equivalentes já usados em registros antigos (ex.: Pix vinculado)
     * @return string|false 'recorded', 'exists', 'skipped' ou false (tentar de novo)
     */
    private function register_payment($invoice_id, $amount, $payment_mode, $charge_id, array $alternate_ids, $paid_at, $note)
    {
        $db = $this->ci->db;
        $table = db_prefix() . 'pagou_settlements';
        $now = date('Y-m-d H:i:s');

        $claimed = $db->insert($table, [
            'invoice_id' => $invoice_id,
            'charge_id'  => $charge_id,
            'status'     => 'claimed',
            'amount'     => round((float) $amount, 2),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$claimed) {
            if ((int) ($db->error()['code'] ?? 0) !== 1062) {
                pagou_log('webhook', 'error', 'Falha ao reivindicar a baixa: ' . ($db->error()['message'] ?? ''));
                return false;
            }
            $existing = $db->where('charge_id', $charge_id)->get($table)->row();
            if ($existing && $existing->status !== 'claimed') {
                return 'exists';
            }
            // Reivindicação abandonada (processo interrompido): retoma depois de 5 minutos
            $db->query('UPDATE `' . $table . '` SET `updated_at` = ? WHERE `charge_id` = ? AND `status` = ? AND `updated_at` < ?',
                [$now, $charge_id, 'claimed', date('Y-m-d H:i:s', time() - 300)]);
            if ($db->affected_rows() !== 1) {
                return false;
            }
        }

        $finish = function ($status, $payment_id = null) use ($db, $table, $charge_id) {
            $db->where('charge_id', $charge_id)->update($table, [
                'status'     => $status,
                'payment_id' => $payment_id,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        };

        // Registro antigo da mesma cobrança (versões anteriores ou ID equivalente)
        $ids = array_values(array_unique(array_merge([$charge_id], $alternate_ids)));
        $previous = $db->where('invoiceid', $invoice_id)->where_in('transactionid', $ids)
            ->get(db_prefix() . 'invoicepaymentrecords')->row();
        if ($previous) {
            $finish('done', $previous->id);
            return 'exists';
        }

        $invoice = $this->ci->invoices_model->get($invoice_id);
        if (!$invoice) {
            $db->where('charge_id', $charge_id)->delete($table);
            pagou_log('webhook', 'error', "Fatura {$invoice_id} não encontrada para registrar pagamento.");
            return false;
        }

        // Fatura cancelada ou já quitada (baixa manual, outro meio): não registra de novo
        $due = pagou_invoice_amount_due($invoice);
        if ((int) $invoice->status === 5 || $due <= 0.005) {
            $finish('skipped');
            $this->add_invoice_activity($invoice_id, 'A Pagou confirmou o pagamento de R$ ' . number_format((float) $amount, 2, ',', '.')
                . ' (cobrança ' . $charge_id . '), mas a fatura já estava ' . ((int) $invoice->status === 5 ? 'cancelada' : 'quitada')
                . '. Nenhum pagamento foi lançado; confira se houve pagamento em duplicidade.');
            return 'skipped';
        }

        $payment = [
            'amount'        => round((float) ($amount > 0 ? $amount : $due), 2),
            'invoiceid'     => $invoice_id,
            'paymentmode'   => $payment_mode,
            'transactionid' => $charge_id,
            'note'          => $note,
        ];
        if ($paid_at && function_exists('_d')) {
            $payment['date'] = _d(date('Y-m-d', strtotime($paid_at)));
        }

        try {
            $payment_id = $this->ci->payments_model->add($payment);
        } catch (\Throwable $e) {
            pagou_log('webhook', 'error', 'Erro ao registrar pagamento: ' . $e->getMessage());
            $payment_id = false;
        }

        if ($payment_id) {
            $finish('done', (int) $payment_id);
            return 'recorded';
        }

        $db->where('charge_id', $charge_id)->delete($table);
        return false;
    }

    private function has_settlement($charge_id)
    {
        return $this->ci->db->where('charge_id', $charge_id)
            ->count_all_results(db_prefix() . 'pagou_settlements') > 0;
    }

    // ================================================================ boleto: snapshot

    /**
     * Atualiza a linha local do boleto e o Pix vinculado a partir da consulta.
     *
     * @param int|null $http_code Recebe o código HTTP da consulta
     * @return object|null
     */
    public function refresh_boleto($boleto, &$http_code = null)
    {
        $http_code = 0;
        $api = Pagou_api::for_gateway('pagou_boleto');
        if (!$api) {
            return null;
        }
        $response = $api->get_charge($boleto->transaction_id);
        $http_code = $response->http_code;
        if (!$response->ok() || !is_array($response->body)) {
            return null;
        }
        $charge = json_decode(json_encode($response->body));
        if (isset($charge->split)) {
            (new Pagou_split())->track('boleto', $boleto->transaction_id, $charge->split, $boleto->invoice_id);
        }

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

        return $charge;
    }

    // ================================================================ reconciliação (cron)

    /**
     * Consulta cobranças em aberto (ou pagas na API sem baixa no Perfex) e dá baixa,
     * cobrindo webhooks perdidos. Roda no máximo a cada 15 minutos, com até 20 consultas de Pix,
     * 20 de boleto e 25 segundos por execução, das menos recentemente conferidas para as mais.
     *
     * @return void
     */
    public function reconcile()
    {
        $last = (int) get_option('pagou_last_reconcile');
        if ($last && time() - $last < 900) {
            return;
        }
        $this->set_option('pagou_last_reconcile', (string) time());

        // Split primeiro, com prazo próprio: pagamento confirmado não significa repasse concluído
        $this->reconcile_split(time() + 8);

        $deadline = time() + 25;
        $settlements = db_prefix() . 'pagou_settlements';

        foreach ([
            ['pagou_transactions_pix', 'settle_pix', '-3 days', ['refund_requested', 'partially_refunded', 'cancel_requested']],
            ['pagou_transactions_boleto', 'settle_boleto', '-60 days', ['cancel_requested']],
        ] as $job) {
            list($table, $method, $window, $extra_statuses) = $job;
            $full = db_prefix() . $table;
            $this->ci->db->select('t.*')
                ->from($full . ' t')
                ->join($settlements . ' s', 's.charge_id = t.transaction_id', 'left')
                ->where('t.date_created >=', date('Y-m-d H:i:s', strtotime($window)))
                ->group_start()
                    ->where_in('t.status', array_merge(self::OPEN_STATUSES, $extra_statuses))
                    ->or_group_start()
                        ->where('t.status', 'paid')
                        ->where('s.id IS NULL', null, false)
                    ->group_end()
                ->group_end();
            if ($table === 'pagou_transactions_pix') {
                $this->ci->db->where('t.boleto_transaction_id IS NULL', null, false);
            }
            $rows = $this->ci->db->order_by('t.last_checked_at IS NULL', 'DESC', false)
                ->order_by('t.last_checked_at', 'ASC')
                ->limit(20)
                ->get()->result();

            foreach ($rows as $row) {
                if (time() > $deadline) {
                    return;
                }
                $this->ci->db->where('id', $row->id)->update($full, ['last_checked_at' => date('Y-m-d H:i:s')]);
                $this->$method($row);
            }
        }

        // Cartão: em andamento (sem limite de idade) e, com menor frequência, capturadas/liquidadas
        // dos últimos 180 dias (estorno ou chargeback pelo painel chegam depois)
        $card_table = db_prefix() . 'pagou_card_charges';
        $open_states = array_diff(Pagou_card::OPEN, ['needs_review']);
        $cards = $this->ci->db->where_in('status', $open_states)
            ->order_by('last_checked_at IS NULL', 'DESC', false)
            ->order_by('last_checked_at', 'ASC')
            ->limit(15)
            ->get($card_table)->result();
        $cards = array_merge($cards, $this->ci->db->where_in('status', ['captured', 'settled'])
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-180 days')))
            ->group_start()
                ->where('last_checked_at IS NULL', null, false)
                ->or_where('last_checked_at <', date('Y-m-d H:i:s', time() - 6 * 3600))
            ->group_end()
            ->order_by('last_checked_at IS NULL', 'DESC', false)
            ->order_by('last_checked_at', 'ASC')
            ->limit(10)
            ->get($card_table)->result());
        foreach ($cards as $row) {
            if (time() > $deadline) {
                return;
            }
            $this->ci->db->where('id', $row->id)->update(db_prefix() . 'pagou_card_charges', ['last_checked_at' => date('Y-m-d H:i:s')]);
            $this->settle_card($row);
        }

        // Retenção: eventos com mais de 90 dias
        $this->ci->db->where('created_at <', date('Y-m-d H:i:s', strtotime('-90 days')))
            ->delete(db_prefix() . 'pagou_webhook_events');
    }

    /**
     * Acompanha distribuições de split pendentes; tira da fila pagamentos encerrados sem pagamento
     * e alerta quando a API não devolve o split de um pagamento confirmado.
     */
    private function reconcile_split($deadline)
    {
        $getters = [
            'pix'    => ['pagou_pix', 'get_pix'],
            'boleto' => ['pagou_boleto', 'get_charge'],
            'cartao' => [Pagou_card::GATEWAY, 'get_card_charge'],
        ];
        $split = new Pagou_split();
        foreach ($split->pending_snapshots(10) as $snapshot) {
            if (time() > $deadline || !isset($getters[$snapshot->method])) {
                break;
            }
            list($gateway_id, $getter) = $getters[$snapshot->method];
            $api = Pagou_api::for_gateway($gateway_id);
            if (!$api) {
                continue;
            }
            $this->ci->db->where('id', $snapshot->id)->update(db_prefix() . 'pagou_split_snapshots', ['last_checked_at' => date('Y-m-d H:i:s')]);
            $response = $api->$getter($snapshot->charge_id);
            if (!$response->ok() || !is_array($response->body)) {
                continue;
            }
            $body = $response->body;
            if (isset($body['split'])) {
                $split->track($snapshot->method, $snapshot->charge_id, $body['split'], $snapshot->invoice_id);
            }

            $status = $body['status'] ?? null;
            $paid = $snapshot->method === 'cartao'
                ? in_array(Pagou_card::status($status), ['captured', 'settled'], true)
                : ($snapshot->method === 'boleto' ? pagou_boleto_api_is_paid(json_decode(json_encode($body))) : in_array((int) $status, [4, 5], true));
            $closed = $snapshot->method === 'cartao'
                ? in_array(Pagou_card::status($status), ['failed', 'canceled'], true)
                : in_array((int) $status, $snapshot->method === 'boleto' ? [3, 6, 7] : [3, 6], true);

            if (!$paid && $closed) {
                $split->close($snapshot->method, $snapshot->charge_id, Pagou_split::STATUS_CLOSED);
            } elseif ($paid && !isset($body['split']) && $snapshot->split_status === null) {
                $split->close($snapshot->method, $snapshot->charge_id, Pagou_split::STATUS_MISSING,
                    'A cobrança ' . $snapshot->charge_id . ' foi paga, mas a Pagou não informou o split enviado. Confira a distribuição no painel da Pagou.');
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

    private function set_option($name, $value)
    {
        $exists = $this->ci->db->where('name', $name)->count_all_results(db_prefix() . 'options') > 0;
        if ($exists) {
            update_option($name, $value);
        } else {
            add_option($name, $value, 0);
        }
    }
}
