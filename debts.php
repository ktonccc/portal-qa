<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\DebtService;

$errors = [];
$rutInput = trim((string) ($_GET['rut'] ?? ''));
$normalizedRut = normalize_rut($rutInput);

if ($rutInput === '' || $normalizedRut === '') {
    header('Location: index.php');
    exit;
}

$debts = [];
$noDebtsFound = false;
try {
    $service = new DebtService(
        (string) config_value('services.debt_wsdl'),
        (string) config_value('services.debt_wsdl_fallback')
    );
    // Siempre consultamos al WS para mostrar la tabla con datos frescos.
    $debts = $service->fetchDebts($normalizedRut);
    store_debt_snapshot($normalizedRut, $debts);
} catch (Throwable $exception) {
    $errors[] = 'No fue posible obtener las deudas asociadas al RUT consultado.';
}

if (empty($debts) && empty($errors)) {
    $noDebtsFound = true;
    $errors[] = 'No se encontraron deudas asociadas al RUT ingresado.';
}

if (!function_exists('format_debt_period')) {
    function format_debt_period(mixed $month, mixed $year): string
    {
        $monthNames = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];

        $monthClean = trim((string) ($month ?? ''));
        $monthLabel = '';
        if ($monthClean !== '') {
            if (is_numeric($monthClean)) {
                $monthNumber = (int) $monthClean;
                $monthLabel = $monthNames[$monthNumber] ?? $monthClean;
            } else {
                $monthLabel = strtolower($monthClean);
            }
        }

        $yearDigits = preg_replace('/\D/', '', (string) ($year ?? ''));
        $yearDigits = is_string($yearDigits) ? $yearDigits : '';
        $yearLabel = '';
        if ($yearDigits !== '') {
            $yearLabel = substr($yearDigits, -0);
        }

        if ($monthLabel !== '' && $yearLabel !== '') {
            return $monthLabel . '-' . $yearLabel;
        }

        if ($monthLabel !== '') {
            return $monthLabel;
        }

        return $yearLabel;
    }
}

if (!function_exists('normalize_payment_flag_value')) {
    function normalize_payment_flag_value(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        if (is_numeric($stringValue)) {
            return (int) $stringValue !== 0;
        }

        $normalized = strtolower($stringValue);
        $truthy = ['true', 'yes', 'si', 'habilitado', 'on', 'available'];
        $falsy = ['false', 'no', 'off', 'inhabilitado'];

        if (in_array($normalized, $truthy, true)) {
            return true;
        }

        if (in_array($normalized, $falsy, true)) {
            return false;
        }

        return null;
    }
}

if (!function_exists('resolve_payment_availability')) {
    /**
     * @param array<int, array<string, mixed>> $debts
     * @return array<string, bool>
     */
    function resolve_payment_availability(array $debts): array
    {
        $methods = ['webpay', 'bcoestado', 'zumpago', 'flow', 'mercadopago'];
        $resolved = array_fill_keys($methods, null);

        foreach ($debts as $debt) {
            if (!is_array($debt)) {
                continue;
            }

            foreach ($methods as $method) {
                if ($resolved[$method] !== null) {
                    continue;
                }

                $candidates = [$method . '_enabled', $method];
                foreach ($candidates as $candidate) {
                    if (!array_key_exists($candidate, $debt)) {
                        continue;
                    }

                    $flag = normalize_payment_flag_value($debt[$candidate]);
                    if ($flag !== null) {
                        $resolved[$method] = $flag;
                    }
                    break;
                }
            }

            if (!in_array(null, $resolved, true)) {
                break;
            }
        }

        foreach ($resolved as $method => $flag) {
            $resolved[$method] = $flag ?? true;
        }

        return $resolved;
    }
}

$debugPayload = !empty($debts) ? $debts : null;

$customerName = null;
if (!empty($debts)) {
    $firstDebt = reset($debts);
    if (is_array($firstDebt) && isset($firstDebt['nombre'])) {
        $customerName = (string) $firstDebt['nombre'];
    }
}

if ($customerName === null && isset($service) && method_exists($service, 'getLastCustomerName')) {
    $serviceName = $service->getLastCustomerName();
    if ($serviceName !== null) {
        $customerName = $serviceName;
    }
}

$paymentAvailability = resolve_payment_availability($debts);

