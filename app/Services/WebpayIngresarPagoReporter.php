<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

class WebpayIngresarPagoReporter
{
    private const DEFAULT_COLLECTOR = 'WEBPAY';

    /** @var array<string, IngresarPagoService> */
    private array $serviceCache = [];

    public function __construct(
        private readonly WebpayTransactionStorage $storage,
        private readonly IngresarPagoService $service,
        private readonly string $logPath,
        private readonly string $errorLogPath,
        private readonly string $collector = self::DEFAULT_COLLECTOR,
        /** @var array<string, ?string> $endpointMap */
        private readonly array $endpointMap = []
    ) {
    }

    public function report(string $token): void
    {
        $record = $this->storage->get($token);
        if (!is_array($record)) {
            $this->logError($token, 'No se encontró la transacción almacenada para notificar IngresarPago.', null, null, null);
            return;
        }

        $meta = $record['ingresar_pago'] ?? [];
        if (is_array($meta) && !empty($meta['processed'])) {
            return;
        }

        $responses = $record['webpay']['responses'] ?? [];
        if (!is_array($responses) || empty($responses)) {
            $this->logError($token, 'No existen respuestas Webpay registradas para notificar IngresarPago.', $record, null, null);
            return;
        }

        $latest = $responses[array_key_last($responses)];
        if (!is_array($latest)) {
            $this->logError($token, 'La respuesta Webpay más reciente no tiene el formato esperado.', $record, null, null);
            return;
        }

        $code = $latest['detail']['response_code'] ?? null;
        if ((int) $code !== 0) {
            $this->logError($token, 'Se omitió la notificación porque Webpay informó el código ' . $code . '.', $record, $latest, null);
            return;
        }

        $payloads = $this->buildPayloads($record, $latest);
        if (empty($payloads)) {
            $this->logError($token, 'No se generaron cargas útiles válidas para IngresarPago.', $record, $latest, null);
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
                    // omitimos errores al obtener el envelope
                }

                $this->logError($token, $exception->getMessage(), $record, $latest, $payload);
                throw $exception;
            }
        }

        try {
            $this->storage->markProcessed($token, [
                'responses' => $results,
            ]);
        } catch (RuntimeException $exception) {
            $this->logError($token, 'No fue posible actualizar el estado local después de notificar IngresarPago: ' . $exception->getMessage(), $record, $latest, null);
            throw $exception;
        }

        $this->logSuccess($token, $payloads, $results, $record, $latest);
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

        $detail = $response['detail'] ?? [];
        if (!is_array($detail)) {
            $detail = [];
        }

        $transactionDate = (string) ($detail['transaction_date'] ?? '');
        $paymentTypeCode = (string) ($detail['payment_type_code'] ?? '');
        $amount = $this->normalizeAmount($detail['amount'] ?? $record['amount'] ?? null);
        $installments = $detail['shares_number'] ?? null;

        $fechaPago = $this->formatDate($transactionDate);
        $fechaContable = $fechaPago;
        $channel = $this->resolveChannel($paymentTypeCode, $installments);

        $payloads = [];

        foreach ($debts as $debt) {
            if (!is_array($debt)) {
                continue;
            }

            $idEmpresa = (string) ($debt['idempresa'] ?? '');
            $idCliente = $this->normalizeInt($debt['idcliente'] ?? null);
            $mes = $this->normalizeInt($debt['mes'] ?? null);
            $ano = $this->normalizeInt($debt['ano'] ?? null);
            $monto = $this->normalizeAmount($debt['amount'] ?? $amount ?? null);

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
                'MontoFlow' => $monto,
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

    private function resolveChannel(string $paymentTypeCode, mixed $installments): string
    {
        $paymentTypeCode = strtoupper(trim($paymentTypeCode));

        if ($paymentTypeCode === '') {
            return $this->collector;
        }

        $channel = $this->collector . '-' . $paymentTypeCode;

        $installmentsNumber = $this->normalizeInt($installments);
        if ($installmentsNumber !== null && $installmentsNumber > 0) {
            $channel .= '-' . $installmentsNumber . 'C';
        }

        return $channel;
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

        $timestamp = strtotime($value);

        if ($timestamp === false && preg_match('/^\d{8}$/', $value) === 1) {
            $year = substr($value, 0, 4);
            $month = substr($value, 4, 2);
            $day = substr($value, 6, 2);
            $timestamp = strtotime(sprintf('%s-%s-%s', $year, $month, $day));
        }

        if ($timestamp === false) {
            return date('d-m-Y');
        }

        return date('d-m-Y', $timestamp);
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
     * @param array<int, array<string, mixed>> $payloads
     * @param array<int, array<string, mixed>> $results
     * @param array<string, mixed> $record
     * @param array<string, mixed> $response
     */
    private function logSuccess(string $token, array $payloads, array $results, array $record, array $response): void
    {
        $entry = [
            'token' => $token,
            'collector' => $this->collector,
            'payloads' => $payloads,
            'responses' => $results,
            'webpay' => $this->sanitizeResponseForLog($response),
            'transaction' => $this->sanitizeRecordForLog($record),
        ];

        $this->appendLog($this->logPath, '[Webpay][IngresarPago]', $entry);
    }

    /**
     * @param array<string, mixed>|null $record
     * @param array<string, mixed>|null $response
     * @param array<string, mixed>|null $payload
     */
    private function logError(string $token, string $message, ?array $record, ?array $response, ?array $payload): void
    {
        $entry = [
            'token' => $token,
            'collector' => $this->collector,
            'message' => $message,
            'transaction' => $this->sanitizeRecordForLog($record),
            'webpay' => $this->sanitizeResponseForLog($response),
            'payload' => $payload,
        ];

        $this->appendLog($this->errorLogPath, '[Webpay][IngresarPago][error]', $entry);
    }

    private function sanitizeRecordForLog(?array $record): ?array
    {
        if (!is_array($record)) {
            return null;
        }

        return [
            'token' => $record['token'] ?? null,
            'rut' => $record['rut'] ?? null,
            'email' => $record['email'] ?? null,
            'amount' => $record['amount'] ?? null,
            'selected_ids' => $record['selected_ids'] ?? null,
            'buy_order' => $record['buy_order'] ?? null,
        ];
    }

    private function sanitizeResponseForLog(?array $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        return [
            'status' => $response['status'] ?? null,
            'code' => $response['detail']['response_code'] ?? null,
            'authorization' => $response['detail']['authorization_code'] ?? null,
            'amount' => $response['detail']['amount'] ?? null,
            'transaction_date' => $response['detail']['transaction_date'] ?? null,
            'payment_type_code' => $response['detail']['payment_type_code'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function appendLog(string $path, string $tag, array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode(['error' => 'No fue posible codificar el log de Webpay.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
