<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Portal de Pagos Test HomeNet';
$bodyClass = 'hnet';

$errors = [];
$rutInput = '';
$normalizedRut = '';
// Handle the RUT submission before rendering the landing page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rutInput = trim((string) ($_POST['rut'] ?? ''));

    if ($rutInput === '') {
        $errors[] = 'Debe ingresar su RUT.';
    }

    $normalizedRut = normalize_rut($rutInput);

    if ($normalizedRut === '') {
        $errors[] = 'El RUT ingresado no es válido.';
    }

    if ($normalizedRut !== '' && !is_valid_rut($normalizedRut)) {
        $errors[] = 'El RUT ingresado no es válido.';
    }

    if (empty($errors)) {
        $logPath = __DIR__ . '/app/logs/app.log';
        error_log(sprintf('[%s] Consulta de deudas para RUT (redirect): %s%s', date('Y-m-d H:i:s'), $normalizedRut, PHP_EOL), 3, $logPath);
        header('Location: debts.php?rut=' . urlencode($normalizedRut));
        exit;
    }
}

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <section class="landing-hero">
        <div class="landing-heading">
            <h1>Portal de Pagos</h1>
            <p class="landing-subtitle">Ingrese un R.U.T.</p>
        </div>

        <div class="landing-form-card">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-3" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form class="rut-form js-rut-form" method="POST" action="" novalidate>
                <div class="rut-form-top">
                    <div class="form-group">
                        <input
                            type="text"
                            id="rut"
                            name="rut"
                            value="<?= h($rutInput); ?>"
                            class="form-control js-rut rut-field"
                            maxlength="9"
                            placeholder="Ej: 12345678K"
                            required
                            autocomplete="off"
                            autocapitalize="characters"
                            inputmode="text"
                            aria-describedby="rutFeedback"
                            aria-invalid="false"
                        >
                        <div id="rutFeedback" class="invalid-feedback rut-feedback js-rut-feedback"></div>
                    </div>

                </div>

                <div class="rut-form-divider" aria-hidden="true"></div>

                <div class="rut-form-bottom">
                    <button type="submit" class="btn btn-primary rut-submit">Consultar</button>
                </div>
            </form>
        </div>

    </section>

<?php view('layout/footer'); ?>
