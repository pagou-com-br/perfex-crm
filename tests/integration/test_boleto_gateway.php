<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gateway_stubs.php';
require $MODULE . '/libraries/Pagou_boleto_gateway.php';
$db = get_instance()->db;

class BoletoClients {
    public function get($id) {
        $full = $GLOBALS['__client_full'] ?? true;
        return (object) ['userid' => $id, 'company' => 'Cliente Boleto', 'vat' => '52998224725',
            'billing_street' => $full ? 'Avenida Paulista' : '', 'billing_city' => 'São Paulo', 'billing_state' => 'São Paulo', 'billing_zip' => '01310-100',
            'address' => '', 'city' => '', 'state' => '', 'zip' => ''];
    }
}
get_instance()->clients_model = new BoletoClients();

foreach (['tblcustomfields', 'tblcustomfieldsvalues'] as $t) { $db->query("DROP TABLE IF EXISTS $t"); }
$db->query("CREATE TABLE tblcustomfields (id int auto_increment primary key, fieldto varchar(30), slug varchar(100))");
$db->query("CREATE TABLE tblcustomfieldsvalues (id int auto_increment primary key, relid int, fieldid int, fieldto varchar(30), value text)");
$db->insert('tblcustomfields', ['fieldto' => 'customers', 'slug' => 'customers_numero']); $num = $db->insert_id();
$db->insert('tblcustomfields', ['fieldto' => 'customers', 'slug' => 'customers_bairro']); $bai = $db->insert_id();
$db->insert('tblcustomfieldsvalues', ['relid' => 1, 'fieldid' => $num, 'fieldto' => 'customers', 'value' => '1000']);
$db->insert('tblcustomfieldsvalues', ['relid' => 1, 'fieldid' => $bai, 'fieldto' => 'customers', 'value' => 'Bela Vista']);

$db->query("DELETE FROM tblpagou_transactions_boleto WHERE invoice_id >= 20");
$db->query("DELETE FROM tblinvoices WHERE id >= 20");
$db->query("DELETE FROM tblinvoicepaymentrecords WHERE invoiceid >= 20");
foreach (['enable_fine', 'fine_value', 'enable_interest', 'interest_value', 'enable_grace_period', 'grace_period_days'] as $o) {
    $db->where('name', 'paymentmethod_pagou_boleto_' . $o)->delete('tbloptions');
}
update_option('paymentmethod_pagou_boleto_api_token', 'enc:' . base64_encode('boletokey'));
add_option('paymentmethod_pagou_boleto_enable_fine', '1'); add_option('paymentmethod_pagou_boleto_fine_value', '2');
add_option('paymentmethod_pagou_boleto_enable_interest', '1'); add_option('paymentmethod_pagou_boleto_interest_value', '1');

