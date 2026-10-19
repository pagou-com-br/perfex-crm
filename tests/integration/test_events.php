<?php
require __DIR__ . '/bootstrap.php';
$db = get_instance()->db;

// ---------------------------------------------------------------- esquema mínimo do Perfex + tabelas 1.x do módulo
foreach (['tbloptions', 'tblinvoices', 'tblinvoicepaymentrecords', 'tblpagou_transactions_pix', 'tblpagou_transactions_boleto', 'tblpix_avulso', 'tblpagou_webhook_events'] as $t) {
    $db->query("DROP TABLE IF EXISTS `$t`");
}
$db->query("CREATE TABLE tbloptions (id int auto_increment primary key, name varchar(191) unique, value longtext, autoload int)");
$db->query("CREATE TABLE tblinvoices (id int primary key, total decimal(15,2), status int, invoice_pagou tinyint(1) not null default 0, hash varchar(32), duedate date, clientid int)");
$db->query("CREATE TABLE tblinvoicepaymentrecords (id int auto_increment primary key, invoiceid int, amount decimal(15,2), paymentmode varchar(40), paymentmethod varchar(191), transactionid varchar(191), date date, daterecorded datetime, note text)");
$db->query("CREATE TABLE tblpagou_transactions_pix (id int auto_increment primary key, invoice_id int not null, cliente varchar(255) not null default '', doc varchar(20) not null default '', transaction_id varchar(255) not null, payment_method enum('pix') not null default 'pix', qr_code text, qr_code_text text, amount decimal(15,2) not null default 0, amount_final decimal(15,2) not null default 0, status varchar(50) not null, date_created datetime not null, data_pagamento datetime, data_estorno datetime, webhook_log text, external_id varchar(255), expiration_seconds int default 86400, api_request text, api_response text)");
$db->query("CREATE TABLE tblpagou_transactions_boleto (id int auto_increment primary key, invoice_id int not null, cliente varchar(255) not null default '', doc varchar(20) not null default '', transaction_id varchar(255) not null, payment_method varchar(50), barcode varchar(255), barcode_url text, boleto_url text, amount decimal(15,2), amount_final decimal(15,2), status varchar(50), date_created datetime, due_date date, payment_date datetime, updated_at datetime, api_request text, api_response text, external_id varchar(255))");
$db->query("CREATE TABLE tblpix_avulso (id int auto_increment primary key, nome varchar(255), cpf varchar(20), descricao text, valor decimal(15,2), link varchar(100), data_emissao datetime, data_modificacao datetime, status varchar(20), qr_code text, qr_code_text text, qr_code_img longtext)");

add_option('paymentmethod_pagou_pix_api_token', 'enc:' . base64_encode('pixkey'));
add_option('paymentmethod_pagou_boleto_api_token', 'enc:' . base64_encode('boletokey'));
add_option('paymentmethod_pagou_pix_is_production', '0');
add_option('paymentmethod_pagou_boleto_is_production', '0');
add_option('pagou_logs_api', '0');
add_option('paymentmethod_pagou_boleto_discount_value', '500');
add_option('paymentmethod_pagou_boleto_discount_type', 'fixed');

// Dados "da 1.2.0": faturas, Pix próprio, boleto com Pix vinculado sem vínculo, Pix avulso
$db->insert('tblinvoices', ['id' => 1, 'total' => 100, 'status' => 1, 'invoice_pagou' => 0, 'hash' => 'h1', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblinvoices', ['id' => 2, 'total' => 200, 'status' => 1, 'invoice_pagou' => 0, 'hash' => 'h2', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblinvoices', ['id' => 3, 'total' => 50, 'status' => 6, 'invoice_pagou' => 0, 'hash' => 'h3', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 1, 'transaction_id' => 'pix-1', 'amount' => 100, 'amount_final' => 100, 'status' => 'pending', 'date_created' => date('Y-m-d H:i:s')]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 2, 'transaction_id' => 'bol-2', 'amount' => 200, 'amount_final' => 200, 'status' => '2', 'date_created' => date('Y-m-d H:i:s'), 'due_date' => '2026-10-30',
    'api_response' => json_encode(['id' => 'bol-2', 'status' => 2, 'payload' => ['qrcode_id' => 'qr-2', 'data' => 'copia', 'image' => 'img']])]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 2, 'transaction_id' => 'qr-2', 'amount' => 200, 'amount_final' => 200, 'status' => '2', 'date_created' => date('Y-m-d H:i:s')]);
