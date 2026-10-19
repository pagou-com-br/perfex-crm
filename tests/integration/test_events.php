<?php
require __DIR__ . '/bootstrap.php';
$db = get_instance()->db;

// ---------------------------------------------------------------- esquema mínimo do Perfex + tabelas 1.x do módulo
foreach (['tbloptions', 'tblinvoices', 'tblinvoicepaymentrecords', 'tblpagou_transactions_pix', 'tblpagou_transactions_boleto', 'tblpix_avulso', 'tblpagou_webhook_events', 'tblpagou_settlements'] as $t) {
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

$now = date('Y-m-d H:i:s');
// Dados "da 1.2.0"
$db->insert('tblinvoices', ['id' => 1, 'total' => 100, 'status' => 1, 'invoice_pagou' => 0, 'hash' => 'h1', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblinvoices', ['id' => 2, 'total' => 200, 'status' => 1, 'invoice_pagou' => 0, 'hash' => 'h2', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblinvoices', ['id' => 3, 'total' => 50, 'status' => 6, 'invoice_pagou' => 0, 'hash' => 'h3', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 1, 'transaction_id' => 'pix-1', 'amount' => 100, 'amount_final' => 100, 'status' => 'pending', 'date_created' => $now]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 2, 'transaction_id' => 'bol-2', 'amount' => 200, 'amount_final' => 200, 'status' => '2', 'date_created' => $now, 'due_date' => '2026-10-30',
    'api_response' => json_encode(['id' => 'bol-2', 'status' => 2, 'payload' => ['qrcode_id' => 'qr-2', 'data' => 'copia', 'image' => 'img']])]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 2, 'transaction_id' => 'qr-2', 'amount' => 200, 'amount_final' => 200, 'status' => '2', 'date_created' => $now]);
$db->insert('tblpix_avulso', ['nome' => 'X', 'cpf' => '1', 'descricao' => 'avulso', 'valor' => 10, 'link' => 'l', 'status' => 'emitido', 'qr_code' => 'av-1']);
// 1.2.0: boleto pago pelo Pix vinculado, registrado com o ID do QR (fatura 7)
$db->insert('tblinvoices', ['id' => 7, 'total' => 70, 'status' => 2, 'invoice_pagou' => 1, 'hash' => 'h7', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 7, 'transaction_id' => 'bol-7', 'amount' => 70, 'status' => '2', 'date_created' => $now,
    'api_response' => json_encode(['id' => 'bol-7', 'status' => 2, 'payload' => ['qrcode_id' => 'qr-7', 'data' => 'c']])]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 7, 'transaction_id' => 'qr-7', 'amount' => 70, 'amount_final' => 70, 'status' => 'paid', 'date_created' => $now]);
