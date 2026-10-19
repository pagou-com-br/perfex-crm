<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gateway_stubs.php';
require $MODULE . '/libraries/Pagou_pix_gateway.php';
$db = get_instance()->db;

// Depende do banco preparado por test_events e test_card (esquema aplicado, campos personalizados)
Pagou_schema::apply(true);
class SplitClients {
    public function get($id) {
        return (object) ['userid' => $id, 'company' => 'Cliente Split', 'vat' => '52998224725', 'phonenumber' => '11999999999',
            'billing_street' => 'Avenida Paulista', 'billing_city' => 'São Paulo', 'billing_state' => 'SP', 'billing_zip' => '01310-100',
            'address' => '', 'city' => '', 'state' => '', 'zip' => ''];
    }
}
get_instance()->clients_model = new SplitClients();
foreach (['tblpagou_split_rules', 'tblpagou_split_recipients', 'tblpagou_split_snapshots'] as $t) { $db->query("DELETE FROM $t"); }
$db->query("DELETE FROM tblpagou_transactions_pix WHERE invoice_id >= 60");
$db->query("DELETE FROM tblpagou_card_charges WHERE invoice_id >= 60");
$db->query("DELETE FROM tblinvoices WHERE id >= 60");
$db->query("DELETE FROM tblinvoicepaymentrecords WHERE invoiceid >= 60");
update_option('paymentmethod_pagou_pix_api_token', 'enc:' . base64_encode('pixkey'));
update_option('paymentmethod_pagou_pix_enable_split', '1');
update_option('paymentmethod_pagou_cartao_enable_split', '1');
update_option('paymentmethod_pagou_cartao_api_token', 'enc:' . base64_encode('cardkey'));
update_option('pagou_split_min_margin_pct', '10');
$db->where('name', 'paymentmethod_pagou_pix_pix_mode')->delete('tbloptions');

