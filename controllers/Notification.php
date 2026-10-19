<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * URL antiga de notificação do PIX Avulso (pagou/notification).
 * Mantida para cobranças já emitidas; usa o mesmo processamento autenticado do webhook principal.
 */
class Notification extends App_Controller
{
    public function index()
    {
        $raw_body = file_get_contents('php://input');

        try {
            pagou_load_libraries();
            $events = new Pagou_events();
            list($code, $message) = $events->handle_webhook($raw_body);
        } catch (\Throwable $e) {
            pagou_log('webhook', 'error', 'Exceção não tratada: ' . $e->getMessage());
            list($code, $message) = [500, 'Erro interno ao processar o webhook.'];
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $code, 'message' => $message]);
    }
}
