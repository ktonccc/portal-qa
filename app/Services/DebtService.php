<?php

declare(strict_types=1);

namespace App\Services;

use nusoap_client;
use RuntimeException;
use SimpleXMLElement;
use InvalidArgumentException;
use Throwable;

class DebtService
{
    /** @var array<int, string> */
    private array $wsdlEndpoints;

    private bool $cacheEnabled = false;

    private int $cacheTtlSeconds = 0;

    private string $cacheDirectory = '';

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $runtimeCache = [];

    private ?string $lastCustomerName = null;

    public function __construct(string ...$wsdlEndpoints)
    {
        $filtered = array_values(array_filter($wsdlEndpoints, static fn ($endpoint) => is_string($endpoint) && trim($endpoint) !== ''));

        if (!empty($filtered)) {
            $filtered = array_values(array_unique($filtered));
        }

        if (empty($filtered)) {
            throw new InvalidArgumentException('Debe configurarse al menos un endpoint WSDL para el servicio de deudas.');
        }

        $this->wsdlEndpoints = $filtered;

        $cacheConfig = (array) \config_value('services.debt_cache', []);
        $this->cacheEnabled = array_key_exists('enabled', $cacheConfig)
            ? (bool) $cacheConfig['enabled']
            : true;
        $ttl = (int) ($cacheConfig['ttl'] ?? 90);
        $this->cacheTtlSeconds = $ttl > 0 ? $ttl : 0;

        $storagePath = dirname(__DIR__) . '/storage/debts_cache';
        $this->cacheDirectory = $storagePath;

        if ($this->cacheEnabled && $this->cacheTtlSeconds > 0) {
            if (!is_dir($storagePath) && !@mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
                $this->cacheEnabled = false;
            }
        } else {
            $this->cacheEnabled = false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDebts(string $rut): array
    {
        $lastException = null;
        $this->lastCustomerName = null;
        $cacheKey = $this->resolveCacheKey($rut);

        if ($cacheKey !== null) {
            $cachedDebts = $this->getCachedDebts($cacheKey);
            if ($cachedDebts !== null) {
                $this->debugLog('DebtService.cacheHit', [
                    'rut' => $cacheKey,
                    'records_count' => count($cachedDebts),
                    'ttl' => $this->cacheTtlSeconds,
                ]);
                return $cachedDebts;
            }
        }

        $rutForQuery = $cacheKey ?? $rut;

        // Try each configured WSDL until one responds with a usable payload.
        foreach ($this->wsdlEndpoints as $endpoint) {
            try {
                $result = $this->callSoapEndpoint($endpoint, $rutForQuery);
                $response = $result['payload'] ?? null;

                $this->debugLog('DebtService.fetchDebts response', [
                    'rut' => $rutForQuery,
                    'endpoint' => $endpoint,
                    'transport' => $result['transport'] ?? 'unknown',
                    'type' => gettype($response),
                    'has_return' => $this->payloadContainsReturnField($response),
                    'preview' => is_string($response) ? mb_substr($response, 0, 200) : null,
                ]);

                $payload = $this->normalizePayload($response);
                $hasInvalidRutHint = is_string($payload) && $this->payloadIndicatesInvalidRut($payload);

                $shouldRetryRaw = ($result['transport'] ?? '') !== 'raw_soap'
                    && (
                        $payload === null
                        || trim((string) $payload) === ''
                        || $hasInvalidRutHint
                    );

                if ($shouldRetryRaw) {
                    $this->debugLog('DebtService.retryWithRawSoap', [
                        'rut' => $rutForQuery,
                        'endpoint' => $endpoint,
                        'previous_transport' => $result['transport'] ?? 'unknown',
                        'reason' => $hasInvalidRutHint ? 'invalid_rut_hint' : 'empty_payload',
                    ]);

                    $fallbackResult = $this->callRawSoap($endpoint, $rutForQuery);
                    if ($fallbackResult !== null) {
                        $response = $fallbackResult['payload'] ?? null;
                        $result = array_merge($result, $fallbackResult);
                        $payload = $this->normalizePayload($response);
                    }
                }

                if ($payload === null) {
                    $this->debugLog('DebtService.emptyResponse', [
                        'endpoint' => $endpoint,
                        'transport' => $result['transport'] ?? 'unknown',
                        'raw_response' => $result['raw_response'] ?? null,
                    ]);
                    continue;
                }

                if (trim($payload) === '') {
                    $this->debugLog('DebtService.emptyResponse', [
                        'endpoint' => $endpoint,
                        'transport' => $result['transport'] ?? 'unknown',
                        'raw_response' => $result['raw_response'] ?? null,
                    ]);
                    continue;
                }

                if ($this->payloadIndicatesInvalidRut((string) $payload)) {
                    $this->debugLog('DebtService.invalidRutResponse', [
                        'endpoint' => $endpoint,
                        'transport' => $result['transport'] ?? 'unknown',
                        'rut' => $rutForQuery,
                    ]);
                    continue;
                }

                // Map the XML payload into an array of debts.
                $records = $this->extractRecords($payload);

                $this->debugLog('DebtService.recordsExtracted', [
                    'endpoint' => $endpoint,
                    'transport' => $result['transport'] ?? 'unknown',
                    'rut' => $rutForQuery,
                    'records_count' => is_array($records) ? count($records) : 0,
                ]);

                if (empty($records)) {
                    $this->debugLog('DebtService.recordsEmptyPayload', [
                        'endpoint' => $endpoint,
                        'transport' => $result['transport'] ?? 'unknown',
                        'rut' => $rutForQuery,
                        'payload_preview' => mb_substr($payload, 0, 300),
                        'raw_response_preview' => is_string($result['raw_response'] ?? null) ? mb_substr((string) $result['raw_response'], 0, 300) : null,
                    ]);
                }

                $debts = [];
                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $this->captureCustomerName($record);

                    $amount = $this->normalizeAmount((string) ($record['deuda'] ?? '0'));
                    if ($amount <= 0) {
                        continue;
                    }

                    $service = $this->pickRecordValue($record, ['servicio', 'detalle', 'concepto', 'serv']);
                    $month = $this->pickRecordValue($record, ['mes', 'periodo', 'mes_label']);
                    $year = $this->pickRecordValue($record, ['ano', 'anio', 'año', 'periodo_ano', 'ano_label']);
                    $amountDisplay = $this->pickRecordValue($record, ['amount_display', 'deuda', 'monto', 'total']);
                    $paymentAvailability = $this->extractPaymentAvailability($record);

                    $debts[] = [
                        'idempresa' => (string) ($record['idempresa'] ?? ''),
                        'idcliente' => (string) ($record['idcliente'] ?? ''),
                        'rut' => $rutForQuery,
                        'nombre' => (string) ($record['nombre'] ?? ''),
                        'direccion' => (string) ($record['direccion'] ?? ''),
                        'servicio' => $service,
                        'mes' => $month,
                        'ano' => $year,
                        'amount' => $amount,
                        'amount_display' => $amountDisplay !== '' ? $amountDisplay : (string) $amount,
                        'payment_methods' => $paymentAvailability,
                        'webpay_enabled' => $paymentAvailability['webpay'],
                        'bcoestado_enabled' => $paymentAvailability['bcoestado'],
                        'zumpago_enabled' => $paymentAvailability['zumpago'],
                        'flow_enabled' => $paymentAvailability['flow'],
                        'mercadopago_enabled' => $paymentAvailability['mercadopago'],
                    ];
                }

                if ($cacheKey !== null) {
                    $this->storeCache($cacheKey, $debts);
                }

                return $debts;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        return [];
    }

    public function getLastCustomerName(): ?string
    {
        $name = $this->lastCustomerName;
        if ($name === null) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function callSoapEndpoint(string $endpoint, string $rut): array
    {
        $errors = [];

        /* Primer intento (SoapClient/NuSOAP) pausado temporalmente; dejamos sólo la consulta raw con el RUT. */

        $rawResult = $this->callRawSoap($endpoint, $rut);
        if ($rawResult !== null) {
            return $rawResult;
        }

        $message = 'No fue posible consultar la deuda.';
        if (!empty($errors)) {
            $message .= ' Detalles: ' . implode(' | ', array_filter($errors));
        }

        throw new RuntimeException($message);
    }

    private function payloadContainsReturnField(mixed $payload): bool
    {
        if (is_array($payload)) {
            return array_key_exists('return', $payload) || array_key_exists('ObtenerDeudaResult', $payload);
        }

        if (is_object($payload)) {
            return isset($payload->return) || isset($payload->ObtenerDeudaResult);
        }

        return false;
    }

    private function normalizePayload(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (is_bool($payload)) {
            return null;
        }

        if (is_string($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            $keys = ['return', 'ObtenerDeudaResult'];
            foreach ($keys as $key) {
                if (array_key_exists($key, $payload)) {
                    return $this->normalizePayload($payload[$key]);
                }
            }

            if (count($payload) === 1) {
                $value = reset($payload);
                return $this->normalizePayload($value);
            }

            return null;
        }

        if (is_object($payload)) {
            $keys = ['return', 'ObtenerDeudaResult'];
            foreach ($keys as $key) {
                if (isset($payload->{$key})) {
                    return $this->normalizePayload($payload->{$key});
                }
            }

            if (isset($payload->enc_value)) {
                return $this->normalizePayload($payload->enc_value);
            }

            if (method_exists($payload, '__toString')) {
                return (string) $payload;
            }

            return null;
        }

        if (is_scalar($payload)) {
            return (string) $payload;
        }

        return null;
    }

    private function extractPayloadFromSoapEnvelope(string $envelope): ?string
    {
        if (trim($envelope) === '') {
            return null;
        }

        if (stripos($envelope, '<return') === false && stripos($envelope, '<ObtenerDeudaResult') === false) {
            return null;
        }

        $patterns = [
            '/<return[^>]*><!\[CDATA\[(.*?)\]\]><\/return>/is',
            '/<return[^>]*>(.*?)<\/return>/is',
            '/<ObtenerDeudaResult[^>]*><!\[CDATA\[(.*?)\]\]><\/ObtenerDeudaResult>/is',
            '/<ObtenerDeudaResult[^>]*>(.*?)<\/ObtenerDeudaResult>/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $envelope, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Attempt a raw SOAP request (without relying on the WSDL) as a last resort.
     *
     * @return array<string, mixed>|null
     */
    private function callRawSoap(string $endpoint, string $rut): ?array
    {
        $serviceUrl = $this->buildServiceUrl($endpoint);

        if ($serviceUrl === '') {
            return null;
        }

        $envelope = $this->buildSoapEnvelope($rut);
        $soapAction = $this->buildSoapAction($endpoint);
        $headers = [
            'Content-Type: text/xml;charset=UTF-8',
            'SOAPAction: "' . $soapAction . '"',
            'Content-Length: ' . strlen($envelope),
        ];

        $this->debugLog('DebtService.rawSoapRequest', [
            'endpoint' => $endpoint,
            'service_url' => $serviceUrl,
            'rut' => $rut,
            'headers' => $headers,
            'envelope_preview' => mb_substr($envelope, 0, 400),
        ]);

        $response = null;
        $httpCode = null;
        $error = null;

        if (function_exists('curl_init')) {
            // cURL disponible: lo usamos para controlar timeouts y headers con precisión.
            $ch = curl_init($serviceUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch) ?: 'Error desconocido al ejecutar cURL';
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $envelope,
                    'timeout' => 20,
                ],
            ]);

            $response = @file_get_contents($serviceUrl, false, $context);
            if ($response === false) {
                $error = 'No fue posible ejecutar la solicitud SOAP con stream context.';
            }
            if (isset($http_response_header[0]) && preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0], $matches)) {
                $httpCode = (int) $matches[1];
            }
        }

        if ($response === false || $response === null) {
            $this->debugLog('DebtService.rawSoapError', [
                'endpoint' => $endpoint,
                'service_url' => $serviceUrl,
                'rut' => $rut,
                'error' => $error,
                'http_code' => $httpCode,
            ]);

            return null;
        }

        if ($httpCode !== null && $httpCode >= 400) {
            $this->debugLog('DebtService.rawSoapHttpError', [
                'endpoint' => $endpoint,
                'service_url' => $serviceUrl,
                'rut' => $rut,
                'http_code' => $httpCode,
                'response_preview' => mb_substr($response, 0, 200),
            ]);

            return null;
        }

        $extracted = $this->extractPayloadFromSoapEnvelope($response);
        // Guardamos tanto el payload limpio como la respuesta completa para depurar.
        $payload = $extracted !== null && trim($extracted) !== '' ? $extracted : $response;
        $sanitizedPayload = $this->sanitizePayloadXml($payload);

        $this->debugLog('DebtService.rawSoapSuccess', [
            'endpoint' => $endpoint,
            'service_url' => $serviceUrl,
            'rut' => $rut,
            'http_code' => $httpCode,
            'payload_is_envelope' => $extracted === null,
        ]);

        return [
            'transport' => 'raw_soap',
            'payload' => $sanitizedPayload,
            'raw_response' => $response,
            'http_status' => $httpCode,
        ];
    }

    private function buildServiceUrl(string $endpoint): string
    {
        $trimmed = trim($endpoint);

        if ($trimmed === '') {
            return '';
        }

        if (str_contains($trimmed, '?')) {
            return substr($trimmed, 0, strpos($trimmed, '?'));
        }

        return $trimmed;
    }

    private function resolveCacheKey(string $rut): ?string
    {
        $normalized = \normalize_rut($rut);
        if ($normalized !== '') {
            return $normalized;
        }

        $compact = preg_replace('/\s+/', '', strtoupper((string) $rut));

        return $compact !== '' ? $compact : null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function getCachedDebts(string $cacheKey): ?array
    {
        if (!$this->cacheEnabled || $this->cacheTtlSeconds <= 0 || $cacheKey === '') {
            return null;
        }

        if (array_key_exists($cacheKey, $this->runtimeCache)) {
            return $this->runtimeCache[$cacheKey];
        }

        $path = $this->cacheDirectory . '/' . sha1($cacheKey) . '.json';
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload) || !array_key_exists('debts', $payload) || !isset($payload['expires_at'])) {
            return null;
        }

        if ((int) $payload['expires_at'] < time()) {
            @unlink($path);
            return null;
        }

        $debts = $payload['debts'];
        if (!is_array($debts)) {
            return null;
        }

        $this->runtimeCache[$cacheKey] = $debts;

        return $debts;
    }

    private function storeCache(string $cacheKey, array $debts): void
    {
        if (!$this->cacheEnabled || $this->cacheTtlSeconds <= 0 || $cacheKey === '') {
            return;
        }

        $this->runtimeCache[$cacheKey] = $debts;

        $payload = json_encode([
            'rut' => $cacheKey,
            'stored_at' => time(),
            'expires_at' => time() + $this->cacheTtlSeconds,
            'debts' => $debts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $path = $this->cacheDirectory . '/' . sha1($cacheKey) . '.json';
        @file_put_contents($path, $payload, LOCK_EX);
    }

    public function clearCacheForRut(string $rut): void
    {
        $cacheKey = $this->resolveCacheKey($rut);
        if ($cacheKey === null) {
            return;
        }

        unset($this->runtimeCache[$cacheKey]);

        if (!$this->cacheEnabled || $this->cacheTtlSeconds <= 0 || $cacheKey === '') {
            return;
        }

        $path = $this->cacheDirectory . '/' . sha1($cacheKey) . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function buildSoapAction(string $endpoint): string
    {
        $url = $this->buildServiceUrl($endpoint);

        if ($url === '') {
            return 'ObtenerDeuda';
        }

        return rtrim($url, '/') . '/ObtenerDeuda';
    }

    private function buildSoapEnvelope(string $rut): string
    {
        $escapedRut = htmlspecialchars($rut, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="http://ws.homenet.cl">
   <soapenv:Header/>
   <soapenv:Body>
      <ws:ObtenerDeuda soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
         <Rut_Cliente xsi:type="xsd:string">{$escapedRut}</Rut_Cliente>
      </ws:ObtenerDeuda>
   </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(string $response): array
    {
        $response = $this->sanitizePayloadXml($response);
        $xml = $this->loadXml($response);

        if ($xml !== null) {
            $records = [];

            $entries = $xml->xpath('//datos');
            if ($entries !== false && !empty($entries)) {
                foreach ($entries as $entry) {
                    if (!($entry instanceof SimpleXMLElement)) {
                        continue;
                    }

                    $record = [];
                    foreach ($entry->children() as $child) {
                        $tag = strtolower($child->getName());
                        $record[$tag] = trim((string) $child);
                    }

                    if (!empty($record)) {
                        $records[] = $record;
                    }
                }

                if (!empty($records)) {
                    return $records;
                }
            }

            $legacy = $xml->datos ?? [];
            $legacy = json_decode(json_encode($legacy), true) ?? [];

            if (isset($legacy['idcliente'])) {
                $legacy = [$legacy];
            }

            if (is_array($legacy) && !empty($legacy)) {
                return $legacy;
            }
        }

        return $this->parseLegacyPayload($response);
    }

    private function sanitizePayloadXml(string $payload): string
    {
        if ($payload === '') {
            return $payload;
        }

        $payload = html_entity_decode($payload, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $payload = preg_replace('/,\s*</', '<', $payload) ?? $payload;
        $payload = preg_replace('/<\/(\w+)>\s*,/', '</$1>', $payload) ?? $payload;

        if (preg_match('/^<cliente>/i', $payload) === 1) {
            $payload = '<root>' . $payload . '</root>';
        }

        $payload = preg_replace('/&(?!(?:amp|lt|gt|quot|apos);)/', '&amp;', $payload) ?? $payload;

        return $payload;
    }

    private function loadXml(string $payload): ?SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $normalized = $this->ensureUtf8($payload);
        $xml = simplexml_load_string($normalized);
        libxml_clear_errors();

        if ($xml === false) {
            return null;
        }

        return $xml;
    }

    private function normalizeAmount(string $raw): int
    {
        $digits = preg_replace('/[^\d]/', '', $raw);

        if ($digits === null || $digits === '') {
            return 0;
        }

        return (int) $digits;
    }

    private function payloadIndicatesInvalidRut(string $payload): bool
    {
        if ($payload === '') {
            return false;
        }

        return stripos($payload, 'RUT NO EXISTE') !== false;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, bool|null>
     */
    private function extractPaymentAvailability(array $record): array
    {
        $methodCandidates = [
            'webpay' => ['webpay'],
            'bcoestado' => ['bcoestado', 'bancoestado'],
            'zumpago' => ['zumpago'],
            'flow' => ['flow'],
            'mercadopago' => ['mercadopago', 'mercado_pago'],
        ];

        $availability = [];

        foreach ($methodCandidates as $method => $candidates) {
            $value = null;

            foreach ($candidates as $candidate) {
                $key = strtolower($candidate);
                if (array_key_exists($key, $record)) {
                    $value = $record[$key];
                    break;
                }
            }

            $availability[$method] = $this->interpretBooleanFlag($value);
        }

        return $availability;
    }

    private function interpretBooleanFlag(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value) && $value !== '') {
            if (is_numeric($value)) {
                return (int) $value !== 0;
            }

            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }

            $truthy = ['true', 'yes', 'si', 'habilitado', 'on', 'available'];
            $falsy = ['false', 'no', 'off', 'inhabilitado'];

            if (in_array($normalized, $truthy, true)) {
                return true;
            }

            if (in_array($normalized, $falsy, true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function pickRecordValue(array $record, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $key = strtolower($candidate);

            if (array_key_exists($key, $record)) {
                $value = trim((string) $record[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function captureCustomerName(array $record): void
    {
        if ($this->lastCustomerName !== null) {
            return;
        }

        $name = $this->pickRecordValue($record, ['nombre', 'cliente', 'name']);
        if ($name !== '') {
            $this->lastCustomerName = $name;
        }
    }

    private function debugLog(string $message, array $context = []): void
    {
        try {
            $payload = sprintf(
                '[%s] %s %s%s',
                date('Y-m-d H:i:s'),
                $message,
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PHP_EOL
            );

            $logPath = __DIR__ . '/../logs/debt.log';
            file_put_contents($logPath, $payload, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $exception) {
            // Ignore logging errors to avoid breaking the main flow.
        }
    }

    /**
     * Attempt to sanitize legacy ISO-8859-1 payloads to UTF-8.
     */
    private function ensureUtf8(string $payload): string
    {
        if ($payload === '') {
            return $payload;
        }

        if (function_exists('mb_detect_encoding') && mb_detect_encoding($payload, 'UTF-8', true) !== false) {
            return $payload;
        }

        $converted = function_exists('iconv')
            ? @iconv('ISO-8859-1', 'UTF-8//IGNORE', $payload)
            : false;

        return $converted !== false ? $converted : $payload;
    }

    /**
     * Fallback parser for legacy comma-separated payloads.
     */
    private function parseLegacyPayload(string $payload): array
    {
        $payload = $this->sanitizePayloadXml($payload);

        if ($payload === '' || stripos($payload, '<') === false) {
            return [];
        }

        // Try to extract each <datos>...</datos> block.
        preg_match_all('/<datos>(.*?)<\/datos>/is', $payload, $matches);
        $blocks = $matches[1] ?? [];

        if (empty($blocks)) {
            $blocks = [$payload];
        }

        $records = [];

        foreach ($blocks as $block) {
            $record = [];
            preg_match_all('/<(\w+)>([^<]*)<\/\1>/', $block, $fields, PREG_SET_ORDER);

            foreach ($fields as $field) {
                $tag = strtolower($field[1]);
                $value = trim($field[2]);
                $record[$tag] = $value;
            }

            if (!empty($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
