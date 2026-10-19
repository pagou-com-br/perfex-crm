<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Gateway Pix da Pagou para faturas do Perfex.
 *
 * Imediato: POST /v1/pix (validade em segundos, sem encargos).
 * Com vencimento: POST /v1/pix/due (validade em dias após o vencimento, multa, juros e desconto).
 * A confirmação do pagamento vem do webhook autenticado ou da consulta (Pagou_events).
 */
class Pagou_pix_gateway extends App_gateway
{
    const INTEREST_TYPES = [
        'percentage_month_calendar_days' => 'Percentual ao mês (dias corridos)',
        'percentage_month_working_days'  => 'Percentual ao mês (dias úteis)',
        'percentage_calendar_days'       => 'Percentual ao dia (dias corridos)',
        'percentage_working_days'        => 'Percentual ao dia (dias úteis)',
        'fixed_calendar_days'            => 'Reais por dia corrido',
        'fixed_working_days'             => 'Reais por dia útil',
        'percentage_year_calendar_days'  => 'Percentual ao ano (dias corridos)',
        'percentage_year_working_days'   => 'Percentual ao ano (dias úteis)',
    ];

    protected $ci;

    public function __construct()
    {
        parent::__construct();
        $this->ci = &get_instance();
        $this->ci->load->database();

        $this->setId('pagou_pix');
        $this->setName('Pagou.com.br PIX');

        $interest_options = [];
        foreach (self::INTEREST_TYPES as $value => $label) {
            $interest_options[] = ['value' => $value, 'label' => $label];
        }

        $this->setSettings([
            ['name' => 'api_token', 'encrypted' => true, 'label' => 'API | Chave de API da Pagou (do mesmo ambiente escolhido abaixo)', 'type' => 'input'],
            ['name' => 'is_production', 'label' => 'AMBIENTE | Produção? (NÃO = Sandbox, para testes)', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'pix_mode', 'label' => 'TIPO DE PIX | "immediate" = QR Code imediato; "due" = Pix com vencimento da fatura (permite multa, juros e desconto)', 'type' => 'input', 'default_value' => 'immediate'],
            ['name' => 'expiration', 'label' => 'PIX IMEDIATO | Validade do QR Code em minutos (mínimo 1)', 'type' => 'input', 'default_value' => '1440'],
            ['name' => 'due_expiration_days', 'label' => 'PIX COM VENCIMENTO | Dias em que o Pix continua pagável após o vencimento (mínimo 1)', 'type' => 'input', 'default_value' => '30'],
            ['name' => 'currencies', 'label' => 'MOEDA | Códigos aceitos', 'type' => 'input', 'default_value' => 'BRL'],
            ['name' => 'enable_logs', 'label' => 'LOGS | Registrar logs da integração (dados sensíveis são mascarados)', 'type' => 'yes_no', 'default_value' => 0],

            ['name' => 'enable_discount', 'label' => '━━━━ DESCONTO (só Pix com vencimento) ━━━━', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'discount_type', 'label' => '     ⤷ Tipo: "fixed" (reais) ou "percentage" (%)', 'type' => 'input', 'default_value' => 'fixed'],
            ['name' => 'discount_value', 'label' => '     ⤷ Valor: em reais (5.00 = R$ 5,00) ou em % (10 = 10%)', 'type' => 'input', 'default_value' => '0'],
            ['name' => 'discount_days_before', 'label' => '     ⤷ Válido até quantos dias antes do vencimento (0 = até o vencimento)', 'type' => 'input', 'default_value' => '0'],

            ['name' => 'enable_fine', 'label' => '━━━━ MULTA POR ATRASO (só Pix com vencimento) ━━━━', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'fine_type', 'label' => '     ⤷ Tipo: "fixed" (reais) ou "percentage" (%)', 'type' => 'input', 'default_value' => 'percentage'],
            ['name' => 'fine_value', 'label' => '     ⤷ Valor: em reais ou em % (2 = 2%)', 'type' => 'input', 'default_value' => '0'],

            ['name' => 'enable_interest', 'label' => '━━━━ JUROS POR ATRASO (só Pix com vencimento) ━━━━', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'interest_type', 'label' => '     ⤷ Tipo: ' . implode(', ', array_keys(self::INTEREST_TYPES)), 'type' => 'input', 'default_value' => 'percentage_month_calendar_days'],
            ['name' => 'interest_value', 'label' => '     ⤷ Valor conforme o tipo (ex.: 1 = 1% ao mês)', 'type' => 'input', 'default_value' => '0'],

            ['name' => 'webhook_url', 'label' => 'WEBHOOK | URL personalizada (deixe em branco para usar a padrão do módulo)', 'type' => 'input', 'default_value' => ''],
            ['name' => 'enable_split', 'label' => 'SPLIT | Aplicar as regras de split da Pagou (exige split habilitado na conta e recebedores autorizados no painel)', 'type' => 'yes_no', 'default_value' => 0],
        ]);

        hooks()->add_action('before_add_payment_gateway', [$this, 'register_gateway']);
    }

