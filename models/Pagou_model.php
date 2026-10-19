<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Modelo Pagou_model
 *
 * Este modelo lida com operações relacionadas às transações do Pagou.
 */
class Pagou_model extends App_Model
{
    /**
     * Construtor
     */
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        
        // Verificar e criar campos necessários caso não existam
        $this->check_required_fields();
    }
    
    /**
     * Verifica se os campos necessários existem e cria se não existirem
     */
    private function check_required_fields()
    {
        if (!$this->db->field_exists('data_estorno', db_prefix() . 'pagou_transactions_pix')) {
            $this->db->query('ALTER TABLE ' . db_prefix() . 'pagou_transactions_pix ADD data_estorno DATETIME NULL');
            log_message('info', 'Campo data_estorno adicionado à tabela pagou_transactions_pix');
        }
        
        if (!$this->db->field_exists('reembolso_log', db_prefix() . 'pagou_transactions_pix')) {
            $this->db->query('ALTER TABLE ' . db_prefix() . 'pagou_transactions_pix ADD reembolso_log TEXT NULL');
            log_message('info', 'Campo reembolso_log adicionado à tabela pagou_transactions_pix');
        }
    }

    /**
     * Obtém transações PIX com base nos filtros fornecidos.
     *
     * @param array $filters Filtros para buscar transações (cliente, período, status).
     * @return array Retorna um array de objetos de transações PIX.
     */
    public function get_transactions_pix($filters = [])
    {
        $this->db->select('
            pix.*,
            inv.hash,
            inv.clientid,
            inv.number as invoice_number,
            inv.prefix as invoice_prefix,
            c.company as client_company
        ');
        $this->db->from(db_prefix() . 'pagou_transactions_pix as pix');
        $this->db->join(db_prefix() . 'invoices as inv', 'inv.id = pix.invoice_id', 'left');
        $this->db->join(db_prefix() . 'clients as c', 'c.userid = inv.clientid', 'left');

        // Filtro por cliente
        if (!empty($filters['client'])) {
            $this->db->where('c.company LIKE', '%' . $filters['client'] . '%');
        }

        // Filtro por período
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $this->db->where('DATE(pix.date_created) >=', $filters['start_date']);
            $this->db->where('DATE(pix.date_created) <=', $filters['end_date']);
        }

        // Filtro por status (por padrão, mostra todos os status relevantes)
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== []) {
            if (is_array($filters['status'])) {
                $this->db->where_in('pix.status', $filters['status']);
            } else {
                $this->db->where('pix.status', $filters['status']);
            }
        } else {
            // Inclui 'refunded' como status padrão para mostrar
            $this->db->where_in('pix.status', ['paid', '4', 'refund_requested', 'refunded']);
        }

        $this->db->order_by('pix.date_created', 'DESC');
        $query = $this->db->get();

        return $query->result();
    }

    /**
     * Obtém transações de Boleto com base nos filtros fornecidos.
     *
     * @param array $filters Filtros para buscar transações (cliente, período, status).
     * @return array Retorna um array de objetos de transações Boleto.
     */
    public function get_transactions_boleto($filters = [])
    {
        $this->db->select('
            boleto.*,
            inv.hash,
            inv.clientid,
            inv.number as invoice_number,
            inv.prefix as invoice_prefix,
            c.company as client_company
        ');
        $this->db->from(db_prefix() . 'pagou_transactions_boleto as boleto');
        $this->db->join(db_prefix() . 'invoices as inv', 'inv.id = boleto.invoice_id', 'left');
        $this->db->join(db_prefix() . 'clients as c', 'c.userid = inv.clientid', 'left');

        // Filtro por cliente
        if (!empty($filters['client'])) {
            $this->db->where('c.company LIKE', '%' . $filters['client'] . '%');
        }

        // Filtro por período
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $this->db->where('DATE(boleto.date_created) >=', $filters['start_date']);
            $this->db->where('DATE(boleto.date_created) <=', $filters['end_date']);
        }

        // Filtro por status (por padrão, mostra apenas os pagos)
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== []) {
            if (is_array($filters['status'])) {
                $this->db->where_in('boleto.status', $filters['status']);
            } else {
                $this->db->where('boleto.status', $filters['status']);
            }
        } else {
            $this->db->where_in('boleto.status', ['paid', '4', '8', '9']);
        }

        $this->db->order_by('boleto.date_created', 'DESC');
        $query = $this->db->get();

        return $query->result();
    }

    /**
     * Obtém o status de uma transação PIX específica.
     *
     * @param string $transaction_id ID da transação PIX.
     * @return string|null Retorna o status da transação ou null se não encontrado.
     */
    public function get_transaction_pix_status($transaction_id)
    {
        $this->db->select('status');
        $this->db->from(db_prefix() . 'pagou_transactions_pix');
        $this->db->where('transaction_id', $transaction_id);
        $query = $this->db->get();

        if ($query->num_rows() > 0) {
            return $query->row()->status;
        }

        return null;
    }

    /**
     * Obtém o status de uma transação Boleto específica.
     *
     * @param string $transaction_id ID da transação Boleto.
     * @return string|null Retorna o status da transação ou null se não encontrado.
     */
    public function get_transaction_boleto_status($transaction_id)
    {
        $this->db->select('status');
        $this->db->from(db_prefix() . 'pagou_transactions_boleto');
        $this->db->where('transaction_id', $transaction_id);
        $query = $this->db->get();

        if ($query->num_rows() > 0) {
            return $query->row()->status;
        }

        return null;
    }

    /**
     * Obtém uma transação PIX específica com base no ID.
     *
     * @param string $transaction_id ID da transação PIX.
     * @return object|null Retorna o objeto da transação ou null se não encontrado.
     */
    public function get_transaction_pix_by_id($transaction_id)
    {
        $this->db->select('
            pix.*,
            inv.hash,
            inv.clientid,
            inv.number as invoice_number,
            inv.prefix as invoice_prefix,
            c.company as client_company
        ');
        $this->db->from(db_prefix() . 'pagou_transactions_pix as pix');
        $this->db->join(db_prefix() . 'invoices as inv', 'inv.id = pix.invoice_id', 'left');
        $this->db->join(db_prefix() . 'clients as c', 'c.userid = inv.clientid', 'left');
        $this->db->where('pix.transaction_id', $transaction_id);
        $query = $this->db->get();

        if ($query->num_rows() > 0) {
            return $query->row();
        }

        return null;
    }

    /**
     * Obtém uma transação Boleto específica com base no ID.
     *
     * @param string $transaction_id ID da transação Boleto.
     * @return object|null Retorna o objeto da transação ou null se não encontrado.
     */
    public function get_transaction_boleto_by_id($transaction_id)
    {
        $this->db->select('
            boleto.*,
            inv.hash,
            inv.clientid,
            inv.number as invoice_number,
            inv.prefix as invoice_prefix,
            c.company as client_company
        ');
        $this->db->from(db_prefix() . 'pagou_transactions_boleto as boleto');
        $this->db->join(db_prefix() . 'invoices as inv', 'inv.id = boleto.invoice_id', 'left');
        $this->db->join(db_prefix() . 'clients as c', 'c.userid = inv.clientid', 'left');
        $this->db->where('boleto.transaction_id', $transaction_id);
        $query = $this->db->get();

        if ($query->num_rows() > 0) {
            return $query->row();
        }

        return null;
    }

    /**
     * Atualiza o status de uma transação PIX específica.
     *
     * @param string $transaction_id ID da transação PIX.
     * @param string $status Novo status da transação.
     * @param array $additional_data Dados adicionais para atualizar.
     * @return bool Retorna true se a atualização foi bem-sucedida, false caso contrário.
     */
    public function update_transaction_pix_status($transaction_id, $status, $additional_data = [])
    {
        // Log para debugar os dados recebidos
        log_message('debug', "Atualizando status da transação PIX: {$transaction_id} para '{$status}' com dados adicionais: " . json_encode($additional_data));
        
        // Verifica se a transação existe
        $this->db->where('transaction_id', $transaction_id);
        $exists = $this->db->get(db_prefix() . 'pagou_transactions_pix')->num_rows() > 0;
        
        if (!$exists) {
            log_message('error', "Transação PIX não encontrada para atualização: {$transaction_id}");
            return false;
        }
        
        // Prepara os dados a serem atualizados
        $update_data = ['status' => $status];
        
        // Processa dados adicionais - garantindo que apenas campos válidos sejam usados
        foreach ($additional_data as $key => $value) {
            // Verifica se o campo existe na tabela antes de adicioná-lo ao update
            if ($this->db->field_exists($key, db_prefix() . 'pagou_transactions_pix')) {
                $update_data[$key] = $value;
            } else {
                log_message('warning', "Campo '{$key}' não existe na tabela 'pagou_transactions_pix' e será ignorado");
                
                // Trata o caso especial do campo 'refund_date'
                if ($key == 'refund_date' && !isset($additional_data['data_estorno'])) {
                    $update_data['data_estorno'] = $value;
                    log_message('info', "Usando 'data_estorno' no lugar de 'refund_date'");
                }
            }
        }
        
        // Garante que sempre tenhamos data_estorno para reembolsos
        if ($status == 'refunded' && !isset($update_data['data_estorno'])) {
            $update_data['data_estorno'] = date('Y-m-d H:i:s');
            log_message('info', "Adicionando data de estorno automática: {$update_data['data_estorno']}");
        }
        
        // Executa a atualização
        $this->db->where('transaction_id', $transaction_id);
        $result = $this->db->update(db_prefix() . 'pagou_transactions_pix', $update_data);
        
        // Log do resultado da atualização
        log_message('info', "Resultado da atualização de status da transação PIX {$transaction_id}: " . ($result ? 'Sucesso' : 'Falha'));
        
        return $result;
    }

    /**
     * Adiciona um log de reembolso para uma transação PIX.
     *
     * @param string $transaction_id ID da transação PIX.
     * @param array $log_data Dados do log.
     * @return bool Retorna true se bem-sucedido, false caso contrário.
     */
    public function add_refund_log($transaction_id, $log_data)
    {
        $transaction = $this->get_transaction_pix_by_id($transaction_id);
        
        if (!$transaction) {
            log_message('error', "Tentativa de adicionar log de reembolso para transação inexistente: {$transaction_id}");
            return false;
        }
        
        // Obtém o log existente ou cria um novo array
        $current_log = [];
        if (!empty($transaction->reembolso_log)) {
            $current_log = json_decode($transaction->reembolso_log, true);
            if (!is_array($current_log)) {
                $current_log = [];
            }
        }
        
        // Adiciona timestamp ao novo log
        $log_data['timestamp'] = date('Y-m-d H:i:s');
        
        // Adiciona o novo log ao array existente
        $current_log[] = $log_data;
        
        // Converte o array atualizado de volta para JSON
        $updated_log = json_encode($current_log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Atualiza o log de reembolso no banco de dados
        $this->db->where('transaction_id', $transaction_id);
        $result = $this->db->update(db_prefix() . 'pagou_transactions_pix', ['reembolso_log' => $updated_log]);
        
        return $result;
    }

    /**
     * Obtém o histórico de reembolso de uma transação PIX específica.
     *
     * @param string $transaction_id ID da transação PIX.
     * @return array Retorna array de logs de reembolso.
     */
    public function get_refund_history($transaction_id)
    {
        $transaction = $this->get_transaction_pix_by_id($transaction_id);
        
        if (!$transaction || empty($transaction->reembolso_log)) {
            return [];
        }
        
        $logs = json_decode($transaction->reembolso_log, true);
        
        return is_array($logs) ? $logs : [];
    }

    /**
     * Obtém a chave API criptografada para um determinado método de pagamento.
     *
     * @param string $payment_method Método de pagamento ('pix' ou 'boleto').
     * @return string|null Retorna a chave API criptografada ou null se não encontrado.
     */
    public function get_encrypted_api_token($payment_method)
    {
        if ($payment_method == 'pix') {
            return get_option('paymentmethod_pagou_pix_api_token');
        } else {
            return get_option('paymentmethod_pagou_boleto_api_token');
        }
    }
    
    /**
     * Obtém transações PIX com paginação
     *
     * @param int $start Índice inicial
     * @param int $length Número de registros
     * @param string $search Termo de busca
     * @return array Transações PIX
     */
    public function get_transactions_pix_paginated($start = 0, $length = 25, $search = '')
    {
        $this->db->select('
            pix.*,
            inv.hash,
            inv.clientid,
            inv.number as invoice_number,
            inv.prefix as invoice_prefix,
            c.company as client_company
        ');
        $this->db->from(db_prefix() . 'pagou_transactions_pix as pix');
        $this->db->join(db_prefix() . 'invoices as inv', 'inv.id = pix.invoice_id', 'left');
        $this->db->join(db_prefix() . 'clients as c', 'c.userid = inv.clientid', 'left');

        // Aplicar busca se fornecida
        if (!empty($search)) {
            $this->db->group_start();
            $this->db->like('c.company', $search);
            $this->db->or_like('pix.transaction_id', $search);
            $this->db->or_like('pix.status', $search);
            $this->db->or_like('inv.number', $search);
            $this->db->group_end();
        }

        $this->db->order_by('pix.date_created', 'DESC');
        
        // Aplicar paginação se fornecida
        if ($length > 0) {
            $this->db->limit($length, $start);
        }
        
        $query = $this->db->get();
        return $query->result();
    }

    /**
     * Conta o total de transações PIX
     *
     * @param string $search Termo de busca
     * @return int Total de transações PIX
     */
    public function count_transactions_pix($search = '')
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from(db_prefix() . 'pagou_transactions_pix as pix');
        $this->db->join(db_prefix() . 'invoices as inv', 'inv.id = pix.invoice_id', 'left');
        $this->db->join(db_prefix() . 'clients as c', 'c.userid = inv.clientid', 'left');

        // Aplicar busca se fornecida
        if (!empty($search)) {
            $this->db->group_start();
            $this->db->like('c.company', $search);
            $this->db->or_like('pix.transaction_id', $search);
            $this->db->or_like('pix.status', $search);
            $this->db->or_like('inv.number', $search);
            $this->db->group_end();
        }

        $query = $this->db->get();
        $result = $query->row();
        return intval($result->total);
    }
}