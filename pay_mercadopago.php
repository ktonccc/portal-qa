<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\DebtService;
use App\Services\MercadoPagoPaymentService;
use App\Services\MercadoPagoTransactionStorage;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$rutInput = trim((string) ($_POST['rut'] ?? ''));
$idClienteInput = $_POST['idcliente'] ?? [];
$email = trim((string) ($_POST['email'] ?? ''));

$selectedIds = [];
if (is_array($idClienteInput)) {
    foreach ($idClienteInput as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $selectedIds[] = $value;
        }
    }
} elseif (is_string($idClienteInput) || is_numeric($idClienteInput)) {
    $value = trim((string) $idClienteInput);
    if ($value !== '') {
        $selectedIds[] = $value;
    }
}

$selectedIds = array_values(array_unique($selectedIds));

$errors = [];
$normalizedRut = normalize_rut($rutInput);

if ($normalizedRut === '') {
    $errors[] = 'El RUT recibido no es válido.';
}

if (empty($selectedIds)) {
    $errors[] = 'Debe seleccionar al menos una deuda para pagar.';
}

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors[] = 'Debe ingresar un correo electrónico válido.';
}

$mercadoPagoConfig = (array) config_value('mercadopago', []);
$mercadoPagoPublicKey = trim((string) ($mercadoPagoConfig['public_key'] ?? ''));
$mercadoPagoAccessToken = trim((string) ($mercadoPagoConfig['access_token'] ?? ''));

if ($mercadoPagoPublicKey === '' || $mercadoPagoAccessToken === '') {
    $errors[] = 'Mercado Pago no se encuentra configurado. Contacta al administrador del sistema.';
}

$availableDebts = [];
if ($normalizedRut !== '' && empty($errors)) {
    $snapshot = get_debt_snapshot($normalizedRut);
    if ($snapshot !== null) {
        $availableDebts = $snapshot;
    } else {
        try {
            $service = new DebtService(
                (string) config_value('services.debt_wsdl'),
                (string) config_value('services.debt_wsdl_fallback')
            );
            $availableDebts = $service->fetchDebts($normalizedRut);
            store_debt_snapshot($normalizedRut, $availableDebts);
        } catch (Throwable $exception) {
            $errors[] = 'No fue posible validar la deuda asociada al cliente.';
        }
    }
}

$selectedDebts = [];
if (empty($errors)) {
    foreach ($selectedIds as $idCliente) {
        $found = null;
        foreach ($availableDebts as $debt) {
            if ((string) ($debt['idcliente'] ?? '') === $idCliente) {
                $found = $debt;
                break;
            }
        }

        if ($found === null) {
            $errors[] = 'No se encontró la deuda seleccionada con ID ' . $idCliente . '. Vuelve a consultar e inténtalo nuevamente.';
        } else {
            $selectedDebts[] = $found;
        }
    }
}

