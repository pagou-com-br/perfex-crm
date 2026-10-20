<?php
// Ambiente mínimo: CodeIgniter 3 (só o banco) + stubs das funções do Perfex usadas pelo módulo.
error_reporting(E_ALL);
ini_set('display_errors', '1');

$MODULE = $argv[1] ?? getenv('PAGOU_MODULE') ?: dirname(__DIR__, 2);
define('BASEPATH', __DIR__ . '/.ci3/CodeIgniter-3.1.13/system/');
define('APPPATH', __DIR__ . '/.app/');
define('ENVIRONMENT', 'testing');
define('PAGOU_API_BASE_URL_OVERRIDE', 'http://127.0.0.1:' . (getenv('PAGOU_MOCK_PORT') ?: '8098'));
@mkdir(APPPATH . 'logs', 0777, true);

function log_message($level, $msg) { $GLOBALS['__logs'][] = "[$level] $msg"; }
function show_error($m) { throw new Exception('show_error: ' . (is_array($m) ? implode(' ', $m) : $m)); }
function config_item($k) { return null; }
function is_php($v) { return version_compare(PHP_VERSION, $v, '>='); }
function function_usable($f) { return function_exists($f); }
function &load_class($c) { static $o = []; if (!isset($o[$c])) { $o[$c] = new stdClass(); } return $o[$c]; }

require BASEPATH . 'database/DB.php';
$db = DB([
    'dsn' => '', 'hostname' => '127.0.0.1', 'port' => 33071, 'username' => 'root', 'password' => 'test',
    'database' => 'perfex', 'dbdriver' => 'mysqli', 'dbprefix' => '', 'pconnect' => false, 'db_debug' => false,
    'char_set' => 'utf8mb4', 'dbcollat' => 'utf8mb4_general_ci', 'save_queries' => false,
], true);
$db->initialize();

class FakeLoader {
    public function database() {}
    public function model($m) {}
    public function library($l) {}
    public function helper($h) {}
}
class FakeEncryption {
    public function encrypt($v) { return 'enc:' . base64_encode($v); }
    public function decrypt($v) { return strpos($v, 'enc:') === 0 ? base64_decode(substr($v, 4)) : false; }
}
class FakeInvoices {
    public function get($id) {
        $row = get_instance()->db->where('id', $id)->get('tblinvoices')->row();
        return $row ?: null;
    }
    public function mark_as_cancelled($id) { get_instance()->db->where('id', $id)->update('tblinvoices', ['status' => 5]); return true; }
    public function log_invoice_activity($id, $d) { $GLOBALS['__activity'][] = [$id, $d]; }
}
class FakePayments {
    // Simplificação do Payments_model::add do Perfex: insere e recalcula o status
    public function add($data) {
        if (!empty($GLOBALS['__fail_add'])) { return false; }
        $ci = get_instance();
        $data['daterecorded'] = date('Y-m-d H:i:s');
        $data['date'] = isset($data['date']) ? $data['date'] : date('Y-m-d');
        $ci->db->insert('tblinvoicepaymentrecords', $data);
        $id = $ci->db->insert_id();
        $inv = $ci->db->where('id', $data['invoiceid'])->get('tblinvoices')->row();
        $paid = (float) $ci->db->select_sum('amount')->where('invoiceid', $data['invoiceid'])->get('tblinvoicepaymentrecords')->row()->amount;
        $ci->db->where('id', $inv->id)->update('tblinvoices', ['status' => $paid + 0.001 >= (float) $inv->total ? 2 : 3]);
        return $id;
    }
}
class FakeCI {
    public $db, $load, $encryption, $invoices_model, $payments_model;
}
$CI = new FakeCI();
$CI->db = $db;
$CI->load = new FakeLoader();
$CI->encryption = new FakeEncryption();
$CI->invoices_model = new FakeInvoices();
$CI->payments_model = new FakePayments();
function &get_instance() { global $CI; return $CI; }

function db_prefix() { return 'tbl'; }
function get_option($n) { $r = get_instance()->db->where('name', $n)->get('tbloptions')->row(); return $r ? $r->value : ''; }
function update_option($n, $v) {
    // Como no Perfex: cria a opção quando ela não existe
    $db = get_instance()->db;
    if ($db->where('name', $n)->count_all_results('tbloptions') > 0) { $db->where('name', $n)->update('tbloptions', ['value' => $v]); }
    else { $db->insert('tbloptions', ['name' => $n, 'value' => $v, 'autoload' => 1]); }
    return true;
}
function add_option($n, $v, $a = 1) { get_instance()->db->insert('tbloptions', ['name' => $n, 'value' => $v, 'autoload' => $a]); return true; }
function log_activity($d) { $GLOBALS['__activity'][] = [0, $d]; }
function _d($d) { return $d; }
function get_invoice_total_left_to_pay($id, $total) {
    $paid = (float) get_instance()->db->select_sum('amount')->where('invoiceid', $id)->get('tblinvoicepaymentrecords')->row()->amount;
    return $total - $paid;
}

require $MODULE . '/helpers/pagou_helper.php';
pagou_load_libraries();