$ok = 0; $fail = 0;
function t($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FALHOU  $name\n"; } }
function api($state) { file_put_contents(__DIR__ . '/state.json', json_encode($state)); }
function inv($id) { return get_instance()->db->where('id', $id)->get('tblinvoices')->row(); }
function brows($inv) { return get_instance()->db->where('invoice_id', $inv)->order_by('id')->get('tblpagou_transactions_boleto')->result(); }
function pays($inv) { return get_instance()->db->where('invoiceid', $inv)->get('tblinvoicepaymentrecords')->result(); }
function run($inv, $auto) {
    $GLOBALS['__view'] = null; $GLOBALS['__alert'] = null;
    try { return (new Pagou_boleto_gateway())->process_payment(['invoice' => inv($inv), 'auto_generated' => $auto]); }
    catch (RedirectException $e) { return 'redirect'; }
}
$bar44 = str_repeat('1', 44);
$charge = function ($id, $status, $extra = []) use ($bar44) {
    return ['code' => 200, 'body' => array_merge(['id' => $id, 'status' => $status, 'amount' => 120, 'due_at' => date('Y-m-d', strtotime('+5 days')) . 'T03:00:00Z',
        'payload' => ['line' => 'LINHA-' . $id, 'bar_code' => $bar44]], $extra)];
};

$db->insert('tblinvoices', ['id' => 20, 'total' => 120, 'status' => 1, 'invoice_pagou' => 0, 'hash' => 'h20', 'duedate' => date('Y-m-d', strtotime('+5 days')), 'clientid' => 1]);

echo "Cadastro incompleto\n";
$GLOBALS['__client_full'] = false;
reset_calls();
$r = run(20, true);
t('modo automático: false, sem redirect e sem chamada', $r === false && count(calls()) === 0);
t('não inventa endereço', count(brows(20)) === 0);
$GLOBALS['__client_full'] = true;

echo "Emissão automática\n";
reset_calls(); api(['POST /v1/charges' => ['code' => 201, 'body' => ['id' => 'b-1', 'status' => 1, 'amount' => 120]]]);
$r = run(20, true);
t('modo automático retorna sucesso sem view', is_array($r) && $r['success'] && $GLOBALS['__view'] === null);
$req = json_decode(last_call('POST /v1/charges')['body'], true);
t('grace_period sempre presente', $req['grace_period'] === 1);
t('pagador completo e normalizado', $req['payer'] === ['name' => 'Cliente Boleto', 'document' => '52998224725', 'zip' => '01310100', 'street' => 'Avenida Paulista', 'city' => 'São Paulo', 'state' => 'SP', 'number' => '1000', 'neighborhood' => 'Bela Vista']);
t('multa e juros como números', $req['fine'] == 2 && $req['interest'] == 1 && !isset($req['external_id']) && strpos($req['customer_code'], 'perfex-20-b') === 0);

echo "Página: registro e reaproveitamento\n";
reset_calls(); api(['GET /v1/charges/b-1' => $charge('b-1', 2)]);
$r = run(20, false);
t('mostra linha digitável e código de barras', $r === true && $GLOBALS['__view'][1]['line'] === 'LINHA-b-1' && strpos($GLOBALS['__view'][1]['barcode_svg'], '<svg') !== false);
t('links HTML e PDF', $GLOBALS['__view'][1]['boleto_pdf_url'] === 'https://fatura.pagou.com.br/boleto/pdf/b-1');
t('não emite outro boleto', last_call('POST /v1/charges') === null && count(brows(20)) === 1);

echo "Vencido após carência\n";
$db->insert('tblinvoices', ['id' => 21, 'total' => 120, 'status' => 4, 'invoice_pagou' => 1, 'hash' => 'h21', 'duedate' => date('Y-m-d', strtotime('-10 days')), 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 21, 'transaction_id' => 'b-old', 'amount' => 120, 'status' => 'active', 'date_created' => date('Y-m-d H:i:s', strtotime('-10 days')), 'due_date' => date('Y-m-d', strtotime('-10 days'))]);
reset_calls();
api(['GET /v1/charges/b-old' => $charge('b-old', 2, ['due_at' => date('Y-m-d', strtotime('-10 days')) . 'T03:00:00Z']), 'DELETE /v1/charges/b-old' => ['code' => 204],
     'POST /v1/charges' => ['code' => 201, 'body' => ['id' => 'b-2', 'status' => 1]], 'GET /v1/charges/b-2' => $charge('b-2', 2)]);
$r = run(21, false);
t('cancela o vencido e emite novo com vencimento hoje', last_call('DELETE /v1/charges/b-old') !== null && json_decode(last_call('POST /v1/charges')['body'], true)['due_date'] === max(date('Y-m-d'), gmdate('Y-m-d')));
t('antigo aguarda o cancelamento (cancel_requested)', brows(21)[0]->status === 'cancel_requested');

echo "Boleto da 1.x com due_date local errado mas ainda válido na API\n";
$db->insert('tblinvoices', ['id' => 22, 'total' => 120, 'status' => 4, 'invoice_pagou' => 1, 'hash' => 'h22', 'duedate' => date('Y-m-d', strtotime('-10 days')), 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 22, 'transaction_id' => 'b-legacy', 'amount' => 120, 'status' => '2', 'date_created' => date('Y-m-d H:i:s'), 'due_date' => date('Y-m-d', strtotime('-10 days'))]);
reset_calls(); api(['GET /v1/charges/b-legacy' => $charge('b-legacy', 2, ['due_at' => date('Y-m-d') . 'T03:00:00Z'])]);
run(22, false);
t('usa due_at da API e reaproveita', last_call('DELETE /v1/charges/b-legacy') === null && last_call('POST /v1/charges') === null);

echo "Cancelamento recusado (400)\n";
$db->insert('tblinvoices', ['id' => 23, 'total' => 120, 'status' => 4, 'invoice_pagou' => 1, 'hash' => 'h23', 'duedate' => date('Y-m-d', strtotime('-10 days')), 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 23, 'transaction_id' => 'b-nc', 'amount' => 120, 'status' => 'active', 'date_created' => date('Y-m-d H:i:s'), 'due_date' => date('Y-m-d', strtotime('-10 days'))]);
reset_calls();
api(['GET /v1/charges/b-nc' => $charge('b-nc', 2, ['due_at' => date('Y-m-d', strtotime('-10 days')) . 'T03:00:00Z']), 'DELETE /v1/charges/b-nc' => ['code' => 400, 'body' => ['error' => 'x', 'code' => 'CHARGE_2']],
     'POST /v1/charges' => ['code' => 201, 'body' => ['id' => 'b-3', 'status' => 1]], 'GET /v1/charges/b-3' => $charge('b-3', 2)]);
run(23, false);
t('vencido e não pago: segue com nova emissão', last_call('POST /v1/charges') !== null && brows(23)[0]->status === 'expired');

echo "Cancelamento indeterminado\n";
$db->insert('tblinvoices', ['id' => 24, 'total' => 120, 'status' => 4, 'invoice_pagou' => 1, 'hash' => 'h24', 'duedate' => date('Y-m-d', strtotime('-10 days')), 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 24, 'transaction_id' => 'b-unc', 'amount' => 120, 'status' => 'active', 'date_created' => date('Y-m-d H:i:s'), 'due_date' => date('Y-m-d', strtotime('-10 days'))]);
reset_calls();
api(['GET /v1/charges/b-unc' => $charge('b-unc', 2, ['due_at' => date('Y-m-d', strtotime('-10 days')) . 'T03:00:00Z']), 'DELETE /v1/charges/b-unc' => ['code' => 503]]);
$r = run(24, true);
t('503 no cancelamento: indeterminado, sem nova emissão', $r === 'uncertain' && last_call('POST /v1/charges') === null);

echo "Pago pelo Pix vinculado\n";
$db->insert('tblinvoices', ['id' => 25, 'total' => 120, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h25', 'duedate' => date('Y-m-d', strtotime('+5 days')), 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 25, 'transaction_id' => 'b-pix', 'amount' => 120, 'status' => 'active', 'date_created' => date('Y-m-d H:i:s'), 'due_date' => date('Y-m-d', strtotime('+5 days'))]);
reset_calls(); api(['GET /v1/charges/b-pix' => $charge('b-pix', 3, ['payload' => ['line' => 'L', 'bar_code' => $bar44, 'qrcode_id' => 'qr-25', 'qrcode_status' => 4, 'data' => 'copia']])]);
run(25, false);
t('dá baixa e não reemite', count(pays(25)) === 1 && last_call('POST /v1/charges') === null && brows(25)[0]->status === 'paid');

echo "Valor em aberto mudou\n";
$db->insert('tblinvoicepaymentrecords', ['invoiceid' => 20, 'amount' => 20, 'paymentmode' => 'x', 'transactionid' => 'manual']);
$db->where('id', 20)->update('tblinvoices', ['status' => 3]);
reset_calls(); api(['GET /v1/charges/b-1' => $charge('b-1', 2), 'DELETE /v1/charges/b-1' => ['code' => 204], 'POST /v1/charges' => ['code' => 201, 'body' => ['id' => 'b-4', 'status' => 1]], 'GET /v1/charges/b-4' => $charge('b-4', 2)]);
run(20, false);
t('cancela e emite pelo saldo', last_call('DELETE /v1/charges/b-1') !== null && json_decode(last_call('POST /v1/charges')['body'], true)['amount'] == 100);

echo "Criação indeterminada\n";
$db->insert('tblinvoices', ['id' => 26, 'total' => 60, 'status' => 1, 'invoice_pagou' => 0, 'hash' => 'h26', 'duedate' => date('Y-m-d', strtotime('+5 days')), 'clientid' => 1]);
api(['POST /v1/charges' => ['code' => 0]]);
api(['POST /v1/charges' => ['code' => 504]]);
$r = run(26, true);
t('504 na criação: uncertain', $r === 'uncertain' && brows(26)[0]->status === 'uncertain');
$code = brows(26)[0]->customer_code;
reset_calls(); api(['GET /v1/charges' => ['code' => 200, 'body' => [['id' => 'b-5', 'customer_code' => $code, 'status' => 1]]], 'GET /v1/charges/b-5' => $charge('b-5', 2)]);
$r = run(26, true);
t('recupera pela listagem sem nova emissão', is_array($r) && $r['transaction_id'] === 'b-5' && last_call('POST /v1/charges') === null);

echo "Revisão: boleto vigente mantido, later e due_at\n";
$db->insert('tblinvoices', ['id' => 27, 'total' => 150, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h27', 'duedate' => date('Y-m-d', strtotime('+5 days')), 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 27, 'transaction_id' => 'b-keep', 'amount' => 120, 'status' => 'active', 'date_created' => date('Y-m-d H:i:s'), 'due_date' => date('Y-m-d', strtotime('+5 days'))]);
reset_calls();
api(['GET /v1/charges/b-keep' => $charge('b-keep', 2), 'DELETE /v1/charges/b-keep' => ['code' => 400, 'body' => ['error' => 'x', 'code' => 'CHARGE_2']]]);
$r = run(27, false);
t('valor mudou e cancelamento recusado: mostra o boleto vigente', $r === true && $GLOBALS['__view'][1]['pagou_transaction_id'] === 'b-keep' && last_call('POST /v1/charges') === null);
$saved = get_option('paymentmethod_pagou_boleto_api_token');
update_option('paymentmethod_pagou_boleto_api_token', '');
$db->insert('tblinvoices', ['id' => 28, 'total' => 60, 'status' => 1, 'invoice_pagou' => 0, 'hash' => 'h28', 'duedate' => date('Y-m-d', strtotime('+5 days')), 'clientid' => 1]);
t('sem chave no modo automático: later (tentar depois)', run(28, true) === 'later');
update_option('paymentmethod_pagou_boleto_api_token', $saved);
$db->insert('tblinvoices', ['id' => 29, 'total' => 120, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h29', 'duedate' => date('Y-m-d'), 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 29, 'transaction_id' => 'b-utc', 'amount' => 120, 'status' => 'active', 'date_created' => date('Y-m-d H:i:s'), 'due_date' => date('Y-m-d', strtotime('-3 days'))]);
reset_calls();
api(['GET /v1/charges/b-utc' => $charge('b-utc', 2, ['due_at' => date('Y-m-d', strtotime('-1 day')) . 'T00:00:00Z'])]);
run(29, false);
t('due_at à meia-noite UTC não antecipa o vencimento', last_call('DELETE /v1/charges/b-utc') === null);

echo "\n$ok ok, $fail falhas\n";
exit($fail ? 1 : 0);
