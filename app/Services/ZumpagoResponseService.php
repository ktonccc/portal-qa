<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class ZumpagoResponseService
{
    private const CIPHER = 'DES-EDE3';

    private string $xmlKey;
    private string $verificationKey;
    private string $initializationVector;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->xmlKey = $this->requireValue($config, 'xml_key');
        $this->verificationKey = $this->requireValue($config, 'verification_key');
        $this->initializationVector = $this->requireValue($config, 'iv');
    }

    /**
     * @return array{
     *     xml:string,
     *     data:array<string,string>,
     *     verification:array{raw:string,decrypted?:string,expected?:string,is_valid:bool,error?:string}|null
     * }
     */
    public function parseResponse(string $encryptedXml): array
    {
        $encryptedXml = trim($encryptedXml);
        if ($encryptedXml === '') {
            throw new InvalidArgumentException('La respuesta de Zumpago no incluyó el parámetro "xml".');
        }

        $xmlString = $this->decrypt($encryptedXml, $this->xmlKey);
        $normalizedXml = $this->normalizeXmlEncoding($xmlString);

        $data = $this->parseXmlToArray($normalizedXml);

        $verification = null;
        $verificationCode = trim($data['CodigoVerificacion'] ?? '');

        if ($verificationCode !== '') {
            $verification = $this->analyzeVerificationCode($verificationCode, $data);
        }

        return [
            'xml' => $normalizedXml,
            'data' => $data,
            'verification' => $verification,
        ];
    }

    /**
     * @return array{raw:string,decrypted?:string,expected?:string,is_valid:bool,error?:string}
     */
    private function analyzeVerificationCode(string $verificationCode, array $data): array
    {
        $result = [
            'raw' => $verificationCode,
            'is_valid' => false,
        ];

        try {
            $decrypted = $this->decrypt($verificationCode, $this->verificationKey);
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
            return $result;
        }

        $expected = $this->buildVerificationString($data);

        $result['decrypted'] = $decrypted;
        if ($expected !== '') {
            $result['expected'] = $expected;
            $result['is_valid'] = hash_equals($expected, $decrypted);
        }

        return $result;
    }

    private function buildVerificationString(array $data): string
    {
        $fields = [
            'IdComercio' => [6, '0'],
            'IdTransaccion' => [13, '0'],
            'Fecha' => [8, '0'],
            'Hora' => [6, '0'],
            'MontoTotal' => [12, '0'],
            'CodigoRespuesta' => [3, '0'],
            'FechaProcesamiento' => [14, '0'],
        ];

        $parts = [];

        foreach ($fields as $key => [$length, $pad]) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
                return '';
            }

            $parts[] = str_pad($value, $length, $pad, STR_PAD_LEFT);
        }

        return implode('', $parts);
    }

    /**
     * @return array<string,string>
     */
    private function parseXmlToArray(string $xml): array
    {
        if ($xml === '') {
            throw new RuntimeException('La respuesta de Zumpago está vacía.');
        }

        $internalErrors = libxml_use_internal_errors(true);
        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        } finally {
            libxml_use_internal_errors($internalErrors);
        }

        if ($element === false) {
            throw new RuntimeException('No fue posible interpretar la respuesta XML de Zumpago.');
        }

        $data = [];
        foreach ($element->children() as $child) {
            $data[$child->getName()] = trim((string) $child);
        }

        return $data;
    }

    private function normalizeXmlEncoding(string $xml): string
    {
        if ($xml === '') {
            return '';
        }

        $encoding = $this->detectXmlEncoding($xml);
        if ($encoding === null) {
            return $xml;
        }

        $upper = strtoupper($encoding);
        if ($upper === 'UTF-8') {
            return $xml;
        }

        $converted = @iconv($upper, 'UTF-8//IGNORE', $xml);
        if ($converted === false) {
            throw new RuntimeException('No fue posible convertir la respuesta XML de Zumpago a UTF-8.');
        }

        return $this->replaceXmlEncodingDeclaration($converted, $encoding, 'UTF-8');
    }

    private function detectXmlEncoding(string $xml): ?string
    {
        if (preg_match('/<\\?xml[^>]*encoding=["\']([^"\']+)["\']/i', $xml, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function replaceXmlEncodingDeclaration(string $xml, string $from, string $to): string
    {
        $pattern = sprintf(
            '/(<\\?xml[^>]*encoding=["\'])%s(["\'])/i',
            preg_quote($from, '/')
        );

        $replacement = '$1' . $to . '$2';

        return (string) preg_replace($pattern, $replacement, $xml, 1);
    }

    private function decrypt(string $payload, string $key): string
    {
        $iv = $this->resolveIv();

        $plain = openssl_decrypt(
            $payload,
            self::CIPHER,
            $key,
            0,
            $iv
        );

        if ($plain === false) {
            throw new RuntimeException('No fue posible desencriptar la información enviada por Zumpago.');
        }

        return $plain;
    }

    private function resolveIv(): string
    {
        $length = openssl_cipher_iv_length(self::CIPHER);

        if ($length === false) {
            throw new RuntimeException('No fue posible determinar la longitud esperada del vector de inicialización para Zumpago.');
        }

        if ($length === 0) {
            return '';
        }

        if (strlen($this->initializationVector) < $length) {
            throw new InvalidArgumentException('El IV configurado para Zumpago no tiene la longitud requerida.');
        }

        return substr($this->initializationVector, 0, $length);
    }

    private function requireValue(array $config, string $key): string
    {
        $value = trim((string) ($config[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('La configuración de Zumpago requiere el parámetro "%s".', $key));
        }

        return $value;
    }
}

