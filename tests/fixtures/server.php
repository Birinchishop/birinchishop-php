<?php

// Router for `php -S 127.0.0.1:<port>` used by CurlTransportTest (loopback only, never the real API).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_ends_with((string) $path, '/slow')) {
    usleep(1500000);
}
header('Content-Type: application/json');
header('X-Request-Id: req_loopback');
if (str_ends_with((string) $path, '/error')) {
    http_response_code(409);
    echo json_encode(['error' => ['code' => 'duplicate_order', 'message' => 'dup'], 'request_id' => 'req_loopback']);
    return;
}
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
echo json_encode(['data' => [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], 'request_id' => 'req_loopback']);
