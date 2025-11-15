<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Debug Flow';
$bodyClass = 'hnet';

$logFiles = [
    'flow.log' => __DIR__ . '/app/logs/flow.log',
    'flow-error.log' => __DIR__ . '/app/logs/flow-error.log',
    'flow-callback.log' => __DIR__ . '/app/logs/flow-callback.log',
    'flow-callback-error.log' => __DIR__ . '/app/logs/flow-callback-error.log',
    'flow-return.log' => __DIR__ . '/app/logs/flow-return.log',
    'flow-return-error.log' => __DIR__ . '/app/logs/flow-return-error.log',
];

/**
 * @return string[]
 */
function tailLog(string $path, int $lines = 200): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $file = new SplFileObject($path, 'r');
    $file->seek(PHP_INT_MAX);
    $lastLine = $file->key();

    $start = max(0, $lastLine - $lines);
    $output = [];

    for ($i = $start; $i <= $lastLine; $i++) {
        $file->seek($i);
        $output[] = rtrim((string) $file->current(), "\r\n");
    }

    return $output;
}

$sessionLast = $_SESSION['flow']['last_transaction'] ?? null;
$sessionStatus = $_SESSION['flow']['status_history'] ?? [];

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <section class="container my-4">
        <div class="alert alert-warning">
            <strong>Uso interno:</strong> Esta página expone información sensible. No la dejes disponible en producción sin autenticación.
        </div>

        <h2 class="h4 mb-3">Última transacción en sesión</h2>
        <?php if (is_array($sessionLast)): ?>
            <pre class="bg-light p-3 border rounded small"><?= h(json_encode($sessionLast, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php else: ?>
            <p class="text-muted">No hay transacciones en la sesión actual.</p>
        <?php endif; ?>

        <?php if (!empty($sessionStatus)): ?>
            <h3 class="h5 mt-4">Historial de estados en sesión</h3>
            <pre class="bg-light p-3 border rounded small"><?= h(json_encode($sessionStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php endif; ?>

        <h2 class="h4 mt-5 mb-3">Logs de Flow (últimas líneas)</h2>
        <?php foreach ($logFiles as $label => $path): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?= h($label); ?></span>
                    <small class="text-muted"><?= h($path); ?></small>
                </div>
                <div class="card-body">
                    <?php
                    $lines = tailLog($path, 200);
                    ?>
                    <?php if (empty($lines)): ?>
                        <p class="text-muted mb-0">No hay datos o no se puede leer el archivo.</p>
                    <?php else: ?>
                        <pre class="bg-light p-3 border rounded small mb-0"><?= h(implode(PHP_EOL, $lines)); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php view('layout/footer'); ?>
