<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class MercadoPagoPaymentService
{
    private string $accessToken;
    private string $baseUrl;
    private ?string $statementDescriptor;

    public function __construct(
        private readonly array $config
    ) {
        $this->accessToken = trim((string) ($config['access_token'] ?? ''));
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.mercadopago.com'), '/');
        $this->statementDescriptor = isset($config['statement_descriptor'])
            ? trim((string) $config['statement_descriptor'])
            : null;

        if ($this->accessToken === '') {
            throw new RuntimeException('Debe configurar el access token de Mercado Pago.');
        }

        if ($this->baseUrl === '') {
            $this->baseUrl = 'https://api.mercadopago.com';
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPayment(array $payload): array
    {
        if ($this->statementDescriptor !== null && $this->statementDescriptor !== '') {
            $payload += ['statement_descriptor' => $this->statementDescriptor];
        }

        return $this->request('POST', '/v1/payments', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPreference(array $payload): array
    {
        if ($this->statementDescriptor !== null && $this->statementDescriptor !== '') {
            $payload += ['statement_descriptor' => $this->statementDescriptor];
        }

        return $this->request('POST', '/checkout/preferences', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            throw new RuntimeException('El identificador del pago de Mercado Pago no puede estar vacío.');
        }

        $encodedId = rawurlencode($paymentId);

        return $this->request('GET', '/v1/payments/' . $encodedId);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('No fue posible inicializar la conexión con Mercado Pago.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $methodUpper = strtoupper($method);

        if ($methodUpper === 'GET') {
            if (!empty($payload)) {
                $query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
                if ($query !== '') {
                    $separator = str_contains($url, '?') ? '&' : '?';
                    $url .= $separator . $query;
                    curl_setopt($curl, CURLOPT_URL, $url);
                }
            }
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        } elseif ($methodUpper === 'POST') {
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encodedPayload === false) {
                curl_close($curl);
                throw new RuntimeException('No fue posible codificar la solicitud a Mercado Pago.');
            }

            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $encodedPayload);
        } else {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $methodUpper);
            if (!empty($payload)) {
                $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encodedPayload === false) {
                    curl_close($curl);
                    throw new RuntimeException('No fue posible codificar la solicitud a Mercado Pago.');
                }
                curl_setopt($curl, CURLOPT_POSTFIELDS, $encodedPayload);
            }
        }

        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('No fue posible comunicarse con Mercado Pago: ' . $error);
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Mercado Pago retornó una respuesta inválida.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $this->resolveErrorMessage($decoded);
            throw new RuntimeException(sprintf('Mercado Pago rechazó la solicitud (%d): %s', $httpCode, $message));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function resolveErrorMessage(array $response): string
    {
        $message = (string) ($response['message'] ?? $response['error'] ?? 'Error desconocido de Mercado Pago');

        if (isset($response['cause']) && is_array($response['cause']) && !empty($response['cause'])) {
            $details = [];
            foreach ($response['cause'] as $cause) {
                if (!is_array($cause)) {
                    continue;
                }
                $detail = trim((string) ($cause['description'] ?? $cause['code'] ?? ''));
                if ($detail !== '') {
                    $details[] = $detail;
                }
            }

            if (!empty($details)) {
                $message .= ' (' . implode(', ', $details) . ')';
            }
        }

        return $message;
    }
}
