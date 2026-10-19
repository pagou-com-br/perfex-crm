<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Split de pagamento: resolve as regras do Perfex no array `split` da criação e acompanha a distribuição.
 *
 * - Recebedores são clientes Pagou (UUID) autorizados no painel da Pagou (Configurações → Split).
 * - Regras por cliente prevalecem sobre as globais; cada regra vale para os meios escolhidos.
 * - `fixed` em reais (inclusive no cartão, cuja cobrança é em centavos) e `percentage` em %.
 * - Validação local: até 10 recebedores, sem repetição, e margem mínima para as tarifas.
 * - O estado do split (split.status) é separado do pagamento e acompanhado pela reconciliação.
 */
class Pagou_split
{
    const METHODS = ['pix', 'boleto', 'cartao'];

    private $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->database();
    }

    /**
     * Gateway com split ligado?
     */
    public static function enabled_for($gateway_id)
    {
        return get_option('paymentmethod_' . $gateway_id . '_enable_split') === '1';
    }

    /**
     * Instruções de split para a fatura.
     *
     * @param object $invoice
     * @param string $method pix, boleto ou cartao
     * @param float  $amount Valor bruto do pagamento em reais
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

        $split = [];
        $seen = [];
        $reserved_cents = 0;
        foreach ($rules as $rule) {
            if (isset($seen[$rule->customer_uuid])) {
                $error = 'Há mais de uma regra de split para o mesmo recebedor (' . $rule->recipient_name . ').';
                return [];
            }
            $seen[$rule->customer_uuid] = true;

            if ($rule->type === 'percentage') {
                $value = round((float) $rule->value, 4);
                if ($value <= 0 || $value > 100) {
                    $error = 'Percentual de split inválido para ' . $rule->recipient_name . '.';
                    return [];
                }
                // Mesmo arredondamento da Pagou: centavos, meio centavo para cima
                $reserved_cents += (int) floor($amount * 100 * $value / 100 + 0.5);
            } else {
                $value = round((float) $rule->value, 2);
                if ($value <= 0) {
                    $error = 'Valor fixo de split inválido para ' . $rule->recipient_name . '.';
                    return [];
                }
                $reserved_cents += (int) round($value * 100);
            }
            $split[] = ['customer_id' => $rule->customer_uuid, 'type' => $rule->type === 'percentage' ? 'percentage' : 'fixed', 'value' => $value];
        }

        if (count($split) > 10) {
            $error = 'O split aceita no máximo 10 recebedores por pagamento.';
            return [];
        }

        // Margem para a tarifa da modalidade e a tarifa de split (calculadas pela Pagou)
        $margin = max(0, min(90, (float) str_replace(',', '.', (string) get_option('pagou_split_min_margin_pct'))));
        $limit_cents = (int) floor($amount * 100 * (100 - $margin) / 100);
        if ($reserved_cents > $limit_cents) {
            $error = 'As regras de split somam R$ ' . number_format($reserved_cents / 100, 2, ',', '.')
                . ', acima do limite de R$ ' . number_format($limit_cents / 100, 2, ',', '.')
                . ' (margem mínima de ' . $margin . '% para tarifas).';
            return [];
        }

        return $split;
    }

    /**
     * Regras ativas: as do cliente, se existirem para o meio; senão as globais.
     */
    private function rules_for($client_id, $method)
    {
        $rules = db_prefix() . 'pagou_split_rules';
        $recipients = db_prefix() . 'pagou_split_recipients';
        $query = function ($scope, $scope_id) use ($rules, $recipients, $method) {
            return $this->ci->db->select('r.*, p.customer_uuid, p.name AS recipient_name')
                ->from($rules . ' r')
                ->join($recipients . ' p', 'p.id = r.recipient_id')
                ->where('r.active', 1)->where('p.active', 1)
                ->where('r.scope', $scope)->where('r.scope_id', $scope_id)
                ->like('r.methods', $method)
                ->order_by('r.id', 'ASC')
                ->get()->result();
        };

        $client_rules = $query('client', $client_id);

        return $client_rules ?: $query('global', 0);
    }

    // ================================================================ acompanhamento

    /**
     * Registra o split enviado e o retorno da API para o pagamento criado.
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
     * Atualiza o estado do split a partir de uma consulta do pagamento; alerta em "ação necessária" (6).
     */
    public function track($method, $charge_id, $response_split)
    {
        if (!is_array($response_split) && !is_object($response_split)) {
            return;
        }
        $table = db_prefix() . 'pagou_split_snapshots';
        $row = $this->ci->db->where('method', $method)->where('charge_id', $charge_id)->get($table)->row();
        if (!$row) {
            return;
        }
        $fields = $this->response_fields($response_split);
        $fields['last_checked_at'] = date('Y-m-d H:i:s');
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->ci->db->where('id', $row->id)->update($table, $fields);

        $status = $fields['split_status'] ?? null;
        if ($status !== null && (int) $status !== (int) $row->split_status && in_array((int) $status, [5, 6], true)) {
            $this->ci->load->model('invoices_model');
            $text = (int) $status === 6
                ? 'Split da cobrança ' . $charge_id . ' com ação necessária na Pagou: confira participantes e saldo e procure o suporte com o ID do pagamento.'
                : 'Split da cobrança ' . $charge_id . ' revertido pela Pagou: confira o estorno ou a devolução relacionada.';
            $this->ci->invoices_model->log_invoice_activity($row->invoice_id, $text);
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
     * Pagamentos com split ainda não concluído (para a reconciliação).
     */
    public function pending_snapshots($limit = 10)
    {
        return $this->ci->db->where_in('split_status', [1, 2, 6])
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-90 days')))
            ->order_by('last_checked_at IS NULL', 'DESC', false)
            ->order_by('last_checked_at', 'ASC')
            ->limit($limit)
            ->get(db_prefix() . 'pagou_split_snapshots')->result();
    }
}
