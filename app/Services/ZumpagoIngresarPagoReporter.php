<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

class ZumpagoIngresarPagoReporter
{
    private const DEFAULT_COLLECTOR = 'ZUMPAGO';

    /** @var array<string, IngresarPagoService> */
    private array $serviceCache = [];

    public function __construct(
        private readonly ZumpagoTransactionStorage $storage,
        private readonly IngresarPagoService $service,
        private readonly string $logPath,
        private readonly string $errorLogPath,
        private readonly string $collector = self::DEFAULT_COLLECTOR,
        /** @var array<string, ?string> $endpointMap */
        private readonly array $endpointMap = []
    ) {
    }

    public function report(string $transactionId): void
    {
        $record = $this->storage->get($transactionId);
        if (!is_array($record)) {
            $this->logError($transactionId, 'No se encontró la transacción almacenada para notificar IngresarPago.', null, null, null);
            return;
        }

        $meta = $record['ingresar_pago'] ?? [];
        if (is_array($meta) && !empty($meta['processed'])) {
            return;
        }

        $responses = $record['zumpago']['responses'] ?? [];
        if (!is_array($responses) || empty($responses)) {
            $this->logError($transactionId, 'No se encontraron respuestas de Zumpago para notificar IngresarPago.', $record, null, null);
            return;
        }

        $latest = $responses[array_key_last($responses)];
        if (!is_array($latest)) {
            $this->logError($transactionId, 'La respuesta más reciente de Zumpago no tiene el formato esperado.', $record, null, null);
            return;
        }

        $code = (string) ($latest['code'] ?? '');
        if ($code !== '000') {
            $this->logError($transactionId, 'Se omitió la notificación a IngresarPago porque Zumpago informó el código ' . $code . '.', $record, $latest, null);
            return;
        }

        $payloads = $this->buildPayloads($record, $latest);
        if (empty($payloads)) {
            $this->logError($transactionId, 'No se generaron cargas útiles válidas para IngresarPago.', $record, $latest, null);
            return;
        }

        $results = [];

        foreach ($payloads as $payload) {
            $targetService = $this->resolveServiceForPayload($payload);

            try {
                $result = $targetService->submit($payload);
                $result['wsdl'] = $targetService->getWsdlEndpoint();
                $results[] = $result;
            } catch (Throwable $exception) {
                $payload['__target_wsdl'] = $targetService->getWsdlEndpoint();
                try {
                    $payload['__envelope'] = $targetService->previewEnvelope($payload);
                } catch (Throwable) {
                    // Ignorar errores al obtener el envelope para log.
                }

                $this->logError($transactionId, $exception->getMessage(), $record, $latest, $payload);
                throw $exception;
            }
        }

        try {
            $this->storage->markProcessed($transactionId, [
                'responses' => $results,
            ]);
        } catch (RuntimeException $exception) {
            $this->logError($transactionId, 'No fue posible actualizar el estado local después de notificar IngresarPago: ' . $exception->getMessage(), $record, $latest, null);
            throw $exception;
        }

        $this->logSuccess($transactionId, $payloads, $results, $record, $latest);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function buildPayloads(array $record, array $response): array
    {
        $rut = (string) ($record['rut'] ?? '');
        $mail = (string) ($record['email'] ?? '');
        $debts = $record['debts'] ?? [];

        if (!is_array($debts)) {
            $debts = [];
        }

        $parsed = $response['raw']['parsed'] ?? [];
        if (!is_array($parsed)) {
            $parsed = [];
        }

        $medioPago = trim((string) ($parsed['MedioPagoAutorizado'] ?? ''));
        $channel = $this->resolveChannel($medioPago);
        $fechaPago = $this->formatDate($parsed['Fecha'] ?? null);
        $fechaContable = $this->formatDate($this->extractDateFromTimestamp($parsed['FechaProcesamiento'] ?? null));
        $responseAmount = $this->normalizeAmount($parsed['MontoTotal'] ?? $record['amount'] ?? null);

        $payloads = [];

        foreach ($debts as $debt) {
            if (!is_array($debt)) {
                continue;
            }

            $idEmpresa = (string) ($debt['idempresa'] ?? '');
            $idCliente = $this->normalizeInt($debt['idcliente'] ?? null);
            $mes = $this->normalizeInt($debt['mes'] ?? null);
            $ano = $this->normalizeInt($debt['ano'] ?? null);
            $monto = $this->normalizeAmount($debt['amount'] ?? $responseAmount ?? null);
            $montoFlow = $monto ?? $responseAmount ?? null;

            if ($idEmpresa === '' || $idCliente === null || $idCliente <= 0 || $monto === null || $monto <= 0) {
                continue;
            }

            $payloads[] = [
                'IdEmpresa' => $idEmpresa,
                'IdCliente' => $idCliente,
                'RutCliente' => $rut,
                'Mail' => $mail,
                'Recaudador' => $this->collector,
                'Canal' => $channel,
                'FechaPago' => $fechaPago,
                'FechaContable' => $fechaContable,
                'Mes' => $mes,
                'Ano' => $ano,
                'Monto' => $monto,
                'MontoFlow' => $montoFlow ?? $monto,
            ];
        }

        return $payloads;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveServiceForPayload(array $payload): IngresarPagoService
    {
        $idEmpresa = (string) ($payload['IdEmpresa'] ?? '');
        $endpoint = $this->resolveEndpoint($idEmpresa);

        if ($endpoint === null || $endpoint === $this->service->getWsdlEndpoint()) {
            return $this->service;
        }

        if (!isset($this->serviceCache[$endpoint])) {
            $this->serviceCache[$endpoint] = new IngresarPagoService($endpoint);
        }

        return $this->serviceCache[$endpoint];
    }

    private function resolveEndpoint(string $idEmpresa): ?string
    {
        $normalized = \normalize_rut($idEmpresa);

        if ($normalized === '' || !array_key_exists($normalized, $this->endpointMap)) {
            return null;
        }

        $candidate = $this->endpointMap[$normalized];

        if (!is_string($candidate)) {
            return null;
        }

        $trimmed = trim($candidate);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolveChannel(string $medioPago): string
    {
        $medioPago = trim($medioPago);

        if ($medioPago === '') {
            return $this->collector;
        }

        return $this->collector . '-' . $medioPago;
    }

    private function formatDate(?string $value): string
    {
        if (!is_string($value)) {
            return date('d-m-Y');
        }

        $value = trim($value);

        if ($value === '') {
            return date('d-m-Y');
        }

        if (preg_match('/^\d{8}$/', $value) === 1) {
            $year = substr($value, 0, 4);
            $month = substr($value, 4, 2);
            $day = substr($value, 6, 2);

            return sprintf('%s-%s-%s', $day, $month, $year);
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return date('d-m-Y');
        }

        return date('d-m-Y', $timestamp);
    }

    private function extractDateFromTimestamp(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) >= 8) {
            return substr($value, 0, 8);
        }

        return $value;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $digits = preg_replace('/[^0-9-]/', '', $value) ?? '';
            if ($digits === '') {
                return null;
            }

            return (int) $digits;
        }

        return null;
    }