    public function register_gateway()
    {
        $this->ci->payment_gateways->add($this);
    }

    public function init_settings()
    {
        parent::init_settings();
        update_option('pagou_logs_api', $this->getSetting('enable_logs') === '1' ? '1' : '0');
    }

    /**
     * Mantido por compatibilidade com chamadas antigas (Pix avulso).
     */
    public function log($level, $message, $data = null)
    {
        pagou_log('pix', $level, $message, $data === null ? [] : ['data' => $data]);
    }

    // ================================================================ pagamento

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

        if (!Pagou_schema::ready()) {
            return $this->fail($back, 'O pagamento por Pix está temporariamente indisponível.');
        }

        if (!pagou_invoice_is_open($invoice)) {
            return $this->fail($back, 'Esta fatura não está aberta para pagamento.');
        }

        $amount = pagou_invoice_amount_due($invoice);
        if ($amount <= 0) {
            return $this->fail($back, 'Esta fatura não tem valor em aberto.');
        }

        $api = Pagou_api::for_gateway($this->getId());
        if (!$api) {
            return $this->fail($back, 'O pagamento por Pix está indisponível no momento.');
        }

        // Uma emissão por vez para a mesma fatura (cliques duplos, abas simultâneas)
        $lock = 'pagou_pix_invoice_' . $invoice->id;
        if (!pagou_acquire_lock($lock)) {
            return $this->fail($back, 'Já existe uma emissão de Pix em andamento para esta fatura. Tente novamente em instantes.');
        }
        try {
            $row = $this->current_charge($api, $invoice, $amount);
            if ($row === false) {
                return $this->fail($back, 'Não foi possível confirmar uma cobrança Pix anterior. Tente novamente em instantes.');
            }
            if (!$row) {
                $row = $this->create_charge($api, $invoice, $amount, $error);
                if (!$row) {
                    return $this->fail($back, $error);
                }
            }
        } finally {
            pagou_release_lock($lock);
        }

        $this->ci->load->view('pagou/payment_pix', [
            'invoice'              => $invoice,
            'qr_code'              => $row->qr_code,
            'qr_code_text'         => $row->qr_code_text,
            'date_created'         => $row->date_created,
            'expiration_seconds'   => $row->expiration_seconds,
            'pagou_transaction_id' => $row->transaction_id,
        ]);

