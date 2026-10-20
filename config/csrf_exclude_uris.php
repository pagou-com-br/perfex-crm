<?php

// Rotas de webhook sem token CSRF (registradas pelo filtro csrf_exclude_uris em pagou.php).
// A autenticação desses endpoints é feita pela assinatura HMAC da Pagou.
return [
    'pagou/notification',
    'pagou/webhook',
    'pagou/pagou_webhook/process',
    'pagou/pagou_webhook/process/.*',
    'admin/pagou/pagou_webhook/process',
    'admin/pagou/pagou_webhook/process/.*',
];