$db->insert('tblinvoicepaymentrecords', ['invoiceid' => 7, 'amount' => 70, 'paymentmode' => 'pagou_pix', 'transactionid' => 'qr-7']);
// 1.2.0: charge.paid quebrava; staff deu baixa manual (fatura 8)
$db->insert('tblinvoices', ['id' => 8, 'total' => 80, 'status' => 2, 'invoice_pagou' => 1, 'hash' => 'h8', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_boleto', ['invoice_id' => 8, 'transaction_id' => 'bol-8', 'amount' => 80, 'status' => '2', 'date_created' => $now]);
$db->insert('tblinvoicepaymentrecords', ['invoiceid' => 8, 'amount' => 80, 'paymentmode' => '1', 'transactionid' => '']);

$ok = 0; $fail = 0;
function t($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FALHOU  $name\n"; } }
function api($state) { file_put_contents(__DIR__ . '/state.json', json_encode($state)); }
function send($event, $key = 'pixkey', $ts = null) {
    $raw = json_encode($event);
    $ts = (string) ($ts ?? time());
    $_SERVER['HTTP_X_PAGOU_TIMESTAMP'] = $ts;
    $_SERVER['HTTP_X_PAGOU_SIGNATURE'] = hash_hmac('sha256', $ts . $raw, $key);
    return (new Pagou_events())->handle_webhook($raw);
}
function payments($invoice) { return get_instance()->db->where('invoiceid', $invoice)->get('tblinvoicepaymentrecords')->result(); }
function inv($id) { return get_instance()->db->where('id', $id)->get('tblinvoices')->row(); }
function pixrow($tid) { return get_instance()->db->where('transaction_id', $tid)->get('tblpagou_transactions_pix')->row(); }
function activity_has($text) { foreach ($GLOBALS['__activity'] ?? [] as $a) { if (strpos($a[1], $text) !== false) { return true; } } return false; }
function reset_reconcile() { update_option('pagou_last_reconcile', '0'); }
$pix1_paid = ['code' => 200, 'body' => ['id' => 'pix-1', 'status' => 4, 'amount' => 100, 'paid_at' => '2026-10-19T15:00:00Z', 'refund' => null]];

echo "Esquema\n";
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1']]);
t('antes da migration: 503', $r[0] === 503);
Pagou_schema::apply(false); // ex.: reativação do módulo antes de rodar a migration
t('versão gravada', Pagou_schema::ready());
t('desconto convertido mesmo pela instalação/reativação', get_option('paymentmethod_pagou_boleto_discount_value') === '5');
Pagou_schema::apply(true);
t('migration idempotente (não converte de novo)', get_option('paymentmethod_pagou_boleto_discount_value') === '5');
t('Pix vinculado antigo ligado ao boleto', pixrow('qr-2')->boleto_transaction_id === 'bol-2');
t('faturas existentes marcadas (exceto rascunho)', (int) inv(1)->invoice_pagou === 1 && (int) inv(3)->invoice_pagou === 0);
$st = $db->where('charge_id', 'bol-7')->get('tblpagou_settlements')->row();
t('pagamento antigo pelo ID do QR vira baixa do boleto', $st && $st->status === 'done');

echo "Autenticação\n";
t('assinatura inválida: 401', send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1']], 'chave-errada')[0] === 401);
t('timestamp fora da janela: 401', send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1']], 'pixkey', time() - 400)[0] === 401);

echo "Pix próprio\n";
api(['GET /v1/pix/pix-1' => ['code' => 200, 'body' => ['id' => 'pix-1', 'status' => 2, 'amount' => 100]]]);
$ev = ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1', 'transaction_id' => 'tx-1', 'amount' => 100, 'e2e_id' => 'E2E1']];
$r = send($ev);
t('API ainda ativa: 503 para reenvio', $r[0] === 503 && count(payments(1)) === 0);
api(['GET /v1/pix/pix-1' => $pix1_paid]);
$GLOBALS['__fail_add'] = true;
$r = send($ev);
t('falha no add(): 503 e linha não fica paga', $r[0] === 503 && count(payments(1)) === 0 && pixrow('pix-1')->status !== 'paid');
$GLOBALS['__fail_add'] = false;
$r = send($ev);
t('reenvio: 200 e 1 pagamento', $r[0] === 200 && count(payments(1)) === 1);
t('fatura paga, modo pagou_pix e e2e', (int) inv(1)->status === 2 && payments(1)[0]->paymentmode === 'pagou_pix' && pixrow('pix-1')->e2e_id === 'E2E1');
t('reenvio duplicado: 200 sem novo pagamento', send($ev)[0] === 200 && count(payments(1)) === 1);

echo "Entrega concorrente\n";
$key = hash('sha256', 'qrcode.completed|pix-1|tx-busy');
$db->insert('tblpagou_webhook_events', ['event_key' => $key, 'name' => 'qrcode.completed', 'charge_id' => 'pix-1', 'transaction_id' => 'tx-busy', 'status' => 'processing', 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now, 'locked_at' => $now]);
t('mesmo evento em processamento: 503 sem reaplicar', send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1', 'transaction_id' => 'tx-busy']])[0] === 503);

echo "Devolução (sempre confirmada pela API)\n";
api(['GET /v1/pix/pix-1' => $pix1_paid]);
$r = send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-1', 'transaction_id' => 'rf-x', 'amount' => 100]], 'pixkey');
t('devolução que a API não confirma não tem efeito', pixrow('pix-1')->status === 'paid' && (float) pixrow('pix-1')->refunded_amount === 0.0);
api(['GET /v1/pix/pix-1' => ['code' => 200, 'body' => ['id' => 'pix-1', 'status' => 5, 'amount' => 100, 'paid_at' => '2026-10-19T15:00:00Z', 'refund' => ['status' => 3, 'amount' => 30]]]]);
send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-1', 'transaction_id' => 'rf-1', 'amount' => 30]]);
t('parcial pelo valor da API: partially_refunded, fatura não cancelada', pixrow('pix-1')->status === 'partially_refunded' && (float) pixrow('pix-1')->refunded_amount === 30.0 && (int) inv(1)->status === 2);
send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-1', 'transaction_id' => 'rf-1', 'amount' => 30]]);
t('reenvio não soma de novo', (float) pixrow('pix-1')->refunded_amount === 30.0);
api(['GET /v1/pix/pix-1' => ['code' => 200, 'body' => ['id' => 'pix-1', 'status' => 5, 'amount' => 100, 'paid_at' => '2026-10-19T15:00:00Z', 'refund' => ['status' => 3, 'amount' => 100]]]]);
send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-1', 'transaction_id' => 'rf-2', 'amount' => 70]]);
t('integral: refunded, fatura não é cancelada e há atividade', pixrow('pix-1')->status === 'refunded' && (int) inv(1)->status === 2 && activity_has('Ajuste o pagamento da fatura manualmente'));
t('pagamento atrasado não duplica', send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-1', 'transaction_id' => 'tx-1b', 'amount' => 100]])[0] === 200 && count(payments(1)) === 1);

echo "Fora de ordem\n";
$db->insert('tblinvoices', ['id' => 9, 'total' => 90, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h9', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 9, 'transaction_id' => 'pix-9', 'amount' => 90, 'amount_final' => 90, 'status' => 'active', 'date_created' => $now]);
api(['GET /v1/pix/pix-9' => ['code' => 200, 'body' => ['id' => 'pix-9', 'status' => 5, 'amount' => 90, 'paid_at' => '2026-10-19T15:00:00Z', 'refund' => ['status' => 3, 'amount' => 30]]]]);
send(['name' => 'qrcode.refunded', 'data' => ['id' => 'pix-9', 'transaction_id' => 'rf-9', 'amount' => 30]]);
t('devolução antes do pagamento: o pagamento é registrado pela API e a devolução marcada', count(payments(9)) === 1 && pixrow('pix-9')->status === 'partially_refunded');

echo "Boleto com Pix vinculado\n";
$paid_by_pix = ['code' => 200, 'body' => ['id' => 'bol-2', 'status' => 3, 'amount' => 200, 'paid_at' => '2026-10-19T16:00:00Z', 'payload' => ['qrcode_id' => 'qr-2', 'qrcode_status' => 4, 'data' => 'copia', 'line' => '1234', 'bar_code' => '5678']]];
api(['GET /v1/charges/bol-2' => $paid_by_pix]);
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'qr-2', 'transaction_id' => 'tx-qr2', 'amount' => 200]], 'boletokey');
t('qrcode.completed do Pix vinculado confirmado pelo boleto (status 3 + qrcode 4)', $r[0] === 200 && count(payments(2)) === 1);
t('registro com ID do boleto e modo pagou_boleto', payments(2)[0]->transactionid === 'bol-2' && payments(2)[0]->paymentmode === 'pagou_boleto');
t('charge.paid do mesmo boleto não duplica', send(['name' => 'charge.paid', 'data' => ['id' => 'bol-2', 'transaction_id' => 'tx-b2', 'amount' => ['paid' => 200, 'original' => 200]]], 'boletokey')[0] === 200 && count(payments(2)) === 1);
$b = $db->where('transaction_id', 'bol-2')->get('tblpagou_transactions_boleto')->row();
t('linha do boleto paid e linha digitável gravada', $b->status === 'paid' && $b->barcode_url === '1234');
$GLOBALS['__activity'] = [];
send(['name' => 'qrcode.refunded', 'data' => ['id' => 'qr-2', 'transaction_id' => 'rf-qr2', 'amount' => 50]], 'boletokey');
t('devolução do Pix vinculado vira atividade na fatura', activity_has('Pix vinculado ao boleto bol-2'));

echo "Duplicidade com registros anteriores\n";
reset_reconcile();
api(['GET /v1/charges/bol-7' => ['code' => 200, 'body' => ['id' => 'bol-7', 'status' => 3, 'amount' => 70, 'payload' => ['qrcode_id' => 'qr-7', 'qrcode_status' => 4]]],
     'GET /v1/charges/bol-8' => ['code' => 200, 'body' => ['id' => 'bol-8', 'status' => 4, 'amount' => 80]]]);
$GLOBALS['__activity'] = [];
(new Pagou_events())->reconcile();
t('1.2.0 com ID do QR: reconciliação não duplica', count(payments(7)) === 1);
t('baixa manual anterior: não duplica e avisa na atividade', count(payments(8)) === 1 && activity_has('já estava quitada'));

echo "Diversos\n";
t('cobrança desconhecida: 200 ignorado', send(['name' => 'qrcode.completed', 'data' => ['id' => 'desconhecido']])[0] === 200);
t('evento desconhecido: 200', send(['name' => 'outro.evento', 'data' => ['id' => 'x']])[0] === 200);
api(['GET /v1/pix/av-1' => ['code' => 200, 'body' => ['id' => 'av-1', 'status' => 4]]]);
$r = send(['name' => 'qrcode.completed', 'data' => ['id' => 'av-1', 'transaction_id' => 'tx-av', 'amount' => 10]]);
t('Pix avulso marcado pago', $r[0] === 200 && $db->where('qr_code', 'av-1')->get('tblpix_avulso')->row()->status === 'pago');
api(['GET /v1/charges/bol-2' => ['code' => 503]]);
t('charge.created com consulta falhando: 503 para reenvio', send(['name' => 'charge.created', 'data' => ['id' => 'bol-2', 'transaction_id' => 'tx-created']], 'boletokey')[0] === 503);

echo "Troca de chave\n";
pagou_remember_api_tokens();
update_option('paymentmethod_pagou_pix_api_token', 'enc:' . base64_encode('pixkey-nova'));
pagou_remember_api_tokens();
$db->insert('tblinvoices', ['id' => 4, 'total' => 40, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h4', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 4, 'transaction_id' => 'pix-4', 'amount' => 40, 'amount_final' => 40, 'status' => 'pending', 'date_created' => $now]);
api(['GET /v1/pix/pix-4' => ['code' => 200, 'body' => ['id' => 'pix-4', 'status' => 4, 'amount' => 40]]]);
t('evento assinado com a chave antiga ainda é aceito (30 dias)', send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-4', 'transaction_id' => 'tx-4', 'amount' => 40]], 'pixkey')[0] === 200 && count(payments(4)) === 1);
pagou_forget_previous_api_tokens();
t('depois de esquecer as chaves anteriores, a antiga é rejeitada', send(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-4', 'transaction_id' => 'tx-4c']], 'pixkey')[0] === 401);

echo "Reconciliação\n";
$db->insert('tblinvoices', ['id' => 5, 'total' => 55, 'status' => 1, 'invoice_pagou' => 1, 'hash' => 'h5', 'duedate' => '2026-10-30', 'clientid' => 1]);
$db->insert('tblpagou_transactions_pix', ['invoice_id' => 5, 'transaction_id' => 'pix-5', 'amount' => 55, 'amount_final' => 55, 'status' => 'paid', 'date_created' => $now]);
api(['GET /v1/pix/pix-5' => ['code' => 200, 'body' => ['id' => 'pix-5', 'status' => 4, 'amount' => 55]]]);
reset_reconcile();
(new Pagou_events())->reconcile();
t('linha paga sem baixa é reconciliada', count(payments(5)) === 1);
(new Pagou_events())->reconcile();
t('respeita o intervalo e não duplica', count(payments(5)) === 1);

echo "\n$ok ok, $fail falhas\n";
exit($fail ? 1 : 0);
