<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: text/plain');

$rawBody = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];

$logEntry = [
    'timestamp' => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'query' => $_GET,
    'body' => $_POST,
    'headers' => $headers,
    'raw' => $rawBody,
];

$logPath = __DIR__ . '/../app/logs/zumpago.log';

try {
    $json = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        file_put_contents($logPath, $json . PHP_EOL, FILE_APPEND);
    }
} catch (Throwable $exception) {
    // No interrumpimos la respuesta en caso de un fallo al escribir el log.
}

http_response_code(200);
echo 'OK';

