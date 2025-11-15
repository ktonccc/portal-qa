<?php

declare(strict_types=1);

if (!function_exists('config_value')) {
    /**
     * Retrieve a configuration value using dot notation.
     *
     * @param mixed $default
     */
    function config_value(string $key, mixed $default = null): mixed
    {
        /** @var array<string, mixed>|null $config */
        static $cache = null;

        if ($cache === null) {
            global $config;
            $cache = is_array($config) ? $config : [];
        }

        $segments = explode('.', $key);
        $value = $cache;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('normalize_rut')) {
    /**
     * Normalizes a RUT by removing formatting characters.
     */
    function normalize_rut(string $rut): string
    {
        $normalized = preg_replace('/[^0-9kK]/', '', $rut);

        return strtoupper($normalized ?? '');
    }
}

if (!function_exists('format_rut')) {
    /**
     * Applies a simple format to a RUT (##.###.###-#).
     */
    function format_rut(string $rut): string
    {
        $rut = normalize_rut($rut);

        if (strlen($rut) < 2) {
            return $rut;
        }

        $body = substr($rut, 0, -1);
        $dv = substr($rut, -1);

        $formattedBody = '';
        $counter = 0;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $formattedBody = $body[$i] . $formattedBody;
            $counter++;
            if ($counter === 3 && $i !== 0) {
                $formattedBody = '.' . $formattedBody;
                $counter = 0;
            }
        }

        return sprintf('%s-%s', $formattedBody, $dv);
    }
}

if (!function_exists('is_valid_rut')) {
    /**
     * Validates the RUT check digit.
     */
    function is_valid_rut(string $rut): bool
    {
        $normalized = normalize_rut($rut);

        if (strlen($normalized) < 2) {
            return false;
        }

        $body = substr($normalized, 0, -1);
        $dv = substr($normalized, -1);

        if (!ctype_digit($body)) {
            return false;
        }

        $sum = 0;
        $multiplier = 2;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        $remainder = 11 - ($sum % 11);

        if ($remainder === 11) {
            $computedDv = '0';
        } elseif ($remainder === 10) {
            $computedDv = 'K';
        } else {
            $computedDv = (string) $remainder;
        }

        return strtoupper($dv) === $computedDv;
    }
}

if (!function_exists('format_currency')) {
    /**
     * Formats an integer amount as Chilean peso.
     */
    function format_currency(int $amount): string
    {
        return '$' . number_format($amount, 0, ',', '.');
    }
}

if (!function_exists('h')) {
    /**
     * HTML-escapes a value.
     */
    function h(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('view')) {
    /**
     * Renders a PHP view located under app/Views.
     */
    function view(string $template, array $data = []): void
    {
        $path = __DIR__ . '/../Views/' . $template . '.php';

        if (!file_exists($path)) {
            throw new RuntimeException("Template {$template} not found at {$path}");
        }

        extract($data, EXTR_SKIP);
        require $path;
    }
}

if (!function_exists('asset')) {
    /**
     * Generates a relative URL for static assets.
     */
    function asset(string $path): string
    {
        $prefix = trim((string) config_value('app.asset_prefix', ''), '/');
        $base = $prefix === '' ? '' : '/' . $prefix;

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('clear_debt_cache_for_rut')) {
    function clear_debt_cache_for_rut(?string $rut): void
    {
        $rut = trim((string) ($rut ?? ''));

        if ($rut === '') {
            return;
        }

        $normalized = normalize_rut($rut);
        $rutKey = $normalized !== '' ? $normalized : $rut;

        try {
            $service = new \App\Services\DebtService(
                (string) config_value('services.debt_wsdl'),
                (string) config_value('services.debt_wsdl_fallback')
            );
            $service->clearCacheForRut($rutKey);
        } catch (\Throwable) {
            // La limpieza de cache no debe interrumpir el flujo principal.
        }

        if (!isset($_SESSION)) {
            return;
        }

        if (isset($_SESSION['debt_snapshots'][$rutKey])) {
            unset($_SESSION['debt_snapshots'][$rutKey]);
        }
    }
}

if (!function_exists('store_debt_snapshot')) {
    /**
     * Persist debts in session for quick reuse between steps.
     *
     * @param array<int, array<string, mixed>> $debts
     */
    function store_debt_snapshot(string $rut, array $debts, int $ttlSeconds = 120): void
    {
        if (!isset($_SESSION)) {
            return;
        }

        $normalized = normalize_rut($rut);
        $key = $normalized !== '' ? $normalized : trim($rut);

        if ($key === '') {
            return;
        }

        $_SESSION['debt_snapshots'][$key] = [
            'debts' => $debts,
            'stored_at' => time(),
            'expires_at' => time() + max(30, $ttlSeconds),
        ];
    }
}

if (!function_exists('get_debt_snapshot')) {
    /**
     * @return array<int, array<string, mixed>>|null
     */
    function get_debt_snapshot(string $rut): ?array
    {
        if (!isset($_SESSION)) {
            return null;
        }

        $normalized = normalize_rut($rut);
        $key = $normalized !== '' ? $normalized : trim($rut);

        if ($key === '' || !isset($_SESSION['debt_snapshots'][$key])) {
            return null;
        }

        $snapshot = $_SESSION['debt_snapshots'][$key];
        $expiresAt = (int) ($snapshot['expires_at'] ?? 0);

        if ($expiresAt < time()) {
            unset($_SESSION['debt_snapshots'][$key]);
            return null;
        }

        $debts = $snapshot['debts'] ?? null;

        if (!is_array($debts)) {
            unset($_SESSION['debt_snapshots'][$key]);
            return null;
        }

        return $debts;
    }
}
