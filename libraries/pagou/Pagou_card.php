<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cobranças de cartão de crédito (POST /v1/creditcard/charges), modo com dados completos.
 *
 * - O cartão chega tokenizado do navegador (card_token, holder, expires_at); número e CVV nunca passam aqui.
 * - Cada tentativa de compra tem uma Idempotency-Key própria, gravada com os bytes exatos do corpo
 *   (criptografados) antes do primeiro envio; resultado incerto é recuperado reenviando os mesmos bytes.
 * - A baixa da fatura acontece só em captured/settled (Pagou_events::settle_card).
 */
class Pagou_card
{
    const GATEWAY = 'pagou_cartao';
    const OPEN = ['creating', 'uncertain', 'pending', 'authorized', 'unknown'];
    const FINAL_STATUSES = ['captured', 'settled', 'failed', 'canceled', 'reversed', 'charged_back', 'needs_review'];

    private $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->database();
        $this->ci->load->library('encryption');
    }

    /**
     * Cobrança de cartão ainda em andamento para a fatura, se houver.
     */
    public function open_charge($invoice_id)
    {
        return $this->ci->db->where('invoice_id', $invoice_id)
            ->where_in('status', self::OPEN)
            ->order_by('id', 'DESC')
            ->get(db_prefix() . 'pagou_card_charges')->row();
    }

    /**
     * @param object $invoice
     * @param array  $card     ['card_token', 'holder', 'expires_at']
     * @param int    $installments
     * @param array  $contact  ['email', 'phone'] informados no checkout
     * @param string $buyer_ip IP real do comprador
     * @return array ['result' => 'paid'|'processing'|'failed'|'error', 'message' => string]
     */
    public function charge($invoice, array $card, $installments, array $contact, $buyer_ip)
    {
        $api = Pagou_api::for_gateway(self::GATEWAY);
        if (!$api) {
            return ['result' => 'error', 'message' => 'O pagamento com cartão está indisponível no momento.'];
        }
        if (!pagou_invoice_is_open($invoice)) {
            return ['result' => 'error', 'message' => 'Esta fatura não está aberta para pagamento.'];
        }
        $amount = pagou_invoice_amount_due($invoice);
        if ($amount <= 0) {
            return ['result' => 'error', 'message' => 'Esta fatura não tem valor em aberto.'];
        }
        if (!filter_var($buyer_ip, FILTER_VALIDATE_IP)) {
            return ['result' => 'error', 'message' => 'Não foi possível identificar a conexão do pagador.'];
        }

        $customer = $this->customer($invoice, $contact, $missing);
        if ($missing) {
            return ['result' => 'error', 'message' => 'Para pagar com cartão, complete: ' . implode(', ', $missing) . '.'];
        }

        $table = db_prefix() . 'pagou_card_charges';
        $lock = 'pagou_card_invoice_' . $invoice->id;
        if (!pagou_acquire_lock($lock)) {
            return ['result' => 'processing', 'message' => 'Já existe um pagamento em andamento para esta fatura. Aguarde alguns instantes.'];
        }
        try {
            if ($this->open_charge($invoice->id)) {
                return ['result' => 'processing', 'message' => 'Já existe um pagamento com cartão em processamento para esta fatura.'];
            }

            $description = hooks()->apply_filters('pagou_cartao_description', 'Fatura ' . format_invoice_number($invoice->id), $invoice);
            $body = [
                'customer'     => $customer,
                'card'         => [
                    'card_token' => $card['card_token'],
                    'holder'     => mb_substr($card['holder'], 0, 100),
                    'expires_at' => $card['expires_at'],
                ],
                'value'        => (int) round($amount * 100),
                'installments' => (int) $installments,
                'payday'       => gmdate('Y-m-d'),
                'description'  => mb_substr($description, 0, 255),
                'antifraud'    => ['ip' => $buyer_ip],
            ];
            $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $key = 'perfex-card-' . bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');

            // Grava chave e corpo exato antes do primeiro envio
            $this->ci->db->insert($table, [
                'invoice_id'      => $invoice->id,
                'clientid'        => (int) $invoice->clientid,
                'value_cents'     => $body['value'],
                'installments'    => $body['installments'],
                'status'          => 'creating',
                'idempotency_key' => $key,
                'request_enc'     => $this->ci->encryption->encrypt($raw),
                'buyer_ip'        => $buyer_ip,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $row_id = $this->ci->db->insert_id();

            $response = $api->create_card_charge($raw, $key);
            $row = $this->apply_create_response($row_id, $response);
        } finally {
            pagou_release_lock($lock);
        }

        return $this->outcome($row, $response);
    }

    /**
     * Interpreta a resposta da criação (ou da repetição idempotente) e atualiza a linha local.
     */
    public function apply_create_response($row_id, Pagou_api_response $response)
    {
        $table = db_prefix() . 'pagou_card_charges';
        $code = $response->error_code();
        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if (in_array($response->http_code, [200, 201], true) && is_array($response->body) && !empty($response->body['id'])) {
            $body = $response->body;
            $update += [
                'pagou_charge_id'   => (string) $body['id'],
                'pagou_customer_id' => isset($body['customer_id']) ? (string) $body['customer_id'] : null,
                'pagou_card_id'     => isset($body['card_id']) ? (string) $body['card_id'] : null,
                'status'            => $this->status($body['status'] ?? 'unknown'),
                'failure_reason'    => isset($body['failure_reason']) ? mb_substr((string) $body['failure_reason'], 0, 255) : null,
                'brand'             => isset($body['card']['brand']['name']) ? mb_substr((string) $body['card']['brand']['name'], 0, 30) : null,
                'last4'             => isset($body['card']['last4']) ? substr((string) $body['card']['last4'], -4) : null,
            ];
        } elseif ($response->uncertain() || in_array($code, ['idempotency_in_progress', 'idempotency_outcome_unknown'], true)) {
            $update['status'] = 'uncertain';
        } elseif ($code === 'idempotency_key_conflict') {
            $update['status'] = 'needs_review';
            $update['failure_reason'] = 'Conflito de idempotência: verificar com o suporte da Pagou.';
        } else {
            $update['status'] = 'failed';
            $update['failure_reason'] = mb_substr($response->error_message(), 0, 255);
        }

        if (in_array($update['status'] ?? '', self::FINAL_STATUSES, true)) {
            $update['request_enc'] = null; // o corpo com o token só é guardado enquanto pode ser repetido
        }
        $this->ci->db->where('id', $row_id)->update($table, $update);

        return $this->ci->db->where('id', $row_id)->get($table)->row();
    }

    /**
     * Resultado indeterminado sem ID da cobrança: repete a mesma requisição (mesma chave e bytes)
     * dentro da validade de 24 horas da idempotência; depois disso, marca para revisão.
     */
    public function recover($row)
    {
        if (!empty($row->pagou_charge_id)) {
            return $row;
        }
        $table = db_prefix() . 'pagou_card_charges';
        if (strtotime($row->created_at) < time() - 23 * 3600 || empty($row->request_enc)) {
            $this->ci->db->where('id', $row->id)->update($table, [
                'status'         => 'needs_review',
                'failure_reason' => 'Resultado não confirmado em 24 horas: verificar com o suporte da Pagou (chave ' . $row->idempotency_key . ').',
                'request_enc'    => null,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
            $this->log_activity($row->invoice_id, 'Pagamento com cartão sem confirmação da Pagou em 24 horas. Verifique com o suporte (Idempotency-Key ' . $row->idempotency_key . ').');
            return $this->ci->db->where('id', $row->id)->get($table)->row();
        }

        $api = Pagou_api::for_gateway(self::GATEWAY);
        $raw = $this->ci->encryption->decrypt($row->request_enc);
        if (!$api || !$raw) {
            return $row;
        }

        return $this->apply_create_response($row->id, $api->create_card_charge($raw, $row->idempotency_key));
    }

    /**
     * Mensagem para o checkout a partir do estado da linha.
     */
    public function outcome($row, $response = null)
    {
        switch ($row ? $row->status : 'failed') {
            case 'captured':
            case 'settled':
                pagou_load_libraries();
                (new Pagou_events())->settle_card($row);
                return ['result' => 'paid', 'message' => 'Pagamento aprovado.'];
            case 'failed':
            case 'canceled':
                $reason = $row && $row->failure_reason ? $row->failure_reason : 'Pagamento não aprovado.';
                return ['result' => 'failed', 'message' => 'Pagamento não aprovado: ' . $reason . ' Confira os dados ou use outro cartão.'];
            case 'needs_review':
                return ['result' => 'processing', 'message' => 'Não foi possível confirmar o pagamento. Não tente de novo; o estabelecimento vai verificar.'];
            default:
                return ['result' => 'processing', 'message' => 'Pagamento em processamento. Esta página atualiza sozinha; não tente de novo.'];
        }
    }

    // ================================================================ pagador

    /**
     * Pagador completo (documento, nome, e-mail, telefone, endereço); nada é inventado.
     */
    public function customer($invoice, array $contact, &$missing)
    {
        $this->ci->load->model('clients_model');
        $client = $this->ci->clients_model->get($invoice->clientid);
        $missing = [];

        $name = pagou_client_display_name($client);
        $document = preg_replace('/\D/', '', (string) ($client->vat ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($contact['phone'] ?? ''));

        $street = (string) ($invoice->billing_street ?? '');
        $city = (string) ($invoice->billing_city ?? '');
        $state = (string) ($invoice->billing_state ?? '');
        $zip = (string) ($invoice->billing_zip ?? '');
        if ($street === '' && $client) {
            $street = (string) ($client->billing_street ?: ($client->address ?? ''));
            $city = (string) ($client->billing_city ?: ($client->city ?? ''));
            $state = (string) ($client->billing_state ?: ($client->state ?? ''));
            $zip = (string) ($client->billing_zip ?: ($client->zip ?? ''));
        }
        $street = trim(preg_replace('/\s+/', ' ', strip_tags($street)));
        $number = $client ? $this->custom_field($client->userid, 'customers_numero') : '';
        $neighborhood = $client ? $this->custom_field($client->userid, 'customers_bairro') : '';
        if ($number === '' && preg_match('/^(.+?),\s*(\d+[^,]*)$/', $street, $m)) {
            $street = trim($m[1]);
            $number = trim($m[2]);
        }
        $zip = preg_replace('/\D/', '', $zip);
        $state = strtoupper(trim($state));

        if (mb_strlen($name) < 3) { $missing[] = 'nome ou razão social'; }
        if (!pagou_valid_document($document)) { $missing[] = 'CPF ou CNPJ válido (campo VAT)'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $missing[] = 'e-mail válido'; }
        if (strlen($phone) < 10 || strlen($phone) > 14) { $missing[] = 'telefone com DDD'; }
        if (strlen($zip) !== 8) { $missing[] = 'CEP com 8 dígitos'; }
        if ($street === '') { $missing[] = 'endereço'; }
        if ($number === '') { $missing[] = 'número do endereço'; }
        if ($neighborhood === '') { $missing[] = 'bairro'; }
        if ($city === '') { $missing[] = 'cidade'; }
        if (strlen($state) !== 2) { $missing[] = 'UF com 2 letras'; }

        return [
            'document'      => $document,
            'document_type' => strlen($document) === 14 ? 'cnpj' : 'cpf',
            'name'          => mb_substr($name, 0, 100),
            'email'         => $email,
            'phone'         => $phone,
            'address'       => [
                'zip_code'     => $zip,
                'street'       => mb_substr($street, 0, 100),
                'number'       => mb_substr($number, 0, 10),
                'neighborhood' => mb_substr($neighborhood, 0, 60),
                'city'         => mb_substr($city, 0, 60),
                'state'        => $state,
            ],
        ];
    }

    /**
     * E-mail e telefone sugeridos para o checkout (contato principal e cadastro do cliente).
     */
    public function contact_defaults($invoice)
    {
        $this->ci->load->model('clients_model');
        $client = $this->ci->clients_model->get($invoice->clientid);
        $email = '';
        if (function_exists('get_primary_contact_user_id')) {
            $contact_id = get_primary_contact_user_id($invoice->clientid);
            if ($contact_id) {
                $contact = $this->ci->db->where('id', $contact_id)->get(db_prefix() . 'contacts')->row();
                $email = $contact ? (string) $contact->email : '';
            }
        }

        return ['email' => $email, 'phone' => (string) ($client->phonenumber ?? '')];
    }

    private function custom_field($client_id, $slug)
    {
        $field = $this->ci->db->where('fieldto', 'customers')->where('slug', $slug)->get(db_prefix() . 'customfields')->row();
        if (!$field) {
            return '';
        }
        $value = $this->ci->db->where('relid', $client_id)->where('fieldid', $field->id)->get(db_prefix() . 'customfieldsvalues')->row();

        return $value ? trim((string) $value->value) : '';
    }

    private function status($value)
    {
        $value = strtolower((string) $value);
        $known = ['pending', 'authorized', 'captured', 'canceled', 'failed', 'reversed', 'charged_back', 'settled', 'unknown'];

        return in_array($value, $known, true) ? $value : 'unknown';
    }

    private function log_activity($invoice_id, $description)
    {
        $this->ci->load->model('invoices_model');
        if (method_exists($this->ci->invoices_model, 'log_invoice_activity')) {
            $this->ci->invoices_model->log_invoice_activity($invoice_id, $description);
        } else {
            log_activity($description . ' [Fatura ID: ' . $invoice_id . ']');
        }
    }
}
