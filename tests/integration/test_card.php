<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gateway_stubs.php';
$db = get_instance()->db;

class CardClients {
    public function get($id) {
        return (object) ['userid' => $id, 'company' => 'Cliente Cartão', 'vat' => $GLOBALS['__card_vat'] ?? '52998224725', 'phonenumber' => '11999999999',
            'billing_street' => 'Avenida Paulista', 'billing_city' => 'São Paulo', 'billing_state' => 'SP', 'billing_zip' => '01310-100',
            'address' => '', 'city' => '', 'state' => '', 'zip' => ''];
    }
}
get_instance()->clients_model = new CardClients();

// Tabelas de campos personalizados (número e bairro)
foreach (['tblcustomfields', 'tblcustomfieldsvalues'] as $t) { $db->query("DROP TABLE IF EXISTS $t"); }
$db->query("CREATE TABLE tblcustomfields (id int auto_increment primary key, fieldto varchar(30), slug varchar(100))");
$db->query("CREATE TABLE tblcustomfieldsvalues (id int auto_increment primary key, relid int, fieldid int, fieldto varchar(30), value text)");
$db->insert('tblcustomfields', ['fieldto' => 'customers', 'slug' => 'customers_numero']); $num = $db->insert_id();
$db->insert('tblcustomfields', ['fieldto' => 'customers', 'slug' => 'customers_bairro']); $bai = $db->insert_id();
$db->insert('tblcustomfieldsvalues', ['relid' => 1, 'fieldid' => $num, 'fieldto' => 'customers', 'value' => '1000']);
$db->insert('tblcustomfieldsvalues', ['relid' => 1, 'fieldid' => $bai, 'fieldto' => 'customers', 'value' => 'Bela Vista']);

$db->query("DELETE FROM tblpagou_card_charges");
$db->query("DELETE FROM tblinvoices WHERE id >= 40");
$db->query("DELETE FROM tblinvoicepaymentrecords WHERE invoiceid >= 40");
update_option('paymentmethod_pagou_cartao_api_token', 'enc:' . base64_encode('cardkey'));
update_option('paymentmethod_pagou_cartao_is_production', '0');

$ok = 0; $fail = 0;
function t($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FALHOU  $name\n"; } }
function api($state) { file_put_contents(__DIR__ . '/state.json', json_encode($state)); }
function inv($id) { return get_instance()->db->where('id', $id)->get('tblinvoices')->row(); }
function crows($inv) { return get_instance()->db->where('invoice_id', $inv)->order_by('id')->get('tblpagou_card_charges')->result(); }
function pays($inv) { return get_instance()->db->where('invoiceid', $inv)->get('tblinvoicepaymentrecords')->result(); }
$token = str_repeat('QUJD', 30);
$card = ['card_token' => $token, 'holder' => 'CLIENTE TESTE', 'expires_at' => '2030-12'];
$contact = ['email' => 'cliente@example.com', 'phone' => '(11) 99999-9999'];
function charge($inv, $installments = 1, $ip = '198.51.100.7') {
    global $card, $contact;
    return (new Pagou_card())->charge(inv($inv), $card, $installments, $contact, $ip);
}

echo "Aprovado na criação\n";
$db->insert('tblinvoices', ['id' => 40, 'total' => 50, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h40', 'duedate' => null, 'clientid' => 1]);
reset_calls();
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'cc-1', 'customer_id' => 'cus-1', 'card_id' => 'card-1', 'value' => 5000, 'installments' => 1, 'status' => 'captured']],
     'GET /v1/creditcard/charges/cc-1' => ['code' => 200, 'body' => ['id' => 'cc-1', 'value' => 5000, 'status' => 'captured']]]);