$ok = 0; $fail = 0;
function t($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FALHOU  $name\n"; } }
function api($state) { file_put_contents(__DIR__ . '/state.json', json_encode($state)); }
function inv($id) { return get_instance()->db->where('id', $id)->get('tblinvoices')->row(); }
function pay($inv) {
    $GLOBALS['__view'] = null; $GLOBALS['__alert'] = null;
    try { (new Pagou_pix_gateway())->process_payment(['invoice' => inv($inv)]); return 'view'; } catch (RedirectException $e) { return 'redirect'; }
}
$A = '60000000-0000-4000-8000-000000000003';
$B = '60000000-0000-4000-8000-000000000004';
$db->insert('tblpagou_split_recipients', ['name' => 'Parceiro A', 'customer_uuid' => $A, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]); $ra = $db->insert_id();
$db->insert('tblpagou_split_recipients', ['name' => 'Parceiro B', 'customer_uuid' => $B, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]); $rb = $db->insert_id();
$db->insert('tblpagou_split_rules', ['scope' => 'global', 'scope_id' => 0, 'recipient_id' => $ra, 'type' => 'fixed', 'value' => 20, 'methods' => 'pix,boleto,cartao', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
$db->insert('tblpagou_split_rules', ['scope' => 'global', 'scope_id' => 0, 'recipient_id' => $rb, 'type' => 'percentage', 'value' => 25, 'methods' => 'pix', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);

echo "Resolução das regras\n";
$db->insert('tblinvoices', ['id' => 60, 'total' => 100, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h60', 'duedate' => null, 'clientid' => 1]);
$split = (new Pagou_split())->resolve(inv(60), 'pix', 100, $err);
t('global: fixo em reais e percentual', $err === null && $split === [['customer_id' => $A, 'type' => 'fixed', 'value' => 20.0], ['customer_id' => $B, 'type' => 'percentage', 'value' => 25.0]]);
$split = (new Pagou_split())->resolve(inv(60), 'cartao', 100, $err);
t('regra só vale para os meios marcados', count($split) === 1 && $split[0]['customer_id'] === $A);
$db->insert('tblpagou_split_rules', ['scope' => 'client', 'scope_id' => 1, 'recipient_id' => $rb, 'type' => 'percentage', 'value' => 10, 'methods' => 'pix', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
$split = (new Pagou_split())->resolve(inv(60), 'pix', 100, $err);
t('regra do cliente substitui as globais', $split === [['customer_id' => $B, 'type' => 'percentage', 'value' => 10.0]]);
$db->where('scope', 'client')->delete('tblpagou_split_rules');
$db->insert('tblinvoices', ['id' => 61, 'total' => 30, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h61', 'duedate' => null, 'clientid' => 1]);
$split = (new Pagou_split())->resolve(inv(61), 'pix', 30, $err);
t('acima da margem para tarifas: erro e nenhum split', $split === [] && strpos($err, 'margem mínima') !== false);

echo "Pix com split\n";
reset_calls();
api(['POST /v1/pix' => ['code' => 201, 'body' => ['id' => 'sp-1', 'status' => 2, 'payload' => ['data' => 'C', 'image' => ''], 'split' => ['fee' => 1, 'status' => 1, 'allocations' => []]]]]);
pay(60);
$req = json_decode(last_call('POST /v1/pix')['body'], true);
t('split no corpo da criação', isset($req['split']) && count($req['split']) === 2);
$snap = $db->where('charge_id', 'sp-1')->get('tblpagou_split_snapshots')->row();
t('snapshot gravado com status 1', $snap && (int) $snap->split_status === 1 && (float) $snap->fee === 1.0);
reset_calls();
t('regra inválida bloqueia a emissão', pay(61) === 'redirect' && last_call('POST /v1/pix') === null);

echo "Cartão com split nos bytes idempotentes\n";
$db->insert('tblinvoices', ['id' => 62, 'total' => 100, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h62', 'duedate' => null, 'clientid' => 1]);
reset_calls();
api(['POST /v1/creditcard/charges' => ['code' => 502]]);
$card = ['card_token' => str_repeat('QUJD', 30), 'holder' => 'CLIENTE', 'expires_at' => '2030-12'];
(new Pagou_card())->charge(inv(62), $card, 1, ['email' => 'a@example.com', 'phone' => '11999999999'], '198.51.100.7');
$first = last_call('POST /v1/creditcard/charges');
$req = json_decode($first['body'], true);
t('cartão: split fixo em reais com cobrança em centavos', $req['value'] === 10000 && $req['split'] === [['customer_id' => $A, 'type' => 'fixed', 'value' => 20]]);
api(['POST /v1/creditcard/charges' => ['code' => 201, 'body' => ['id' => 'sp-c1', 'value' => 10000, 'status' => 'captured', 'split' => ['status' => 1]]], 'GET /v1/creditcard/charges/sp-c1' => ['code' => 200, 'body' => ['id' => 'sp-c1', 'value' => 10000, 'status' => 'captured', 'split' => ['status' => 2]]]]);
$row = $db->where('invoice_id', 62)->get('tblpagou_card_charges')->row();
(new Pagou_events())->settle_card($row);
t('repetição envia o mesmo split (mesmos bytes)', last_call('POST /v1/creditcard/charges')['body'] === $first['body']);

echo "Acompanhamento\n";
api(['GET /v1/pix/sp-1' => ['code' => 200, 'body' => ['id' => 'sp-1', 'status' => 4, 'amount' => 100, 'split' => ['fee' => 1, 'status' => 6, 'allocations' => [['id' => 'al-1', 'status' => 6]]]]]]);
$GLOBALS['__activity'] = [];
update_option('pagou_last_reconcile', '0');
(new Pagou_events())->reconcile();
$snap = $db->where('charge_id', 'sp-1')->get('tblpagou_split_snapshots')->row();
$found = false; foreach ($GLOBALS['__activity'] as $a) { if (strpos($a[1], 'ação necessária') !== false) { $found = true; } }
t('status 6 atualizado e vira atividade na fatura', (int) $snap->split_status === 6 && $found);

echo "Revisão: precedência, isenção, saldo e arredondamento\n";
$db->insert('tblpagou_split_recipients', ['name' => 'Parceiro C', 'customer_uuid' => '60000000-0000-4000-8000-000000000005', 'active' => 0, 'created_at' => date('Y-m-d H:i:s')]); $rc = $db->insert_id();
$db->insert('tblpagou_split_rules', ['scope' => 'client', 'scope_id' => 1, 'recipient_id' => $rc, 'type' => 'percentage', 'value' => 30, 'methods' => 'pix', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
$split = (new Pagou_split())->resolve(inv(60), 'pix', 100, $err);
t('recebedor inativo na regra do cliente não cai nas globais', $split === [] && $err === null);
$db->where('scope', 'client')->delete('tblpagou_split_rules');
$db->insert('tblpagou_split_rules', ['scope' => 'client', 'scope_id' => 1, 'recipient_id' => 0, 'type' => 'none', 'value' => 0, 'methods' => 'pix', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
t('regra "sem split" isenta o cliente', (new Pagou_split())->resolve(inv(60), 'pix', 100, $err) === []);
$db->where('scope', 'client')->delete('tblpagou_split_rules');
$db->insert('tblinvoices', ['id' => 63, 'total' => 1000, 'status' => 3, 'invoice_pagou' => 1, 'hash' => 'h63', 'duedate' => null, 'clientid' => 1]);
$split = (new Pagou_split())->resolve(inv(63), 'cartao', 200, $err);
t('fixo proporcional ao saldo cobrado (R$ 20 de 1000 → R$ 4 em 200)', $split === [['customer_id' => $A, 'type' => 'fixed', 'value' => 4.0]]);
t('meio centavo arredonda para cima (50% de 113 = 57)', Pagou_split::percent_cents(113, 50) === 57 && Pagou_split::percent_cents(100, 33.3333) === 33);

echo "Revisão: recuperação ganha acompanhamento\n";
$db->insert('tblinvoices', ['id' => 64, 'total' => 100, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h64', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/pix' => ['code' => 502]]);
pay(64);
$row64 = $db->where('invoice_id', 64)->get('tblpagou_transactions_pix')->row();
$db->where('id', $row64->id)->update('tblpagou_transactions_pix', ['date_created' => date('Y-m-d H:i:s', time() - 400)]);
api(['GET /v1/pix' => ['code' => 200, 'body' => [['id' => 'sp-rec', 'customer_code' => $row64->customer_code, 'status' => 2]]],
     'GET /v1/pix/sp-rec' => ['code' => 200, 'body' => ['id' => 'sp-rec', 'status' => 2, 'payload' => ['data' => 'C', 'image' => ''], 'split' => ['status' => 1]]]]);
pay(64);
t('Pix adotado pela listagem ganha snapshot do split', $db->where('charge_id', 'sp-rec')->count_all_results('tblpagou_split_snapshots') === 1);
$card_row = $db->where('invoice_id', 62)->get('tblpagou_card_charges')->row();
t('cartão recuperado por repetição ganha snapshot', $db->where('charge_id', $card_row->pagou_charge_id)->count_all_results('tblpagou_split_snapshots') === 1);

echo "Revisão: regra mudou e a cobrança reaproveitada é reemitida\n";
$db->insert('tblinvoices', ['id' => 65, 'total' => 100, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h65', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/pix' => ['code' => 201, 'body' => ['id' => 'sp-h1', 'status' => 2, 'payload' => ['data' => 'C1', 'image' => '']]]]);
pay(65);
$db->where('recipient_id', $rb)->update('tblpagou_split_rules', ['value' => 30]);
reset_calls();
api(['DELETE /v1/pix/sp-h1' => ['code' => 204], 'GET /v1/pix/sp-h1' => ['code' => 200, 'body' => ['id' => 'sp-h1', 'status' => 2]], 'POST /v1/pix' => ['code' => 201, 'body' => ['id' => 'sp-h2', 'status' => 2, 'payload' => ['data' => 'C2', 'image' => '']]]]);
pay(65);
t('split diferente: cancela e reemite', last_call('DELETE /v1/pix/sp-h1') !== null && last_call('POST /v1/pix') !== null);
$db->where('recipient_id', $rb)->update('tblpagou_split_rules', ['value' => 25]);

echo "Revisão: recusa de split pela API\n";
$db->insert('tblinvoices', ['id' => 66, 'total' => 100, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h66', 'duedate' => null, 'clientid' => 1]);
$db->where('name', 'pagou_split_err_66')->delete('tbloptions');
api(['POST /v1/pix' => ['code' => 400, 'body' => ['error' => 'payment amount must cover allocations and fees']]]);
$GLOBALS['__activity'] = [];
pay(66);
$logged = false; foreach ($GLOBALS['__activity'] as $a) { if (strpos($a[1], 'recusou o split') !== false) { $logged = true; } }
t('pagador vê mensagem genérica e o staff recebe o motivo', strpos($GLOBALS['__alert'][1], 'allocations') === false && $logged);

echo "\n$ok ok, $fail falhas\n";
exit($fail ? 1 : 0);
