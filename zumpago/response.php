<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\IngresarPagoService;
use App\Services\ZumpagoConfigResolver;
use App\Services\ZumpagoIngresarPagoReporter;
use App\Services\ZumpagoResponseService;
use App\Services\ZumpagoTransactionStorage;

$pageTitle = 'Resultado del Pago Zumpago';
$bodyClass = 'hnet';

$lastTransaction = $_SESSION['zumpago']['last_transaction'] ?? null;
$debugData = $_SESSION['zumpago']['debug'] ?? [];
$debugPayloadRecord = is_array($debugData['payload'] ?? null) ? $debugData['payload'] : null;

$debugResponseRecord = [
    'captured_at' => gmdate('c'),
    'get' => $_GET,
    'post' => $_POST,
    'request' => $_REQUEST,
];

$encryptedXmlParam = (string) ($_REQUEST['xml'] ?? '');
$parsedResponseData = null;
$decryptedXml = null;
$verificationDetails = null;
$zumpagoProcessingErrors = [];
$responseCode = null;
$responseDescription = null;
$responseAmount = null;
$zumpagoDetails = [];
$processedAt = null;
$zumpagoTableRows = [];
$activeCompanyId = null;

if ($encryptedXmlParam !== '') {
    try {
        $config = (array) config_value('zumpago', []);
        $resolver = new ZumpagoConfigResolver($config);
        $sessionCompanyId = is_array($lastTransaction) ? (string) ($lastTransaction['company_id'] ?? '') : '';

        $parseWithProfile = static function (array $profile, string $payload): array {
            $responseService = new ZumpagoResponseService($profile);
            return $responseService->parseResponse($payload);
        };

        $profilesToTry = [];
        $queuedProfiles = [];
        $queueProfile = static function (array $profile) use (&$profilesToTry, &$queuedProfiles): void {
            $key = ($profile['company_id'] ?? '') . '|' . ($profile['company_code'] ?? '');
            if (isset($queuedProfiles[$key])) {
                return;
            }
            $queuedProfiles[$key] = true;
            $profilesToTry[] = $profile;
        };

        if ($sessionCompanyId !== '') {
            $queueProfile($resolver->resolveByCompanyId($sessionCompanyId));
        }

        foreach ($resolver->getProfiles() as $profileCandidate) {
            $queueProfile($profileCandidate);
        }

        $parsedResponse = null;
        $activeProfile = null;
        $lastParseException = null;

        foreach ($profilesToTry as $profileCandidate) {
            try {
                $parsedResponse = $parseWithProfile($profileCandidate, $encryptedXmlParam);
                $activeProfile = $profileCandidate;
                break;
            } catch (\Throwable $parseException) {
                $lastParseException = $parseException;
            }
        }

        if ($parsedResponse === null || $activeProfile === null) {
            if ($lastParseException instanceof \Throwable) {
                throw $lastParseException;
            }

            throw new \RuntimeException('No fue posible interpretar la respuesta de Zumpago.');
        }

        $idComercio = trim((string) ($parsedResponse['data']['IdComercio'] ?? ''));
        if ($idComercio !== '') {
            $profileByCommerce = $resolver->resolveByCommerceCode($idComercio);
            $commerceKey = ($profileByCommerce['company_id'] ?? '') . '|' . ($profileByCommerce['company_code'] ?? '');
            $activeKey = ($activeProfile['company_id'] ?? '') . '|' . ($activeProfile['company_code'] ?? '');

            if ($commerceKey !== $activeKey) {
                $parsedResponse = $parseWithProfile($profileByCommerce, $encryptedXmlParam);
                $activeProfile = $profileByCommerce;
            }
        }

        $activeCompanyId = (string) ($activeProfile['company_id'] ?? '');
        if ($activeCompanyId !== '') {
            if (!isset($_SESSION['zumpago']) || !is_array($_SESSION['zumpago'])) {
                $_SESSION['zumpago'] = [];
            }
            if (!isset($_SESSION['zumpago']['last_transaction']) || !is_array($_SESSION['zumpago']['last_transaction'])) {
                $_SESSION['zumpago']['last_transaction'] = [];
            }
            $_SESSION['zumpago']['last_transaction']['company_id'] = $activeCompanyId;
        }

        $decryptedXml = $parsedResponse['xml'];
        $parsedResponseData = $parsedResponse['data'];
        $verificationDetails = $parsedResponse['verification'];
        $transactionId = trim((string) ($parsedResponseData['IdTransaccion'] ?? ''));
        $responseCode = str_pad(
            trim((string) ($parsedResponseData['CodigoRespuesta'] ?? '')),
            3,
            '0',
            STR_PAD_LEFT
        );
        $responseDescription = trim((string) ($parsedResponseData['DescripcionRespuesta'] ?? ''));
        $processedAtRaw = trim((string) ($parsedResponseData['FechaProcesamiento'] ?? ''));
        $processedAt = $processedAtRaw !== '' ? $processedAtRaw : null;
        $responseAmountValue = (string) ($parsedResponseData['MontoTotal'] ?? '');
        if ($responseAmountValue !== '') {
            $responseAmount = (int) $responseAmountValue;
        }

        if ($responseCode !== null && $responseCode !== '') {
            $zumpagoDetails[] = [
                'label' => 'Código de respuesta',
                'value' => $responseCode,
            ];
        }

        if ($responseDescription !== '') {
            $zumpagoDetails[] = [
                'label' => 'Detalle entregado',
                'value' => $responseDescription,
            ];
        }

        if ($responseAmount !== null && $responseAmount > 0) {
            $zumpagoDetails[] = [
                'label' => 'Monto informado',
                'value' => format_currency($responseAmount),
            ];
        }

        $authorizationCode = trim((string) ($parsedResponseData['CodigoAutorizacion'] ?? ''));
        if ($authorizationCode !== '') {
            $zumpagoDetails[] = [
                'label' => 'Código de autorización',
                'value' => $authorizationCode,
            ];
        }

        $paymentMethod = trim((string) ($parsedResponseData['MedioPagoAutorizado'] ?? ''));
        if ($paymentMethod !== '') {
            $zumpagoDetails[] = [
                'label' => 'Medio de pago',
                'value' => $paymentMethod,
            ];
        }

        if ($processedAt !== null) {
            $zumpagoDetails[] = [
                'label' => 'Procesado por Zumpago',
                'value' => $processedAt,
            ];
        }

        $fieldLabels = [
            'IdComercio' => 'ID Comercio',
            'IdTransaccion' => 'ID Transacción',
            'Fecha' => 'Fecha',
            'Hora' => 'Hora',
            'MontoTotal' => 'Monto informado',
            'MedioPagoAutorizado' => 'Medio de pago autorizado',
            'CodigoRespuesta' => 'Código de respuesta',
            'DescripcionRespuesta' => 'Detalle de respuesta',
            'CodigoAutorizacion' => 'Código de autorización',
            'FechaProcesamiento' => 'Fecha de procesamiento',
            'CodigoVerificacion' => 'Código de verificación (encriptado)',
        ];

        foreach ($parsedResponseData as $field => $value) {
            $label = $fieldLabels[$field] ?? preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $field);
            $label = $label !== null ? trim((string) $label) : $field;

            $displayValue = (string) $value;

            if ($field === 'MontoTotal') {
                $numericValue = (int) preg_replace('/\D/', '', $displayValue);
                if ($numericValue > 0) {
                    $displayValue = format_currency($numericValue);
                }
            }

            if ($field === 'Fecha' && preg_match('/^\d{8}$/', $displayValue) === 1) {
                $displayValue = substr($displayValue, 0, 4) . '-' . substr($displayValue, 4, 2) . '-' . substr($displayValue, 6, 2);
            }

            if ($field === 'Hora' && preg_match('/^\d{6}$/', $displayValue) === 1) {
                $displayValue = substr($displayValue, 0, 2) . ':' . substr($displayValue, 2, 2) . ':' . substr($displayValue, 4, 2);
            }

            if ($field === 'FechaProcesamiento' && strlen($displayValue) >= 14) {
                $displayValue = substr($displayValue, 0, 4) . '-' . substr($displayValue, 4, 2) . '-' . substr($displayValue, 6, 2)
                    . ' ' . substr($displayValue, 8, 2) . ':' . substr($displayValue, 10, 2) . ':' . substr($displayValue, 12, 2);
            }

            $zumpagoTableRows[] = [
                'key' => $field,
                'label' => $label,
                'value' => $displayValue,
            ];
        }
        if (is_array($verificationDetails)) {
            if (isset($verificationDetails['decrypted'])) {
                $zumpagoTableRows[] = [
                    'key' => 'CodigoVerificacionDesencriptado',
                    'label' => 'Código de verificación (desencriptado)',
                    'value' => (string) $verificationDetails['decrypted'],
                ];
            }
            if (isset($verificationDetails['expected'])) {
                $zumpagoTableRows[] = [
                    'key' => 'CodigoVerificacionEsperado',
                    'label' => 'Código de verificación esperado',
                    'value' => (string) $verificationDetails['expected'],
                ];
            }
        }
    } catch (\Throwable $exception) {
        $zumpagoProcessingErrors[] = $exception->getMessage();
    }
}

