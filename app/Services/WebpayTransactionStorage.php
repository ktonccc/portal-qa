<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class WebpayTransactionStorage
{
    public function __construct(
        private readonly string $directory
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $token, array $data): void
    {
        $data['token'] = $token;

        if (!isset($data['ingresar_pago']) || !is_array($data['ingresar_pago'])) {
            $data['ingresar_pago'] = [
                'processed' => false,
                'attempts' => [],
            ];
        }

        if (!isset($data['webpay']) || !is_array($data['webpay'])) {
            $data['webpay'] = [];
        }

        if (!isset($data['webpay']['responses']) || !is_array($data['webpay']['responses'])) {
            $data['webpay']['responses'] = [];
        }

        $this->writeFile($token, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $token): ?array
    {
        $path = $this->pathForToken($token);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function merge(string $token, array $attributes): ?array
    {
        $existing = $this->get($token);
        if ($existing === null) {
            return null;
        }

        $merged = $this->recursiveMerge($existing, $attributes);
        $this->writeFile($token, $merged);

        return $merged;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function appendResponse(string $token, array $response): array
    {
        $record = $this->get($token);

        if ($record === null) {
            $record = [
                'token' => $token,
                'created_at' => time(),
                'ingresar_pago' => [
                    'processed' => false,
                    'attempts' => [],
                ],
            ];
        }

        if (!isset($record['webpay']) || !is_array($record['webpay'])) {
            $record['webpay'] = [];
        }

        if (!isset($record['webpay']['responses']) || !is_array($record['webpay']['responses'])) {
            $record['webpay']['responses'] = [];
        }

        $record['webpay']['responses'][] = $response;

        $this->writeFile($token, $record);

        return $record;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function markProcessed(string $token, array $meta): void
    {
        $result = $this->merge($token, [
            'ingresar_pago' => $this->recursiveMerge(
                [
                    'processed' => true,
                    'processed_at' => time(),
                ],
                $meta
            ),
        ]);

        if ($result === null) {
            throw new RuntimeException("No se encontró la transacción Webpay asociada al token {$token} para marcarla como procesada.");
        }
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function recursiveMerge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_array($value) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = $this->recursiveMerge($left[$key], $value);
                continue;
            }

            $left[$key] = $value;
        }

        return $left;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(string $token, array $data): void
    {
        $this->ensureDirectory();

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($encoded === false) {
            throw new RuntimeException('No fue posible codificar la transacción Webpay en JSON.');
        }

        $path = $this->pathForToken($token);

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException("No fue posible escribir la transacción Webpay en {$path}.");
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException("No fue posible crear el directorio {$this->directory} para almacenar transacciones Webpay.");
        }
    }

    private function pathForToken(string $token): string
    {
        $hash = hash('sha256', $token);

        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.json';
    }
}