$r = charge(40);
t('resultado paid e fatura paga', $r['result'] === 'paid' && (int) inv(40)->status === 2 && count(pays(40)) === 1);
$call = last_call('POST /v1/creditcard/charges');
$req = json_decode($call['body'], true);
t('valor em centavos, payday UTC, IP real e parcelas', $req['value'] === 5000 && $req['payday'] === gmdate('Y-m-d') && $req['antifraud'] === ['ip' => '198.51.100.7'] && $req['installments'] === 1);
t('pagador completo e normalizado', $req['customer']['document_type'] === 'cpf' && $req['customer']['phone'] === '11999999999' && $req['customer']['address']['zip_code'] === '01310100' && $req['customer']['address']['neighborhood'] === 'Bela Vista');
t('cartão só com token, titular e validade', array_keys($req['card']) === ['card_token', 'holder', 'expires_at']);
$row = crows(40)[0];
t('IDs salvos, corpo descartado após estado final', $row->pagou_customer_id === 'cus-1' && $row->pagou_card_id === 'card-1' && $row->request_enc === null);
t('pagamento registrado em reais com modo pagou_cartao', (float) pays(40)[0]->amount === 50.0 && pays(40)[0]->paymentmode === 'pagou_cartao');

echo "Recusado\n";
$db->insert('tblinvoices', ['id' => 41, 'total' => 80, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h41', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'cc-2', 'value' => 8000, 'status' => 'failed', 'failure_reason' => 'saldo insuficiente']]]);
$r = charge(41);
t('falha mostra o motivo e não registra pagamento', $r['result'] === 'failed' && strpos($r['message'], 'saldo insuficiente') !== false && count(pays(41)) === 0);
reset_calls();
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'cc-3', 'value' => 8000, 'status' => 'captured']], 'GET /v1/creditcard/charges/cc-3' => ['code' => 200, 'body' => ['id' => 'cc-3', 'value' => 8000, 'status' => 'captured']]]);
$r = charge(41);
$keys = array_map(function ($r) { return $r->idempotency_key; }, crows(41));
t('nova compra com outra chave', $r['result'] === 'paid' && count(array_unique($keys)) === 2);

echo "Resultado indeterminado\n";
$db->insert('tblinvoices', ['id' => 42, 'total' => 30, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h42', 'duedate' => null, 'clientid' => 1]);
reset_calls();
api(['POST /v1/creditcard/charges' => ['code' => 502]]);
$r = charge(42);
$first = last_call('POST /v1/creditcard/charges');
t('502: processing e linha uncertain', $r['result'] === 'processing' && crows(42)[0]->status === 'uncertain');
$r = charge(42);
t('enquanto indeterminado, nova tentativa é bloqueada', $r['result'] === 'processing' && count(crows(42)) === 1);
reset_calls();
api(['POST /v1/creditcard/charges' => ['code' => 409, 'body' => ['error' => 'x', 'code' => 'idempotency_in_progress']]]);
(new Pagou_events())->settle_card(crows(42)[0]);
t('409 in_progress mantém uncertain', crows(42)[0]->status === 'uncertain');
reset_calls();
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'cc-4', 'value' => 3000, 'status' => 'captured']], 'GET /v1/creditcard/charges/cc-4' => ['code' => 200, 'body' => ['id' => 'cc-4', 'value' => 3000, 'status' => 'captured']]]);
(new Pagou_events())->settle_card(crows(42)[0]);
$replay = last_call('POST /v1/creditcard/charges');
t('recuperação repete os mesmos bytes', $replay['body'] === $first['body']);
t('fatura paga após a recuperação', (int) inv(42)->status === 2 && count(pays(42)) === 1);

