<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Gateway de boleto da Pagou para faturas do Perfex (POST /v1/charges).
 *
 * O registro é assíncrono (linha digitável chega por charge.created ou pela consulta) e o boleto
 * pode trazer Pix vinculado. A baixa vem do processador de eventos (Pagou_events).
 * Chamado pela página da fatura e, em modo automático, pelo cron e pelos hooks de fatura.
 */
class Pagou_boleto_gateway extends App_gateway
{
    const UNCERTAIN = 'uncertain';
    // Nada foi emitido e vale tentar depois (sem chave, banco não atualizado, trava ocupada)
    const LATER = 'later';

    protected $ci;

    public function __construct()
    {
        parent::__construct();
        $this->ci = &get_instance();
        $this->ci->load->database();

        $this->setId('pagou_boleto');
        $this->setName('Pagou.com.br Boleto');

        $this->setSettings([
            ['name' => 'api_token', 'encrypted' => true, 'label' => 'API | Chave de API da Pagou (do mesmo ambiente escolhido abaixo)', 'type' => 'input'],
            ['name' => 'is_production', 'label' => 'AMBIENTE | Produção? (NÃO = Sandbox, para testes)', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'currencies', 'label' => 'MOEDA | Códigos aceitos', 'type' => 'input', 'default_value' => 'BRL'],
            ['name' => 'enable_logs', 'label' => 'LOGS | Registrar logs da integração (dados sensíveis são mascarados)', 'type' => 'yes_no', 'default_value' => 0],

            ['name' => 'enable_discount', 'label' => '━━━━ DESCONTO ━━━━', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'discount_type', 'label' => '     ⤷ Tipo: "fixed" (reais) ou "percentage" (%)', 'type' => 'input', 'default_value' => 'fixed'],
            ['name' => 'discount_value', 'label' => '     ⤷ Valor: em reais (5.00 = R$ 5,00) ou em % (10 = 10%)', 'type' => 'input', 'default_value' => '0'],
            ['name' => 'discount_limit_date', 'label' => '     ⤷ Válido até quantos dias antes do vencimento (0 = até o vencimento)', 'type' => 'input', 'default_value' => '0'],

            ['name' => 'enable_fine', 'label' => '━━━━ MULTA POR ATRASO ━━━━', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'fine_value', 'label' => '     ⤷ Percentual de multa (ex.: 2 = 2%)', 'type' => 'input', 'default_value' => '2'],

            ['name' => 'enable_interest', 'label' => '━━━━ JUROS POR ATRASO ━━━━', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'interest_value', 'label' => '     ⤷ Juros: percentual MENSAL, cobrado por dia de atraso (ex.: 1 = 1% ao mês)', 'type' => 'input', 'default_value' => '1'],

            ['name' => 'enable_grace_period', 'label' => '━━━━ CARÊNCIA APÓS VENCIMENTO ━━━━', 'type' => 'yes_no', 'default_value' => 0],
            ['name' => 'grace_period_days', 'label' => '     ⤷ Dias em que o boleto continua pagável após o vencimento (1 a 30; padrão 1)', 'type' => 'input', 'default_value' => '1'],

            ['name' => 'webhook_url', 'label' => 'WEBHOOK | URL personalizada (deixe em branco para usar a padrão do módulo)', 'type' => 'input', 'default_value' => ''],
            ['name' => 'enable_pix_with_boleto', 'label' => 'PIX | Mostrar também o Pix vinculado ao boleto, quando a Pagou fornecer', 'type' => 'yes_no', 'default_value' => 1],
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

    public function log($level, $message, $data = null)
    {
        pagou_log('boleto', $level, $message, $data === null ? [] : ['data' => $data]);
    }

    // ================================================================ pagamento

    /**
     * @param array $data ['invoice' => object, 'auto_generated' => bool]
     * @return bool|string|array Página: true/false. Automático: ['success' => true, ...], 'uncertain' ou false.
     */
    public function process_payment($data)
    {
        if (empty($data['invoice'])) {
            set_alert('danger', _l('invoice_not_found'));
            redirect(site_url());
            return false;
        }

        pagou_load_libraries();
        $invoice = $data['invoice'];
        $auto = !empty($data['auto_generated']);

        if (!Pagou_schema::ready()) {
            return $this->fail($invoice, $auto, 'O pagamento por boleto está temporariamente indisponível.', self::LATER);
        }
        if (!pagou_invoice_is_open($invoice)) {
            return $this->fail($invoice, $auto, 'Esta fatura não está aberta para pagamento.');
        }
        $amount = pagou_invoice_amount_due($invoice);
        if ($amount < 5) {
            return $this->fail($invoice, $auto, 'O boleto exige valor mínimo de R$ 5,00.');
        }
        $api = Pagou_api::for_gateway($this->getId());
        if (!$api) {
            return $this->fail($invoice, $auto, 'O pagamento por boleto está indisponível no momento.', self::LATER);
        }

        $lock = 'pagou_boleto_invoice_' . $invoice->id;
        if (!pagou_acquire_lock($lock)) {
            return $this->fail($invoice, $auto, 'Já existe uma emissão de boleto em andamento para esta fatura. Tente novamente em instantes.', self::LATER);
        }
        try {
            $row = $this->current_boleto($api, $invoice, $amount);
            if (is_string($row)) {
                return $this->fail($invoice, $auto, $row, true);
            }
            if (!$row) {
                $row = $this->create_boleto($api, $invoice, $amount, $error, $uncertain);
                if (!$row) {
                    return $this->fail($invoice, $auto, $error, $uncertain);
                }
            }
        } finally {
            pagou_release_lock($lock);
        }

        if ($auto) {
            return ['success' => true, 'transaction_id' => $row->transaction_id];
        }

        return $this->load_boleto_view($invoice, $row);
    }

    /**
     * Boleto reaproveitável, null para emitir outro, ou string com o motivo para não decidir agora.
     */
    private function current_boleto(Pagou_api $api, $invoice, $amount)
    {
        $table = db_prefix() . 'pagou_transactions_boleto';
        $events = new Pagou_events();
        $rows = $this->ci->db->where('invoice_id', $invoice->id)
            ->where_in('status', ['creating', 'uncertain', 'pending', 'active', '1', '2'])
            ->order_by('id', 'DESC')
            ->get($table)->result();

        foreach ($rows as $row) {
            if (in_array($row->status, ['creating', 'uncertain'], true)) {
                $adopted = $this->recover_uncertain($api, $row);
                if ($adopted === false) {
                    return 'Não foi possível confirmar um boleto anterior desta fatura. Tente novamente em instantes.';
                }
                if ($adopted === null) {
                    continue;
                }
                $row = $adopted;
            }

            $charge = $events->refresh_boleto($row);
            if (!$charge) {
                // Consulta indisponível: mantém o boleto conhecido em vez de emitir outro às cegas
                return $row;
            }
            if (pagou_boleto_api_is_paid($charge)) {
                $events->settle_boleto($this->reload($row));
                return $this->reload($row);
            }

            $status = (int) ($charge->status ?? 0);
            if (in_array($status, [3, 5, 6, 7], true)) {
                continue; // encerrado na Pagou
            }

            $same_amount = abs((float) $row->amount - $amount) < 0.005;
            if (!$this->is_expired($row, $charge) && $same_amount) {
                return $this->reload($row);
            }

            // Vencido (após a carência) ou com valor diferente: cancela antes de reemitir
            $cancel = $api->cancel_charge($row->transaction_id);
            if ($cancel->uncertain()) {
                return 'Não foi possível cancelar o boleto anterior. Tente novamente em instantes.';
            }
            if ($cancel->http_code !== 204) {
                // Cancelamento recusado (ex.: CHARGE_2) ou inexistente: confere o estado antes de seguir
                $again = $events->refresh_boleto($row);
                if ($again && pagou_boleto_api_is_paid($again)) {
                    $events->settle_boleto($this->reload($row));
                    return $this->reload($row);
                }
                if (!$this->is_expired($row, $again ?: $charge)) {
                    // Cancelamento recusado e boleto ainda pagável: segue com o boleto vigente
                    set_alert('warning', 'O valor da fatura mudou, mas o boleto já emitido não pôde ser substituído. Ele continua válido para pagamento.');
                    return $this->reload($row);
                }
                $this->ci->db->where('id', $row->id)->update($table, ['status' => 'expired']);
                continue;
            }
            // Pedido de cancelamento aceito (204): a reconciliação acompanha até o estado final
            $this->ci->db->where('id', $row->id)->update($table, ['status' => 'cancel_requested']);
        }

        return null;
    }

    /**
     * @return object|null|false Linha adotada, null se não existe na Pagou, false se a busca falhou
     */
    private function recover_uncertain(Pagou_api $api, $row)
    {
        $table = db_prefix() . 'pagou_transactions_boleto';
        $known = array_column($this->ci->db->select('transaction_id')->where('invoice_id', $row->invoice_id)->get($table)->result_array(), 'transaction_id');
        $item = pagou_find_created_charge(function ($filters) use ($api) {
            return $api->list_charges($filters);
        }, $row, $known);

        if ($item === false) {
            return false;
        }
        if ($item) {
            $this->ci->db->where('id', $row->id)->update($table, [
                'transaction_id' => $item['id'],
                'status'         => pagou_normalize_status($item['status'] ?? 1, 'boleto'),
                'api_response'   => json_encode(pagou_redact($item)),
            ]);
            return $this->reload($row);
        }
        if (time() < strtotime($row->date_created) + 360) {
            return false;
        }
        $this->ci->db->where('id', $row->id)->update($table, ['status' => 'failed']);

        return null;
    }

    private function create_boleto(Pagou_api $api, $invoice, $amount, &$error, &$uncertain)
    {
        $uncertain = false;
        $payer = $this->payer($invoice, $missing);
        if ($missing) {
            $error = 'Para gerar o boleto, complete o cadastro do cliente: ' . implode(', ', $missing) . '.';
            return null;
        }

        $due_date = pagou_effective_due_date($invoice->duedate);
        $description = hooks()->apply_filters('pagou_boleto_description', 'Fatura ' . format_invoice_number($invoice->id), $invoice);
        $notification_url = trim((string) $this->getSetting('webhook_url'));

        $body = [
            'amount'           => $amount,
            'description'      => mb_substr($description, 0, 140),
            'due_date'         => $due_date,
            'grace_period'     => $this->grace_days(),
            'payer'            => $payer,
            'notification_url' => $notification_url !== '' ? $notification_url : site_url('pagou/pagou_webhook/process'),
            'metadata'         => [
                ['key' => 'invoice_id', 'value' => (string) $invoice->id],
                ['key' => 'invoice_number', 'value' => (string) format_invoice_number($invoice->id)],
                ['key' => 'source', 'value' => 'perfex_crm'],
            ],
        ] + $this->charges($due_date);

        $table = db_prefix() . 'pagou_transactions_boleto';
        $this->ci->db->insert($table, [
            'invoice_id'     => $invoice->id,
            'cliente'        => $payer['name'],
            'doc'            => $payer['document'],
            'transaction_id' => 'creating-' . bin2hex(random_bytes(8)),
            'payment_method' => $this->getId(),
            'barcode'        => '',
            'barcode_url'    => '',
            'boleto_url'     => '',
            'amount'         => $amount,
            'amount_final'   => $amount,
            'status'         => 'creating',
            'date_created'   => date('Y-m-d H:i:s'),
            'due_date'       => $due_date,
        ]);
        $row_id = $this->ci->db->insert_id();
        $body['customer_code'] = 'perfex-' . $invoice->id . '-b' . $row_id;
        $this->ci->db->where('id', $row_id)->update($table, [
            'customer_code' => $body['customer_code'],
            'api_request'   => json_encode(pagou_redact($body)),
        ]);

        $response = $api->create_charge($body);

        if ($response->http_code === 201 && !empty($response->body['id'])) {
            $this->ci->db->where('id', $row_id)->update($table, [
                'transaction_id' => $response->body['id'],
                'status'         => pagou_normalize_status($response->body['status'] ?? 1, 'boleto'),
                'api_response'   => json_encode(pagou_redact($response->body)),
            ]);
            $row = $this->ci->db->where('id', $row_id)->get($table)->row();
            pagou_save_linked_pix($invoice->id, json_decode(json_encode($response->body)));
            return $row;
        }

        if ($response->uncertain() || $response->http_code === 409) {
            $this->ci->db->where('id', $row_id)->update($table, ['status' => 'uncertain']);
            $uncertain = true;
            $error = 'A Pagou não confirmou a emissão do boleto. Aguarde alguns instantes e abra a fatura de novo.';
            return null;
        }

        $this->ci->db->where('id', $row_id)->update($table, ['status' => 'failed', 'api_response' => json_encode(pagou_redact($response->body))]);
        $error = 'Não foi possível gerar o boleto: ' . $response->error_message();

        return null;
    }

    /**
     * Multa e juros como números (percentuais); desconto {type, amount, limit_date}.
     */
    private function charges($due_date)
    {
        $out = [];
        $number = function ($v) {
            return round((float) str_replace(',', '.', (string) $v), 2);
        };
        if ($this->getSetting('enable_fine') === '1' && $number($this->getSetting('fine_value')) > 0) {
            $out['fine'] = min(max($number($this->getSetting('fine_value')), 0.1), 100);
        }
        if ($this->getSetting('enable_interest') === '1' && $number($this->getSetting('interest_value')) > 0) {
            $out['interest'] = min(max($number($this->getSetting('interest_value')), 0.1), 100);
        }
        if ($this->getSetting('enable_discount') === '1' && $number($this->getSetting('discount_value')) > 0) {
            $limit = date('Y-m-d', strtotime($due_date . ' -' . max(0, (int) $this->getSetting('discount_limit_date')) . ' days'));
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

    private function grace_days()
    {
        if ($this->getSetting('enable_grace_period') === '1' && is_numeric($this->getSetting('grace_period_days'))) {
            return min(max((int) $this->getSetting('grace_period_days'), 1), 30);
        }
        return 1;
    }

    /**
     * O boleto deixa de ser pagável depois do vencimento informado pela API (due_at) mais a carência.
     */
    private function is_expired($row, $charge)
    {
        $due = null;
        if (!empty($charge->due_at)) {
            // Data do vencimento como informada (sem conversão de fuso)
            $due = substr((string) $charge->due_at, 0, 10);
        } elseif (!empty($row->api_request)) {
            $request = json_decode($row->api_request, true);
            $due = $request['due_date'] ?? null;
        }
        $due = $due ?: $row->due_date;
        if (empty($due)) {
            return false;
        }

        return time() > strtotime($due . ' 23:59:59 +' . $this->grace_days() . ' days');
    }

    private function reload($row)
    {
        return $this->ci->db->where('id', $row->id)->get(db_prefix() . 'pagou_transactions_boleto')->row();
    }

    /**
     * Encerra com erro: no modo automático só registra; na página mostra o alerta e volta para a fatura.
     *
     * @return bool|string false, ou 'uncertain' no modo automático quando o resultado é indeterminado
     */
    private function fail($invoice, $auto, $message, $uncertain = false)
    {
        pagou_log('boleto', 'error', 'Fatura ID ' . $invoice->id . ': ' . $message);
        if ($auto) {
            if ($uncertain === self::LATER) {
                return self::LATER;
            }
            return $uncertain ? self::UNCERTAIN : false;
        }
        set_alert('danger', $message);
        redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        return false;
    }

    // ================================================================ exibição

    public function load_boleto_view($invoice, $transaction)
    {
        if (is_numeric($transaction)) {
            $transaction = $this->ci->db->where('id', $transaction)->get(db_prefix() . 'pagou_transactions_boleto')->row();
        }
        if (!$transaction) {
            return false;
        }

        $pix = null;
        if ($this->getSetting('enable_pix_with_boleto') !== '0') {
            $pix = $this->ci->db->where('boleto_transaction_id', $transaction->transaction_id)
                ->get(db_prefix() . 'pagou_transactions_pix')->row();
        }

        $line = (string) ($transaction->barcode_url ?? '');
        $bar_code = preg_replace('/\D/', '', (string) ($transaction->barcode ?? ''));

        $this->ci->load->view('pagou/payment_boleto', [
            'invoice'              => $invoice,
            'line'                 => $line,
            'barcode_svg'          => $this->barcode_svg($bar_code),
            'is_registered'        => $line !== '',
            'due_date'             => $transaction->due_date,
            'pagou_transaction_id' => $transaction->transaction_id,
            'boleto_html_url'      => 'https://fatura.pagou.com.br/boleto/' . rawurlencode($transaction->transaction_id),
            'boleto_pdf_url'       => 'https://fatura.pagou.com.br/boleto/pdf/' . rawurlencode($transaction->transaction_id),
            'qr_code_text'         => $pix ? (string) $pix->qr_code_text : '',
            'qr_code_img'          => $pix ? (string) $pix->qr_code : '',
            'status_url'           => site_url('pagou/payment_status/check/' . (int) $invoice->id . '/' . $invoice->hash),
        ]);

        return true;
    }

    /**
     * Código de barras do boleto (Interleaved 2 of 5, 44 dígitos) em SVG.
     */
    private function barcode_svg($bar_code)
    {
        if (strlen($bar_code) !== 44) {
            return '';
        }
        try {
            require_once __DIR__ . '/Picqer/autoload.php';
            $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
            return $generator->getBarcode($bar_code, $generator::TYPE_INTERLEAVED_2_5, 1, 50);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ================================================================ pagador

    /**
     * Pagador com endereço completo; $missing recebe os campos ausentes (nada é inventado).
     */
    private function payer($invoice, &$missing)
    {
        $this->ci->load->model('clients_model');
        $client = $this->ci->clients_model->get($invoice->clientid);
        $missing = [];

        $name = pagou_client_display_name($client);
        $document = preg_replace('/\D/', '', (string) ($client->vat ?? ''));

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
        $state = $this->state_code($state);

        if ($name === '') { $missing[] = 'nome ou razão social'; }
        if (!pagou_valid_document($document)) { $missing[] = 'CPF ou CNPJ válido (campo VAT)'; }
        if (strlen($zip) !== 8) { $missing[] = 'CEP com 8 dígitos'; }
        if ($street === '') { $missing[] = 'endereço'; }
        if ($number === '') { $missing[] = 'número do endereço'; }
        if ($neighborhood === '') { $missing[] = 'bairro'; }
        if ($city === '') { $missing[] = 'cidade'; }
        if (strlen($state) !== 2) { $missing[] = 'UF com 2 letras'; }

        return [
            'name'         => mb_substr($name, 0, 100),
            'document'     => $document,
            'zip'          => $zip,
            'street'       => mb_substr($street, 0, 100),
            'city'         => mb_substr($city, 0, 60),
            'state'        => $state,
            'number'       => mb_substr($number, 0, 10),
            'neighborhood' => mb_substr($neighborhood, 0, 60),
        ];
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

    private function state_code($state)
    {
        $state = trim((string) $state);
        if (strlen($state) === 2) {
            return strtoupper($state);
        }
        $map = [
            'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM', 'BAHIA' => 'BA', 'CEARA' => 'CE',
            'DISTRITO FEDERAL' => 'DF', 'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO', 'MARANHAO' => 'MA', 'MATO GROSSO' => 'MT',
            'MATO GROSSO DO SUL' => 'MS', 'MINAS GERAIS' => 'MG', 'PARA' => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR',
            'PERNAMBUCO' => 'PE', 'PIAUI' => 'PI', 'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN',
            'RIO GRANDE DO SUL' => 'RS', 'RONDONIA' => 'RO', 'RORAIMA' => 'RR', 'SANTA CATARINA' => 'SC', 'SAO PAULO' => 'SP',
            'SERGIPE' => 'SE', 'TOCANTINS' => 'TO',
        ];
        $key = strtoupper(strtr($state, ['á' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Ú' => 'U', 'Ç' => 'C']));

        return $map[$key] ?? '';
    }

    /**
     * Mantido para compatibilidade com chamadas antigas.
     */
    public function save_pix_data_from_boleto($invoice_id, $boleto_data)
    {
        return pagou_save_linked_pix($invoice_id, $boleto_data);
    }
}
