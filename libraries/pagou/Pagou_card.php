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
    const OPEN = ['creating', 'uncertain', 'pending', 'authorized', 'unknown', 'needs_review'];
    const MAX_FAILURES_PER_INVOICE = 5;
    const MAX_FAILURES_PER_IP = 10;
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
        $table = db_prefix() . 'pagou_card_charges';
        $open = $this->ci->db->where('invoice_id', $invoice_id)
            ->where_in('status', self::OPEN)
            ->order_by('id', 'DESC')
            ->get($table)->row();
        if ($open) {
            return $open;
        }

        // Capturada na Pagou mas ainda sem baixa no Perfex: também bloqueia
        return $this->ci->db->select('c.*')
            ->from($table . ' c')
            ->join(db_prefix() . 'pagou_settlements s', "s.charge_id = c.pagou_charge_id AND s.status IN ('done', 'skipped')", 'left', false)
            ->where('c.invoice_id', $invoice_id)
            ->where_in('c.status', ['captured', 'settled'])
            ->where('s.id IS NULL', null, false)
            ->order_by('c.id', 'DESC')->limit(1)
            ->get()->row();
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
        if (!filter_var($buyer_ip, FILTER_VALIDATE_IP)) {
            return ['result' => 'error', 'message' => 'Não foi possível identificar a conexão do pagador.'];
        }

        $table = db_prefix() . 'pagou_card_charges';
        $lock = 'pagou_card_invoice_' . $invoice->id;
        if (!pagou_acquire_lock($lock)) {
            return ['result' => 'processing', 'message' => 'Já existe um pagamento em andamento para esta fatura. Aguarde alguns instantes.'];
        }
        try {
            // Dentro da trava: estado e valor da fatura relidos agora
            $this->ci->load->model('invoices_model');
            $invoice = $this->ci->invoices_model->get($invoice->id);
            if (!pagou_invoice_is_open($invoice)) {
                return ['result' => 'error', 'message' => 'Esta fatura não está aberta para pagamento.'];
            }
            $amount = pagou_invoice_amount_due($invoice);
            if ($amount <= 0) {
                return ['result' => 'error', 'message' => 'Esta fatura não tem valor em aberto.'];
            }
            if ($this->open_charge($invoice->id)) {
                return ['result' => 'processing', 'message' => 'Já existe um pagamento com cartão em processamento para esta fatura.'];
            }

            // Limite de recusas (teste de cartões): por fatura e por IP na última hora
            $since = date('Y-m-d H:i:s', time() - 3600);
            $by_invoice = $this->ci->db->where('invoice_id', $invoice->id)->where('status', 'failed')->where('created_at >=', $since)->count_all_results($table);
            $by_ip = $this->ci->db->where('buyer_ip', $buyer_ip)->where('status', 'failed')->where('created_at >=', $since)->count_all_results($table);
            if ($by_invoice >= self::MAX_FAILURES_PER_INVOICE || $by_ip >= self::MAX_FAILURES_PER_IP) {
                return ['result' => 'error', 'message' => 'Muitas tentativas recusadas. Aguarde uma hora ou use outra forma de pagamento.'];
            }

            $customer = $this->customer($invoice, $contact, $missing);
            if ($missing) {
                return ['result' => 'error', 'message' => 'Para pagar com cartão, o cadastro precisa de: ' . implode(', ', $missing) . '. Fale com o estabelecimento para atualizar seus dados.'];
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
            // Split (valores fixos em reais) entra nos bytes idempotentes da criação
            $split = [];
            if (Pagou_split::enabled_for(self::GATEWAY)) {
                $split = (new Pagou_split())->resolve($invoice, 'cartao', $amount, $split_error);
                if ($split_error) {
                    $this->log_activity($invoice->id, 'Pagou: cobrança de cartão não criada. ' . $split_error);
                    return ['result' => 'error', 'message' => 'O pagamento com cartão está indisponível no momento.'];
                }
                if ($split) {
                    $body['split'] = $split;
                }
            }
            $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $key = 'perfex-card-' . bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');

            // Grava chave e corpo exato antes do primeiro envio; sem a linha, não envia
            $inserted = $this->ci->db->insert($table, [
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
            $row_id = $inserted ? (int) $this->ci->db->insert_id() : 0;
            if (!$row_id) {
                return ['result' => 'error', 'message' => 'O pagamento com cartão está indisponível no momento.'];
            }

            $response = $api->create_card_charge($raw, $key);
            $row = $this->apply_create_response($row_id, $response);
            if ($split && $row && $row->pagou_charge_id) {
                (new Pagou_split())->snapshot('cartao', $row->pagou_charge_id, $invoice->id, $split, $response->body['split'] ?? null);
            }

            // Baixa ainda dentro da trava: outra aba só segue depois dela
            return $this->outcome($row);
        } finally {
            pagou_release_lock($lock);
        }
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

        if (!empty($update['pagou_charge_id']) || in_array($update['status'] ?? '', self::FINAL_STATUSES, true)) {
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
        if (!empty($row->pagou_charge_id) || $row->status === 'needs_review') {
            return $row;
        }
        // Criação ainda em curso (outra requisição): não repetir em paralelo
        if ($row->status === 'creating' && strtotime($row->created_at) > time() - 60) {
            return $row;
        }
        $lock = 'pagou_card_invoice_' . $row->invoice_id;
        if (!pagou_acquire_lock($lock, 0)) {
            return $row;
        }
        try {
            return $this->recover_locked($this->ci->db->where('id', $row->id)->get(db_prefix() . 'pagou_card_charges')->row());
        } finally {
            pagou_release_lock($lock);
        }
    }

    private function recover_locked($row)
    {
        if (!$row || !empty($row->pagou_charge_id) || $row->status === 'needs_review') {
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
    public function outcome($row)
    {
        switch ($row ? $row->status : 'failed') {
            case 'captured':
            case 'settled':
                list($result) = (new Pagou_events())->settle_card($row);
                if ($result === Pagou_events::RESULT_PROCESSED) {
                    return ['result' => 'paid', 'message' => 'Pagamento aprovado.'];
                }
                return ['result' => 'processing', 'message' => 'Pagamento aprovado; a confirmação na fatura está em processamento. Não tente de novo.'];
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

    public static function status($value)
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
