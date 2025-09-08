<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Notification extends App_Controller
{
    /**
     * Propriedade para controlar o estado dos logs.
     * Se a opção "pagou_logs_api" na tabela tbloptions (db_prefix().'options')
     * tiver o valor "1", os logs serão ativados; caso contrário, serão desativados.
     *
     * @var bool
     */
    private $enable_logs;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();

        // Consulta a configuração na tabela de opções (tbloptions)
        $log_option = $this->db->get_where(db_prefix().'options', ['name' => 'pagou_logs_api'])->row();
        if ($log_option && $log_option->value === '1') {
            $this->enable_logs = true;
        } else {
            $this->enable_logs = false;
        }

        $this->logDebug('Construtor da classe Notification chamado. Logs ' . ($this->enable_logs ? 'ativados' : 'desativados') . '.');
    }

    /**
     * Método auxiliar para registrar logs quando habilitados.
     *
     * @param string $message Mensagem a ser registrada no log.
     * @return void
     */
    private function logDebug($message)
    {
        if ($this->enable_logs) {
            log_message('debug', $message);
        }
    }

    /**
     * Método principal para processar a notificação de pagamento via PIX.
     *
     * @return void
     */
    public function index()
    {
        $this->logDebug('Método index iniciado.');

        // Lê o payload (JSON) recebido
        $raw_input = file_get_contents('php://input');
        $this->logDebug('Payload recebido: ' . $raw_input);

        $data = json_decode($raw_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logDebug('Erro ao decodificar JSON: ' . json_last_error_msg());
        } else {
            $this->logDebug('JSON decodificado com sucesso.');
        }

        // Valida o payload
        if (!isset($data['data']['id']) || empty($data['data']['id'])) {
            http_response_code(400);
            $this->logDebug('Resposta 400: Payload inválido.');
            echo "<html><body><h1>Invalid payload</h1></body></html>";
            return;
        }

        // Extrai dados do payload
        $transactionId = $data['data']['id'];
        $payerData     = isset($data['data']['payer']['bank']) ? $data['data']['payer']['bank'] : [];
        $payerName     = isset($payerData['name']) ? $payerData['name'] : 'Desconhecido';
        $payerDocument = isset($payerData['document']) ? $payerData['document'] : 'Desconhecido';

        $this->logDebug('Transaction ID capturado: ' . $transactionId);
        $this->logDebug("Dados do pagador: Nome: {$payerName}, Documento: {$payerDocument}");

        // Busca a transação no banco
        $this->db->where('transaction_id', $transactionId);
        $transaction = $this->db->get(db_prefix() . 'pagou_transactions_pix')->row();

        if (!$transaction) {
            http_response_code(400);
            $this->logDebug('Transação não encontrada: ' . $transactionId);
            echo "<html><body><h1>Transaction not found</h1></body></html>";
            return;
        } else {
            $this->logDebug('Transação encontrada: ' . print_r($transaction, true));
        }

        // Atualiza a tabela pagou_transactions_pix
        $update_data = [
            'status'         => 'paid',
            'data_pagamento' => date('Y-m-d H:i:s')
        ];
        $this->db->where('transaction_id', $transactionId);
        $this->db->update(db_prefix() . 'pagou_transactions_pix', $update_data);

        if ($this->db->affected_rows() > 0) {
            $this->logDebug('Tabela pagou_transactions_pix atualizada com status "paid".');
        } else {
            $this->logDebug('Nenhuma linha atualizada em pagou_transactions_pix para transaction_id: ' . $transactionId);
        }

        // Atualiza o status da fatura na tabela tblinvoices
        $invoiceId = $transaction->invoice_id;
        $this->db->where('id', $invoiceId);
        $this->db->update(db_prefix() . 'invoices', ['status' => 2]);
        if ($this->db->affected_rows() > 0) {
            $this->logDebug("Fatura ID {$invoiceId} atualizada para status '2' (Pago).");
        } else {
            $this->logDebug("Nenhuma atualização realizada na fatura ID {$invoiceId}.");
        }

        // Registra o pagamento na tblinvoicepaymentrecords
        $payment_data = [
            'invoiceid'     => $invoiceId,
            'amount'        => $transaction->amount_final,
            'paymentmode'   => 'pix',
            'paymentmethod' => null,
            'date'          => date('Y-m-d'),
            'daterecorded'  => "Nome: {$payerName}, Documento: {$payerDocument}",
            'transactionid' => $transactionId,
            'note'          => "Pagamento via PIX. Pagador: {$payerName}, Documento: {$payerDocument}"
        ];
        $this->db->insert(db_prefix() . 'invoicepaymentrecords', $payment_data);
        if ($this->db->affected_rows() > 0) {
            $this->logDebug('Registro de pagamento inserido com sucesso na tabela invoicepaymentrecords.');
        } else {
            $this->logDebug('Falha ao inserir registro de pagamento na tabela invoicepaymentrecords.');
        }

        // Registra log de atividade no sistema
        $activity_message = 'Pagamento via PIX recebido para a fatura #' . format_invoice_number($invoiceId);
        log_activity($activity_message);
        $this->logDebug("Atividade registrada: {$activity_message}");

        // Resposta de sucesso em HTML, com script para recarregar a página
        http_response_code(200);
        $this->logDebug('Resposta 200 enviada: Webhook processado com sucesso.');

        echo "<html><body>";
        echo "<h1>Pagamento processado com sucesso</h1>";
        echo "<script>window.location.reload();</script>";
        echo "</body></html>";
    }

    /**
     * Método para ativar os logs manualmente.
     *
     * @return void
     */
    public function enableLogs()
    {
        $this->enable_logs = true;
        $this->logDebug('Logs ativados via método enableLogs().');
    }

    /**
     * Método para desativar os logs manualmente.
     *
     * @return void
     */
    public function disableLogs()
    {
        $this->logDebug('Logs desativados via método disableLogs().');
        $this->enable_logs = false;
    }
}
?>
