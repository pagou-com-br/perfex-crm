<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

/* ============================
     Definição da Classe Callback
   ============================ */
class Callback extends APP_Controller
{
    /**
     * Método Construtor
     *
     * Inicializa o controlador Callback carregando as bibliotecas necessárias.
     */
    public function __construct()
    {
        parent::__construct();
        /*
        log_message('debug', 'Callback Controller initialized');
        */
        $this->load->library(['pagou_boleto_gateway', 'pagou_pix_gateway']);
    }

    /**
     * Método principal para processar o webhook recebido da API Pagou.
     *
     * @return void
     */
    public function index()
    {
        /* ============================
             Obtenção e Validação do Payload
           ============================ */
        // Decodifica o payload JSON recebido
        $payload = json_decode(file_get_contents('php://input'));

        // Verifica se o payload está vazio ou não contém o ID da transação
        if (!$payload || !isset($payload->id)) {
            /*
            log_message('error', 'Invalid webhook payload received');
            */
            header('HTTP/1.1 400 Bad Request');
            exit;
        }

        /* ============================
             Busca da Transação no Banco de Dados
           ============================ */
        // Busca a transação com base no ID do pagamento recebido no payload
        $this->db->where('transaction_id', $payload->id);
        $transaction = $this->db->get(db_prefix() . 'pagou_transactions')->row();

        // Verifica se a transação foi encontrada
        if (!$transaction) {
            /*
            log_message('error', 'Transaction not found for Payment ID: ' . $payload->id);
            */
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        /*
        log_message('debug', 'Transaction found: ' . json_encode($transaction));
        */

        /* ============================
             Atualização do Status da Transação
           ============================ */
        // Atualiza o status da transação no banco de dados
        $this->db->where('id', $transaction->id);
        $this->db->update(db_prefix() . 'pagou_transactions', [
            'status' => $payload->status,
            'date_updated' => date('Y-m-d H:i:s')
        ]);

        /* ============================
             Verificação e Processamento do Pagamento
           ============================ */
        // Se o status do pagamento for 'paid', marca a fatura como paga
        if ($payload->status === 'paid') {
            // Carrega o modelo de pagamentos
            $this->load->model('payments_model');

            // Prepara os dados do pagamento
            $payment_data = [
                'invoiceid'     => $transaction->invoice_id,
                'amount'        => $transaction->amount,
                'paymentmode'   => $transaction->payment_method == 'pix' ? 'pagou_pix' : 'pagou_boleto',
                'transactionid' => $transaction->transaction_id,
                'note'          => 'Pagamento confirmado via ' . strtoupper($transaction->payment_method) . ' - Pagou.com.br',
                'date'          => date('Y-m-d')
            ];

            // Adiciona o registro de pagamento
            $this->payments_model->add($payment_data);

            /* ============================
                 Atualização do Status da Fatura
               ============================ */
            // Atualiza o status da fatura para 'Pago' (status = 2)
            $this->db->where('id', $transaction->invoice_id);
            $this->db->update(db_prefix() . 'invoices', ['status' => 2]);

            /*
            log_activity('Pagamento confirmado via ' . strtoupper($transaction->payment_method) . ' para a fatura #' . $transaction->invoice_id);
            */
        }

        /* ============================
             Envio da Resposta de Sucesso
           ============================ */
        header('HTTP/1.1 200 OK');
        exit;
    }

    /* ============================
         Método para Verificar o Status do Pagamento
       ============================ */
    public function check_payment($hash)
    {
        /*
        log_message('debug', 'Check payment called with hash: ' . $hash);
        */

        // Busca a fatura com base no hash fornecido
        $this->db->where('hash', $hash);
        $invoice = $this->db->get('tblinvoices')->row();

        // Verifica se a fatura foi encontrada
        if (!$invoice) {
            /*
            log_message('error', 'Invoice not found for hash: ' . $hash);
            */
            echo json_encode(['status' => 'not_found']);
            return;
        }

        /*
        log_message('debug', 'Invoice found: ' . json_encode($invoice));
        */

        // Busca a transação associada à fatura
        $this->db->where('invoice_id', $invoice->id);
        $this->db->order_by('date_created', 'DESC');
        $transaction = $this->db->get('pagou_transactions')->row();

        // Verifica o status da transação
        if ($transaction && $transaction->status === 'paid') {
            /*
            log_message('debug', 'Transaction status: paid for invoice ID: ' . $invoice->id);
            */
            echo json_encode(['status' => 'paid']);
        } else {
            /*
            log_message('debug', 'Transaction status: pending for invoice ID: ' . $invoice->id);
            */
            echo json_encode(['status' => 'pending']);
        }
    }

    /* ============================
         Método para Obter o Status do Pagamento por ID da Transação
       ============================ */
    public function get_payment_status($transaction_id)
    {
        /*
        log_message('debug', 'get_payment_status called with transaction_id: ' . $transaction_id);
        */
        $status = $this->pagou_model->get_transaction_status($transaction_id);
        echo json_encode(['status' => $status]);
    }
}
?>
