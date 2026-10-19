<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Split de pagamento: resolve as regras do Perfex no array `split` da criação e acompanha a distribuição.
 *
 * - Recebedores são clientes Pagou (UUID) autorizados no painel da Pagou (Configurações → Split).
 * - Se o cliente tem regra para o meio, só as regras dele valem (inclusive "sem split"); senão, as globais.
 * - `fixed` em reais (inclusive no cartão), proporcional ao saldo cobrado; `percentage` em %, sobre o bruto.
 * - Validação local: até 10 recebedores, sem repetição, e margem mínima para as tarifas.
 * - O estado do split (split.status) é separado do pagamento e acompanhado pela reconciliação.
 */
class Pagou_split
{
    const METHODS = ['pix', 'boleto', 'cartao'];
    const MAX_FIXED = 9999999.99;

    // Estados locais além dos da API: 0 = a API não devolveu split; 3 = pagamento encerrado sem pagar
    const STATUS_MISSING = 0;
    const STATUS_CLOSED = 3;

    private $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->database();
    }

    public static function enabled_for($gateway_id)
    {
        return get_option('paymentmethod_' . $gateway_id . '_enable_split') === '1';
    }

    /**
     * Instruções de split para a cobrança da fatura.
     *
     * @param object $invoice
     * @param string $method pix, boleto ou cartao
     * @param float  $amount Valor bruto desta cobrança em reais (saldo da fatura)
     * @param string|null $error Recebe a mensagem quando as regras não cabem no pagamento
     * @return array Lista de {customer_id, type, value} (vazia = sem split)
     */
    public function resolve($invoice, $method, $amount, &$error = null)
    {
        $error = null;
        $rules = $this->rules_for((int) $invoice->clientid, $method);
        if (!$rules) {
            return [];
        }

        $amount_cents = (int) round($amount * 100);
        $total_cents = max($amount_cents, (int) round((float) $invoice->total * 100));
        $split = [];
        $seen = [];
        $reserved_cents = 0;
        foreach ($rules as $rule) {
            if (isset($seen[$rule->customer_uuid])) {
                continue; // a tela impede duplicatas; aqui a primeira regra vale
            }
            $seen[$rule->customer_uuid] = true;

            if ($rule->type === 'percentage') {
                $value = round((float) $rule->value, 4);
                if ($value <= 0 || $value > 100) {
                    $error = 'Percentual de split inválido para ' . $rule->recipient_name . '.';
                    return [];
                }
                $reserved_cents += self::percent_cents($amount_cents, $value);
            } else {
                // Fixo vale para a fatura inteira: cobrança de saldo recebe a parte proporcional
                $value = round((float) $rule->value * $amount_cents / $total_cents, 2);
                if ($value <= 0) {
                    continue;
                }
                $reserved_cents += (int) round($value * 100);
            }
            $split[] = ['customer_id' => $rule->customer_uuid, 'type' => $rule->type === 'percentage' ? 'percentage' : 'fixed', 'value' => $value];
        }

        if (count($split) > 10) {
            $error = 'O split aceita no máximo 10 recebedores por pagamento.';
            return [];
        }

        $margin = self::margin();
        $limit_cents = intdiv($amount_cents * (int) round((100 - $margin) * 100), 10000);
        if ($reserved_cents > $limit_cents) {
            $error = 'As regras de split somam R$ ' . number_format($reserved_cents / 100, 2, ',', '.')
                . ', acima do limite de R$ ' . number_format($limit_cents / 100, 2, ',', '.')
                . ' (margem mínima de ' . $margin . '% para tarifas).';
            return [];
        }

        return $split;
    }

    /**
     * Centavos de um percentual com até 4 casas, em inteiros, meio centavo para cima (como a Pagou).
     */
    public static function percent_cents($amount_cents, $percent)
    {
        $scaled = (int) round($percent * 10000); // percentual × 10⁴
        $numerator = $amount_cents * $scaled;    // centavos × percentual × 10⁴

        return intdiv($numerator + 500000, 1000000);
    }

    public static function margin()
    {
        return max(0, min(90, (float) str_replace(',', '.', (string) get_option('pagou_split_min_margin_pct'))));
    }

    /**
     * Hash do split resolvido: muda quando as regras mudam (para reemitir a cobrança reaproveitada).
     */
    public static function hash(array $split)
    {
        return $split ? sha1(json_encode($split)) : '';
    }

    /**
     * Regras do cliente para o meio (mesmo com recebedor inativo, a precedência é do cliente);
     * sem regra do cliente, as globais.
     */
    private function rules_for($client_id, $method)
    {
        $client = $this->query('client', $client_id, $method);
        if ($client) {
            foreach ($client as $rule) {
                if ($rule->type === 'none') {
                    return []; // cliente isento de split neste meio
                }
            }
            return array_values(array_filter($client, function ($r) { return (int) $r->recipient_active === 1; }));
        }

        return array_values(array_filter($this->query('global', 0, $method), function ($r) { return (int) $r->recipient_active === 1; }));
    }

    private function query($scope, $scope_id, $method)
    {
        return $this->ci->db->select('r.*, p.customer_uuid, p.name AS recipient_name, p.active AS recipient_active')
            ->from(db_prefix() . 'pagou_split_rules r')
            ->join(db_prefix() . 'pagou_split_recipients p', 'p.id = r.recipient_id', 'left')
            ->where('r.active', 1)
            ->where('r.scope', $scope)->where('r.scope_id', $scope_id)
            ->where('FIND_IN_SET(' . $this->ci->db->escape($method) . ', r.methods) >', 0, false)
            ->order_by('r.id', 'ASC')
            ->get()->result();
    }

    /**
     * Erro de configuração do split: atividade na fatura no máximo uma vez a cada 24 h.
     */
    public function log_config_error($invoice_id, $message)
    {
        $key = 'pagou_split_err_' . (int) $invoice_id;
        $last = (int) get_option($key);
        if ($last && time() - $last < 86400) {
            return;
        }
        $exists = $this->ci->db->where('name', $key)->count_all_results(db_prefix() . 'options') > 0;
        $exists ? update_option($key, (string) time()) : add_option($key, (string) time(), 0);
        $this->ci->load->model('invoices_model');
        $this->ci->invoices_model->log_invoice_activity($invoice_id, 'Pagou: cobrança não emitida por configuração de split. ' . $message);
    }

    /**
     * Mensagem de erro da API sobre split (vem em "error", sem code).
     */
    public static function is_api_split_error($message)
    {
        $message = strtolower((string) $message);
        foreach (['split', 'allocation', 'recipient'] as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    // ================================================================ acompanhamento

    /**
     * Registra o split enviado e o retorno da API para o pagamento criado (ou recuperado).
     */
    public function snapshot($method, $charge_id, $invoice_id, array $request_split, $response_split)
    {
        if (!$request_split || !$charge_id) {
            return;
        }
        $table = db_prefix() . 'pagou_split_snapshots';
        $now = date('Y-m-d H:i:s');
        $exists = $this->ci->db->where('method', $method)->where('charge_id', $charge_id)->count_all_results($table) > 0;
        $data = [
            'method'       => $method,
            'charge_id'    => $charge_id,
            'invoice_id'   => (int) $invoice_id,
            'request_json' => json_encode($request_split),
            'updated_at'   => $now,
        ];
        $data += $this->response_fields($response_split);
        if ($exists) {
            $this->ci->db->where('method', $method)->where('charge_id', $charge_id)->update($table, $data);
        } else {
            $data['created_at'] = $now;
            $this->ci->db->insert($table, $data);
        }
    }

    /**
     * Atualiza o estado do split a partir de uma consulta do pagamento; alerta em 5, 6 e alocação cancelada.
     * Cria o acompanhamento se a cobrança tem split e ainda não tinha (ex.: criada antes de um snapshot).
     */
    public function track($method, $charge_id, $response_split, $invoice_id = null)
    {
        if (!is_array($response_split) && !is_object($response_split)) {
            return;
        }
        $table = db_prefix() . 'pagou_split_snapshots';
        $row = $this->ci->db->where('method', $method)->where('charge_id', $charge_id)->get($table)->row();
        if (!$row) {
            if (!$invoice_id) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            $this->ci->db->insert($table, ['method' => $method, 'charge_id' => $charge_id, 'invoice_id' => (int) $invoice_id, 'created_at' => $now, 'updated_at' => $now]);
            $row = $this->ci->db->where('method', $method)->where('charge_id', $charge_id)->get($table)->row();
        }
        $fields = $this->response_fields($response_split);
        $fields['last_checked_at'] = date('Y-m-d H:i:s');
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->ci->db->where('id', $row->id)->update($table, $fields);

        $status = $fields['split_status'] ?? null;
        $this->ci->load->model('invoices_model');
        if ($status !== null && (int) $status !== (int) $row->split_status && in_array((int) $status, [5, 6], true)) {
            $text = (int) $status === 6
                ? 'Split da cobrança ' . $charge_id . ' com ação necessária na Pagou: confira participantes e saldo e procure o suporte com o ID do pagamento.'
                : 'Split da cobrança ' . $charge_id . ' revertido pela Pagou: confira o estorno ou a devolução relacionada.';
            $this->ci->invoices_model->log_invoice_activity($row->invoice_id, $text);
        }

        // Alocação cancelada (status 3) que antes não estava cancelada
        $before = [];
        foreach ((array) json_decode((string) $row->allocations_json, true) as $alloc) {
            if (isset($alloc['id'])) {
                $before[$alloc['id']] = (int) ($alloc['status'] ?? 0);
            }
        }
        foreach ((array) json_decode((string) ($fields['allocations_json'] ?? '[]'), true) as $alloc) {
            if (isset($alloc['id']) && (int) ($alloc['status'] ?? 0) === 3 && ($before[$alloc['id']] ?? 0) !== 3) {
                $this->ci->invoices_model->log_invoice_activity($row->invoice_id, 'Alocação de split ' . $alloc['id'] . ' cancelada na cobrança ' . $charge_id . '.');
            }
        }
    }

    /**
     * Pedido de estorno ou devolução numa cobrança com split: aviso na fatura e volta para acompanhamento.
     *
     * @return bool true se a cobrança tem split
     */
    public function on_reversal($method, $charge_id, $invoice_id)
    {
        $table = db_prefix() . 'pagou_split_snapshots';
        $row = $this->ci->db->where('method', $method)->where('charge_id', $charge_id)->get($table)->row();
        if (!$row) {
            return false;
        }
        $this->ci->db->where('id', $row->id)->update($table, ['split_status' => 2, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->ci->load->model('invoices_model');
        $this->ci->invoices_model->log_invoice_activity($invoice_id, 'A cobrança ' . $charge_id . ' tem split: o estorno/devolução depende da recuperação dos valores repassados aos recebedores. Acompanhe o status do split.');

        return true;
    }

    public function has_split($method, $charge_id)
    {
        return $this->ci->db->where('method', $method)->where('charge_id', $charge_id)
            ->count_all_results(db_prefix() . 'pagou_split_snapshots') > 0;
    }

    /**
     * Tira da fila: pagamento encerrado sem pagamento, ou API sem split depois de pago (com alerta).
     */
    public function close($method, $charge_id, $status, $alert = null)
    {
        $table = db_prefix() . 'pagou_split_snapshots';
        $row = $this->ci->db->where('method', $method)->where('charge_id', $charge_id)->get($table)->row();
        if (!$row) {
            return;
        }
        $this->ci->db->where('id', $row->id)->update($table, ['split_status' => $status, 'last_checked_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        if ($alert) {
            $this->ci->load->model('invoices_model');
            $this->ci->invoices_model->log_invoice_activity($row->invoice_id, $alert);
        }
    }

    private function response_fields($split)
    {
        $split = is_object($split) ? json_decode(json_encode($split), true) : $split;
        if (!is_array($split)) {
            return [];
        }

        return [
            'split_status'     => isset($split['status']) ? (int) $split['status'] : null,
            'fee'              => isset($split['fee']) ? (float) $split['fee'] : null,
            'allocations_json' => isset($split['allocations']) ? json_encode($split['allocations']) : null,
        ];
    }

    /**
     * Pagamentos com split ainda não concluído ou ainda sem retorno da API (para a reconciliação).
     */
    public function pending_snapshots($limit = 10)
    {
        return $this->ci->db->group_start()
                ->where_in('split_status', [1, 2, 6])
                ->or_where('split_status IS NULL', null, false)
            ->group_end()
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-180 days')))
            ->order_by('last_checked_at IS NULL', 'DESC', false)
            ->order_by('last_checked_at', 'ASC')
            ->limit($limit)
            ->get(db_prefix() . 'pagou_split_snapshots')->result();
    }
}
