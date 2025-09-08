<?php

$config['csrf_exclude_uris'] = array(
    'pagou/webhook',
    'pagou/notification',
    'pagou/pagou_webhook/process',
    'admin/pagou/pagou_webhook/process',
    'pagou/pagou_webhook',
    'pagou/webhook/boleto',
    'pagou/boleto/webhook',
    'admin/pagou/webhook',
    'admin/pagou/boleto/webhook'
);

return [
    'pagou/webhook',
    'pagou/notification',
    'pagou/pagou_webhook/process',
    'admin/pagou/pagou_webhook/process',
    'pagou/pagou_webhook',
    'pagou/webhook/boleto',
    'pagou/boleto/webhook',
    'admin/pagou/webhook',
    'admin/pagou/boleto/webhook'
];