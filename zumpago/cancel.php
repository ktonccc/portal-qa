<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Pago Zumpago cancelado';
$bodyClass = 'hnet';

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <div class="card shadow-lg pagos-cont mx-auto">
        <div class="card-header bg-warning text-dark text-center">
            <h4 class="mb-0">Pago cancelado</h4>
        </div>
        <div class="card-body">
            <p class="lead text-center">
                No se realizó ningún cargo. Si necesitas continuar con tu pago, vuelve al portal e inténtalo nuevamente.
            </p>
            <div class="text-center mt-4">
                <a href="../index.php" class="btn btn-primary">Volver al portal de pagos</a>
            </div>
        </div>
    </div>
<?php view('layout/footer'); ?>
