<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class ZumpagoRedirectService
{
    private string $companyCode;
    private string $xmlKey;
    private string $verificationKey;
    private string $initializationVector;
    private string $endpointUrl;
    private string $paymentMethods;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->companyCode = $this->requireValue($config, 'company_code');
        $this->xmlKey = $this->requireValue($config, 'xml_key');
        $this->verificationKey = $this->requireValue($config, 'verification_key');
        $this->initializationVector = $this->requireValue($config, 'iv');
        $this->endpointUrl = $this->resolveEndpointUrl($config);

        $paymentMethods = trim((string) ($config['payment_methods'] ?? ''));
        $this->paymentMethods = $paymentMethods !== '' ? $paymentMethods : '016';
    }

    /**
     * @param string[] $documentIds
     * @return array{
     *     endpoint:string,
     *     redirect_url:string,
     *     encrypted_xml:string,
     *     xml:string,
     *     transaction:array{id:string,date:string,time:string,verification_code:string}
     * }
     */
    public function createRedirectData(
        string $normalizedRut,
        int $totalAmount,
        array $documentIds,
        string $email
    ): array {
        if ($totalAmount <= 0) {
            throw new InvalidArgumentException('El monto total debe ser mayor a cero.');
        }

        $date = date('Ymd');
        $time = date('His');
        $transactionId = $this->generateTransactionId($normalizedRut, $documentIds);

        $padded = $this->padFields([
            'IdComercio' => $this->companyCode,
            'IdTransaccion' => $transactionId,
            'Fecha' => $date,
            'Hora' => $time,
            'MontoTotal' => (string) $totalAmount,
        ]);

        $verificationCode = $this->encrypt(
            implode('', $padded),
            $this->verificationKey,
            $this->initializationVector
        );

        $xml = $this->buildXml([
            'IdComercio' => $this->companyCode,
            'IdTransaccion' => $transactionId,
            'Fecha' => $date,
            'Hora' => $time,
            'MontoTotal' => (string) $totalAmount,
            'MediosPago' => $this->paymentMethods,
            'CodigoVerificacion' => $verificationCode,
        ]);

        $encryptedXml = $this->encrypt($xml, $this->xmlKey, $this->initializationVector);
        $redirectUrl = $this->buildRedirectUrl($encryptedXml);

        return [
            'endpoint' => $this->endpointUrl,
            'redirect_url' => $redirectUrl,
            'encrypted_xml' => $encryptedXml,
            'xml' => $xml,
            'transaction' => [
                'id' => $transactionId,
                'date' => $date,
                'time' => $time,
                'verification_code' => $verificationCode,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function resolveEndpointUrl(array $config): string
    {
        $environment = strtolower((string) ($config['environment'] ?? 'production'));
        $urls = (array) ($config['urls'] ?? []);

        $candidates = [
            $environment,
            $environment === 'production' ? 'prod' : null,
            $environment === 'certification' ? 'qa' : null,
            'production',
            'certification',
        ];

        foreach ($candidates as $key) {
            if ($key !== null && isset($urls[$key])) {
                $url = trim((string) $urls[$key]);
                if ($url !== '') {
                    return $this->stripQueryString($url);
                }
            }
        }

        throw new InvalidArgumentException('La configuración de Zumpago requiere una URL válida para el entorno seleccionado.');
    }

    /**
     * @param string[] $documentIds
     */
    private function generateTransactionId(string $normalizedRut, array $documentIds): string
    {
        $numericRut = preg_replace('/\D/', '', $normalizedRut) ?? '';
        $rutFragment = $numericRut !== '' ? substr($numericRut, -7) : '';

        $microTimestamp = (string) round(microtime(true) * 1000);
        $timestampFragment = substr($microTimestamp, -6);

        $candidate = $rutFragment . $timestampFragment;
        $candidate = str_pad($candidate, 13, '0', STR_PAD_LEFT);

        return substr($candidate, -13);
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,string>
     */
    private function padFields(array $fields): array
    {
        return [
            'IdComercio' => str_pad($fields['IdComercio'], 6, '0', STR_PAD_LEFT),
            'IdTransaccion' => str_pad($fields['IdTransaccion'], 13, '0', STR_PAD_LEFT),
            'Fecha' => str_pad($fields['Fecha'], 8, '0', STR_PAD_LEFT),
            'Hora' => str_pad($fields['Hora'], 6, '0', STR_PAD_LEFT),
            'MontoTotal' => str_pad($fields['MontoTotal'], 12, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @param array<string,string> $elements
     */
    private function buildXml(array $elements): string
    {
        $xmlParts = [
            '<?xml version="1.0" encoding="ISO-8859-1"?>',
            '<Envio>',
        ];

        foreach ($elements as $tag => $value) {
            $xmlParts[] = sprintf(
                '<%1$s>%2$s</%1$s>',
                $tag,
                $this->escapeXml($value)
            );
        }

        $xmlParts[] = '</Envio>';

        return implode('', $xmlParts);
    }

    private function buildRedirectUrl(string $encryptedXml): string
    {
        $separator = str_contains($this->endpointUrl, '?') ? '&' : '?';

        return $this->endpointUrl . $separator . 'xml=' . urlencode($encryptedXml);
    }

    private function encrypt(string $data, string $key, string $iv): string
    {
        $cipher = 'DES-EDE3';

        $payload = openssl_encrypt(
            $data,
            $cipher,
            $key,
            0,
            $iv
        );

        if ($payload === false) {
            throw new RuntimeException('No fue posible encriptar la información para Zumpago.');
        }

        return $payload;
    }

    private function stripQueryString(string $url): string
    {
        $position = strpos($url, '?');

        if ($position === false) {
            return $url;
        }

        return substr($url, 0, $position);
    }

    private function requireValue(array $config, string $key): string
    {
        $value = trim((string) ($config[$key] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('La configuración de Zumpago requiere el parámetro "%s".', $key));
        }

        return $value;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES, 'ISO-8859-1');
    }
}