$debugResponseRecord['company_id'] = $activeCompanyId;

$debugResponseRecord['xml'] = [
    'raw' => $encryptedXmlParam !== '' ? $encryptedXmlParam : null,
    'decrypted' => $decryptedXml,
    'parsed' => $parsedResponseData,
    'verification' => $verificationDetails,
    'errors' => $zumpagoProcessingErrors,
];
$debugResponseRecord['summary'] = [
    'code' => $responseCode,
    'description' => $responseDescription,
];

$debugData['response'] = $debugResponseRecord;
$_SESSION['zumpago']['debug'] = $debugData;

$logPath = __DIR__ . '/../app/logs/zumpago.log';
$logPayload = [
    'rut' => $lastTransaction['rut'] ?? null,
    'email' => $lastTransaction['email'] ?? null,
    'amount' => $lastTransaction['amount'] ?? null,
    'company_id' => $activeCompanyId,
    'transaction' => $lastTransaction['transaction'] ?? null,
    'payload' => $debugPayloadRecord,
    'response' => $debugResponseRecord,
];
$logMessage = sprintf(
    "[%s] [Zumpago][response] %s%s",
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

$payloadDisplay = null;
if ($debugPayloadRecord !== null) {
    $payloadDisplay = json_encode($debugPayloadRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadDisplay)) {
        $payloadDisplay = null;
    }
}

