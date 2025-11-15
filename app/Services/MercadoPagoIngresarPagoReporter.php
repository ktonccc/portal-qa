<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

class MercadoPagoIngresarPagoReporter
{
    private const DEFAULT_CHANNEL = 'MERCADOPAGO';
    private const DEFAULT_COLLECTOR = 'MercadoPago';

    /** @var array<string, IngresarPagoService> */
    private array $serviceCache = [];

    public function __construct(
        private readonly MercadoPagoTransactionStorage $storage,
        private readonly IngresarPagoService $service,
        private readonly string $logPath,
        private readonly string $errorLogPath,
        private readonly string $channel = self::DEFAULT_CHANNEL,
        private readonly string $collector = self::DEFAULT_COLLECTOR,
        /** @var array<string, ?string> $endpointMap */
        private readonly array $endpointMap = []
    ) {
    }

    /**
     * @param array<string, mixed> $payment
     */
    public function report(string $transactionId, array $payment): void
    {
        $status = strtolower((string) ($payment['status'] ?? ''));
        if ($status !== 'approved') {
            return;
        }

        $transaction = $this->storage->get($transactionId);
        if (!is_array($transaction)) {
            $this->logError($transactionId, 'No se encontró la transacción asociada para notificar IngresarPago.', $payment, null, null, 0);
            return;
        }

        $meta = $transaction['ingresar_pago'] ?? [];
        if (is_array($meta) && !empty($meta['processed'])) {
            return;
        }

        $payloads = $this->buildPayloads($transaction, $payment);
        if (empty($payloads)) {
            $this->logError($transactionId, 'No se generaron cargas útiles para IngresarPago.', $payment, $transaction, null, 0);
            return;
        }

        $responses = [];
        $callCount = 0;

        foreach ($payloads as $payload) {
            $callCount++;
            $targetService = $this->resolveServiceForPayload($payload);

            try {
                $result = $targetService->submit($payload);
                $result['wsdl'] = $targetService->getWsdlEndpoint();
                $responses[] = $result;
            } catch (Throwable $exception) {
                $this->logError($transactionId, $exception->getMessage(), $payment, $transaction, $payload, $callCount);
                throw $exception;
            }
        }

        try {
            $this->storage->markProcessed($transactionId, [
                'responses' => $responses,
            ]);
        } catch (RuntimeException $exception) {
            $this->logError($transactionId, 'No fue posible actualizar el estado local del pago: ' . $exception->getMessage(), $payment, $transaction, null, $callCount);
            throw $exception;
        }

        $this->logSuccess($transactionId, $responses, $payment, $transaction, $callCount);
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $payment
     * @return array<int, array<string, mixed>>
     */
    private function buildPayloads(array $transaction, array $payment): array
    {
        $rut = $this->resolveRut($transaction);
        $mail = $this->resolveMail($transaction, $payment);

        $paymentDate = $this->normalizeDate(
            $payment['date_approved'] ?? $payment['date_created'] ?? null
        );
        $accountingDate = $this->normalizeDate(
            $payment['money_release_date'] ?? null,
            $paymentDate
        );

        $debts = $transaction['debts'] ?? [];
        if (!is_array($debts)) {
            $debts = [];
        }

        $debts = array_values($debts);
        $netShares = $this->distributeNetReceivedAmount(
            $debts,
            $this->resolveNetReceivedAmount($payment)
        );

        if (empty($debts)) {
            return [];
        }

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
                $netShares[$index] ?? null
            );

            if ($payload !== null) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param array<string, mixed> $debt
     */
    private function buildPayloadFromDebt(
        array $debt,
        string $rut,
        string $mail,
        string $paymentDate,
        string $accountingDate,
        ?int $netAmountShare = null
    ): ?array {
        $idEmpresa = trim((string) ($debt['idempresa'] ?? ''));
        $idCliente = $this->normalizeInt($debt['idcliente'] ?? null);
        $mes = $this->normalizeInt($debt['mes'] ?? null);
        $ano = $this->normalizeInt($debt['ano'] ?? null);
        $amount = $this->normalizeAmount($debt['amount'] ?? null);

        if ($idEmpresa === '' || $idCliente === null || $idCliente <= 0 || $amount === null || $amount <= 0) {
            return null;
        }

        return [
            'IdEmpresa' => $idEmpresa,
            'IdCliente' => $idCliente,
            'RutCliente' => $rut,
            'Mail' => $mail,
            'Recaudador' => $this->collector,
            'Canal' => $this->channel,
            'FechaPago' => $paymentDate,
            'FechaContable' => $accountingDate,
            'Mes' => $mes ?? 0,
            'Ano' => $ano ?? 0,
            'Monto' => $amount,
            'MontoFlow' => $netAmountShare ?? $amount,
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function resolveRut(array $transaction): string
    {
        $rut = (string) ($transaction['rut'] ?? '');

        if (function_exists('normalize_rut')) {
            $normalized = normalize_rut($rut);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $rut;
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $payment
     */
    private function resolveMail(array $transaction, array $payment): string
    {
        $email = trim((string) ($transaction['email'] ?? ''));

        if ($email === '') {
            $email = trim((string) ($payment['payer']['email'] ?? ''));
        }

        return $email;
    }

    private function normalizeDate(mixed $value, ?string $fallback = null): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d-m-Y');
        }

        $stringValue = trim((string) ($value ?? ''));
        if ($stringValue !== '') {
            $timestamp = strtotime($stringValue);
            if ($timestamp !== false) {
                return date('d-m-Y', $timestamp);
            }
        }

        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        return date('d-m-Y');
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '' || $digits === null) {
            return null;
        }

        return (int) $digits;
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

        $numeric = preg_replace('/[^\d]/', '', (string) $value);
        if ($numeric === '' || $numeric === null) {
            return null;
        }

        return (int) $numeric;
    }

    private function resolveNetReceivedAmount(array $payment): ?int
    {
        $details = $payment['transaction_details'] ?? null;
        if (!is_array($details)) {
            return null;
        }

        return $this->normalizeAmount($details['net_received_amount'] ?? null);
    }

    /**
     * @param array<int, array<string, mixed>> $debts
     * @return array<int, int>
     */
    private function distributeNetReceivedAmount(array $debts, ?int $netAmount): array
    {
        if ($netAmount === null || $netAmount <= 0) {
            return [];
        }

        $amounts = [];
        $total = 0;

        foreach ($debts as $index => $debt) {
            if (!is_array($debt)) {
                continue;
            }

            $amount = $this->normalizeAmount($debt['amount'] ?? null);
            if ($amount === null || $amount <= 0) {
                continue;
            }

            $amounts[$index] = $amount;
            $total += $amount;
        }

        if ($total <= 0) {
            return [];
        }

        $shares = [];
        $allocated = 0;
        $indexes = array_keys($amounts);
        $lastPosition = count($indexes) - 1;

        foreach ($indexes as $position => $index) {
            if ($position === $lastPosition) {
                $share = $netAmount - $allocated;
            } else {
                $share = (int) floor(($netAmount * $amounts[$index]) / $total);
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
     * @param array<string, mixed> $payload
     */
    private function resolveServiceForPayload(array $payload): IngresarPagoService
    {
        $idEmpresa = (string) ($payload['IdEmpresa'] ?? '');
        $endpoint = trim((string) ($this->endpointMap[$idEmpresa] ?? ''));

        if ($endpoint === '' || $endpoint === $this->service->getWsdlEndpoint()) {
            return $this->service;
        }

        if (!isset($this->serviceCache[$endpoint])) {
            $this->serviceCache[$endpoint] = new IngresarPagoService($endpoint);
        }

        return $this->serviceCache[$endpoint];
    }

    /**
     * @param array<int, array<string, mixed>> $responses
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $transaction
     */
    private function logSuccess(string $transactionId, array $responses, array $payment, array $transaction, int $callCount): void
    {
        $entry = [
            'transaction_id' => $transactionId,
            'payment_id' => $payment['id'] ?? null,
            'payment_status' => $payment['status'] ?? null,
            'call_count' => $callCount,
            'responses' => $responses,
            'rut' => $transaction['rut'] ?? null,
            'amount' => $transaction['amount'] ?? null,
        ];

        $message = sprintf(
            "[%s] [MercadoPago][IngresarPago] %s%s",
            date('Y-m-d H:i:s'),
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );

        $this->writeLog($this->logPath, $message);
    }

    /**
     * @param array<string, mixed>|null $transaction
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $payment
     */
    private function logError(
        string $transactionId,
        string $message,
        array $payment,
        ?array $transaction,
        ?array $payload,
        int $callCount
    ): void {
        $entry = [
            'transaction_id' => $transactionId,
            'message' => $message,
            'payment' => [
                'id' => $payment['id'] ?? null,
                'status' => $payment['status'] ?? null,
            ],
            'rut' => $transaction['rut'] ?? null,
            'payload' => $payload,
            'call_count' => $callCount,
        ];

        $logMessage = sprintf(
            "[%s] [MercadoPago][IngresarPago][error] %s%s",
            date('Y-m-d H:i:s'),
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );

        $this->writeLog($this->errorLogPath, $logMessage);
    }

    private function writeLog(string $path, string $message): void
    {
        $directory = dirname($path);
        $canWrite = (file_exists($path) && is_writable($path))
            || (!file_exists($path) && is_writable($directory));

        if ($canWrite) {
            error_log($message, 3, $path);
            return;
        }

        error_log($message);
    }
}
