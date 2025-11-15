<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Transbank\Webpay\Configuration;
use Transbank\Webpay\Webpay;

class WebpayNormalService
{
    private readonly object $transaction;
    private readonly array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->transaction = $this->buildTransaction($config);
    }

    /**
     * @return array{url: string, token: string}
     */
    public function initTransaction(
        int $amount,
        string $buyOrder,
        string $sessionId,
        string $returnUrl,
        string $finalUrl
    ): array {
        $response = $this->transaction->initTransaction(
            $amount,
            $buyOrder,
            $sessionId,
            $returnUrl,
            $finalUrl
        );

        $logPath = __DIR__ . '/../../app/logs/webpay.log';
        $rawDump = print_r($response, true);
        error_log('[debug] Webpay initTransaction raw response ' . $rawDump . PHP_EOL, 3, $logPath);

        return [
            'url' => $response->url,
            'token' => $response->token,
        ];
    }

    public function getTransactionResult(string $token): object
    {
        return $this->transaction->getTransactionResult($token);
    }

    public function acknowledgeTransaction(string $token): void
    {
        $this->transaction->acknowledgeTransaction($token);
    }

    private function buildTransaction(array $config): object
    {
        $environment = strtoupper((string) ($config['environment'] ?? ''));

        if ($environment === 'INTEGRACION' || $environment === 'INTEGRATION') {
            /** @var object */
            $transaction = (new Webpay(Configuration::forTestingWebpayPlusNormal()))
                ->getNormalTransaction();

            return $transaction;
        }

        $privateKeyPath = (string) ($config['private_key_path'] ?? '');
        $publicCertPath = (string) ($config['public_cert_path'] ?? '');

        if (!is_file($privateKeyPath) || !is_readable($privateKeyPath)) {
            throw new RuntimeException('No se encontró la llave privada de Webpay.');
        }

        if (!is_file($publicCertPath) || !is_readable($publicCertPath)) {
            throw new RuntimeException('No se encontró el certificado público de Webpay.');
        }

        $configuration = new Configuration();
        $configuration->setCommerceCode((string) ($config['commerce_code'] ?? ''));
        $configuration->setPrivateKey(file_get_contents($privateKeyPath));
        $configuration->setPublicCert(file_get_contents($publicCertPath));
        $configuration->setEnvironment($environment !== '' ? $environment : 'PRODUCCION');

        /** @var object */
        $transaction = (new Webpay($configuration))->getNormalTransaction();

        return $transaction;
    }
}