$totalAmount = 0;
if (empty($errors)) {
    foreach ($selectedDebts as $debt) {
        $amount = (int) ($debt['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        $totalAmount += $amount;
    }

    if ($totalAmount <= 0) {
        $errors[] = 'El monto total de las deudas seleccionadas no es válido.';
    }
}

$transactionId = null;
$preferenceRedirectUrl = null;
$preferenceResponse = null;
$paymentService = null;
$customerName = null;

if (!empty($selectedDebts)) {
    $firstDebt = $selectedDebts[0];
    if (is_array($firstDebt) && !empty($firstDebt['nombre'])) {
        $customerName = (string) $firstDebt['nombre'];
    }
}
if (empty($errors)) {
    try {
        $transactionId = 'mp-' . bin2hex(random_bytes(10));
    } catch (Throwable) {
        $transactionId = 'mp-' . uniqid('', true);
    }

    $storage = new MercadoPagoTransactionStorage(__DIR__ . '/app/storage/mercadopago');

    $debtsForStorage = [];
    foreach ($selectedDebts as $debt) {
        if (!is_array($debt)) {
            continue;
        }

        $debtsForStorage[] = [
            'idempresa' => (string) ($debt['idempresa'] ?? ''),
            'idcliente' => (string) ($debt['idcliente'] ?? ''),
            'mes' => (string) ($debt['mes'] ?? ''),
            'ano' => (string) ($debt['ano'] ?? ''),
            'amount' => (int) ($debt['amount'] ?? 0),
        ];
    }

    $storagePayload = [
        'transaction_id' => $transactionId,
        'rut' => $normalizedRut,
        'email' => $email,
        'amount' => $totalAmount,
        'selected_ids' => $selectedIds,
        'debts' => $debtsForStorage,
        'created_at' => time(),
    ];

    try {
        $storage->save($transactionId, $storagePayload);
    } catch (Throwable $exception) {
        $errors[] = 'No fue posible preparar la transacción de Mercado Pago.';

        $logPath = __DIR__ . '/app/logs/mercadopago-error.log';
        $logMessage = sprintf(
            "[%s] [MercadoPago][storage-error] %s%s",
            date('Y-m-d H:i:s'),
            json_encode([
                'transaction_id' => $transactionId,
                'error' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );
        error_log($logMessage, 3, $logPath);
    }

    if (empty($errors)) {
        try {
            $paymentService = new MercadoPagoPaymentService($mercadoPagoConfig);
        } catch (Throwable $exception) {
            $errors[] = 'No fue posible inicializar la conexión con Mercado Pago.';
            $logPath = __DIR__ . '/app/logs/mercadopago-error.log';
            $logEntry = [
                'timestamp' => gmdate('c'),
                'transaction_id' => $transactionId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ];
            error_log(
                sprintf('[%s] [MercadoPago][init-error] %s%s', date('Y-m-d H:i:s'), json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL),
                3,
                $logPath
            );
        }
    }

    if ($paymentService !== null && empty($errors)) {
        $items = [];
        foreach ($selectedDebts as $index => $debt) {
            if (!is_array($debt)) {
                continue;
            }

            $label = trim((string) ($debt['servicio'] ?? $debt['mes'] ?? 'Servicio'));
            if ($label === '') {
                $label = 'Servicio ' . (($index + 1));
            }

            $contractId = (string) ($debt['idcliente'] ?? ('item-' . $index));
            $nameRaw = trim((string) ($debt['nombre'] ?? $customerName ?? ''));
            $nameForTitle = $nameRaw !== ''
                ? (function_exists('mb_strtolower') ? mb_strtolower($nameRaw, 'UTF-8') : strtolower($nameRaw))
                : 'cliente homenet';

            $items[] = [
                'id' => $contractId,
                'title' => trim($contractId . ' - ' . $nameForTitle),
                'description' => substr($label, 0, 60),
                'quantity' => 1,
                'currency_id' => 'CLP',
                'unit_price' => (int) ($debt['amount'] ?? 0),
            ];
        }

        if (empty($items)) {
            $items[] = [
                'id' => 'HN-' . $transactionId,
                'title' => 'Pago servicios HomeNet',
                'quantity' => 1,
                'currency_id' => 'CLP',
                'unit_price' => $totalAmount,
            ];
        }

        $returnUrls = (array) ($mercadoPagoConfig['return_urls'] ?? []);
        $autoReturn = trim((string) ($mercadoPagoConfig['auto_return'] ?? 'approved'));
        $notificationUrl = trim((string) ($mercadoPagoConfig['notification_url'] ?? ''));

        $preferencePayload = [
            'items' => $items,
            'payer' => [
                'email' => $email,
                'name' => $customerName ?? '',
            ],
            'external_reference' => $transactionId,
            'metadata' => [
                'transaction_id' => $transactionId,
                'rut' => $normalizedRut,
                'selected_ids' => $selectedIds,
            ],
            'back_urls' => [
                'success' => (string) ($returnUrls['success'] ?? 'https://pagos2.homenet.cl/mercadopago_return.php'),
                'pending' => (string) ($returnUrls['pending'] ?? 'https://pagos2.homenet.cl/mercadopago_return.php'),
                'failure' => (string) ($returnUrls['failure'] ?? 'https://pagos2.homenet.cl/mercadopago_return.php'),
            ],
            'auto_return' => $autoReturn !== '' ? $autoReturn : 'approved',
            'binary_mode' => true,
        ];

        if ($notificationUrl !== '') {
            $preferencePayload['notification_url'] = $notificationUrl;
        }

        try {
            $preferenceResponse = $paymentService->createPreference($preferencePayload);
            $preferenceRedirectUrl = (string) ($preferenceResponse['init_point'] ?? $preferenceResponse['sandbox_init_point'] ?? '');
            if ($preferenceRedirectUrl === '') {
                $errors[] = 'Mercado Pago no entregó la URL para continuar el pago.';
            } else {
                $storage->merge($transactionId, [
                    'preference' => [
                        'id' => $preferenceResponse['id'] ?? null,
                        'init_point' => $preferenceRedirectUrl,
                        'sandbox_init_point' => $preferenceResponse['sandbox_init_point'] ?? null,
                    ],
                ]);
            }
        } catch (Throwable $exception) {
            $errors[] = 'No fue posible generar la preferencia de pago en Mercado Pago.';

            $logPath = __DIR__ . '/app/logs/mercadopago-error.log';
            $logEntry = [
                'timestamp' => gmdate('c'),
                'transaction_id' => $transactionId,
                'error' => $exception->getMessage(),
            ];
            error_log(
                sprintf('[%s] [MercadoPago][preference-error] %s%s', date('Y-m-d H:i:s'), json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL),
                3,
                $logPath
            );
        }

        if ($preferenceRedirectUrl !== null && $preferenceRedirectUrl !== '' && empty($errors)) {
            $_SESSION['mercadopago']['checkout'] = [
                'transaction_id' => $transactionId,
                'rut' => $normalizedRut,
                'selected_ids' => $selectedIds,
                'selected_count' => count($selectedDebts),
                'debts' => $selectedDebts,
                'amount' => $totalAmount,
                'email' => $email,
                'created_at' => time(),
                'preference_id' => $preferenceResponse['id'] ?? null,
                'redirect_url' => $preferenceRedirectUrl,
            ];

            $logPath = __DIR__ . '/app/logs/mercadopago.log';
            $logEntry = [
                'timestamp' => gmdate('c'),
                'transaction_id' => $transactionId,
                'preference_id' => $preferenceResponse['id'] ?? null,
                'rut' => $normalizedRut,
                'email' => $email,
                'amount' => $totalAmount,
                'selected_ids' => $selectedIds,
                'redirect_url' => $preferenceRedirectUrl,
            ];
            error_log(
                sprintf(
                    "[%s] [MercadoPago][checkout-pro] %s%s",
                    date('Y-m-d H:i:s'),
                    json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    PHP_EOL
                ),
                3,
                $logPath
            );

            header('Location: ' . $preferenceRedirectUrl);
            exit;
        }
    }
}

if (empty($errors)) {
    // Fallback por si no se redirigió automáticamente
    header('Location: index.php');
    exit;
}

$pageTitle = 'Error con Mercado Pago';
$bodyClass = 'hnet';

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
<div class="alert alert-danger" role="alert">
    <p class="mb-2">No fue posible preparar el pago con Mercado Pago.</p>
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
            <li><?= h($error); ?></li>
        <?php endforeach; ?>
    </ul>
    <a href="index.php" class="btn btn-outline-primary mt-3">Volver al inicio</a>
</div>
<?php view('layout/footer'); ?>
