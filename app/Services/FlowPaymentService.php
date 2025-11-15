<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class FlowPaymentService
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $environment = strtolower((string) ($config['environment'] ?? 'sandbox'));
        $urls = (array) ($config['urls'] ?? []);

        $this->baseUrl = (string) ($urls[$environment] ?? ($config['base_url'] ?? ''));

        if ($this->baseUrl === '') {
            throw new RuntimeException('No se encontró la URL base de Flow para el ambiente configurado.');
        }

        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new RuntimeException('Debe configurar el apiKey y secretKey de Flow.');
        }
    }

    /**
     * @param array{
     *     commerceOrder: string,
     *     subject: string,
     *     amount: int|float|string,
     *     email: string,
     *     currency?: string|null,
     *     paymentMethod?: int|null,
     *     urlConfirmation?: string|null,
     *     urlReturn?: string|null,
     *     optional?: string|null,
     *     timeout?: int|null,
     *     merchantId?: string|null,
     *     payment_currency?: string|null
     * } $payload
     * @return array{url: string, token: string, flowOrder?: int|string|null}
     */
    public function createPayment(array $payload): array
    {
        $defaults = [
            'currency' => $this->config['currency'] ?? null,
            'paymentMethod' => $this->config['payment_method'] ?? null,
            'urlConfirmation' => $this->config['url_confirmation'] ?? null,
            'urlReturn' => $this->config['url_return'] ?? null,
            'timeout' => $this->config['timeout'] ?? null,
        ];

        $params = array_merge(
            [
                'apiKey' => $this->apiKey,
            ],
            $defaults,
            $payload
        );

        $filtered = $this->filterParameters($params);
        $response = $this->sendRequest('POST', '/payment/create', $filtered);

        if (!isset($response['url'], $response['token'])) {
            throw new RuntimeException('Flow no retornó los datos esperados.');
        }

        /** @var array{url: string, token: string, flowOrder?: int|string|null} $response */
        return $response;
    }

    /**
     * Obtiene el estado de una transacción usando el token.
     *
     * @return array<string, mixed>
     */
    public function getPaymentStatus(string $token): array
    {
        if ($token === '') {
            throw new RuntimeException('El token de Flow no puede estar vacío.');
        }

        $params = $this->filterParameters([
            'apiKey' => $this->apiKey,
            'token' => $token,
        ]);

        /** @var array<string, mixed> */
        $response = $this->sendRequest('GET', '/payment/getStatus', $params);

        return $response;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sendRequest(string $method, string $path, array $params): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $signed = $this->signParameters($params);

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException('No fue posible inicializar la conexión a Flow.');
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        if ($method === 'GET') {
            $query = http_build_query($signed);
            curl_setopt($curl, CURLOPT_URL, $url . '?' . $query);
        } else {
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($signed));
        }

        $body = curl_exec($curl);

        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('No fue posible comunicarse con Flow: ' . $error);
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Flow retornó una respuesta inválida.');
        }

        if ($httpCode !== 200) {
            $code = (int) ($decoded['code'] ?? $httpCode);
            $message = (string) ($decoded['message'] ?? 'Error desconocido de Flow');
            throw new RuntimeException(sprintf('Flow rechazó la solicitud (%d): %s', $code, $message));
        }

        if (isset($decoded['code']) && (int) $decoded['code'] !== 0) {
            $message = (string) ($decoded['message'] ?? 'Error desconocido de Flow');
            throw new RuntimeException(sprintf('Flow respondió con un error: %s', $message));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function signParameters(array $params): array
    {
        $sortedKeys = array_keys($params);
        sort($sortedKeys, SORT_STRING);

        $stringToSign = '';

        foreach ($sortedKeys as $key) {
            $stringToSign .= $key . $params[$key];
        }

        $params['s'] = hash_hmac('sha256', $stringToSign, $this->secretKey);

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function filterParameters(array $params): array
    {
        $filtered = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                $filtered[$key] = $value;
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $filtered[$key] = (string) $value;
                continue;
            }

            $filtered[$key] = (string) $value;
        }

        return $filtered;
    }
}

