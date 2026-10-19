<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cobranças de cartão no admin: lista, consulta e estorno (integral).
 * URL: admin/pagou/card_admin
 */
class Card_admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (!staff_can('access_reembolso', 'pagou_module') && !staff_can('card_reverse', 'pagou_module')) {
            access_denied('Cobranças de cartão');
        }
        pagou_load_libraries();
    }

    public function index()
    {
        $data['title'] = 'Cobranças de cartão (Pagou)';
        $data['charges'] = Pagou_schema::ready()
            ? $this->db->select('c.*, i.number AS invoice_number, i.prefix AS invoice_prefix')
                ->from(db_prefix() . 'pagou_card_charges c')
                ->join(db_prefix() . 'invoices i', 'i.id = c.invoice_id', 'left')
                ->order_by('c.id', 'DESC')->limit(200)
                ->get()->result()
            : [];
        $data['can_reverse'] = staff_can('card_reverse', 'pagou_module');
        $this->load->view('admin/pagou/card_charges', $data);
    }

    /**
     * Consulta a cobrança na API e atualiza o estado local.
     */
    public function refresh($id = 0)
    {
        if ($this->input->method() !== 'post') {
            redirect(admin_url('pagou/card_admin'));
        }
        $row = $this->row($id);
        if ($row) {
            list(, $message) = (new Pagou_events())->settle_card($row);
            set_alert('info', $message);
        }
        redirect(admin_url('pagou/card_admin'));
    }

    /**
     * Estorno integral (PUT /reverse) de cobrança authorized, captured ou settled.
     * O estado é conferido antes e depois; o registro de pagamento do Perfex é ajustado pelo staff.
     */
    public function reverse($id = 0)
    {
        if ($this->input->method() !== 'post' || !staff_can('card_reverse', 'pagou_module')) {
            access_denied('Estornar cobrança de cartão');
        }
        $row = $this->row($id);
        $api = Pagou_api::for_gateway(Pagou_card::GATEWAY);
        if (!$row || !$row->pagou_charge_id || !$api) {
            set_alert('danger', 'Cobrança não encontrada ou gateway sem chave configurada.');
            redirect(admin_url('pagou/card_admin'));
        }

        $current = $api->get_card_charge($row->pagou_charge_id);
        $status = is_array($current->body) ? strtolower((string) ($current->body['status'] ?? '')) : '';
        if (!$current->ok() || !in_array($status, ['authorized', 'captured', 'settled'], true)) {
            set_alert('danger', $current->ok() ? 'A cobrança está em "' . html_escape($status) . '" e não pode ser estornada.' : 'Não foi possível consultar a cobrança na Pagou.');
            redirect(admin_url('pagou/card_admin'));
        }

        // Corpo fixo e chave por tentativa; a mesma chave só é reaproveitada depois de resultado incerto
        $raw = json_encode(['reason' => 'Estorno solicitado pelo estabelecimento'], JSON_UNESCAPED_UNICODE);
        $key = ($row->reverse_state === 'uncertain' && $row->reverse_key) ? $row->reverse_key : 'perfex-reverse-' . bin2hex(random_bytes(16));
        $table = db_prefix() . 'pagou_card_charges';
        $this->db->where('id', $row->id)->update($table, ['reverse_key' => $key, 'reverse_state' => 'sent']);
        $response = $api->reverse_card_charge($row->pagou_charge_id, $raw, $key);
        $code = $response->error_code();
        $uncertain = $response->uncertain() || in_array($code, ['idempotency_in_progress', 'idempotency_outcome_unknown'], true);
        $this->db->where('id', $row->id)->update($table, ['reverse_state' => $response->ok() ? 'done' : ($uncertain ? 'uncertain' : 'failed')]);

        $staff = get_staff_full_name(get_staff_user_id());
        if ($response->ok()) {
            if ((new Pagou_split())->on_reversal('cartao', $row->pagou_charge_id, $row->invoice_id)) {
                set_alert('warning', 'Esta cobrança tem split: o estorno depende da recuperação dos repasses aos recebedores.');
            }
            list(, $message) = (new Pagou_events())->settle_card($this->row($id));
            $this->log($row->invoice_id, 'Estorno da cobrança de cartão ' . $row->pagou_charge_id . ' solicitado por ' . $staff . '. Ajuste o pagamento da fatura.');
            set_alert('success', 'Estorno solicitado à Pagou. ' . $message);
        } elseif ($uncertain) {
            $this->log($row->invoice_id, 'Estorno da cobrança de cartão ' . $row->pagou_charge_id . ' sem confirmação (HTTP ' . $response->http_code . '). Confira antes de repetir.');
            set_alert('warning', 'A Pagou não confirmou o estorno. Use "Consultar" antes de tentar de novo.');
        } else {
            $text = $code === 'charge_not_reversible' ? 'a cobrança não está em um estado que permita estorno' : $response->error_message();
            set_alert('danger', 'Estorno recusado: ' . html_escape($text));
        }
        redirect(admin_url('pagou/card_admin'));
    }

    /**
     * Cobrança em revisão (sem confirmação da Pagou): o staff confere com o suporte e registra o desfecho.
     * "not_charged" libera nova compra na fatura; "charged" exige informar o ID da cobrança na Pagou.
     */
    public function resolve($id = 0)
    {
        if ($this->input->method() !== 'post' || !staff_can('card_reverse', 'pagou_module')) {
            access_denied('Resolver cobrança de cartão');
        }
        $row = $this->row($id);
        if (!$row || $row->status !== 'needs_review') {
            redirect(admin_url('pagou/card_admin'));
        }
        $table = db_prefix() . 'pagou_card_charges';
        $staff = get_staff_full_name(get_staff_user_id());
        $charge_id = trim((string) $this->input->post('pagou_charge_id', true));

        if ($this->input->post('outcome') === 'charged' && preg_match('/^[0-9a-f-]{36}$/i', $charge_id)) {
            $this->db->where('id', $row->id)->update($table, ['pagou_charge_id' => $charge_id, 'status' => 'unknown', 'request_enc' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            list(, $message) = (new Pagou_events())->settle_card($this->row($id));
            $this->log($row->invoice_id, 'Cobrança de cartão em revisão vinculada a ' . $charge_id . ' por ' . $staff . '.');
            set_alert('info', $message);
        } elseif ($this->input->post('outcome') === 'not_charged') {
            $this->db->where('id', $row->id)->update($table, ['status' => 'canceled', 'failure_reason' => 'Sem cobrança na Pagou (conferido por ' . $staff . ')', 'request_enc' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->log($row->invoice_id, 'Cobrança de cartão em revisão encerrada sem cobrança por ' . $staff . '. O cliente pode pagar de novo.');
            set_alert('success', 'Revisão encerrada; a fatura volta a aceitar cartão.');
        } else {
            set_alert('danger', 'Informe o desfecho (e o ID da cobrança, se houve cobrança).');
        }
        redirect(admin_url('pagou/card_admin'));
    }

    private function row($id)
    {
        return $this->db->where('id', (int) $id)->get(db_prefix() . 'pagou_card_charges')->row();
    }

    private function log($invoice_id, $description)
    {
        $this->load->model('invoices_model');
        $this->invoices_model->log_invoice_activity($invoice_id, $description);
    }
}