$pageTitle = 'Seleccione la deuda a pagar';
$bodyClass = 'hnet';

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <section class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <?php if (!$noDebtsFound && $customerName !== null): ?>
                <h2 class="h4 mb-0">Cliente: <?= h($customerName); ?></h2>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
            <div class="ms-auto">
                <a href="index.php" class="btn btn-outline-secondary">Volver</a>
            </div>
        </div>

        <?php if ($noDebtsFound): ?>
            <?php
                $noDebtMessage = $customerName !== null
                    ? sprintf('Cliente %s no mantiene deudas vigentes.', $customerName)
                    : 'El RUT consultado no mantiene deudas vigentes.';
            ?>
            <div class="card shadow-sm border-0 my-4">
                <div class="card-body text-center py-5">
                    <div class="rounded-circle mx-auto mb-3" style="width:64px;height:64px;line-height:64px;background:#e8f5e9;color:#28a745;">
                        <span class="h3 d-inline-block mb-0">&#10003;</span>
                    </div>
                    <p class="text-muted mb-1 text-uppercase small">Estado de cuenta</p>
                    <h3 class="h4 mb-3"><?= h($noDebtMessage); ?></h3>
                    <p class="mb-0 text-secondary">Gracias por mantenerse al día.</p>
                </div>
            </div>
        <?php elseif (!empty($errors)): ?>
            <div class="alert alert-warning" role="alert">
                <p class="mb-2">No se pueden mostrar las deudas.</p>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <?php if (is_array($debugPayload)): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        try {
                            var payload = JSON.parse(atob('<?= base64_encode(json_encode($debugPayload)); ?>'));
                            if (window.console && console.log) {
                                console.log('Flow debug – deudas disponibles', {
                                    registros: payload,
                                    totalRegistros: Array.isArray(payload) ? payload.length : 0,
                                });
                                if (console.table && Array.isArray(payload)) {
                                    console.table(payload);
                                }
                            }
                        } catch (error) {
                            if (window.console && console.error) {
                                console.error('Flow debug – error al imprimir las deudas', error);
                            }
                        }
                    });
                </script>
            <?php endif; ?>
            <!-- Tabla que permite seleccionar una o varias deudas -->
            <form class="debt-selection-form js-debt-form" method="POST" action="pay.php" novalidate>
                <input type="hidden" name="rut" value="<?= h($normalizedRut); ?>">
                <p class="debt-summary-heading js-debt-summary">Seleccione una o más deudas</p>
                <div class="table-responsive debt-table-wrapper">
                    <table class="table table-striped table-hover align-middle debt-table">
                        <thead class="thead-light">
                        <tr>
                            <th scope="col" class="text-center">Pagar</th>
                            <th scope="col">Contrato</th>
                            <th scope="col">Dirección</th>
                            <th scope="col">Servicio</th>
                            <th scope="col">Mensualidad</th>
                            <th scope="col" class="text-right">Monto</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($debts as $debt): ?>
                            <tr>
                                <td class="text-center align-middle debt-table__cell" data-label="Seleccionar">
                                    <input
                                        type="checkbox"
                                        class="form-check-input js-debt-checkbox"
                                        name="idcliente[]"
                                        id="debt-<?= h($debt['idcliente']); ?>"
                                        value="<?= h($debt['idcliente']); ?>"
                                        data-amount="<?= (int) ($debt['amount'] ?? 0); ?>"
                                    >
                                </td>
                                <td class="align-middle debt-table__cell" data-label="Contrato">
                                    <label class="mb-0 font-weight-bold" for="debt-<?= h($debt['idcliente']); ?>">
                                        <?= h($debt['idcliente']); ?>
                                    </label>
                                </td>
                                <td class="align-middle debt-table__cell" data-label="Dirección"><?= h($debt['direccion']); ?></td>
                                <td class="align-middle debt-table__cell" data-label="Servicio">
                                    <?= h(!empty($debt['servicio']) ? $debt['servicio'] : ($debt['mes'] ?? '')); ?>
                                </td>
                                <td class="align-middle debt-table__cell" data-label="Mensualidad">
                                    <?= h(format_debt_period($debt['mes'] ?? '', $debt['ano'] ?? '')); ?>
                                </td>
                                <td class="text-right align-middle debt-table__cell" data-label="Monto">
                                    <?php
                                    $displayAmount = trim((string) ($debt['amount_display'] ?? ''));
                                    echo $displayAmount !== '' ? h($displayAmount) : h(format_currency((int) ($debt['amount'] ?? 0)));
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row gy-3 mb-3">
                    <div class="col-md-6 mx-auto">
                        <div class="form-group text-center">
                            <label for="debt-email" class="form-label">Ingrese un email para enviar su comprobante</label>
                            <input
                                type="email"
                                class="form-control js-debt-email"
                                id="debt-email"
                                name="email"
                                placeholder="correo@ejemplo.com"
                                required
                                autocomplete="email"
                            >
                        </div>
                    </div>
                </div>

                <?php
                    $webpayAvailable = $paymentAvailability['webpay'] ?? true;
                    $bcoestadoAvailable = $paymentAvailability['bcoestado'] ?? true;
                    $zumpagoAvailable = $paymentAvailability['zumpago'] ?? true;
                    $flowAvailable = $paymentAvailability['flow'] ?? true;
                    $mercadoPagoAvailable = $paymentAvailability['mercadopago'] ?? true;
                ?>
                <div class="payment-platforms" role="region" aria-label="Medios de pago disponibles">
                    <div class="payment-platforms-inner">
                        <ul class="payment-platforms-list">
                            <li
                                class="payment-platforms-item<?= $webpayAvailable ? '' : ' payment-platforms-item--unavailable'; ?>"
                                data-payment-method="webpay"
                                data-payment-available="<?= $webpayAvailable ? 'true' : 'false'; ?>"
                            >
                                <button
                                    type="submit"
                                    class="payment-platforms-button js-debt-submit"
                                    disabled
                                    aria-label="Pagar con Webpay"
                                    data-payment-method="webpay"
                                    data-payment-available="<?= $webpayAvailable ? 'true' : 'false'; ?>"
                                >
                                    <img src="img/Logo-web-pay-plus.png" alt="Webpay Plus" class="payment-platforms-logo">
                                </button>
                            </li>
                            <li
                                class="payment-platforms-item<?= $bcoestadoAvailable ? '' : ' payment-platforms-item--unavailable'; ?>"
                                data-payment-method="bcoestado"
                                data-payment-available="<?= $bcoestadoAvailable ? 'true' : 'false'; ?>"
                            >
                                <img
                                    src="img/Logo_BancoEstado.png"
                                    alt="BancoEstado"
                                    class="payment-platforms-logo"
                                    data-fixed-grayscale="true"
                                >
                            </li>
                            <li
                                class="payment-platforms-item<?= $zumpagoAvailable ? '' : ' payment-platforms-item--unavailable'; ?>"
                                data-payment-method="zumpago"
                                data-payment-available="<?= $zumpagoAvailable ? 'true' : 'false'; ?>"
                            >
                                <button
                                    type="submit"
                                    class="payment-platforms-button js-debt-submit"
                                    disabled
                                    aria-label="Pagar con Zumpago"
                                    formaction="pay_zumpago.php"
                                    data-payment-method="zumpago"
                                    data-payment-available="<?= $zumpagoAvailable ? 'true' : 'false'; ?>"
                                >
                                    <img src="img/Logo-zumpago.png" alt="Zumpago" class="payment-platforms-logo">
                                </button>
                            </li>
                            <li
                                class="payment-platforms-item<?= $flowAvailable ? '' : ' payment-platforms-item--unavailable'; ?>"
                                data-payment-method="flow"
                                data-payment-available="<?= $flowAvailable ? 'true' : 'false'; ?>"
                            >
                                <button
                                    type="submit"
                                    class="payment-platforms-button js-debt-submit"
                                    disabled
                                    aria-label="Pagar con Flow"
                                    formaction="pay_flow.php"
                                    data-payment-method="flow"
                                    data-payment-available="<?= $flowAvailable ? 'true' : 'false'; ?>"
                                >
                                    <img src="img/Logo-flow.svg" alt="Flow" class="payment-platforms-logo">
                                </button>
                            </li>
                            <li
                                class="payment-platforms-item<?= $mercadoPagoAvailable ? '' : ' payment-platforms-item--unavailable'; ?>"
                                data-payment-method="mercadopago"
                                data-payment-available="<?= $mercadoPagoAvailable ? 'true' : 'false'; ?>"
                            >
                                <button
                                    type="submit"
                                    class="payment-platforms-button js-debt-submit"
                                    disabled
                                    aria-label="Pagar con Mercado Pago"
                                    formaction="pay_mercadopago.php"
                                    data-payment-method="mercadopago"
                                    data-payment-available="<?= $mercadoPagoAvailable ? 'true' : 'false'; ?>"
                                >
                                    <img
                                        src="img/Logo-mercado-pago.png"
                                        alt="Mercado Pago"
                                        class="payment-platforms-logo"
                                    >
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </section>

<?php view('layout/footer'); ?>