    private function normalizeAmount(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        if (is_string($value)) {
            $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
            if ($digits === '') {
                return null;
            }

            return (int) $digits;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $response
     * @param array<int, array<string, mixed>> $payloads
     * @param array<int, array<string, mixed>> $results
     */
    private function logSuccess(string $transactionId, array $payloads, array $results, array $record, array $response): void
    {
        $entry = [
            'transaction_id' => $transactionId,
            'collector' => $this->collector,
            'payloads' => $payloads,
            'responses' => $results,
            'zumpago' => $this->sanitizeResponseForLog($response),
            'transaction' => $this->sanitizeRecordForLog($record),
        ];

        $this->appendLog($this->logPath, '[Zumpago][IngresarPago]', $entry);
    }

    /**
     * @param array<string, mixed>|null $record
     * @param array<string, mixed>|null $response
     * @param array<string, mixed>|null $payload
     */
    private function logError(string $transactionId, string $message, ?array $record, ?array $response, ?array $payload): void
    {
        $entry = [
            'transaction_id' => $transactionId,
            'collector' => $this->collector,
            'message' => $message,
            'transaction' => $this->sanitizeRecordForLog($record),
            'zumpago' => $this->sanitizeResponseForLog($response),
            'payload' => $payload,
        ];

        $this->appendLog($this->errorLogPath, '[Zumpago][IngresarPago][error]', $entry);
    }

    private function sanitizeRecordForLog(?array $record): ?array
    {
        if (!is_array($record)) {
            return null;
        }

        return [
            'transaction_id' => $record['transaction_id'] ?? null,
            'rut' => $record['rut'] ?? null,
            'email' => $record['email'] ?? null,
            'amount' => $record['amount'] ?? null,
            'selected_ids' => $record['selected_ids'] ?? null,
        ];
    }

    private function sanitizeResponseForLog(?array $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        $parsed = $response['raw']['parsed'] ?? null;
        if (!is_array($parsed)) {
            $parsed = null;
        } else {
            $parsed = array_intersect_key(
                $parsed,
                array_flip([
                    'IdTransaccion',
                    'MontoTotal',
                    'MedioPagoAutorizado',
                    'CodigoRespuesta',
                    'DescripcionRespuesta',
                    'CodigoAutorizacion',
                    'Fecha',
                    'FechaProcesamiento',
                ])
            );
        }

        return [
            'status' => $response['status'] ?? null,
            'code' => $response['code'] ?? null,
            'description' => $response['description'] ?? null,
            'amount' => $response['amount'] ?? null,
            'parsed' => $parsed,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function appendLog(string $path, string $tag, array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode(['error' => 'No fue posible codificar el log de Zumpago.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $message = sprintf(
            "[%s] %s %s%s",
            date('Y-m-d H:i:s'),
            $tag,
            $encoded,
            PHP_EOL
        );

        $dir = dirname($path);
        $canWrite = (file_exists($path) && is_writable($path))
            || (!file_exists($path) && is_dir($dir) && is_writable($dir));

        if ($canWrite) {
            error_log($message, 3, $path);
        } else {
            error_log($message);
        }
    }
}
