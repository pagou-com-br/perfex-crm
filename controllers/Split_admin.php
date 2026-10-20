<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Configuração do split: recebedores (clientes Pagou autorizados no painel), regras e margem.
 * URL: admin/pagou/split_admin
 */
class Split_admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (!staff_can('manage_split', 'pagou_module')) {
            access_denied('Split Pagou');
        }
        pagou_load_libraries();
        if (!Pagou_schema::ready()) {
            set_alert('warning', 'Atualize o banco de dados do módulo Pagou antes de configurar o split.');
            redirect(admin_url('modules'));
        }
    }

    public function index()
    {
        $p = db_prefix();
        $data['title'] = 'Split de pagamento (Pagou)';
        $data['recipients'] = $this->db->order_by('name')->get($p . 'pagou_split_recipients')->result();
        $data['rules'] = $this->db->select('r.*, s.name AS recipient_name, c.company AS client_company')
            ->from($p . 'pagou_split_rules r')
            ->join($p . 'pagou_split_recipients s', 's.id = r.recipient_id', 'left')
            ->join($p . 'clients c', "c.userid = r.scope_id AND r.scope = 'client'", 'left')
            ->order_by('r.scope')->order_by('r.scope_id')->order_by('r.id')
            ->get()->result();
        $data['margin'] = get_option('pagou_split_min_margin_pct');
        $data['snapshots'] = $this->db->order_by('id', 'DESC')->limit(50)->get($p . 'pagou_split_snapshots')->result();
        $this->load->view('admin/pagou/split', $data);
    }

    public function add_recipient()
    {
        $this->require_post();
        $name = trim((string) $this->input->post('name', true));
        $uuid = strtolower(trim((string) $this->input->post('customer_uuid', true)));
        if ($name === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            set_alert('danger', 'Informe o nome e o UUID (v4) do cliente Pagou recebedor.');
            redirect(admin_url('pagou/split_admin'));
        }
        $table = db_prefix() . 'pagou_split_recipients';
        if ($this->db->where('customer_uuid', $uuid)->count_all_results($table) > 0) {
            set_alert('warning', 'Este recebedor já está cadastrado.');
            redirect(admin_url('pagou/split_admin'));
        }
        $this->db->insert($table, [
            'name'          => mb_substr($name, 0, 150),
            'customer_uuid' => $uuid,
            'active'        => 1,
            'notes'         => mb_substr(trim((string) $this->input->post('notes', true)), 0, 255),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        log_activity('Pagou split: recebedor ' . $name . ' cadastrado');
        set_alert('success', 'Recebedor cadastrado. Lembre-se de autorizá-lo no painel da Pagou (Configurações → Split).');
        redirect(admin_url('pagou/split_admin'));
    }

    public function toggle_recipient($id = 0)
    {
        $this->require_post();
        $table = db_prefix() . 'pagou_split_recipients';
        $row = $this->db->where('id', (int) $id)->get($table)->row();
        if ($row) {
            $this->db->where('id', $row->id)->update($table, ['active' => $row->active ? 0 : 1]);
            log_activity('Pagou split: recebedor ' . $row->name . ($row->active ? ' desativado' : ' ativado'));
        }
        redirect(admin_url('pagou/split_admin'));
    }

    public function add_rule()
    {
        $this->require_post();
        $scope = $this->input->post('scope') === 'client' ? 'client' : 'global';
        $scope_id = $scope === 'client' ? (int) $this->input->post('client_id') : 0;
        $recipient_id = (int) $this->input->post('recipient_id');
        $type = in_array($this->input->post('type'), ['percentage', 'fixed', 'none'], true) ? $this->input->post('type') : 'fixed';
        $value = round((float) str_replace(',', '.', (string) $this->input->post('value')), $type === 'percentage' ? 4 : 2);
        $methods = array_values(array_intersect((array) $this->input->post('methods'), Pagou_split::METHODS));
        $back = admin_url('pagou/split_admin');

        $fail = function ($message) use ($back) {
            set_alert('danger', $message);
            redirect($back);
        };
        if (!$methods) {
            $fail('Escolha ao menos um meio de pagamento.');
        }
        if ($scope === 'client' && $this->db->where('userid', $scope_id)->count_all_results(db_prefix() . 'clients') === 0) {
            $fail('Cliente não encontrado.');
        }
        if ($type === 'none') {
            if ($scope !== 'client') {
                $fail('"Sem split" só vale para um cliente específico.');
            }
            $recipient_id = 0;
            $value = 0;
        } else {
            $recipient = $this->db->where('id', $recipient_id)->get(db_prefix() . 'pagou_split_recipients')->row();
            if (!$recipient || !$recipient->active) {
                $fail('Escolha um recebedor ativo.');
            }
            if ($value <= 0 || ($type === 'percentage' && $value > 100) || ($type === 'fixed' && $value > Pagou_split::MAX_FIXED)) {
                $fail('Valor inválido: percentual entre 0 e 100 (até 4 casas) ou valor fixo em reais.');
            }
        }

        // Regras existentes do mesmo escopo, por meio: duplicidade, limite de 10 e soma dos percentuais
        $existing = $this->db->where('scope', $scope)->where('scope_id', $scope_id)->where('active', 1)
            ->get(db_prefix() . 'pagou_split_rules')->result();
        $margin = Pagou_split::margin();
        foreach ($methods as $method) {
            $same_method = array_filter($existing, function ($r) use ($method) {
                return in_array($method, explode(',', $r->methods), true);
            });
            foreach ($same_method as $r) {
                if ($type === 'none' || $r->type === 'none') {
                    $fail('Já existe regra para este cliente no meio ' . $method . ' que conflita com "sem split". Remova-a antes.');
                }
                if ((int) $r->recipient_id === $recipient_id) {
                    $fail('Este recebedor já tem regra neste escopo para o meio ' . $method . '.');
                }
            }
            if ($type === 'none') {
                continue;
            }
            if (count($same_method) >= 10) {
                $fail('O split aceita no máximo 10 recebedores por pagamento (meio ' . $method . ').');
            }
            $percent = $type === 'percentage' ? $value : 0;
            foreach ($same_method as $r) {
                $percent += $r->type === 'percentage' ? (float) $r->value : 0;
            }
            if ($percent > 100 - $margin) {
                $fail('Os percentuais do meio ' . $method . ' somariam ' . $percent . '%, acima de ' . (100 - $margin) . '% (margem para tarifas).');
            }
        }

        $this->db->insert(db_prefix() . 'pagou_split_rules', [
            'scope'        => $scope,
            'scope_id'     => $scope_id,
            'recipient_id' => $recipient_id,
            'type'         => $type,
            'value'        => $value,
            'methods'      => implode(',', $methods),
            'active'       => 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        log_activity('Pagou split: regra ' . $type . ' ' . $scope . ($scope_id ? ' #' . $scope_id : '') . ' cadastrada (' . implode(',', $methods) . ')');
        set_alert('success', 'Regra cadastrada. Ela vale para novas cobranças; Pix e boletos em aberto são reemitidos na próxima visita do cliente.');
        redirect($back);
    }

    public function delete_rule($id = 0)
    {
        $this->require_post();
        $this->db->where('id', (int) $id)->delete(db_prefix() . 'pagou_split_rules');
        log_activity('Pagou split: regra #' . (int) $id . ' removida');
        set_alert('success', 'Regra removida.');
        redirect(admin_url('pagou/split_admin'));
    }

    public function save_margin()
    {
        $this->require_post();
        $margin = max(0, min(90, (float) str_replace(',', '.', (string) $this->input->post('margin'))));
        update_option('pagou_split_min_margin_pct', (string) $margin);
        log_activity('Pagou split: margem mínima alterada para ' . $margin . '%');
        set_alert('success', 'Margem mínima atualizada.');
        redirect(admin_url('pagou/split_admin'));
    }

    private function require_post()
    {
        if ($this->input->method() !== 'post') {
            redirect(admin_url('pagou/split_admin'));
        }
    }
}
