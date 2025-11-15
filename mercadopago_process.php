<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\IngresarPagoService;
use App\Services\MercadoPagoConfigResolver;
use App\Services\MercadoPagoIngresarPagoReporter;
use App\Services\MercadoPagoPaymentService;
use App\Services\MercadoPagoTransactionStorage;

header('Content-Type: application/json');

$body = file_get_contents('php://input');
$jsonPayload = json_decode($body ?: 'null', true);

$paymentId = null;
if (is_array($jsonPayload)) {
    if (isset($jsonPayload['data']['id'])) {
        $paymentId = (string) $jsonPayload['data']['id'];
    } elseif (isset($jsonPayload['id'])) {
        $paymentId = (string) $jsonPayload['id'];
    }
}

if ($paymentId === null || $paymentId === '') {
    $paymentId = trim((string) ($_GET['data_id'] ?? $_GET['id'] ?? $_GET['payment_id'] ?? ''));
}

if ($paymentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No se recibió el identificador del pago.']);
    return;
}

$mpConfig = (array) config_value('mercadopago', []);
$configResolver = new MercadoPagoConfigResolver($mpConfig);
$storage = new MercadoPagoTransactionStorage(__DIR__ . '/app/storage/mercadopago');

$transactionIdParam = trim((string) ($_GET['transaction_id'] ?? ''));
$companyIdParam = trim((string) ($_GET['company_id'] ?? ''));
$transaction = null;

if ($transactionIdParam !== '') {
    $transaction = $storage->get($transactionIdParam);
}

$companyId = $companyIdParam !== '' ? $companyIdParam : (string) ($transaction['company_id'] ?? '');
$profileConfig = $configResolver->resolveByCompanyId($companyId);
$accessToken = trim((string) ($profileConfig['access_token'] ?? ''));

if ($accessToken === '') {
    http_response_code(500);
    echo json_encode(['error' => 'La empresa asociada al pago no tiene credenciales de Mercado Pago configuradas.']);
    return;
}

try {
    $paymentService = new MercadoPagoPaymentService($profileConfig);
    $payment = $paymentService->getPayment($paymentId);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'No fue posible obtener el pago en Mercado Pago: ' . $exception->getMessage()]);
    return;
}

$externalReference = (string) ($payment['external_reference'] ?? '');
if ($externalReference === '') {
    http_response_code(202);
    echo json_encode(['warning' => 'Se recibió el pago pero no tiene referencia interna.']);
    return;
}

if ($transaction === null || (string) ($transaction['transaction_id'] ?? '') !== $externalReference) {
    $transaction = $storage->get($externalReference);
}

if (!is_array($transaction)) {
    http_response_code(404);
    echo json_encode(['error' => 'No se encontró la transacción local asociada al pago.']);
    return;
}

$storage->appendResponse($externalReference, [
    'timestamp' => time(),
    'source' => 'webhook',
    'response' => $payment,
]);

$status = strtolower((string) ($payment['status'] ?? ''));

if ($status === 'approved') {
    $rut = isset($transaction['rut']) ? (string) $transaction['rut'] : '';
    if ($rut !== '') {
        clear_debt_cache_for_rut($rut);
    }

    $ingresarPagoWsdl = (string) config_value('services.ingresar_pago_wsdl', '');
    if ($ingresarPagoWsdl !== '') {
        $villarricaWsdl = trim((string) config_value('services.ingresar_pago_wsdl_villarrica', ''));
        if ($villarricaWsdl === '') {
            $villarricaWsdl = $ingresarPagoWsdl;
        }

        $gorbeaWsdl = trim((string) config_value('services.ingresar_pago_wsdl_gorbea', ''));
        if ($gorbeaWsdl === '') {
            $gorbeaWsdl = $ingresarPagoWsdl;
        }

        $endpointOverrides = [
            '764430824' => $ingresarPagoWsdl,
            '765316081' => $villarricaWsdl,
            '76734662K' => $gorbeaWsdl,
        ];

        try {
            $reporter = new MercadoPagoIngresarPagoReporter(
                $storage,
                new IngresarPagoService($ingresarPagoWsdl),
                __DIR__ . '/app/logs/mercadopago-ingresar-pago.log',
                __DIR__ . '/app/logs/mercadopago-ingresar-pago-error.log',
                'MPAGO',
                'MercadoPago',
                $endpointOverrides
            );
            $reporter->report($externalReference, $payment);
        } catch (Throwable $exception) {
            $logPath = __DIR__ . '/app/logs/mercadopago-ingresar-pago-error.log';
            $logMessage = sprintf(
                "[%s] [MercadoPago][IngresarPago][error] %s%s",
                date('Y-m-d H:i:s'),
                json_encode([
                    'transaction_id' => $externalReference,
                    'error' => $exception->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PHP_EOL
            );
            error_log($logMessage, 3, $logPath);
        }
    }
}

http_response_code(200);
echo json_encode([
    'received' => true,
    'payment_id' => $paymentId,
    'status' => $status,
]);