$responseDisplay = json_encode($debugResponseRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($responseDisplay)) {
    $responseDisplay = null;
}

$status = 'info';
$title = 'Estamos validando tu pago.';
$description = 'Hemos recibido la respuesta desde Zumpago y estamos procesando la información.';

$rawStatus = (string) ($_REQUEST['status'] ?? '');
$rawMessage = (string) ($_REQUEST['message'] ?? '');

if ($rawStatus !== '') {
    $status = strtolower($rawStatus) === 'success' ? 'success' : 'warning';
    $title = $status === 'success' ? '¡Tu pago fue realizado con éxito!' : 'Resultado del pago pendiente de confirmación';
    if ($rawMessage !== '') {
        $description = $rawMessage;
    }
}

if ($parsedResponseData !== null) {
    if ($responseCode === '000') {
        $status = 'success';
        $title = '¡Tu pago fue realizado con éxito!';
        $description = $responseDescription !== '' ? $responseDescription : 'Zumpago confirmó tu pago correctamente.';
        if ($responseAmount !== null && $responseAmount > 0) {
            $description .= ' Monto informado por Zumpago: ' . format_currency($responseAmount) . '.';
        }

        $rutToClear = is_array($lastTransaction) ? (string) ($lastTransaction['rut'] ?? '') : '';
        if ($rutToClear === '' && isset($parsedResponseData['RutCliente'])) {
            $rutToClear = (string) $parsedResponseData['RutCliente'];
        }

        if ($rutToClear !== '') {
            clear_debt_cache_for_rut($rutToClear);
        }
    } elseif ($responseCode !== null && $responseCode !== '') {
        $firstDigit = substr($responseCode, 0, 1);
        $isPending = in_array($firstDigit, ['1', '2'], true);
        $status = $isPending ? 'warning' : 'danger';
        $title = $isPending
            ? 'Tu pago está pendiente de confirmación.'
            : 'No pudimos confirmar tu pago.';
        if ($responseDescription !== '') {
            $description = $responseDescription;
        } else {
            $description = sprintf('Zumpago informó el código %s para esta transacción.', $responseCode);
        }
    }
}

