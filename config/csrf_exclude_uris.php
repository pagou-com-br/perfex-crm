<?php

// Rotas de webhook sem token CSRF (registradas pelo filtro csrf_exclude_uris em pagou.php).
// A autenticação desses endpoints é feita pela assinatura HMAC da Pagou.
return [
    'pagou/notification',
    'pagou/pagou_webhook/process',
    'admin/pagou/pagou_webhook/process',
];
