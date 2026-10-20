<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Webhook da Pagou (Pix, Pix vinculado a boleto, Pix avulso e boleto).
 *
 * URL padrão: site_url('pagou/pagou_webhook/process').
 * A URL antiga admin/pagou/pagou_webhook/process[/ID] continua aceita para cobranças já emitidas;
 * o segmento final é ignorado e nunca é usado para localizar faturas.
 */
class Pagou_webhook extends CI_Controller
{
    public function process($ignored = null)
    {
        @ini_set('display_errors', '0');

        // Corpo bruto: a assinatura HMAC é calculada sobre os bytes exatos recebidos
        $raw_body = file_get_contents('php://input');

        try {
            pagou_load_libraries();
            $events = new Pagou_events();
            list($code, $message) = $events->handle_webhook($raw_body);
        } catch (\Throwable $e) {
            pagou_log('webhook', 'error', 'Exceção não tratada: ' . $e->getMessage());
            // 5xx faz a Pagou reenviar o evento
            list($code, $message) = [500, 'Erro interno ao processar o webhook.'];
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($code);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $code, 'message' => $message]);
        exit;
    }
}