$db->insert('tblpix_avulso', ['nome' => 'X', 'cpf' => '1', 'descricao' => 'avulso', 'valor' => 10, 'link' => 'l', 'status' => 'emitido', 'qr_code' => 'av-1']);

$ok = 0; $fail = 0;
function t($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FALHOU  $name\n"; } }
function api($state) { file_put_contents(__DIR__ . '/state.json', json_encode($state)); }
function send($event, $key = 'pixkey', $ts = null) {
    $raw = json_encode($event);
    $ts = (string) ($ts ?? time());
    $_SERVER['HTTP_X_PAGOU_TIMESTAMP'] = $ts;
    $_SERVER['HTTP_X_PAGOU_SIGNATURE'] = hash_hmac('sha256', $ts . $raw, $key);
    $e = new Pagou_events();
    return $e->handle_webhook($raw);
}
function payments($invoice) { return get_instance()->db->where('invoiceid', $invoice)->get('tblinvoicepaymentrecords')->result(); }
function inv($id) { return get_instance()->db->where('id', $id)->get('tblinvoices')->row(); }
function pixrow($tid) { return get_instance()->db->where('transaction_id', $tid)->get('tblpagou_transactions_pix')->row(); }

echo "Esquema\n";
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1']]);
t('antes da migration: 503', $r[0] === 503);
Pagou_schema::apply(true);
t('versão gravada', Pagou_schema::ready());
t('Pix vinculado antigo ligado ao boleto', pixrow('qr-2')->boleto_transaction_id === 'bol-2');
t('faturas existentes marcadas (exceto rascunho)', (int) inv(1)->invoice_pagou === 1 && (int) inv(3)->invoice_pagou === 0);
t('desconto do boleto convertido de centavos para reais', get_option('paymentmethod_pagou_boleto_discount_value') === '5');
Pagou_schema::apply(true);
t('migration idempotente (não converte de novo)', get_option('paymentmethod_pagou_boleto_discount_value') === '5');

echo "Autenticação\n";
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1']], 'chave-errada');
t('assinatura inválida: 401', $r[0] === 401);
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1']], 'pixkey', time() - 400);
t('timestamp fora da janela: 401', $r[0] === 401);

echo "Pix próprio\n";
api(['GET /v1/pix/pix-1' => ['code' => 200, 'body' => ['id' => 'pix-1', 'status' => 2, 'amount' => 100]]]);
$ev = ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1', 'transaction_id' => 'tx-1', 'amount' => 100, 'e2e_id' => 'E2E1']];
$r = send($ev);
t('API ainda ativa: 503 para reenvio', $r[0] === 503 && count(payments(1)) === 0);
api(['GET /v1/pix/pix-1' => ['code' => 200, 'body' => ['id' => 'pix-1', 'status' => 4, 'amount' => 100, 'paid_at' => '2026-10-19T15:00:00Z']]]);
$r = send($ev);
t('reenvio com API paga: 200 e 1 pagamento', $r[0] === 200 && count(payments(1)) === 1);
t('fatura paga', (int) inv(1)->status === 2);
t('modo pagou_pix e e2e gravado', payments(1)[0]->paymentmode === 'pagou_pix' && pixrow('pix-1')->e2e_id === 'E2E1');
$r = send($ev);
t('reenvio duplicado: 200 sem novo pagamento', $r[0] === 200 && count(payments(1)) === 1);

echo "Devolução\n";
$r = send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-1', 'transaction_id' => 'rf-1', 'amount' => 30]]);
t('parcial: status partially_refunded, fatura não cancelada', pixrow('pix-1')->status === 'partially_refunded' && (int) inv(1)->status === 2);
$r = send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-1', 'transaction_id' => 'rf-1', 'amount' => 30]]);
t('mesma devolução reenviada não soma de novo', (float) pixrow('pix-1')->refunded_amount === 30.0);
$r = send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-1', 'transaction_id' => 'rf-2', 'amount' => 70]]);
t('completa a devolução: refunded e fatura cancelada', pixrow('pix-1')->status === 'refunded' && (int) inv(1)->status === 5);
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1', 'transaction_id' => 'tx-1b', 'amount' => 100]]);
t('pagamento atrasado não reabre cobrança estornada', $r[0] === 200 && count(payments(1)) === 1);

echo "Boleto com Pix vinculado\n";
$paid_by_pix = ['code' => 200, 'body' => ['id' => 'bol-2', 'status' => 3, 'amount' => 200, 'paid_at' => '2026-10-19T16:00:00Z', 'payload' => ['qrcode_id' => 'qr-2', 'qrcode_status' => 4, 'data' => 'copia', 'line' => '1234', 'bar_code' => '5678']]];
api(['GET /v1/charges/bol-2' => $paid_by_pix]);
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'qr-2', 'transaction_id' => 'tx-qr2', 'amount' => 200]], 'boletokey');
t('qrcode.completed do Pix vinculado confirmado pelo boleto (status 3 + qrcode 4)', $r[0] === 200 && count(payments(2)) === 1);
t('registro com ID do boleto e modo pagou_boleto', payments(2)[0]->transactionid === 'bol-2' && payments(2)[0]->paymentmode === 'pagou_boleto');
$calls = json_decode(file_get_contents(__DIR__ . '/calls.json'), true);
$last = end($calls);
t('consulta usou a chave do boleto', $last['key'] === 'boletokey' && $last['path'] === '/v1/charges/bol-2');
$r = send(['name' => 'charge.paid', 'data' => ['id' => 'bol-2', 'transaction_id' => 'tx-b2', 'amount' => ['paid' => 200, 'original' => 200]]], 'boletokey');
t('charge.paid do mesmo boleto não duplica', $r[0] === 200 && count(payments(2)) === 1);
$b = get_instance()->db->where('transaction_id', 'bol-2')->get('tblpagou_transactions_boleto')->row();
t('linha do boleto marcada paid (não cancelled) e linha digitável gravada', $b->status === 'paid' && $b->barcode_url === '1234');

