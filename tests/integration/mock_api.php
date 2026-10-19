<?php
// Servidor simulado da API Pagou: responde a partir de state.json (escrito pelo teste).
$state_file = __DIR__ . '/state.json';
$state = is_file($state_file) ? json_decode(file_get_contents($state_file), true) : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Registra as chamadas para o teste conferir
$log = is_file(__DIR__ . '/calls.json') ? json_decode(file_get_contents(__DIR__ . '/calls.json'), true) : [];
$log[] = ['method' => $method, 'path' => $path, 'key' => $_SERVER['HTTP_X_API_KEY'] ?? '', 'body' => file_get_contents('php://input')];
file_put_contents(__DIR__ . '/calls.json', json_encode($log));

$key = $method . ' ' . $path;
header('Content-Type: application/json');
if (isset($state[$key])) {
    http_response_code($state[$key]['code']);
    if (isset($state[$key]['body'])) {
        echo json_encode($state[$key]['body']);
    }
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'not found']);
