<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class ZumpagoTransactionStorage
{
    public function __construct(
        private readonly string $directory
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $transactionId, array $data): void
    {
        $data['transaction_id'] = $transactionId;

        if (!isset($data['ingresar_pago']) || !is_array($data['ingresar_pago'])) {
            $data['ingresar_pago'] = [
                'processed' => false,
                'attempts' => [],
            ];
        }

        if (!isset($data['zumpago']) || !is_array($data['zumpago'])) {
            $data['zumpago'] = [];
        }

        if (!isset($data['zumpago']['responses']) || !is_array($data['zumpago']['responses'])) {
            $data['zumpago']['responses'] = [];
        }

        $this->writeFile($transactionId, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $transactionId): ?array
    {
        $path = $this->pathForTransaction($transactionId);

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
    public function merge(string $transactionId, array $attributes): ?array
    {
        $existing = $this->get($transactionId);
        if ($existing === null) {
            return null;
        }

        $merged = $this->recursiveMerge($existing, $attributes);
        $this->writeFile($transactionId, $merged);

        return $merged;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function appendResponse(string $transactionId, array $response): array
    {
        $record = $this->get($transactionId);

        if ($record === null) {
            $record = [
                'transaction_id' => $transactionId,
                'created_at' => time(),
                'ingresar_pago' => [
                    'processed' => false,
                    'attempts' => [],
                ],
            ];
        }

        if (!isset($record['zumpago']) || !is_array($record['zumpago'])) {
            $record['zumpago'] = [];
        }

        if (!isset($record['zumpago']['responses']) || !is_array($record['zumpago']['responses'])) {
            $record['zumpago']['responses'] = [];
        }

        $record['zumpago']['responses'][] = $response;

        $this->writeFile($transactionId, $record);

        return $record;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function markProcessed(string $transactionId, array $meta): void
    {
        $result = $this->merge($transactionId, [
            'ingresar_pago' => $this->recursiveMerge(
                [
                    'processed' => true,
                    'processed_at' => time(),
                ],
                $meta
            ),
        ]);

        if ($result === null) {
            throw new RuntimeException("No se encontró la transacción Zumpago asociada al ID {$transactionId} para marcarla como procesada.");
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
    private function writeFile(string $transactionId, array $data): void
    {
        $this->ensureDirectory();

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($encoded === false) {
            throw new RuntimeException('No fue posible codificar la transacción Zumpago en JSON.');
        }

        $path = $this->pathForTransaction($transactionId);

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException("No fue posible escribir la transacción Zumpago en {$path}.");
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException("No fue posible crear el directorio {$this->directory} para almacenar transacciones Zumpago.");
        }
    }

    private function pathForTransaction(string $transactionId): string
    {
        $hash = hash('sha256', $transactionId);

        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.json';
    }
}
