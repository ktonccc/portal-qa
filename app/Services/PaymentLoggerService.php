<?php

declare(strict_types=1);

namespace App\Services;

use nusoap_client;
use RuntimeException;

class PaymentLoggerService
{
    public function __construct(
        private readonly string $wsdlEndpoint
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function log(array $payload): void
    {
        $client = new nusoap_client($this->wsdlEndpoint, 'wsdl');
        $client->call('RetornoWebPay', $payload);

        if ($client->fault) {
            $message = $client->faultstring ?? 'SOAP fault';
            throw new RuntimeException("No se pudo registrar la transacción Webpay: {$message}");
        }
    }
}
