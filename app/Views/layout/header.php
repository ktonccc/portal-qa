<?php
/** @var string $pageTitle */
/** @var string|null $bodyClass */

$siteName = config_value('app.name', 'Portal Pagos');
$pageTitle = isset($pageTitle) && trim($pageTitle) !== '' ? $pageTitle : $siteName;
$bodyClass = isset($bodyClass) ? trim($bodyClass) : 'hnet';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Portal de pagos HomeNet">
    <meta name="author" content="HomeNet Ltda.">
    <title><?= h($pageTitle); ?></title>
    <link rel="icon" type="image/png" href="<?= asset('img/logohn.png'); ?>">
    <link rel="apple-touch-icon" href="<?= asset('img/logohn.png'); ?>">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css"
          integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N"
          crossorigin="anonymous">
    <link rel="stylesheet" href="<?= asset('assets/css/homenet.css'); ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css'); ?>">
</head>
<body class="<?= h($bodyClass); ?>">
<header class="site-header">
    <div class="site-header-inner">
        <a class="site-logo-link" href="https://web2.homenet.cl/">
            <img src="<?= asset('img/logo2.png'); ?>" alt="HomeNet" class="img-fluid site-logo">
        </a>
        <nav class="site-nav">
        </nav>
    </div>
</header>
<main role="main" class="main-content">
