<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$phpErrorLog = $logDir . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $phpErrorLog);

register_shutdown_function(
    static function () use ($phpErrorLog): void {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        $message = sprintf(
            '[fatal] %s in %s:%d',
            $error['message'],
            $error['file'],
            $error['line']
        );

        error_log($message);
    }
);

$baseDir = dirname(__DIR__);

require_once $baseDir . '/vendor/autoload.php';
if (file_exists($baseDir . '/lib/nusoap.php')) {
    require_once $baseDir . '/lib/nusoap.php';
}
require_once __DIR__ . '/Support/helpers.php';

// Aseguramos cookies de sesión compatibles con iframes externos (SameSite=None + Secure).
if (!headers_sent()) {
    $cookieParams = session_get_cookie_params();
    $cookieParams['secure'] = true;
    $cookieParams['httponly'] = true;

    if (PHP_VERSION_ID >= 70300) {
        $cookieParams['samesite'] = 'None';
        session_set_cookie_params($cookieParams);
        ini_set('session.cookie_samesite', 'None');
    } else {
        ini_set('session.cookie_samesite', 'None');
    }

    ini_set('session.cookie_secure', '1');
}

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Throwable $sessionException) {
    error_log(sprintf('[bootstrap][session] %s', $sessionException->getMessage()));
}

spl_autoload_register(
    static function (string $class) use ($baseDir): void {
        $prefix = 'App\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = $baseDir . '/app/' . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($path)) {
            require_once $path;
        }
    }
);

/** @var array<string, mixed> $config */
$config = require __DIR__ . '/Config/app.php';
