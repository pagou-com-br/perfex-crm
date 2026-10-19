<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/gateway_stubs.php';
require $MODULE . '/libraries/Pagou_pix_gateway.php';
$db = get_instance()->db;

// Banco já preparado por test_events.php (esquema 2.0 aplicado); limpa só o necessário
$db->query("DELETE FROM tblpagou_transactions_pix WHERE invoice_id >= 10");
$db->query("DELETE FROM tblinvoices WHERE id >= 10");
$db->query("DELETE FROM tblinvoicepaymentrecords WHERE invoiceid >= 10");
update_option('paymentmethod_pagou_pix_api_token', 'enc:' . base64_encode('pixkey'));
foreach (['pix_mode', 'enable_fine', 'fine_value', 'enable_interest', 'interest_value', 'interest_type', 'enable_discount', 'discount_value', 'discount_type', 'discount_days_before', 'due_expiration_days', 'expiration'] as $o) {
    $db->where('name', 'paymentmethod_pagou_pix_' . $o)->delete('tbloptions');
}

$ok = 0; $fail = 0;
function t($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FALHOU  $name\n"; } }
function api($state) { file_put_contents(__DIR__ . '/state.json', json_encode($state)); }
function inv($id) { return get_instance()->db->where('id', $id)->get('tblinvoices')->row(); }
function rows($inv) { return get_instance()->db->where('invoice_id', $inv)->order_by('id')->get('tblpagou_transactions_pix')->result(); }
function pay($inv) {
    $GLOBALS['__view'] = null; $GLOBALS['__alert'] = null;
    $g = new Pagou_pix_gateway();
    try { $g->process_payment(['invoice' => inv($inv)]); return 'view'; } catch (RedirectException $e) { return 'redirect'; }
}
$created = ['code' => 201, 'body' => ['id' => 'np-1', 'status' => 2, 'payload' => ['payload_id' => 1, 'data' => 'COPIA1', 'image' => 'IMG1']]];

$db->insert('tblinvoices', ['id' => 10, 'total' => 150, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h10', 'duedate' => date('Y-m-d', strtotime('+5 days')), 'clientid' => 1]);

echo "Pix imediato\n";
reset_calls(); api(['POST /v1/pix' => $created]);
t('cria e mostra o QR', pay(10) === 'view' && $GLOBALS['__view'][1]['qr_code_text'] === 'COPIA1');
$req = json_decode(last_call('POST /v1/pix')['body'], true);
t('corpo conforme contrato (sem external_id/email, com customer_code, expiration em segundos)',
    !isset($req['external_id']) && !isset($req['payer']['email']) && strpos($req['customer_code'], 'perfex-10-') === 0 && $req['expiration'] === 86400 && $req['amount'] == 150 && $req['payer']['document'] === '52998224725');
t('notification_url pública fora de /admin', $req['notification_url'] === 'https://crm.test/pagou/pagou_webhook/process');
t('não envia encargos no Pix imediato', !isset($req['fine']) && !isset($req['interest']) && !isset($req['discount']));
reset_calls();
t('segunda visita reaproveita o QR sem chamar a API', pay(10) === 'view' && count(calls()) === 0 && count(rows(10)) === 1);

echo "Valor em aberto mudou\n";
$db->insert('tblinvoicepaymentrecords', ['invoiceid' => 10, 'amount' => 50, 'paymentmode' => 'x', 'transactionid' => 'manual']);
$db->where('id', 10)->update('tblinvoices', ['status' => 3]);
reset_calls();
api(['DELETE /v1/pix/np-1' => ['code' => 204], 'POST /v1/pix' => ['code' => 201, 'body' => ['id' => 'np-2', 'status' => 2, 'payload' => ['data' => 'COPIA2', 'image' => '']]]]);
t('cancela o QR antigo e cobra só o saldo', pay(10) === 'view' && last_call('DELETE /v1/pix/np-1') !== null && json_decode(last_call('POST /v1/pix')['body'], true)['amount'] == 100);
t('aceita 201 sem imagem', $GLOBALS['__view'][1]['qr_code_text'] === 'COPIA2' && $GLOBALS['__view'][1]['qr_code'] === '');
t('linha antiga marcada cancelled', rows(10)[0]->status === 'cancelled');

echo "Resultado indeterminado\n";
$db->insert('tblinvoices', ['id' => 11, 'total' => 80, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h11', 'duedate' => null, 'clientid' => 1]);
api(['POST /v1/pix' => ['code' => 502]]);
t('502 na criação: avisa e não deixa QR', pay(11) === 'redirect' && rows(11)[0]->status === 'uncertain');
$code = rows(11)[0]->customer_code;
reset_calls();
api(['GET /v1/pix' => ['code' => 200, 'body' => [['id' => 'np-3', 'customer_code' => $code, 'status' => 2]]], 'GET /v1/pix/np-3' => ['code' => 200, 'body' => ['id' => 'np-3', 'status' => 2, 'payload' => ['data' => 'COPIA3', 'image' => 'IMG3']]]]);
t('próxima visita encontra a cobrança pela listagem e não cria outra', pay(11) === 'view' && $GLOBALS['__view'][1]['qr_code_text'] === 'COPIA3' && last_call('POST /v1/pix') === null && count(rows(11)) === 1);

echo "Pix com vencimento\n";
$db->insert('tblinvoices', ['id' => 12, 'total' => 300, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h12', 'duedate' => date('Y-m-d', strtotime('+10 days')), 'clientid' => 1]);
add_option('paymentmethod_pagou_pix_pix_mode', 'due');
add_option('paymentmethod_pagou_pix_enable_fine', '1'); add_option('paymentmethod_pagou_pix_fine_value', '2');
add_option('paymentmethod_pagou_pix_enable_interest', '1'); add_option('paymentmethod_pagou_pix_interest_value', '1');
add_option('paymentmethod_pagou_pix_enable_discount', '1'); add_option('paymentmethod_pagou_pix_discount_value', '5'); add_option('paymentmethod_pagou_pix_discount_days_before', '3');
reset_calls(); api(['POST /v1/pix/due' => ['code' => 201, 'body' => ['id' => 'np-4', 'payload' => ['data' => 'COPIA4', 'image' => 'IMG4']]]]);
t('usa /v1/pix/due', pay(12) === 'view' && last_call('POST /v1/pix/due') !== null);
$req = json_decode(last_call('POST /v1/pix/due')['body'], true);
t('due_date da fatura e validade em dias', $req['due_date'] === date('Y-m-d', strtotime('+10 days')) && $req['expiration'] === 30);
t('encargos no formato {type, amount}', $req['fine'] === ['type' => 'percentage', 'amount' => 2] && $req['interest'] === ['type' => 'percentage_month_calendar_days', 'amount' => 1]);
t('desconto em reais com limit_date antes do vencimento', $req['discount']['type'] === 'fixed' && $req['discount']['amount'] == 5 && $req['discount']['limit_date'] === date('Y-m-d', strtotime('+7 days')));
$db->insert('tblinvoices', ['id' => 13, 'total' => 90, 'status' => 4, 'invoice_pagou' => 1, 'hash' => 'h13', 'duedate' => date('Y-m-d', strtotime('-20 days')), 'clientid' => 1]);
reset_calls(); api(['POST /v1/pix/due' => ['code' => 201, 'body' => ['id' => 'np-5', 'payload' => ['data' => 'COPIA5', 'image' => '']]]]);
pay(13);
$req = json_decode(last_call('POST /v1/pix/due')['body'], true);
t('fatura vencida: due_date hoje e desconto omitido', $req['due_date'] === max(date('Y-m-d'), gmdate('Y-m-d')) && !isset($req['discount']));

echo "Validações\n";
$db->insert('tblinvoices', ['id' => 14, 'total' => 10, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h14', 'duedate' => null, 'clientid' => 2]);
$GLOBALS['__client_vat'] = '123';
reset_calls();
t('documento inválido: mensagem clara e nenhuma chamada', pay(14) === 'redirect' && strpos($GLOBALS['__alert'][1], 'CPF ou CNPJ') !== false && count(calls()) === 0);
$GLOBALS['__client_vat'] = '52998224725';
$db->where('id', 14)->update('tblinvoices', ['status' => 2]);
t('fatura paga: não emite', pay(14) === 'redirect' && count(calls()) === 0);

echo "\n$ok ok, $fail falhas\n";
exit($fail ? 1 : 0);