echo "Diversos\n";
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'desconhecido']]);
t('cobrança desconhecida: 200 ignorado', $r[0] === 200);
$r = send(['name' => 'outro.evento', 'data' => ['id' => 'x']]);
t('evento desconhecido: 200', $r[0] === 200);
api(['GET /v1/pix/av-1' => ['code' => 200, 'body' => ['id' => 'av-1', 'status' => 4]]]);
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'av-1', 'transaction_id' => 'tx-av', 'amount' => 10]]);
$av = get_instance()->db->where('qr_code', 'av-1')->get('tblpix_avulso')->row();
t('Pix avulso marcado pago', $r[0] === 200 && $av->status === 'pago');

echo "Troca de chave\n";
pagou_remember_api_tokens();
update_option('paymentmethod_pagou_pix_api_token', 'enc:' . base64_encode('pixkey-nova'));
pagou_remember_api_tokens();
$db->insert('tblinvoices', ['id' => 4, 'total' => 40, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h4', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 4, 'transaction_id' => 'pix-4', 'amount' => 40, 'amount_final' => 40, 'status' => 'pending', 'date_created' => date('Y-m-d H:i:s')]);
api(['GET /v1/pix/pix-4' => ['code' => 200, 'body' => ['id' => 'pix-4', 'status' => 4, 'amount' => 40]]]);
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-4', 'transaction_id' => 'tx-4', 'amount' => 40]], 'pixkey');
t('evento assinado com a chave antiga ainda é aceito', $r[0] === 200 && count(payments(4)) === 1);

echo "Reconciliação\n";
$db->insert('tblinvoices', ['id' => 5, 'total' => 55, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h5', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 5, 'transaction_id' => 'pix-5', 'amount' => 55, 'amount_final' => 55, 'status' => 'pending', 'date_created' => date('Y-m-d H:i:s')]);
api(['GET /v1/pix/pix-5' => ['code' => 200, 'body' => ['id' => 'pix-5', 'status' => 4, 'amount' => 55]]]);
(new Pagou_events())->reconcile();
t('cron dá baixa no Pix pago sem webhook', count(payments(5)) === 1);
(new Pagou_events())->reconcile();
t('cron respeita o intervalo e não duplica', count(payments(5)) === 1);

echo "\n$ok ok, $fail falhas\n";
exit($fail ? 1 : 0);
