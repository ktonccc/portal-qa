<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\FlowConfigResolver;
use App\Services\FlowPaymentService;
use App\Services\FlowIngresarPagoReporter;
use App\Services\FlowTransactionStorage;
use App\Services\IngresarPagoService;

$pageTitle = 'Estado del Pago Flow';
$bodyClass = 'hnet';

$token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$errors = [];
$statusData = null;
$statusCode = 0;
$lastTransaction = $_SESSION['flow']['last_transaction'] ?? null;
$transactionRecord = null;

if ($token === '') {
    $errors[] = 'No se recibió el token de Flow para validar la transacción.';
} else {
    try {
        $flowConfig = (array) config_value('flow', []);
        $configResolver = new FlowConfigResolver($flowConfig);
        $flowStorage = new FlowTransactionStorage(__DIR__ . '/app/storage/flow');

        $normalizeCompanyId = static function (mixed $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            $normalized = preg_replace('/[^0-9K]/i', '', $value);

            return strtoupper($normalized ?? '');
        };

        $extractCompanyId = static function (?array $transaction) use ($normalizeCompanyId): string {
            if (!is_array($transaction)) {
                return '';
            }

            $companyId = $normalizeCompanyId($transaction['company_id'] ?? null);
            if ($companyId !== '') {
                return $companyId;
            }

            $debts = $transaction['debts'] ?? [];
            if (!is_array($debts)) {
                return '';
            }

            foreach ($debts as $debt) {
                if (!is_array($debt)) {
                    continue;
                }

                $candidate = $normalizeCompanyId($debt['idempresa'] ?? null);
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return '';
        };

        $companyId = $extractCompanyId(is_array($lastTransaction) ? $lastTransaction : null);
        if ($companyId === '' && $token !== '') {
            $transactionRecord = $flowStorage->get($token);
            $companyId = $extractCompanyId($transactionRecord);
        }

        $service = new FlowPaymentService($configResolver->resolveByCompanyId($companyId));
        $statusData = $service->getPaymentStatus($token);
        $statusCode = (int) ($statusData['status'] ?? 0);

        if (!isset($_SESSION['flow']['status_history']) || !is_array($_SESSION['flow']['status_history'])) {
            $_SESSION['flow']['status_history'] = [];
        }
        $_SESSION['flow']['status_history'][] = [
            'received_at' => time(),
            'source' => 'return',
            'status' => $statusData,
        ];

        $logPath = __DIR__ . '/app/logs/flow-return.log';
        $logPayload = [
            'received_at' => gmdate('c'),
            'token' => $token,
            'payload' => [
                'get' => $_GET,
                'post' => $_POST,
            ],
            'status' => $statusData,
        ];
        $logMessage = sprintf(
            "[%s] [Flow][return] %s%s",
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
                    '764430824' => $ingresarPagoWsdl,
                    '765316081' => $villarricaWsdl,
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
                $reporter->report($token, $statusData);
            }
        } catch (Throwable $reporterException) {
            $reportLogPath = __DIR__ . '/app/logs/flow-ingresar-pago-error.log';
            $reportPayload = [
                'received_at' => gmdate('c'),
                'error' => 'Return: ' . $reporterException->getMessage(),
                'token' => $token,
            ];
            $reportMessage = sprintf(
                "[%s] [Flow][IngresarPago][return-error] %s%s",
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
            $rutToClear = is_array($lastTransaction) ? (string) ($lastTransaction['rut'] ?? '') : '';

            if ($rutToClear === '' && $token !== '') {
                try {
                    $transactionRecord = $transactionRecord ?? $flowStorage->get($token);
                    if (is_array($transactionRecord) && !empty($transactionRecord['rut'])) {
                        $rutToClear = (string) $transactionRecord['rut'];
                    }
                } catch (Throwable $cacheException) {
                    // No bloqueamos la entrega del comprobante si no podemos limpiar la cache.
                }
            }

            if ($rutToClear !== '') {
                clear_debt_cache_for_rut($rutToClear);
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'No fue posible obtener el estado del pago en Flow. Intenta nuevamente.';

        $logPath = __DIR__ . '/app/logs/flow-return-error.log';
        $logPayload = [
            'received_at' => gmdate('c'),
            'error' => $exception->getMessage(),
            'payload' => [
                'get' => $_GET,
                'post' => $_POST,
            ],
        ];
        $logMessage = sprintf(
            "[%s] [Flow][return-error] %s%s",
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
    }
}

$status = 'error';
$title = 'No pudimos confirmar tu pago en Flow';
$description = 'Ocurrió un problema al finalizar el proceso. Si el inconveniente persiste, contáctanos.';
$extra = '';

if ($statusData !== null) {
    switch ($statusCode) {
        case 1:
            $status = 'warning';
            $title = 'Tu pago está pendiente en Flow';
            $description = 'Flow aún no confirma el pago. Te avisaremos cuando recibamos la notificación.';
            $extra = 'Si ya realizaste el pago, espera unos minutos y verifica nuevamente.';
            break;
        case 2:
            $status = 'success';
            $title = '¡Tu pago fue realizado con éxito!';
            $description = 'El pago puede tardar hasta 72 horas en visualizarse.';
            $extra = 'Si tu servicio está suspendido será restablecido a la brevedad.';
            break;
        case 3:
            $status = 'error';
            $title = 'Flow rechazó el pago';
            $description = 'Te recomendamos intentar nuevamente o utilizar otro medio de pago.';
            break;
        case 4:
            $status = 'error';
            $title = 'Flow indicó que el pago fue anulado';
            $description = 'No se realizó ningún cargo. Puedes intentar nuevamente desde el portal.';
            break;
        default:
            $status = 'error';
            $title = 'Estado de pago desconocido';
            $description = 'Recibimos un estado que no pudimos interpretar. Contáctanos para revisar el caso.';
            break;
    }
}

unset($_SESSION['flow']['last_transaction']);

$summary = [];

if (is_array($statusData)) {
    if (!empty($statusData['flowOrder'])) {
        $summary[] = [
            'label' => 'Orden Flow',
            'value' => (string) $statusData['flowOrder'],
        ];
    }
    if (!empty($statusData['commerceOrder'])) {
        $summary[] = [
            'label' => 'Orden Comercio',
            'value' => (string) $statusData['commerceOrder'],
        ];
    }
    if (!empty($statusData['amount'])) {
        $amount = (float) $statusData['amount'];
        $summary[] = [
            'label' => 'Monto',
            'value' => format_currency((int) round($amount)),
        ];
    }
    if (!empty($statusData['paymentData']['media'])) {
        $summary[] = [
            'label' => 'Medio de pago',
            'value' => (string) $statusData['paymentData']['media'],
        ];
    }
    if (!empty($statusData['paymentData']['date'])) {
        $summary[] = [
            'label' => 'Fecha de pago',
            'value' => (string) $statusData['paymentData']['date'],
        ];
    }
}

if (is_array($lastTransaction)) {
    if (!empty($lastTransaction['idcliente'])) {
        $ids = $lastTransaction['idcliente'];
        if (is_array($ids)) {
            $ids = implode(', ', $ids);
        }
        $summary[] = [
            'label' => 'ID Cliente',
            'value' => (string) $ids,
        ];
    }
    if (!empty($lastTransaction['rut'])) {
        $summary[] = [
            'label' => 'RUT',
            'value' => format_rut((string) $lastTransaction['rut']),
        ];
    }
    if (!empty($lastTransaction['email'])) {
        $summary[] = [
            'label' => 'Correo',
            'value' => (string) $lastTransaction['email'],
        ];
    }
    if (!empty($lastTransaction['amount'])) {
        $summary[] = [
            'label' => 'Monto seleccionado',
            'value' => format_currency((int) $lastTransaction['amount']),
        ];
    }
}

$headerClass = 'bg-danger text-white';
if ($status === 'success') {
    $headerClass = 'bg-success text-white';
} elseif ($status === 'warning') {
    $headerClass = 'bg-warning text-dark';
}

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <div class="card shadow-lg pagos-cont mx-auto">
        <div class="card-header <?= h($headerClass); ?> text-center">
            <h4 class="mb-0"><?= h($title); ?></h4>
        </div>
        <div class="card-body">
            <p class="lead text-center"><?= h($description); ?></p>
            <?php if ($extra !== ''): ?>
                <p class="text-center text-muted"><?= h($extra); ?></p>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($summary)): ?>
                <hr>
                <h5 class="text-center mb-3">Resumen del pago</h5>
                <div class="row text-center">
                    <?php foreach ($summary as $item): ?>
                        <div class="col-md-4 mb-3">
                            <span class="d-block text-muted small"><?= h($item['label']); ?></span>
                            <strong><?= h($item['value']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="https://web2.homenet.cl/" class="btn btn-primary">Volver a HomeNet</a>
                <a href="index.php" class="btn btn-outline-secondary ml-2">Realizar otro pago</a>
            </div>
        </div>
    </div>
<?php view('layout/footer'); ?>
