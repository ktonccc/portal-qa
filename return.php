<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\IngresarPagoService;
use App\Services\PaymentLoggerService;
use App\Services\WebpayIngresarPagoReporter;
use App\Services\WebpayNormalService;
use App\Services\WebpayTransactionStorage;

$pageTitle = 'Resultado del Pago';
$bodyClass = 'hnet';

$message = '';
$errors = [];
$formAction = '';
$tokenWs = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_ws'])) {
    $tokenWs = trim((string) $_POST['token_ws']);

    try {
        $webpay = new WebpayNormalService((array) config_value('webpay', []));
        $result = $webpay->getTransactionResult($tokenWs);
        $output = $result->detailOutput;

        if (is_array($output)) {
            // Normal transaction should return a single object, take the first item if needed.
            $output = $output[0] ?? null;
        }

        $responseCode = null;
        $paymentTypeCode = null;
        $authorizationCode = null;
        $sharesNumber = null;
        $amountValue = null;

        if (is_object($output)) {
            $responseCode = isset($output->responseCode) ? (int) $output->responseCode : null;
            $paymentTypeCode = isset($output->paymentTypeCode) ? (string) $output->paymentTypeCode : null;
            $authorizationCode = isset($output->authorizationCode) ? (string) $output->authorizationCode : null;
            $sharesNumber = isset($output->sharesNumber) ? $output->sharesNumber : null;
            $amountValue = isset($output->amount) ? $output->amount : null;
        }

        if ($responseCode === 0) {
            $formAction = $result->urlRedirection;
            $message = 'Pago procesado correctamente. Estamos generando tu comprobante.';

            try {
                $logger = new PaymentLoggerService((string) config_value('services.payment_logger_wsdl'));
                $logger->log([
                    'BuyOrder' => $result->buyOrder,
                    'CardNumber' => $result->cardDetail->cardNumber ?? '',
                    'AutorizacionCode' => $output->authorizationCode ?? '',
                    'PaymentTypeCode' => $output->paymentTypeCode ?? '',
                    'ResponseCode' => $output->responseCode ?? '',
                    'SharesNumber' => $output->sharesNumber ?? 0,
                    'Monto' => $output->amount ?? 0,
                    'CodigoComercio' => $output->commerceCode ?? '',
                    'TransactionDate' => $result->transactionDate ?? '',
                ]);
            } catch (Throwable $e) {
                // Continuamos el flujo aun cuando no es posible registrar el resultado.
            }

            $webpay->acknowledgeTransaction($tokenWs);
        } else {
            $errors[] = 'El pago fue rechazado por Transbank.';
            if ($responseCode !== null) {
                $errors[] = 'Código de respuesta: ' . $responseCode;
            }
        }

        try {
            $storage = new WebpayTransactionStorage(__DIR__ . '/app/storage/webpay');
            $rawResult = json_decode(json_encode($result, JSON_UNESCAPED_UNICODE), true);
            $rawOutput = json_decode(json_encode($output, JSON_UNESCAPED_UNICODE), true);

            $transactionRecord = $storage->appendResponse($tokenWs, [
                'received_at' => time(),
                'status' => $responseCode === 0 ? 'success' : 'error',
                'detail' => [
                    'response_code' => $responseCode,
                    'authorization_code' => $authorizationCode,
                    'payment_type_code' => $paymentTypeCode,
                    'shares_number' => $sharesNumber,
                    'amount' => $amountValue,
                    'transaction_date' => $result->transactionDate ?? null,
                    'card_number' => $result->cardDetail->cardNumber ?? null,
                    'buy_order' => $result->buyOrder ?? null,
                    'session_id' => $result->sessionId ?? null,
                ],
                'raw' => [
                    'result' => $rawResult,
                    'detail' => $rawOutput,
                ],
            ]);

            if ($responseCode === 0) {
                $rutFromRecord = isset($transactionRecord['rut']) ? (string) $transactionRecord['rut'] : '';
                if ($rutFromRecord === '' && isset($_SESSION['webpay']['last_transaction']['rut'])) {
                    $rutFromRecord = (string) $_SESSION['webpay']['last_transaction']['rut'];
                }

                if ($rutFromRecord !== '') {
                    clear_debt_cache_for_rut($rutFromRecord);
                }
            }

            if ($responseCode === 0) {
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
                        '764430824' => $ingresarPagoWsdl,
                        '765316081' => $villarricaWsdl,
                        '76734662K' => $gorbeaWsdl,
                    ];

                    $reporter = new WebpayIngresarPagoReporter(
                        new WebpayTransactionStorage(__DIR__ . '/app/storage/webpay'),
                        new IngresarPagoService($ingresarPagoWsdl),
                        __DIR__ . '/app/logs/webpay-ingresar-pago.log',
                        __DIR__ . '/app/logs/webpay-ingresar-pago-error.log',
                        'WEBPAY',
                        $endpointOverrides
                    );
                    $reporter->report($tokenWs);
                }
            }
        } catch (Throwable $storageException) {
            error_log(
                sprintf(
                    "[%s] [Webpay][storage-error] %s%s",
                    date('Y-m-d H:i:s'),
                    json_encode([
                        'token' => $tokenWs,
                        'context' => 'return',
                        'error' => $storageException->getMessage(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    PHP_EOL
                ),
                3,
                __DIR__ . '/app/logs/webpay.log'
            );
        }
    } catch (Throwable $exception) {
        $errors[] = 'Ocurrió un error al obtener el resultado de la transacción.';
    }
} else {
    $errors[] = 'No se recibió un token válido para procesar el pago.';
}

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <p class="mb-2">No fue posible procesar tu pago.</p>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="index.php" class="btn btn-outline-primary mt-3">Volver al inicio</a>
        </div>
    <?php elseif ($formAction !== ''): ?>
        <div class="alert alert-info text-center" role="alert">
            <?= h($message); ?>
        </div>
        <div class="text-center">
            <form id="webpayVoucherForm" action="<?= h($formAction); ?>" method="POST">
                <input type="hidden" name="token_ws" value="<?= h($tokenWs); ?>">
                <button type="submit" class="btn btn-primary">Ver comprobante</button>
            </form>
        </div>
        <script>
            window.addEventListener('load', function () {
                document.getElementById('webpayVoucherForm').submit();
            });
        </script>
    <?php endif; ?>
<?php view('layout/footer'); ?>
