<?php
defined('BASEPATH') or exit('No direct script access allowed');

$route['pagou/notification'] = 'notification/index';

// notification_url antiga (Checkout legado): mesmo webhook autenticado
$route['pagou/webhook'] = 'pagou_webhook/process';


// Webhook routes - ensure both paths work
$route['pagou/pagou_webhook/process'] = 'pagou_webhook/process';
$route['admin/pagou/pagou_webhook/process'] = 'pagou_webhook/process';