if ($transactionId !== null && $transactionId !== '') {
    try {
        $storage = new ZumpagoTransactionStorage(__DIR__ . '/../app/storage/zumpago');
        $storage->appendResponse($transactionId, [
            'received_at' => time(),
            'context' => 'response',
            'status' => $status,
            'code' => $responseCode,
            'description' => $responseDescription,
            'amount' => $responseAmount,
            'processed_at' => $processedAt,
            'raw' => [
                'encrypted_xml' => $encryptedXmlParam !== '' ? $encryptedXmlParam : null,
                'decrypted_xml' => $decryptedXml,
                'parsed' => $parsedResponseData,
            ],
            'verification' => $verificationDetails,
            'errors' => $zumpagoProcessingErrors,
            'request' => [
                'get' => $_GET,
                'post' => $_POST,
            ],
        ]);
    } catch (\Throwable $storageException) {
        error_log(
            sprintf(
                "[%s] [Zumpago][storage-error] %s%s",
                date('Y-m-d H:i:s'),
                json_encode([
                    'transaction_id' => $transactionId,
                    'context' => 'response',
                    'error' => $storageException->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PHP_EOL
            ),
            3,
            __DIR__ . '/../app/logs/zumpago.log'
        );
    }
}

$shouldNotifyIngresarPago = $transactionId !== null
    && $transactionId !== ''
    && $responseCode === '000';

if ($shouldNotifyIngresarPago) {
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
                '764430824' => $ingresarPagoWsdl, // Padre Las Casas
                '765316081' => $villarricaWsdl,   // Villarrica (WAM BP)
                '76734662K' => $gorbeaWsdl,       // Gorbea (FULLNET BP)
            ];

            $reporter = new ZumpagoIngresarPagoReporter(
                new ZumpagoTransactionStorage(__DIR__ . '/../app/storage/zumpago'),
                new IngresarPagoService($ingresarPagoWsdl),
                __DIR__ . '/../app/logs/zumpago-ingresar-pago.log',
                __DIR__ . '/../app/logs/zumpago-ingresar-pago-error.log',
                'ZUMPAGO',
                $endpointOverrides
            );

            $reporter->report($transactionId);
        }
    } catch (\Throwable $reporterException) {
        error_log(
            sprintf(
                "[%s] [Zumpago][IngresarPago][error] %s%s",
                date('Y-m-d H:i:s'),
                json_encode([
                    'transaction_id' => $transactionId,
                    'code' => $responseCode,
                    'error' => $reporterException->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PHP_EOL
            ),
            3,
            __DIR__ . '/../app/logs/zumpago-ingresar-pago-error.log'
        );
    }
}

$verificationMessage = null;
$verificationAlertClass = null;

if (is_array($verificationDetails)) {
    if (!empty($verificationDetails['error'])) {
        $verificationMessage = 'No fue posible validar el código de verificación entregado por Zumpago.';
        $verificationAlertClass = 'warning';
    } elseif (($verificationDetails['is_valid'] ?? false) === true) {
        $verificationMessage = 'Código de verificación validado correctamente.';
        $verificationAlertClass = 'success';
    } else {
        $verificationMessage = 'El código de verificación informado por Zumpago no coincide con los datos recibidos.';
        $verificationAlertClass = 'warning';
    }
}

$verificationTextClass = null;
if ($verificationAlertClass === 'success') {
    $verificationTextClass = 'text-success';
} elseif ($verificationAlertClass === 'warning') {
    $verificationTextClass = 'text-warning';
}

unset($_SESSION['zumpago']['last_transaction']);

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <section class="landing-hero">
        <div class="landing-heading">
            <h1>Resultado de tu pago en HomeNet</h1>
        </div>
        <div class="landing-form-card landing-form-card--response">
            <div class="text-center">
                <span class="badge badge-pill <?= $status === 'success' ? 'badge-success' : ($status === 'warning' ? 'badge-warning text-dark' : ($status === 'danger' ? 'badge-danger' : 'badge-info')); ?> px-3 py-2 text-uppercase small">
                    <?= h($status === 'success' ? 'Pago confirmado' : ($status === 'warning' ? 'Pendiente de confirmación' : ($status === 'danger' ? 'Pago rechazado' : 'Información'))); ?>
                </span>
            </div>
            <h4 class="text-center mb-2"><?= h($title); ?></h4>
            <p class="lead text-center mb-4"><?= h($description); ?></p>

            <div class="text-center mt-4">
                <a href="https://web2.homenet.cl/" class="btn btn-primary">Volver a HomeNet</a>
                <a href="../index.php" class="btn btn-outline-secondary ml-2">Realizar otro pago</a>
            </div>
        </div>
    </section>
<?php view('layout/footer'); ?>
$transactionId = null;
