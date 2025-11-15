<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\FlowPaymentService;
use App\Services\FlowIngresarPagoReporter;
use App\Services\FlowTransactionStorage;
use App\Services\IngresarPagoService;

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    return;
}

$token = trim((string) ($_POST['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo 'Missing token';
    return;
}

try {
    $flowConfig = (array) config_value('flow', []);
    $flowService = new FlowPaymentService($flowConfig);
    $flowStorage = new FlowTransactionStorage(__DIR__ . '/app/storage/flow');
    $status = $flowService->getPaymentStatus($token);
    $statusCode = (int) ($status['status'] ?? 0);

    if (!isset($_SESSION['flow']['status_history']) || !is_array($_SESSION['flow']['status_history'])) {
        $_SESSION['flow']['status_history'] = [];
    }
    $_SESSION['flow']['status_history'][] = [
        'received_at' => time(),
        'source' => 'callback',
        'status' => $status,
    ];

    $logPath = __DIR__ . '/app/logs/flow-callback.log';
    $logPayload = [
        'received_at' => gmdate('c'),
        'token' => $token,
        'payload' => [
            'post' => $_POST,
            'get' => $_GET,
        ],
        'status' => $status,
    ];
    $logMessage = sprintf(
        "[%s] [Flow][callback] %s%s",
        date('Y-m-d H:i:s'),
        json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        PHP_EOL
    );

    $logDir = dirname($logPath);
    $canWrite = (file_exists($logPath) && is_writable($logPath))
        || (!file_exists($logPath) && is_writable($logDir));

    if ($canWrite) {
        error_log($logMessage, 3, $logPath);
    } else {
        error_log($logMessage);
    }

    try {
        $ingresarPagoWsdl = (string) config_value('services.ingresar_pago_wsdl', '');
        if (trim($ingresarPagoWsdl) !== '') {
            $villarricaWsdl = trim((string) config_value('services.ingresar_pago_wsdl_villarrica', ''));
            if ($villarricaWsdl === '') {
                $villarricaWsdl = $ingresarPagoWsdl;
            }

            $gorbeaWsdl = trim((string) config_value('services.ingresar_pago_wsdl_gorbea', ''));
            if ($gorbeaWsdl === '') {
                $gorbeaWsdl = $ingresarPagoWsdl;
            }

            $endpointOverrides = [
                '764430824' => $ingresarPagoWsdl, // Padre Las Casas (endpoint actual)
                '765316085' => $villarricaWsdl,
                '76734662K' => $gorbeaWsdl,
            ];

            $reporter = new FlowIngresarPagoReporter(
                $flowStorage,
                new IngresarPagoService($ingresarPagoWsdl),
                __DIR__ . '/app/logs/flow-ingresar-pago.log',
                __DIR__ . '/app/logs/flow-ingresar-pago-error.log',
                'FLOW',
                $endpointOverrides
            );
            $reporter->report($token, $status);
        }
    } catch (Throwable $reporterException) {
        $reportLogPath = __DIR__ . '/app/logs/flow-ingresar-pago-error.log';
        $reportPayload = [
            'received_at' => gmdate('c'),
            'error' => 'Callback: ' . $reporterException->getMessage(),
            'token' => $token,
        ];
        $reportMessage = sprintf(
            "[%s] [Flow][IngresarPago][callback-error] %s%s",
            date('Y-m-d H:i:s'),
            json_encode($reportPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );
        $reportDir = dirname($reportLogPath);
        $reportWritable = (file_exists($reportLogPath) && is_writable($reportLogPath))
            || (!file_exists($reportLogPath) && is_writable($reportDir));

        if ($reportWritable) {
            error_log($reportMessage, 3, $reportLogPath);
        } else {
            error_log($reportMessage);
        }
    }

    if ($statusCode === 2) {
        $transactionRecord = $flowStorage->get($token);
        $rutToClear = is_array($transactionRecord) ? (string) ($transactionRecord['rut'] ?? '') : '';

        if ($rutToClear !== '') {
            clear_debt_cache_for_rut($rutToClear);
        }
    }

    echo 'OK';
} catch (Throwable $exception) {
    $logPath = __DIR__ . '/app/logs/flow-callback-error.log';
    $logPayload = [
        'received_at' => gmdate('c'),
        'error' => $exception->getMessage(),
        'payload' => [
            'post' => $_POST,
            'get' => $_GET,
        ],
    ];
    $logMessage = sprintf(
        "[%s] [Flow][callback-error] %s%s",
        date('Y-m-d H:i:s'),
        json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        PHP_EOL
    );

    $logDir = dirname($logPath);
    $canWrite = (file_exists($logPath) && is_writable($logPath))
        || (!file_exists($logPath) && is_writable($logDir));

    if ($canWrite) {
        error_log($logMessage, 3, $logPath);
    } else {
        error_log($logMessage);
    }

    http_response_code(500);
    echo 'ERROR';
}