        return true;
    }

    /**
     * Pix reaproveitável da fatura, null para criar outro, false quando não deu para decidir.
     */
    private function current_charge(Pagou_api $api, $invoice, $amount)
    {
        $table = db_prefix() . 'pagou_transactions_pix';
        $rows = $this->ci->db->where('invoice_id', $invoice->id)
            ->where('boleto_transaction_id IS NULL', null, false)
            ->where_in('status', ['creating', 'uncertain', 'pending', 'active', '1', '2'])
            ->order_by('id', 'DESC')
            ->get($table)->result();

        foreach ($rows as $row) {
            if (in_array($row->status, ['creating', 'uncertain'], true)) {
                $adopted = $this->recover_uncertain($api, $row);
                if ($adopted === false) {
                    return false;
                }
                if ($adopted === null) {
                    continue; // não foi criada na Pagou
                }
                $row = $adopted;
            }

            $expired = strtotime($row->date_created) + (int) $row->expiration_seconds <= time() + 60;
            $same_amount = abs((float) $row->amount - $amount) < 0.005;
            if (!$expired && $same_amount && $row->qr_code_text !== '') {
                return $row;
            }

            // Vencido ou com valor diferente: cancela antes de emitir outro
            $cancel = $api->cancel_pix($row->transaction_id);
            if ($cancel->uncertain()) {
                return false;
            }
            // Qualquer resposta: o estado vem da consulta (404 também pode ser "não elegível", ex.: pago)
            $check = $api->get_pix($row->transaction_id);
            if (!$check->ok() || !is_array($check->body)) {
                return false;
            }
            $status = (int) ($check->body['status'] ?? 0);
            if ($status === 4 || $status === 5) {
                // Pago enquanto isso: a baixa vem pelo processador de eventos
                (new Pagou_events())->settle_pix($row);
                return false;
            }
            if ($cancel->http_code === 204 && in_array($status, [1, 2], true)) {
                // Pedido de cancelamento aceito; a reconciliação acompanha até o estado final
                $this->ci->db->where('id', $row->id)->update($table, ['status' => 'cancel_requested']);
                continue;
            }
            if (in_array($status, [3, 6], true) || $expired) {
                // Encerrado na Pagou, ou QR imediato já vencido e não pago
                $this->ci->db->where('id', $row->id)->update($table, ['status' => in_array($status, [3, 6], true) ? pagou_normalize_status($status) : 'expired']);
                continue;
            }

            return false;
        }

        return null;
    }

    /**
     * Criação com resultado indeterminado: procura pelo customer_code antes de emitir outra.
     *
     * @return object|null|false Linha adotada, null se não existe na Pagou, false se a busca falhou
     */
    private function recover_uncertain(Pagou_api $api, $row)
    {
        $table = db_prefix() . 'pagou_transactions_pix';
        $known = array_column($this->ci->db->select('transaction_id')->where('invoice_id', $row->invoice_id)->get($table)->result_array(), 'transaction_id');
        $item = pagou_find_created_charge(function ($filters) use ($api) {
            return $api->list_pix($filters);
        }, $row, $known);

        if ($item === false) {
            return false;
        }
        if ($item) {
            $full = $api->get_pix($item['id']);
            $body = $full->ok() && is_array($full->body) ? $full->body : $item;
            $this->ci->db->where('id', $row->id)->update($table, [
                'transaction_id' => $item['id'],
                'qr_code'        => $body['payload']['image'] ?? '',
                'qr_code_text'   => $body['payload']['data'] ?? '',
                'status'         => pagou_normalize_status($body['status'] ?? 1),
                'api_response'   => json_encode(pagou_redact($body)),
            ]);
            return $this->ci->db->where('id', $row->id)->get($table)->row();
        }

        // Só é conclusivo depois que a janela de busca terminou
        if (time() < strtotime($row->date_created) + 360) {
            return false;
        }
        $this->ci->db->where('id', $row->id)->update($table, ['status' => 'failed']);

        return null;
    }

    private function create_charge(Pagou_api $api, $invoice, $amount, &$error)
    {
        $payer_name = mb_substr($this->client_name($invoice), 0, 100);
        $document = $this->client_document($invoice);
        if (!pagou_valid_document($document)) {
            $error = 'Para pagar por Pix, o cadastro precisa de um CPF ou CNPJ válido. Atualize o campo CPF/CNPJ (VAT) do cliente.';
            return null;
        }

        $mode = $this->getSetting('pix_mode') === 'due' ? 'due' : 'immediate';
        $description = hooks()->apply_filters(
            'pagou_pix_description',
            'Fatura ' . format_invoice_number($invoice->id),
            $invoice
        );
        $notification_url = trim((string) $this->getSetting('webhook_url'));

        $body = [
            'amount'      => $amount,
            'description' => mb_substr($description, 0, 140),
            'payer'       => ['name' => $payer_name, 'document' => $document],
            'metadata'    => [
                ['key' => 'invoice_id', 'value' => (string) $invoice->id],
                ['key' => 'invoice_number', 'value' => (string) format_invoice_number($invoice->id)],
                ['key' => 'source', 'value' => 'perfex_crm'],
            ],
            'notification_url' => $notification_url !== '' ? $notification_url : site_url('pagou/pagou_webhook/process'),
        ];

        if ($mode === 'due') {
            $due_date = pagou_effective_due_date($invoice->duedate);
            $days = max(1, (int) $this->getSetting('due_expiration_days'));
            $body['due_date'] = $due_date;
            $body['expiration'] = $days;
            $body += $this->charges($due_date);
            $expiration_seconds = max(60, strtotime($due_date . ' 23:59:59 +' . $days . ' days') - time());
        } else {
            $expiration_seconds = max(60, (int) $this->getSetting('expiration') * 60);
            $body['expiration'] = $expiration_seconds;
        }

        // Split: regras do Perfex resolvidas na criação; regra inválida bloqueia a emissão
        $split = [];
        if (Pagou_split::enabled_for('pagou_pix')) {
            $split = (new Pagou_split())->resolve($invoice, 'pix', $amount, $split_error);
            if ($split_error) {
                $this->ci->load->model('invoices_model');
                $this->ci->invoices_model->log_invoice_activity($invoice->id, 'Pagou: cobrança não emitida. ' . $split_error);
                $error = 'O pagamento por Pix está indisponível no momento (configuração de split).';
                return null;
            }
            if ($split) {
                $body['split'] = $split;
            }
        }

        // Linha local antes da chamada: permite reconciliar se o resultado for indeterminado
        $table = db_prefix() . 'pagou_transactions_pix';
        $this->ci->db->insert($table, [
            'invoice_id'         => $invoice->id,
            'cliente'            => $payer_name,
            'doc'                => $document,
            'transaction_id'     => 'creating-' . bin2hex(random_bytes(8)),
            'payment_method'     => 'pix',
            'qr_code'            => '',
            'qr_code_text'       => '',
            'amount'             => $amount,
            'amount_final'       => $amount,
            'status'             => 'creating',
            'date_created'       => date('Y-m-d H:i:s'),
            'expiration_seconds' => $expiration_seconds,
        ]);
        $row_id = $this->ci->db->insert_id();
        $customer_code = 'perfex-' . $invoice->id . '-' . $row_id;
        $body['customer_code'] = $customer_code;
        $this->ci->db->where('id', $row_id)->update($table, [
            'customer_code' => $customer_code,
            'api_request'   => json_encode(pagou_redact($body)),
        ]);

        $response = $mode === 'due' ? $api->create_pix_due($body) : $api->create_pix($body);

        if ($response->http_code === 201 && !empty($response->body['id'])) {
            $result = $response->body;
            $this->ci->db->where('id', $row_id)->update($table, [
                'transaction_id' => $result['id'],
                'qr_code'        => $result['payload']['image'] ?? '',
                'qr_code_text'   => $result['payload']['data'] ?? '',
                'status'         => isset($result['status']) ? pagou_normalize_status($result['status']) : 'active',
                'api_response'   => json_encode(pagou_redact($result)),
            ]);
            (new Pagou_split())->snapshot('pix', $result['id'], $invoice->id, $split, $result['split'] ?? null);
            return $this->ci->db->where('id', $row_id)->get($table)->row();
        }

        if ($response->uncertain() || $response->http_code === 409) {
            $this->ci->db->where('id', $row_id)->update($table, ['status' => 'uncertain']);
            $error = 'A Pagou não confirmou a criação do Pix. Aguarde alguns instantes e abra a fatura de novo; não pague um código diferente sem conferir.';
            return null;
        }

        $this->ci->db->where('id', $row_id)->update($table, ['status' => 'failed', 'api_response' => json_encode($response->body)]);
        $error = 'Não foi possível gerar o Pix: ' . $response->error_message();

        return null;
    }

    /**
     * Multa, juros e desconto do Pix com vencimento, no formato {type, amount}.
     */
    private function charges($due_date)
    {
        $out = [];
        $number = function ($v) {
            return round((float) str_replace(',', '.', (string) $v), 2);
        };

        if ($this->getSetting('enable_fine') === '1' && $number($this->getSetting('fine_value')) > 0) {
            $out['fine'] = [
                'type'   => $this->getSetting('fine_type') === 'fixed' ? 'fixed' : 'percentage',
                'amount' => $number($this->getSetting('fine_value')),
            ];
        }
        if ($this->getSetting('enable_interest') === '1' && $number($this->getSetting('interest_value')) > 0) {
            $type = $this->getSetting('interest_type');
            $out['interest'] = [
                'type'   => array_key_exists($type, self::INTEREST_TYPES) ? $type : 'percentage_month_calendar_days',
                'amount' => $number($this->getSetting('interest_value')),
            ];
        }
        if ($this->getSetting('enable_discount') === '1' && $number($this->getSetting('discount_value')) > 0) {
            $limit = date('Y-m-d', strtotime($due_date . ' -' . max(0, (int) $this->getSetting('discount_days_before')) . ' days'));
            if ($limit >= max(date('Y-m-d'), gmdate('Y-m-d'))) {
                $out['discount'] = [
                    'type'       => $this->getSetting('discount_type') === 'percentage' ? 'percentage' : 'fixed',
                    'amount'     => $number($this->getSetting('discount_value')),
                    'limit_date' => $limit,
                ];
            }
        }

        return $out;
    }

    private function fail($back, $message)
    {
        pagou_log('pix', 'error', $message);
        set_alert('danger', $message);
        redirect($back);
        return false;
    }

    // ================================================================ dados do cliente

    private function client($invoice)
    {
        if (isset($invoice->client) && is_object($invoice->client)) {
            return $invoice->client;
        }
        $this->ci->load->model('clients_model');
        return $this->ci->clients_model->get($invoice->clientid);
    }

    private function client_name($invoice)
    {
        $name = pagou_client_display_name($this->client($invoice));
        return $name !== '' ? $name : 'Cliente';
    }

    private function client_document($invoice)
    {
        $client = $this->client($invoice);
        return preg_replace('/\D/', '', (string) ($client->vat ?? ''));
    }
}
