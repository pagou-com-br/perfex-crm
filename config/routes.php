<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Define webhook route - single route definition
$route['pagou/webhook'] = 'pagou/webhook';
log_message('debug', 'Rota para webhook carregada corretamente.');
$route['pagou/notification'] = 'notification/index';

// Payment check route
$route['pagou/check_payment/(:any)'] = 'pagou/check_payment/$1';

// Webhook routes - ensure both paths work
$route['pagou/pagou_webhook/process'] = 'pagou_webhook/process';
$route['admin/pagou/pagou_webhook/process'] = 'pagou_webhook/process';

$route['public_pix_avulso/view/(:any)'] = 'pagou/public_pix_avulso/view/$1';
