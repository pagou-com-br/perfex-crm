<?php
// Stubs adicionais para exercitar os gateways fora do Perfex.
class RedirectException extends Exception {}

class App_gateway {
    private $id; private $settings = [];
    public function __construct() {}
    public function setId($id) { $this->id = $id; }
    public function getId() { return $this->id; }
    public function setName($n) {}
    public function setSettings($s) { $this->settings = $s; }
    public function getSetting($name) {
        $v = get_option('paymentmethod_' . $this->id . '_' . $name);
        if ($v === '') {
            foreach ($this->settings as $s) {
                if ($s['name'] === $name && isset($s['default_value'])) { return (string) $s['default_value']; }
            }
        }
        return $v;
    }
    public function decryptSetting($name) { return get_instance()->encryption->decrypt(get_option('paymentmethod_' . $this->id . '_' . $name)); }
    public function init_settings() {}
}
class FakeHooks {
    public function add_action($a, $b) {}
    public function apply_filters($n, $v) { return $v; }
}
function hooks() { static $h; return $h ?: ($h = new FakeHooks()); }
function set_alert($type, $msg) { $GLOBALS['__alert'] = [$type, $msg]; }
function redirect($url) { throw new RedirectException($url); }
function site_url($p = '') { return 'https://crm.test/' . ltrim($p, '/'); }
function admin_url($p = '') { return 'https://crm.test/admin/' . ltrim($p, '/'); }
function format_invoice_number($id) { return 'INV-' . str_pad($id, 6, '0', STR_PAD_LEFT); }
function _l($s) { return $s; }
function html_escape($s) { return htmlspecialchars($s, ENT_QUOTES); }

class FakeClients {
    public function get($id) {
        return (object) ['userid' => $id, 'company' => 'Cliente Teste', 'vat' => $GLOBALS['__client_vat'] ?? '529.982.247-25',
            'address' => 'Rua A', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01310-100'];
    }
}
class ViewLoader extends FakeLoader {
    public function view($v, $data = [], $return = false) { $GLOBALS['__view'] = [$v, $data]; }
}
$CI->load = new ViewLoader();
$CI->clients_model = new FakeClients();

function calls() { return is_file(__DIR__ . '/calls.json') ? json_decode(file_get_contents(__DIR__ . '/calls.json'), true) : []; }
function reset_calls() { @unlink(__DIR__ . '/calls.json'); }
function last_call($method_path) {
    foreach (array_reverse(calls()) as $c) {
        if ($c['method'] . ' ' . $c['path'] === $method_path) { return $c; }
    }
    return null;
}
