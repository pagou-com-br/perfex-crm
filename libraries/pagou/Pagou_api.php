<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Resposta de uma chamada à API Pagou.
 */
class Pagou_api_response
{
    /** @var int Código HTTP (0 quando não houve resposta) */
    public $http_code = 0;

    /** @var mixed Corpo decodificado (array associativo) ou null */
    public $body = null;

    /** @var string Erro de transporte do cURL, se houver */
    public $transport_error = '';

    public function __construct($http_code, $body, $transport_error = '')
    {
        $this->http_code = (int) $http_code;
        $this->body = $body;
        $this->transport_error = (string) $transport_error;
    }

    public function ok()
    {
        return $this->http_code >= 200 && $this->http_code < 300;
    }

    /**
     * Timeout, conexão interrompida ou 5xx: numa escrita, o resultado é indeterminado.
     */
    public function uncertain()
    {
        return $this->http_code === 0 || $this->http_code >= 500;
    }

    public function not_found()
    {
        return $this->http_code === 404;
    }

    /**
     * Código de erro da API (campo code), quando presente.
     */
    public function error_code()
    {
        return is_array($this->body) && isset($this->body['code']) && is_scalar($this->body['code'])
            ? (string) $this->body['code']
            : '';
    }

    /**
     * Mensagem de erro legível a partir de error, message ou errors.
     */
    public function error_message()
    {
        if ($this->transport_error !== '') {
            return 'Falha de comunicação com a Pagou.';
        }
        if (!is_array($this->body)) {
            return 'Resposta inesperada da Pagou (HTTP ' . $this->http_code . ').';
        }
        foreach (['error', 'message', 'errors'] as $field) {
            if (!empty($this->body[$field])) {
                $value = $this->body[$field];
                if (is_array($value)) {
                    $flat = [];
                    array_walk_recursive($value, function ($item) use (&$flat) {
                        if (is_scalar($item)) {
                            $flat[] = (string) $item;
                        }
                    });
                    return implode('; ', $flat);
                }
                return (string) $value;
            }
        }

        return 'Erro HTTP ' . $this->http_code . '.';
    }
}

/**
 * Cliente HTTP único da API Pagou.
 * Sandbox: https://api.pagou.cloud · Produção: https://api.pagou.com.br
 */
class Pagou_api
{
    const USER_AGENT = 'pagou-perfex/2.0.0';

    private $api_key;
    private $base_url;

    public function __construct($api_key, $is_production)
    {
        $this->api_key = (string) $api_key;
        $this->base_url = pagou_api_base_url((bool) $is_production);
    }

    /**
     * Cliente configurado com a chave e o ambiente do gateway (pagou_pix, pagou_boleto).
     *
     * @return Pagou_api|null null quando o gateway não tem chave
     */
    public static function for_gateway($gateway_id)
    {
        $key = pagou_gateway_api_token($gateway_id);
        if (!$key) {
            return null;
        }

        return new self($key, pagou_gateway_is_production($gateway_id));
    }

    public function is_configured()
    {
        return $this->api_key !== '';
    }

    /**
     * @param string     $method
     * @param string     $path    Caminho começando por /v1
     * @param array|null $body
     * @param array      $query
     * @return Pagou_api_response
     */
    public function request($method, $path, $body = null, array $query = [])
    {
        $raw = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        return $this->send($method, $path, $raw, $query, []);
    }

    /**
     * Envia um corpo JSON já serializado. Usado nas escritas de cartão com Idempotency-Key,
     * que precisam repetir exatamente os mesmos bytes.
     *
     * @param string      $method
     * @param string      $path
     * @param string|null $raw_body
     * @param array       $query
     * @param array       $extra_headers ['Idempotency-Key' => '...']
     * @return Pagou_api_response
     */
    public function send($method, $path, $raw_body, array $query = [], array $extra_headers = [])
    {
        $url = $this->base_url . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'X-API-KEY: ' . $this->api_key,
        ];

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        foreach ($extra_headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if ($raw_body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $raw_body;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $started = microtime(true);
        $raw = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $transport_error = $raw === false ? curl_error($ch) : '';
        curl_close($ch);

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
        }

        pagou_log('api', $http_code >= 200 && $http_code < 300 ? 'debug' : 'error', "{$method} {$path} -> HTTP {$http_code}", [
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'transport_error' => $transport_error,
            'request' => $raw_body !== null ? json_decode($raw_body, true) : null,
            'response' => $decoded,
        ]);

        return new Pagou_api_response($http_code, $decoded, $transport_error);
    }

    // ---------------------------------------------------------------- Pix

    public function create_pix(array $body)
    {
        return $this->request('POST', '/v1/pix', $body);
    }

    public function create_pix_due(array $body)
    {
        return $this->request('POST', '/v1/pix/due', $body);
    }

    public function get_pix($id)
    {
        return $this->request('GET', '/v1/pix/' . rawurlencode($id));
    }

    public function list_pix(array $filters)
    {
        return $this->request('GET', '/v1/pix', null, $filters);
    }

    public function cancel_pix($id)
    {
        return $this->request('DELETE', '/v1/pix/' . rawurlencode($id));
    }

    public function refund_pix($id, $amount, $reason, $description)
    {
        return $this->request('DELETE', '/v1/pix/' . rawurlencode($id) . '/refund', [
            'amount'      => round((float) $amount, 2),
            'reason'      => (int) $reason,
            'description' => (string) $description,
        ]);
    }

    // ---------------------------------------------------------------- Cartão

    /**
     * Cria a cobrança de cartão com o corpo exato e a chave de idempotência gravados antes do envio.
     */
    public function create_card_charge($raw_body, $idempotency_key)
    {
        return $this->send('POST', '/v1/creditcard/charges', $raw_body, [], ['Idempotency-Key' => $idempotency_key]);
    }

    public function get_card_charge($id)
    {
        return $this->request('GET', '/v1/creditcard/charges/' . rawurlencode($id));
    }

    public function cancel_card_charge($id, $idempotency_key)
    {
        return $this->send('DELETE', '/v1/creditcard/charges/' . rawurlencode($id), null, [], ['Idempotency-Key' => $idempotency_key]);
    }

    public function reverse_card_charge($id, $raw_body, $idempotency_key)
    {
        return $this->send('PUT', '/v1/creditcard/charges/' . rawurlencode($id) . '/reverse', $raw_body, [], ['Idempotency-Key' => $idempotency_key]);
    }

    // ---------------------------------------------------------------- Boleto

    public function create_charge(array $body)
    {
        return $this->request('POST', '/v1/charges', $body);
    }

    public function get_charge($id)
    {
        return $this->request('GET', '/v1/charges/' . rawurlencode($id));
    }

    public function list_charges(array $filters)
    {
        return $this->request('GET', '/v1/charges', null, $filters);
    }

    public function cancel_charge($id)
    {
        return $this->request('DELETE', '/v1/charges/' . rawurlencode($id));
    }
}
