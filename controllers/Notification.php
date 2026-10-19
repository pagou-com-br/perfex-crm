<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Webhook das cobranças PIX Avulso (notification_url = pagou/notification).
 * Cobranças avulsas não têm fatura: o evento só atualiza o status da cobrança.
 */
class Notification extends App_Controller
{
    /**
     * Logs ativados pela opção "pagou_logs_api".
     *
     * @var bool
     */
    private $enable_logs;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();

        $this->enable_logs = (get_option('pagou_logs_api') === '1');
    }

    /**
     * Registra logs quando habilitados. Nunca registra o corpo do webhook.
     *
     * @param string $message
     * @return void
     */
    private function logDebug($message)
    {
        if ($this->enable_logs) {
            log_message('debug', '[Pagou Notification] ' . $message);
        }
    }

    /**
     * Processa o webhook de pagamento das cobranças PIX Avulso.
     *
     * @return void
     */
    public function index()
    {
        // Corpo bruto: a assinatura é calculada sobre os bytes exatos recebidos
        $raw_input = file_get_contents('php://input');

        if (!pagou_authenticate_webhook_request($raw_input)) {
            $this->logDebug('Webhook rejeitado: X-Pagou-Signature ausente ou inválida.');
            $this->respond(401, 'Assinatura inválida.');
            return;
        }

        $data = json_decode($raw_input, true);
        $event_name = $data['name'] ?? null;
        $pagou_id = $data['data']['id'] ?? null;

        if (!is_string($pagou_id) || $pagou_id === '') {
            $this->respond(400, 'Payload inválido.');
            return;
        }

        if ($event_name !== 'qrcode.completed') {
            $this->logDebug("Evento ignorado: {$event_name}");
            $this->respond(200, 'Evento ignorado.');
            return;
        }

        $table = db_prefix() . 'pix_avulso';
        $charge = $this->db->where('qr_code', $pagou_id)->get($table)->row();

        if (!$charge) {
            $this->logDebug("Cobrança {$pagou_id} não pertence a este Perfex. Evento ignorado.");
            $this->respond(200, 'Cobrança desconhecida. Evento ignorado.');
            return;
        }

        if ($charge->status === 'pago') {
            $this->respond(200, 'Cobrança já paga.');
            return;
        }

        // Confirma o pagamento na API antes de atualizar
        $api_status = $this->fetchPixStatus($pagou_id);
        if ($api_status === null) {
            $this->respond(503, 'Não foi possível confirmar o pagamento na API. Reenvie depois.');
            return;
        }

        if ($api_status !== 4) {
            $this->logDebug("Cobrança {$pagou_id} não está paga na API. Status: {$api_status}");
            $this->respond(200, 'Pagamento ainda não confirmado pela API.');
            return;
        }

        $this->db->where('id', $charge->id)->update($table, [
            'status'           => 'pago',
            'data_modificacao' => date('Y-m-d H:i:s'),
        ]);

        log_activity('Pagamento PIX Avulso confirmado pela Pagou: ' . $charge->descricao . ' [ID: ' . $charge->id . ']');
        $this->respond(200, 'Pagamento processado com sucesso.');
    }

    /**
     * Consulta o status numérico de um Pix na API (4 = pago).
     *
     * @param string $pagou_id
     * @return int|null Status ou null em caso de falha
     */
    private function fetchPixStatus($pagou_id)
    {
        $api_token = pagou_gateway_api_token('pagou_pix');
        if (!$api_token) {
            $this->logDebug('Chave da API do gateway Pix não configurada.');
            return null;
        }

        $ch = curl_init(pagou_gateway_api_base_url('pagou_pix') . '/v1/pix/' . rawurlencode($pagou_id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: pagou-perfex/1.2.1',
                'X-API-KEY: ' . $api_token,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            $this->logDebug("Falha ao consultar o Pix {$pagou_id}: HTTP {$http_code}");
            return null;
        }

        $result = json_decode($response, true);

        return isset($result['status']) && is_numeric($result['status']) ? (int) $result['status'] : null;
    }

    /**
     * Responde em JSON e encerra.
     *
     * @param int    $code
     * @param string $message
     * @return void
     */
    private function respond($code, $message)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $code, 'message' => $message]);
    }
}
