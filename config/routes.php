<?php
defined('BASEPATH') or exit('No direct script access allowed');

$route['pagou/notification'] = 'notification/index';

// notification_url antiga (Checkout legado): mesmo webhook autenticado
$route['pagou/webhook'] = 'pagou_webhook/process';

// Payment check route
$route['pagou/check_payment/(:any)'] = 'pagou/check_payment/$1';

// Webhook routes - ensure both paths work
$route['pagou/pagou_webhook/process'] = 'pagou_webhook/process';
$route['admin/pagou/pagou_webhook/process'] = 'pagou_webhook/process';

$route['public_pix_avulso/view/(:any)'] = 'pagou/public_pix_avulso/view/$1';
