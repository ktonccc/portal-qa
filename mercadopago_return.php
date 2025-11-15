<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\MercadoPagoPaymentService;
use App\Services\MercadoPagoTransactionStorage;

$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$paymentId = trim((string) ($_GET['payment_id'] ?? ($_GET['collection_id'] ?? '')));
$externalReference = trim((string) ($_GET['external_reference'] ?? ''));
$preferenceId = trim((string) ($_GET['preference_id'] ?? ''));

$errors = [];
$paymentDetails = null;
$transaction = null;

if ($externalReference !== '') {
    $storage = new MercadoPagoTransactionStorage(__DIR__ . '/app/storage/mercadopago');
    $transaction = $storage->get($externalReference);
}

$mpConfig = (array) config_value('mercadopago', []);

if ($paymentId !== '') {
    try {
        $paymentService = new MercadoPagoPaymentService($mpConfig);
        $paymentDetails = $paymentService->getPayment($paymentId);
    } catch (Throwable $exception) {
        $errors[] = 'No fue posible obtener los detalles del pago en Mercado Pago.';
    }
}

$pageTitle = 'Resultado Mercado Pago';
$bodyClass = 'hnet';

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
<section class="container my-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">Resultado de tu pago con Mercado Pago</h1>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-warning">
                    <p class="mb-2">No pudimos confirmar el estado del pago automáticamente.</p>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($status === 'approved'): ?>
                <div class="alert alert-success">
                    ¡Gracias! El pago fue aprobado por Mercado Pago.
                </div>
            <?php elseif ($status === 'pending'): ?>
                <div class="alert alert-info">
                    El pago quedó en estado pendiente. Te avisaremos cuando Mercado Pago lo confirme.
                </div>
            <?php elseif ($status === 'failure'): ?>
                <div class="alert alert-danger">
                    Mercado Pago rechazó o canceló el pago. Intenta nuevamente o elige otro medio.
                </div>
            <?php else: ?>
                <div class="alert alert-secondary">
                    Volvimos desde Mercado Pago, pero aún no tenemos un estado definitivo.
                </div>
            <?php endif; ?>

            <dl class="row small">
                <?php if ($paymentId !== ''): ?>
                    <dt class="col-sm-4">ID de pago</dt>
                    <dd class="col-sm-8"><?= h($paymentId); ?></dd>
                <?php endif; ?>
                <?php if (is_array($paymentDetails)): ?>
                    <dt class="col-sm-4">Monto</dt>
                    <dd class="col-sm-8">
                        <?= h(format_currency((int) round((float) ($paymentDetails['transaction_amount'] ?? 0)))); ?>
                    </dd>
                    <?php if (!empty($paymentDetails['payer']['email'])): ?>
                        <dt class="col-sm-4">Mail</dt>
                        <dd class="col-sm-8"><?= h((string) $paymentDetails['payer']['email']); ?></dd>
                    <?php endif; ?>
                <?php endif; ?>
            </dl>

            <p class="text-muted small mb-3">
                Si el pago fue aprobado, lo verás reflejado en el portal en unos instantes. Recibirás un correo de confirmación cuando el proceso finalice.
            </p>

            <a href="index.php" class="btn btn-primary">Volver al inicio</a>
            <?php if ($transaction !== null && isset($transaction['rut'])): ?>
                <a href="debts.php?rut=<?= urlencode((string) $transaction['rut']); ?>" class="btn btn-outline-secondary ml-2">
                    Revisar mis deudas
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php view('layout/footer'); ?>
