<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\DebtService;
use App\Services\ZumpagoConfigResolver;
use App\Services\ZumpagoRedirectService;
use App\Services\ZumpagoTransactionStorage;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$rutInput = trim((string) ($_POST['rut'] ?? ''));
$idClienteInput = $_POST['idcliente'] ?? [];
$email = trim((string) ($_POST['email'] ?? ''));

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
$selectedCompanyId = '';

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
        $companySet = [];
        foreach ($selectedDebts as $debt) {
            $rawCompanyId = (string) ($debt['idempresa'] ?? '');
            $normalizedCompanyId = strtoupper(preg_replace('/[^0-9A-Z]/', '', $rawCompanyId) ?? '');
            if ($normalizedCompanyId !== '') {
                $companySet[$normalizedCompanyId] = true;
            }
        }

        $uniqueCompanies = array_keys($companySet);
        if (count($uniqueCompanies) === 0) {
            $errors[] = 'No fue posible determinar la empresa asociada a las deudas seleccionadas.';
        } elseif (count($uniqueCompanies) > 1) {
            $errors[] = 'Las deudas seleccionadas pertenecen a distintas empresas. Selecciona deudas de una sola empresa para continuar con Zumpago.';
        } else {
            $selectedCompanyId = (string) $uniqueCompanies[0];
        }

        $zumpagoConfig = (array) config_value('zumpago', []);
        $configResolver = new ZumpagoConfigResolver($zumpagoConfig);

        if ($selectedCompanyId !== '' && !$configResolver->hasCompanyProfile($selectedCompanyId)) {
            $errors[] = 'La empresa seleccionada no está habilitada para pagos a través de Zumpago.';
        }

        if (empty($errors)) {
            try {
                $profileConfig = $configResolver->resolveByCompanyId($selectedCompanyId);
                $zumpago = new ZumpagoRedirectService($profileConfig);
                $transactionData = $zumpago->createRedirectData(
                    $normalizedRut,
                    $totalAmount,
                    $selectedIds,
                    $email
                );

            $_SESSION['zumpago']['last_transaction'] = [
                'rut' => $normalizedRut,
                'idcliente' => $selectedIds,
                'debts' => $selectedDebts,
                'amount' => $totalAmount,
                'email' => $email,
                'company_id' => $selectedCompanyId,
                'xml' => $transactionData['xml'],
                'encrypted_xml' => $transactionData['encrypted_xml'],
                'transaction' => $transactionData['transaction'],
                'redirect_url' => $transactionData['redirect_url'],
                'endpoint' => $transactionData['endpoint'],
            ];
            $_SESSION['zumpago']['debug'] = [
                'payload' => [
                    'saved_at' => gmdate('c'),
                    'data' => $transactionData,
                ],
            ];

            try {
                $storage = new ZumpagoTransactionStorage(__DIR__ . '/app/storage/zumpago');
                $transactionId = (string) ($transactionData['transaction']['id'] ?? '');

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
                    'created_at' => time(),
                    'company_id' => $selectedCompanyId,
                    'debts' => $debtsForStorage,
                    'xml' => $transactionData['xml'],
                    'encrypted_xml' => $transactionData['encrypted_xml'],
                    'endpoint' => $transactionData['endpoint'],
                    'redirect_url' => $transactionData['redirect_url'],
                    'transaction' => $transactionData['transaction'],
                    'zumpago' => [
                        'request' => [
                            'generated_at' => gmdate('c'),
                            'endpoint' => $transactionData['endpoint'],
                            'redirect_url' => $transactionData['redirect_url'],
                            'xml' => $transactionData['xml'],
                            'encrypted_xml' => $transactionData['encrypted_xml'],
                        ],
                        'responses' => [],
                    ],
                ];

                $storage->save($transactionId, $storagePayload);
            } catch (Throwable $storageException) {
                $storageLogPath = __DIR__ . '/app/logs/zumpago.log';
                $storageLogPayload = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'message' => 'No fue posible almacenar la transacción Zumpago localmente.',
                    'error' => $storageException->getMessage(),
                    'transaction' => $transactionData['transaction']['id'] ?? null,
                ];
                error_log(
                    '[Zumpago][storage-error] ' . json_encode($storageLogPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    3,
                    $storageLogPath
                );
            }

            $logPath = __DIR__ . '/app/logs/zumpago.log';
            $logPayload = [
                'rut' => $normalizedRut,
                'email' => $email,
                'amount' => $totalAmount,
                'selected_ids' => $selectedIds,
                'company_id' => $selectedCompanyId,
                'transaction' => $transactionData['transaction'],
                'endpoint' => $transactionData['endpoint'],
                'redirect_url' => $transactionData['redirect_url'],
            ];
            $logMessage = sprintf(
                "[%s] [Zumpago][payload] %s%s",
                date('Y-m-d H:i:s'),
                json_encode($logPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
        } catch (Throwable $exception) {
            $errors[] = 'No fue posible preparar la redirección a Zumpago.';
        }
    }

}
}

$pageTitle = 'Redirigiendo a Zumpago';
$bodyClass = 'hnet';

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <p class="mb-2">No se pudo iniciar el pago con Zumpago.</p>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="index.php" class="btn btn-outline-primary mt-3">Volver al inicio</a>
        </div>
    <?php elseif ($transactionData !== null): ?>
        <div class="alert alert-info text-center" role="alert">
            Estamos redirigiéndote a Zumpago para completar tu pago de
            <strong><?= h(format_currency((int) $totalAmount)); ?></strong>
            correspondiente a <?= h((string) $selectedCount); ?> deuda(s) seleccionada(s).
        </div>
        <div class="text-center">
            <a href="<?= h($transactionData['redirect_url']); ?>" class="btn btn-primary">Ir a Zumpago</a>
            <p class="text-muted small mt-2">Si no eres redirigido automáticamente, haz clic en el botón.</p>
        </div>
        <script>
            (function () {
                const zumpagoRedirectPayload = <?= json_encode($transactionData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                console.log('[Zumpago] Payload enviado:', zumpagoRedirectPayload);

                window.addEventListener('load', function () {
                    console.log('[Zumpago] Redirigiendo a:', zumpagoRedirectPayload.redirect_url);
                    window.location.href = zumpagoRedirectPayload.redirect_url;
                });
            }());
        </script>
    <?php endif; ?>
<?php view('layout/footer'); ?>
