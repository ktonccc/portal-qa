<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class IngresarPagoService
{
    /**
     * @var array<string, string>
     */
    private const FIELD_TYPES = [
        'IdEmpresa' => 'xsd:string',
        'IdCliente' => 'xsd:int',
        'RutCliente' => 'xsd:string',
        'Mail' => 'xsd:string',
        'Recaudador' => 'xsd:string',
        'Canal' => 'xsd:string',
        'FechaPago' => 'xsd:string',
        'FechaContable' => 'xsd:string',
        'Mes' => 'xsd:int',
        'Ano' => 'xsd:int',
        'Monto' => 'xsd:int',
        'MontoFlow' => 'xsd:int',
    ];

    public function __construct(
        private readonly string $wsdlEndpoint
    ) {
        if (trim($this->wsdlEndpoint) === '') {
            throw new RuntimeException('Debe configurar el endpoint WSDL para IngresarPago.');
        }
    }

    public function getWsdlEndpoint(): string
    {
        return $this->wsdlEndpoint;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     payload: array<string, mixed>,
     *     envelope: string,
     *     response: string|null,
     *     http_status: int|null
     * }
     */
    public function submit(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);
        if (empty($normalized)) {
            throw new RuntimeException('No se proporcionaron datos válidos para IngresarPago.');
        }

        $envelope = $this->buildSoapEnvelope($normalized);
        $serviceUrl = $this->buildServiceUrl($this->wsdlEndpoint);

        if ($serviceUrl === '') {
            throw new RuntimeException('No fue posible determinar la URL del servicio IngresarPago.');
        }

        $soapAction = $this->buildSoapAction($serviceUrl);
        $headers = [
            'Content-Type: text/xml;charset=UTF-8',
            'SOAPAction: "' . $soapAction . '"',
            'Content-Length: ' . strlen($envelope),
        ];

        [$response, $httpCode, $error] = $this->dispatch($serviceUrl, $envelope, $headers);

        if ($response === null) {
            $message = 'No fue posible comunicarse con IngresarPago.';
            if ($error !== null && $error !== '') {
                $message .= ' Detalle: ' . $error;
            }
            throw new RuntimeException($message);
        }

        if ($httpCode !== null && $httpCode >= 400) {
            throw new RuntimeException(sprintf('IngresarPago respondió con HTTP %d.', $httpCode));
        }

        return [
            'payload' => $normalized,
            'envelope' => $envelope,
            'response' => $response,
            'http_status' => $httpCode,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function previewEnvelope(array $payload): string
    {
        $normalized = $this->normalizePayload($payload);

        return $this->buildSoapEnvelope($normalized);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];

        foreach (self::FIELD_TYPES as $field => $type) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($value === null) {
                continue;
            }

            if ($type === 'xsd:int') {
                if ($value === '') {
                    continue;
                }
                $normalized[$field] = (int) $value;
                continue;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }

            $normalized[$field] = $stringValue;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSoapEnvelope(array $payload): string
    {
        $elements = [];

        foreach (self::FIELD_TYPES as $field => $type) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            $elements[] = $this->formatElement($field, $value, $type);
        }

        $body = implode("\n         ", $elements);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="http://ws.homenet.cl">
   <soapenv:Header/>
   <soapenv:Body>
      <ws:IngresarPago soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
         {$body}
      </ws:IngresarPago>
   </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function formatElement(string $name, mixed $value, string $type): string
    {
        if ($type === 'xsd:int') {
            $value = (int) $value;
        }

        $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return sprintf('<%1$s xsi:type="%2$s">%3$s</%1$s>', $name, $type, $escaped);
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

    private function buildSoapAction(string $serviceUrl): string
    {
        return rtrim($serviceUrl, '/') . '/IngresarPago';
    }

    /**
     * @param array<int, string> $headers
     * @return array{0: ?string, 1: ?int, 2: ?string}
     */
    private function dispatch(string $serviceUrl, string $envelope, array $headers): array
    {
        $response = null;
        $httpCode = null;
        $error = null;

        if (function_exists('curl_init')) {
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

        if ($response === false) {
            $response = null;
        }

        return [$response, $httpCode, $error];
    }
}