echo "Conflito e cadastro incompleto\n";
$db->insert('tblinvoices', ['id' => 43, 'total' => 20, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h43', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/creditcard/charges' => ['code' => 422, 'body' => ['error' => 'conflito', 'code' => 'idempotency_key_conflict']]]);
charge(43);
t('422 idempotency_key_conflict: needs_review', crows(43)[0]->status === 'needs_review');
$db->insert('tblinvoices', ['id' => 44, 'total' => 20, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h44', 'duedate' => null, 'clientid' => 2]);
$GLOBALS['__card_vat'] = '123';
reset_calls();
$r = charge(44);
t('documento inválido: erro e nenhuma chamada', $r['result'] === 'error' && count(calls()) === 0 && count(crows(44)) === 0);
$GLOBALS['__card_vat'] = '52998224725';
$r = (new Pagou_card())->charge(inv(44), $card, 1, $contact, 'nao-e-ip');
t('IP inválido: erro', $r['result'] === 'error');

echo "Autorizado e capturado depois\n";
$db->insert('tblinvoices', ['id' => 45, 'total' => 70, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h45', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'cc-5', 'value' => 7000, 'status' => 'authorized']]]);
$r = charge(45);
t('authorized: processing, sem baixa', $r['result'] === 'processing' && count(pays(45)) === 0);
api(['GET /v1/creditcard/charges/cc-5' => ['code' => 200, 'body' => ['id' => 'cc-5', 'value' => 7000, 'status' => 'captured']]]);
update_option('pagou_last_reconcile', '0');
(new Pagou_events())->reconcile();
t('cron captura e dá baixa', count(pays(45)) === 1 && crows(45)[0]->status === 'captured');
api(['GET /v1/creditcard/charges/cc-5' => ['code' => 200, 'body' => ['id' => 'cc-5', 'value' => 7000, 'status' => 'reversed']]]);
$GLOBALS['__activity'] = [];
(new Pagou_events())->settle_card(crows(45)[0]);
$found = false; foreach ($GLOBALS['__activity'] as $a) { if (strpos($a[1], 'Estorno da cobrança de cartão cc-5') !== false) { $found = true; } }
t('estorno pelo painel vira atividade', $found && crows(45)[0]->status === 'reversed');

echo "Revisão: baixa falhando depois da captura\n";
$db->insert('tblinvoices', ['id' => 46, 'total' => 25, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h46', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'cc-6', 'value' => 2500, 'status' => 'captured']], 'GET /v1/creditcard/charges/cc-6' => ['code' => 502]]);
$r = charge(46);
t('captured sem baixa: processing (não "paid")', $r['result'] === 'processing' && count(pays(46)) === 0);
reset_calls();
$r = charge(46);
t('capturada sem baixa bloqueia nova compra', $r['result'] === 'processing' && last_call('POST /v1/creditcard/charges') === null);

echo "Revisão: revisão bloqueia, token descartado, status normalizado\n";
$r = charge(43);
t('needs_review bloqueia nova compra', $r['result'] === 'processing' && count(crows(43)) === 1);
$db->insert('tblinvoices', ['id' => 47, 'total' => 25, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h47', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'cc-7', 'value' => 2500, 'status' => 'authorized']]]);
charge(47);
t('corpo com token apagado assim que há ID', crows(47)[0]->request_enc === null);
api(['GET /v1/creditcard/charges/cc-7' => ['code' => 200, 'body' => ['id' => 'cc-7', 'value' => 2500, 'status' => 'in_analysis']]]);
(new Pagou_events())->settle_card(crows(47)[0]);
t('estado desconhecido vira unknown (segue acompanhado)', crows(47)[0]->status === 'unknown');

echo "Revisão: limite de recusas\n";
$db->insert('tblinvoices', ['id' => 48, 'total' => 25, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h48', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'x', 'value' => 2500, 'status' => 'failed', 'failure_reason' => 'recusado']]]);
for ($i = 0; $i < 5; $i++) {
    file_put_contents(__DIR__ . '/state.json', json_encode(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'f-' . $i, 'value' => 2500, 'status' => 'failed', 'failure_reason' => 'recusado']]]));
    charge(48, 1, '203.0.113.' . (10 + $i));
}
reset_calls();
$r = charge(48, 1, '203.0.113.99');
t('5 recusas na fatura em 1 hora bloqueiam novas tentativas', $r['result'] === 'error' && last_call('POST /v1/creditcard/charges') === null);

echo "Revisão: IP do comprador\n";
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.20, 10.0.0.9';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.30';
t('remote: ignora cabeçalhos', pagou_client_ip('remote', '10.0.0.0/8') === '10.0.0.5');
t('xff: primeiro IP não confiável da direita para a esquerda', pagou_client_ip('xff', '10.0.0.0/8') === '198.51.100.20');
t('xff sem proxy confiável: REMOTE_ADDR', pagou_client_ip('xff', '192.168.0.0/16') === '10.0.0.5');
t('cloudflare com proxy confiável', pagou_client_ip('cloudflare', '10.0.0.0/8') === '198.51.100.30');
t('cloudflare vindo de fora: ignora o cabeçalho', pagou_client_ip('cloudflare', '172.16.0.0/12') === '10.0.0.5');
t('IPv6 em faixa CIDR', pagou_ip_in_range('2400:cb00::1', '2400:cb00::/32') && !pagou_ip_in_range('2401:cb00::1', '2400:cb00::/32'));

echo "\n$ok ok, $fail falhas\n";
exit($fail ? 1 : 0);
