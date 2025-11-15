<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

class FlowIngresarPagoReporter
{
    private const DEFAULT_CHANNEL = 'FLOW';
    /** @var array<string, IngresarPagoService> */
    private array $serviceCache = [];

    public function __construct(
        private readonly FlowTransactionStorage $storage,
        private readonly IngresarPagoService $service,
        private readonly string $logPath,
        private readonly string $errorLogPath,
        private readonly string $channel = self::DEFAULT_CHANNEL,
        /** @var array<string, ?string> $endpointMap */
        private readonly array $endpointMap = []
    ) {
    }

    /**
     * @param array<string, mixed> $status
     */
    public function report(string $token, array $status): void
    {
        if ((int) ($status['status'] ?? 0) !== 2) {
            return;
        }

        $callCount = 0;
        $transaction = $this->storage->get($token);
        if (!is_array($transaction)) {
            $this->logError($token, 'No se encontró la transacción almacenada para notificar IngresarPago.', $status, null, null, $callCount);
            return;
        }

        $meta = $transaction['ingresar_pago'] ?? [];
        if (is_array($meta) && !empty($meta['processed'])) {
            return;
        }

        $collector = $this->resolveCollector();
        $channel = $this->resolveChannel($status, $collector);
        $flowNetAmount = $this->resolveFlowNetAmount($status);
        $payloads = $this->buildPayloads($transaction, $status, $channel, $collector, $flowNetAmount);
        if (empty($payloads)) {
            $this->logError($token, 'No se generaron cargas útiles para IngresarPago.', $status, $transaction, null, $callCount);
            return;
        }

        $responses = [];

        foreach ($payloads as $payload) {
            $callCount++;
            $envelope = null;
            $targetService = $this->resolveServiceForPayload($payload);

            try {
                $result = $targetService->submit($payload);
                $result['wsdl'] = $targetService->getWsdlEndpoint();
                $responses[] = $result;
            } catch (Throwable $exception) {
                try {
                    $envelope = $targetService->previewEnvelope($payload);
                } catch (Throwable) {
                    $envelope = null;
                }

                if ($envelope !== null) {
                    $payload['__envelope'] = $envelope;
                }

                $payload['__target_wsdl'] = $targetService->getWsdlEndpoint();

                $this->logError($token, $exception->getMessage(), $status, $transaction, $payload, $callCount);
                throw $exception;
            }
        }

        try {
            $this->storage->markProcessed($token, [
                'responses' => $responses,
            ]);
        } catch (RuntimeException $exception) {
            $this->logError($token, 'No fue posible actualizar el estado local del token después de notificar IngresarPago: ' . $exception->getMessage(), $status, $transaction, null, $callCount);
            throw $exception;
        }

        $this->logSuccess($token, $responses, $status, $transaction, $callCount);
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $status
     * @param string $channel
     * @param string $collector
     * @param int|null $flowNetAmount
     * @return array<int, array<string, mixed>>
     */
    private function buildPayloads(
        array $transaction,
        array $status,
        string $channel,
        string $collector,
        ?int $flowNetAmount
    ): array
    {
        $rut = $this->resolveRut($transaction, $status);
        $mail = $this->resolveMail($transaction, $status);
        $paymentDate = $this->normalizeDate($status['paymentData']['date'] ?? $status['requestDate'] ?? null);
        $accountingDate = $this->normalizeDate($status['paymentData']['transferDate'] ?? null) ?? $paymentDate ?? date('d-m-Y');

        $optional = $status['optional'] ?? [];
        if (!is_array($optional)) {
            $optional = [];
        }

        $debts = $transaction['debts'] ?? [];
        if (!is_array($debts)) {
            $debts = [];
        }

        if (empty($debts) && !empty($optional)) {
            $debts = $this->buildDebtsFromOptional($optional, $status);
        }

        $debts = array_values($debts);

        $flowNetShares = $this->distributeFlowNetAmount($debts, $flowNetAmount);
        $payloads = [];

        foreach ($debts as $index => $debt) {
            if (!is_array($debt)) {
                continue;
            }

            $payload = $this->buildPayloadFromDebt(
                $debt,
                $rut,
                $mail,
                $paymentDate,
                $accountingDate,
                $channel,
                $collector,
                $flowNetShares[$index] ?? null,
                $status,
                $optional
            );

            if ($payload !== null) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param array<string, mixed> $debt
     * @param string $channel
     * @param string $collector
     * @param int|null $flowNetAmountShare
     * @param array<string, mixed> $status
     * @param array<string, mixed> $optional
     * @return array<string, mixed>|null
     */
    private function buildPayloadFromDebt(
        array $debt,
        string $rut,
        string $mail,
        string $paymentDate,
        string $accountingDate,
        string $channel,
        string $collector,
        ?int $flowNetAmountShare,
        array $status,
        array $optional
    ): ?array {
        $idEmpresa = (string) ($debt['idempresa'] ?? $optional['IdEmpresa'] ?? '');
        $idCliente = $this->normalizeInt($debt['idcliente'] ?? $optional['IDCliente'] ?? null);
        $mes = $this->normalizeInt($debt['mes'] ?? $debt['Mes'] ?? $optional['Mes'] ?? null);
        $ano = $this->normalizeInt($debt['ano'] ?? $debt['Año'] ?? $optional['Año'] ?? $optional['Ano'] ?? null);
        $monto = $this->normalizeAmount($debt['amount'] ?? $optional['Monto'] ?? $status['amount'] ?? null);

        if ($idEmpresa === '' || $idCliente === null || $idCliente <= 0 || $monto === null || $monto <= 0) {
            return null;
        }

        $montoFlow = $flowNetAmountShare ?? $monto;

        return [
            'IdEmpresa' => $idEmpresa,
            'IdCliente' => $idCliente,
            'RutCliente' => $rut,
            'Mail' => $mail,
            'Recaudador' => $collector,
            'Canal' => $channel,
            'FechaPago' => $paymentDate,
            'FechaContable' => $accountingDate,
            'Mes' => $mes,
            'Ano' => $ano,
            'Monto' => $monto,
            'MontoFlow' => $montoFlow,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $debts
     * @return array<int, int>
     */
    private function distributeFlowNetAmount(array $debts, ?int $flowNetAmount): array
    {
        if ($flowNetAmount === null || $flowNetAmount <= 0) {
            return [];
        }

        $amounts = [];
        $totalAmount = 0;

        foreach ($debts as $index => $debt) {
            if (!is_array($debt)) {
                continue;
            }

            $amount = $this->normalizeAmount($debt['amount'] ?? null);
            if ($amount === null || $amount <= 0) {
                continue;
            }

            $amounts[$index] = $amount;
            $totalAmount += $amount;
        }

        if ($totalAmount <= 0) {
            return [];
        }

        $shares = [];
        $allocated = 0;
        $validIndexes = array_keys($amounts);
        $lastPosition = count($validIndexes) - 1;

        foreach ($validIndexes as $position => $index) {
            if ($position === $lastPosition) {
                $share = $flowNetAmount - $allocated;
            } else {
                $share = (int) floor(($flowNetAmount * $amounts[$index]) / $totalAmount);
                $allocated += $share;
            }

            if ($share < 0) {
                $share = 0;
            }

            $shares[$index] = $share;

            if ($position === $lastPosition) {
                $allocated += $share;
            }
        }

        return $shares;
    }

    /**
     * @param array<string, mixed> $optional
     * @param array<string, mixed> $status
     * @return array<int, array<string, mixed>>
     */
    private function buildDebtsFromOptional(array $optional, array $status): array
    {
        if (!isset($optional['IDCliente'])) {
            return [];
        }

        $idClientes = is_array($optional['IDCliente'])
            ? $optional['IDCliente']
            : [$optional['IDCliente']];

        $result = [];

        foreach ($idClientes as $idCliente) {
            $result[] = [
                'idempresa' => $optional['IdEmpresa'] ?? '',
                'idcliente' => $idCliente,
                'mes' => $optional['Mes'] ?? null,
                'ano' => $optional['Año'] ?? $optional['Ano'] ?? null,
                'amount' => $status['amount'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $status
     */
    private function resolveRut(array $transaction, array $status): string
    {
        $candidates = [
            $transaction['rut'] ?? null,
            $transaction['optional_payload']['Rut'] ?? null,
            $status['optional']['Rut'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $normalized = \normalize_rut($candidate);
            if ($normalized === '') {
                continue;
            }

            return $normalized;
        }

        return '';
    }

    private function resolveChannel(array $status, string $collector): string
    {
        $media = $status['paymentData']['media'] ?? null;

        if (is_string($media)) {
            $media = trim($media);
        } else {
            $media = '';
        }

        if ($media !== '') {
            return $media;
        }

        return $collector;
    }

    private function resolveCollector(): string
    {
        $configured = trim($this->channel);

        if ($configured === '') {
            return 'Flow';
        }

        return ucfirst(strtolower($configured));
    }

    private function resolveFlowNetAmount(array $status): ?int
    {
        $paymentData = $status['paymentData'] ?? [];
        if (!is_array($paymentData)) {
            $paymentData = [];
        }

        $amount = $this->normalizeAmount($paymentData['amount'] ?? $status['amount'] ?? null);
        if ($amount === null) {
            return null;
        }

        $fee = $this->normalizeAmount($paymentData['fee'] ?? null) ?? 0;
        $taxes = $this->normalizeAmount($paymentData['taxes'] ?? null) ?? 0;

        $net = $amount - ($fee + $taxes);

        if ($net < 0) {
            $net = 0;
        }

        return $net;
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $status
     */
    private function resolveMail(array $transaction, array $status): string
    {
        $candidates = [
            $transaction['email'] ?? null,
            $status['payer'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function normalizeDate(null|string $value): string
    {
        $format = 'd-m-Y';

        if ($value === null || trim($value) === '') {
            return date($format);
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return date($format);
        }

        return date($format, $timestamp);
    }

    /**
     * Determina el servicio SOAP que debe utilizarse según el IdEmpresa del payload.
     *
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

    /**
     * @return int|null
     */
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
            $digits = preg_replace('/[^0-9-]/', '', $value);
            if ($digits === null || $digits === '') {
                return null;
            }

            return (int) $digits;
        }

        return null;
    }

    /**
     * @return int|null
     */
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
            return (int) $value;
        }

        if (is_string($value)) {
            $digits = preg_replace('/[^0-9]/', '', $value);
            if ($digits === null || $digits === '') {
                return null;
            }

            return (int) $digits;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $responses
     * @param array<string, mixed> $status
     * @param array<string, mixed>|null $transaction
     */
    private function logSuccess(string $token, array $responses, array $status, ?array $transaction, int $attemptCount): void
    {
        $payloads = array_column($responses, 'payload');
        $responsesBody = array_column($responses, 'response');
        $envelopes = array_column($responses, 'envelope');
        $httpStatuses = array_column($responses, 'http_status');
        $wsdls = array_column($responses, 'wsdl');

        $entry = [
            'token' => $token,
            'flow_order' => $status['flowOrder'] ?? $transaction['flow_order'] ?? null,
            'commerce_order' => $transaction['commerce_order'] ?? null,
            'attempt_count' => $attemptCount,
            'payloads' => $payloads,
            'payloads_xml' => $envelopes,
            'http_statuses' => $httpStatuses,
            'responses' => $responsesBody,
            'wsdls' => $wsdls,
        ];

        $this->appendLog($this->logPath, '[Flow][IngresarPago]', $entry);
    }

    /**
     * @param array<string, mixed>|null $transaction
     * @param array<string, mixed>|null $payload
     */
    private function logError(string $token, string $message, array $status, ?array $transaction, ?array $payload, ?int $attemptCount = null): void
    {
        $envelope = null;

        if (is_array($payload) && array_key_exists('__envelope', $payload)) {
            $envelope = $payload['__envelope'];
            unset($payload['__envelope']);
        }

        $entry = [
            'token' => $token,
            'message' => $message,
            'flow_order' => $status['flowOrder'] ?? $transaction['flow_order'] ?? null,
            'commerce_order' => $transaction['commerce_order'] ?? null,
            'attempt_count' => $attemptCount,
            'payload' => $payload,
            'payload_xml' => $envelope,
            'status' => $status,
        ];

        $this->appendLog($this->errorLogPath, '[Flow][IngresarPago][error]', $entry);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function appendLog(string $path, string $tag, array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode(['error' => 'No se pudo codificar el log para IngresarPago.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
