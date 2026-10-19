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

        $reason = mb_substr(trim((string) $this->input->post('reason', true)), 0, 255);
        $raw = json_encode($reason !== '' ? ['reason' => $reason] : new stdClass(), JSON_UNESCAPED_UNICODE);
        // Chave estável por cobrança: repetir o pedido não gera outro estorno
        $key = 'perfex-reverse-' . hash('sha256', $row->pagou_charge_id);
        $response = $api->reverse_card_charge($row->pagou_charge_id, $raw, $key);

        $staff = get_staff_full_name(get_staff_user_id());
        if ($response->ok()) {
            list(, $message) = (new Pagou_events())->settle_card($this->row($id));
            $this->log($row->invoice_id, 'Estorno da cobrança de cartão ' . $row->pagou_charge_id . ' solicitado por ' . $staff . '. Ajuste o pagamento da fatura.');
            set_alert('success', 'Estorno solicitado à Pagou. ' . $message);
        } elseif ($response->uncertain() || $response->http_code === 409) {
            $this->log($row->invoice_id, 'Estorno da cobrança de cartão ' . $row->pagou_charge_id . ' sem confirmação (HTTP ' . $response->http_code . '). Confira antes de repetir.');
            set_alert('warning', 'A Pagou não confirmou o estorno. Use "Consultar" antes de tentar de novo.');
        } else {
            set_alert('danger', 'Estorno recusado: ' . html_escape($response->error_message()));
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
