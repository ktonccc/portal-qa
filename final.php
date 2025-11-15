<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Estado del Pago';
$bodyClass = 'hnet';

$tbkToken = trim((string) ($_POST['TBK_TOKEN'] ?? ''));
$tokenWs = trim((string) ($_POST['token_ws'] ?? ''));

$status = 'error';
$title = 'No pudimos confirmar tu pago';
$description = 'Ocurrió un problema al finalizar el proceso. Si el inconveniente persiste, contáctanos.';
$extra = '';

if ($tbkToken !== '') {
    $title = 'Pago cancelado';
    $description = 'No se realizó ningún cargo a tu tarjeta.';
    $extra = 'Puedes intentar nuevamente desde el portal de pagos.';
} elseif ($tokenWs !== '') {
    $status = 'success';
    $title = '¡Tu pago fue realizado con éxito!';
    $description = 'El pago puede tardar hasta 72 horas en visualizarse.';
    $extra = 'Si tu servicio está suspendido será restablecido a la brevedad.';
}

$lastTransaction = $_SESSION['webpay']['last_transaction'] ?? null;
unset($_SESSION['webpay']['last_transaction']);

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <div class="card shadow-lg pagos-cont mx-auto">
        <div class="card-header <?= $status === 'success' ? 'bg-success' : 'bg-danger'; ?> text-white text-center">
            <h4 class="mb-0"><?= h($title); ?></h4>
        </div>
        <div class="card-body">
            <p class="lead text-center"><?= h($description); ?></p>
            <?php if ($extra !== ''): ?>
                <p class="text-center text-muted"><?= h($extra); ?></p>
            <?php endif; ?>

            <?php if ($status === 'success' && is_array($lastTransaction)): ?>
                <hr>
                <h5 class="text-center mb-3">Resumen del pago</h5>
                <div class="row text-center">
                    <div class="col-md-4 mb-3">
                        <span class="d-block text-muted small">ID Cliente</span>
                        <strong><?= h($lastTransaction['idcliente'] ?? ''); ?></strong>
                    </div>
                    <div class="col-md-4 mb-3">
                        <span class="d-block text-muted small">RUT</span>
                        <strong><?= h(format_rut((string) ($lastTransaction['rut'] ?? ''))); ?></strong>
                    </div>
                    <div class="col-md-4 mb-3">
                        <span class="d-block text-muted small">Monto</span>
                        <strong><?= h(format_currency((int) ($lastTransaction['amount'] ?? 0))); ?></strong>
                    </div>
                </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="https://www.homenet.cl" class="btn btn-primary">Volver a HomeNet</a>
                <a href="index.php" class="btn btn-outline-secondary ml-2">Realizar otro pago</a>
            </div>
        </div>
    </div>
<?php view('layout/footer'); ?>
