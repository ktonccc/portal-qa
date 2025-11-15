<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\DebtService;
use App\Services\WebpayNormalService;
use App\Services\WebpayTransactionStorage;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$rutInput = trim((string) ($_POST['rut'] ?? ''));
$idClienteInput = $_POST['idcliente'] ?? [];
$email = trim((string) ($_POST['email'] ?? ''));

if ($rutInput === '' && (empty($idClienteInput) || (is_array($idClienteInput) && count(array_filter((array) $idClienteInput)) === 0)) && $email === '') {
    header('Location: index.php');
    exit;
}

error_log(
    '[debug] pay.php received POST ' . json_encode([
        'rut' => $rutInput,
        'idcliente' => $idClienteInput,
        'email' => $email,
        'server' => [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    3,
    __DIR__ . '/app/logs/app.log'
);

// Normalizamos la selección: `idcliente[]` puede venir como arreglo o string.
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

$debugLogPath = __DIR__ . '/app/logs/app.log';
if ($normalizedRut === '' || empty($selectedIds) || ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
    $debugPayload = [
        'timestamp' => date('c'),
        'context' => 'pay.php-initial-validation',
        'post' => [
            'rut' => $rutInput,
            'normalized' => $normalizedRut,
            'idcliente' => $selectedIds,
            'email' => $email,
        ],
    ];
    error_log('[debug] ' . json_encode($debugPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, $debugLogPath);
}

if ($normalizedRut === '') {
    $errors[] = 'El RUT recibido no es válido.';
}

if (empty($selectedIds)) {
    $errors[] = 'Debe seleccionar al menos una deuda para pagar.';
}

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors[] = 'Debe ingresar un correo electrónico válido.';
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
            if ((string) $debt['idcliente'] === $idCliente) {
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

$transactionData = null;
$totalAmount = 0;
$selectedCount = count($selectedDebts);

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
    } else {
        try {
            $webpayConfig = (array) config_value('webpay', []);
            $sessionId = session_id();
            $buyOrder = substr(implode('-', $selectedIds) . '-' . time(), 0, 26);
            $returnUrl = (string) ($webpayConfig['return_url'] ?? '');
            $finalUrl = (string) ($webpayConfig['final_url'] ?? '');

            $webpay = new WebpayNormalService($webpayConfig);
            $transactionData = $webpay->initTransaction(
                $totalAmount,
                $buyOrder,
                $sessionId,
                $returnUrl,
                $finalUrl
            );

            $_SESSION['webpay']['last_transaction'] = [
                'rut' => $normalizedRut,
                'idcliente' => $selectedIds,
                'debts' => $selectedDebts,
                'amount' => $totalAmount,
                'buy_order' => $buyOrder,
                'token' => $transactionData['token'],
                'email' => $email,
            ];

            try {
                $storage = new WebpayTransactionStorage(__DIR__ . '/app/storage/webpay');

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

                $storage->save((string) $transactionData['token'], [
                    'token' => (string) $transactionData['token'],
                    'buy_order' => $buyOrder,
                    'session_id' => $sessionId,
                    'created_at' => time(),
                    'rut' => $normalizedRut,
                    'email' => $email,
                    'amount' => $totalAmount,
                    'selected_ids' => $selectedIds,
                    'debts' => $debtsForStorage,
                    'webpay' => [
                        'request' => [
                            'generated_at' => gmdate('c'),
                            'url' => $transactionData['url'],
                            'token' => $transactionData['token'],
                        ],
                        'responses' => [],
                    ],
                ]);
            } catch (Throwable $storageException) {
                error_log(
                    sprintf(
                        "[%s] [Webpay][storage-error] %s%s",
                        date('Y-m-d H:i:s'),
                        json_encode([
                            'message' => 'No fue posible almacenar la transacción Webpay localmente.',
                            'error' => $storageException->getMessage(),
                            'token' => $transactionData['token'] ?? null,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        PHP_EOL
                    ),
                    3,
                    __DIR__ . '/app/logs/webpay.log'
                );
            }

        } catch (Throwable $exception) {
            $errors[] = 'No fue posible iniciar la transacción con Webpay.';
        }
    }
}

$pageTitle = 'Redirigiendo a Webpay';
$bodyClass = 'hnet';

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <p class="mb-2">No se pudo iniciar el pago.</p>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="index.php" class="btn btn-outline-primary mt-3">Volver al inicio</a>
        </div>
    <?php elseif ($transactionData !== null): ?>
        <div class="alert alert-info text-center" role="alert">
            Estamos redirigiéndote a Webpay para completar tu pago de
            <strong><?= h(format_currency((int) $totalAmount)); ?></strong>
            correspondiente a <?= h((string) $selectedCount); ?> deuda(s) seleccionada(s).
        </div>
        <div class="text-center">
            <form id="webpayRedirectForm" action="<?= h($transactionData['url']); ?>" method="POST">
                <input type="hidden" name="token_ws" value="<?= h($transactionData['token']); ?>">
                <button type="submit" class="btn btn-primary">Ir a Webpay</button>
            </form>
        </div>
        <script>
            window.addEventListener('load', function () {
                document.getElementById('webpayRedirectForm').submit();
            });
        </script>
    <?php endif; ?>
<?php view('layout/footer'); ?>
