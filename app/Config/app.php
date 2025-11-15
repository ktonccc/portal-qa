<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Portal Pagos HomeNet',
    ],
    'recaptcha' => [
        'site_key' => '6LcWTeQUAAAAAJBANqfAeSXoUfawNXwWM8Pas_by',
    ],
    'services' => [
        // Endpoint that returns the debt list for a given customer RUT.
        'debt_wsdl' => 'http://ws.homenet.cl/Test_HN_2025.php?wsdl',
        'debt_wsdl_fallback' => 'http://ws.homenet.cl/Test_HN_2025.php?wsdl',
        'debt_cache' => [
            'enabled' => (function () {
                $value = getenv('DEBT_CACHE_ENABLED');
                if ($value === false) {
                    return false;
                }

                $normalized = strtolower(trim((string) $value));

                return !in_array($normalized, ['', '0', 'false', 'off', 'no'], true);
            })(),
            'ttl' => max(0, (int) (getenv('DEBT_CACHE_TTL') ?: 90)),
        ],
        // Endpoint used to log the Webpay transaction result.
        'payment_logger_wsdl' => 'http://ws.homenet.cl/webpay_source.php?wsdl',
        // Endpoint used to registrar pagos confirmados via Flow en el sistema legacy.
        'ingresar_pago_wsdl' => getenv('FLOW_INGRESAR_PAGO_WSDL') ?: 'http://ws.homenet.cl/Test_HN_2025.php?wsdl',
        'ingresar_pago_wsdl_villarrica' => getenv('FLOW_INGRESAR_PAGO_WSDL_VILLARRICA') ?: 'http://ws.homenet.cl/Test_HN_2025.php?wsdl',
        'ingresar_pago_wsdl_gorbea' => getenv('FLOW_INGRESAR_PAGO_WSDL_GORBEA') ?: 'http://ws.homenet.cl/Test_HN_2025.php?wsdl',
    ],
    'webpay' => [
        'commerce_code' => '597035425993',
        'private_key_path' => __DIR__ . '/../../597035425993.key',
        'public_cert_path' => __DIR__ . '/../../597035425993.crt',
        'environment' => 'PRODUCCION',
        'return_url' => 'https://pagos2.homenet.cl/return.php',
        'final_url' => 'https://pagos2.homenet.cl/final.php',
    ],
    'flow' => (function () {
        $credentialsPath = __DIR__ . '/flow_credentials.php';
        $credentials = [];

        if (is_file($credentialsPath)) {
            /** @var array<string, mixed> $loaded */
            $loaded = require $credentialsPath;
            if (is_array($loaded)) {
                $credentials = $loaded;
            }
        }

        $environmentValue = (string) ($credentials['environment'] ?? (getenv('FLOW_ENVIRONMENT') ?: 'production'));
        $environmentKey = strtolower($environmentValue);

        $selectValue = static function (mixed $value) use ($environmentKey, $environmentValue): string {
            if (is_array($value)) {
                if (array_key_exists($environmentKey, $value)) {
                    return (string) $value[$environmentKey];
                }

                if (array_key_exists($environmentValue, $value)) {
                    return (string) $value[$environmentValue];
                }

                return '';
            }

            if ($value === null) {
                return '';
            }

            return (string) $value;
        };

        $resolveCredentials = static function (array $source) use ($selectValue, $environmentKey, $environmentValue): array {
            $apiKey = $source['api_key'] ?? null;
            $secretKey = $source['secret_key'] ?? null;

            if (isset($source['credentials']) && is_array($source['credentials'])) {
                $envCredentials = $source['credentials'][$environmentKey]
                    ?? $source['credentials'][$environmentValue]
                    ?? null;

                if ($envCredentials === null) {
                    foreach ($source['credentials'] as $entry) {
                        if (is_array($entry)) {
                            $envCredentials = $entry;
                            break;
                        }
                    }
                }

                if (is_array($envCredentials)) {
                    if (array_key_exists('api_key', $envCredentials)) {
                        $apiKey = $envCredentials['api_key'];
                    }
                    if (array_key_exists('secret_key', $envCredentials)) {
                        $secretKey = $envCredentials['secret_key'];
                    }
                }
            }

            return [
                'api_key' => $selectValue($apiKey),
                'secret_key' => $selectValue($secretKey),
            ];
        };

        $defaultCredentials = $resolveCredentials([
            'api_key' => $credentials['api_key'] ?? (getenv('FLOW_API_KEY') ?: ''),
            'secret_key' => $credentials['secret_key'] ?? (getenv('FLOW_SECRET_KEY') ?: ''),
            'credentials' => $credentials['credentials'] ?? null,
        ]);

        $normalizeCompanyId = static function (mixed $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            $normalized = preg_replace('/[^0-9K]/i', '', $value);

            return strtoupper($normalized ?? '');
        };

        $rawCompanies = (array) ($credentials['companies'] ?? []);
        $companies = [];

        foreach ($rawCompanies as $key => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $resolved = $resolveCredentials($profile);
            $companyId = $normalizeCompanyId($profile['company_id'] ?? $profile['idempresa'] ?? $key);
            if ($companyId === '') {
                continue;
            }

            $label = trim((string) ($profile['label'] ?? ''));
            $prepared = $profile;
            unset($prepared['credentials']);
            $prepared['company_id'] = $companyId;
            $prepared['label'] = $label !== '' ? $label : $companyId;

            if ($resolved['api_key'] !== '') {
                $prepared['api_key'] = $resolved['api_key'];
            } else {
                unset($prepared['api_key']);
            }

            if ($resolved['secret_key'] !== '') {
                $prepared['secret_key'] = $resolved['secret_key'];
            } else {
                unset($prepared['secret_key']);
            }

            $companies[$companyId] = $prepared;
        }

        $defaultCompanyId = $normalizeCompanyId($credentials['default_company_id'] ?? null);

        return [
            'api_key' => $defaultCredentials['api_key'],
            'secret_key' => $defaultCredentials['secret_key'],
            'environment' => $environmentValue,
            'urls' => [
                'production' => 'https://www.flow.cl/api',
                'sandbox' => 'https://sandbox.flow.cl/api',
            ],
            'currency' => 'CLP',
            'payment_method' => 9,
            'timeout' => 900,
            'url_confirmation' => 'https://pagos2.homenet.cl/flow_confirm.php',
            'url_return' => 'https://pagos2.homenet.cl/flow_return.php',
            'default_company_id' => $defaultCompanyId !== '' ? $defaultCompanyId : null,
            'companies' => $companies,
        ];
    })(),
    'zumpago' => [
        'default_company_id' => '765316081',
        // Configuración por defecto (WAM BP). Se mantiene para compatibilidad.
        'company_code' => '000039',
        'xml_key' => 'Sysasap2014_Zumpago_2014',
        'verification_key' => '501D5913B4591E81BE611E2E',
        'iv' => '12345678',
        'payment_methods' => '016',
        // production | certification
        'environment' => 'certification',
        'urls' => [
            'production' => 'https://www.zumpago.cl/inicio/pagar_cuentas.aspx',
            'certification' => 'http://20.157.19.107:8091/BPZumPago/pago.aspx',
        ],
        // Rutas que debemos informar a Zumpago por ambiente.
        'response_url' => 'https://pagos2.homenet.cl/zumpago/response.php',
        'notification_url' => 'https://pagos2.homenet.cl/zumpago/notify.php',
        'cancellation_url' => 'https://pagos2.homenet.cl/zumpago/cancel.php',
        // Configuración específica por empresa (IdEmpresa).
        'companies' => [
            '765316081' => [
                'label' => 'WAM BP',
                'company_code' => '000039',
                'xml_key' => 'Sysasap2014_Zumpago_2014',
                'verification_key' => '501D5913B4591E81BE611E2E',
                'iv' => '12345678',
                'payment_methods' => '016',
                'urls' => [
                    'production' => 'https://www.zumpago.cl/inicio/pagar_cuentas.aspx',
                    'certification' => 'http://20.157.19.107:8091/BPZumPago/pago.aspx',
                ],
            ],
            '76734662K' => [
                'label' => 'FULLNET BP',
                'company_code' => '000042',
                'xml_key' => 'Sysasap2014_Zumpago_2014',
                'verification_key' => '1314FCC1A6ECD7A6EB72B456',
                'iv' => '12345678',
                'payment_methods' => '016',
                'urls' => [
                    'production' => 'https://www.zumpago.cl/inicio/pagar_cuentas.aspx',
                    'certification' => 'http://20.157.19.107:8091/BPZumPago/pago.aspx',
                ],
            ],
            '764430824' => [
                'label' => 'FRATA BP',
                'company_code' => '000043',
                'xml_key' => 'Sysasap2014_Zumpago_2014',
                'verification_key' => '3C57695858A8BCA514C611DD',
                'iv' => '12345678',
                'payment_methods' => '016',
                'urls' => [
                    'production' => 'https://www.zumpago.cl/inicio/pagar_cuentas.aspx',
                    'certification' => 'http://20.157.19.107:8091/BPZumPago/pago.aspx',
                ],
            ],
        ],
    ],
    'mercadopago' => (function () {
        $envOverrides = [
            'public_key' => trim((string) (getenv('MERCADOPAGO_PUBLIC_KEY') ?: '')),
            'access_token' => trim((string) (getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '')),
            'base_url' => trim((string) (getenv('MERCADOPAGO_BASE_URL') ?: '')),
            'notification_url' => trim((string) (getenv('MERCADOPAGO_NOTIFICATION_URL') ?: '')),
            'statement_descriptor' => trim((string) (getenv('MERCADOPAGO_STATEMENT_DESCRIPTOR') ?: '')),
            'auto_return' => trim((string) (getenv('MERCADOPAGO_AUTO_RETURN') ?: '')),
            'environment' => trim((string) (getenv('MERCADOPAGO_ENV') ?: '')),
        ];

        $returnOverrides = [
            'success' => trim((string) (getenv('MERCADOPAGO_RETURN_URL_SUCCESS') ?: '')),
            'failure' => trim((string) (getenv('MERCADOPAGO_RETURN_URL_FAILURE') ?: '')),
            'pending' => trim((string) (getenv('MERCADOPAGO_RETURN_URL_PENDING') ?: '')),
        ];

        $defaults = [
            'public_key' => $envOverrides['public_key'],
            'access_token' => $envOverrides['access_token'],
            'base_url' => $envOverrides['base_url'] ?: 'https://api.mercadopago.com',
            'notification_url' => $envOverrides['notification_url'],
            'statement_descriptor' => $envOverrides['statement_descriptor'] ?: 'HOMENET',
            'return_urls' => [
                'success' => $returnOverrides['success'] ?: 'https://pagos2.homenet.cl/mercadopago_return.php',
                'failure' => $returnOverrides['failure'] ?: 'https://pagos2.homenet.cl/mercadopago_return.php',
                'pending' => $returnOverrides['pending'] ?: 'https://pagos2.homenet.cl/mercadopago_return.php',
            ],
            'auto_return' => $envOverrides['auto_return'] ?: 'approved',
            'environment' => $envOverrides['environment'] ?: 'production',
        ];

        $credentialsPath = __DIR__ . '/mercadopago_credentials.php';
        if (is_file($credentialsPath)) {
            $fileConfig = require $credentialsPath;
            if (is_array($fileConfig)) {
                $defaults = array_replace_recursive($defaults, $fileConfig);
            }
        }

        $environmentValue = strtolower(trim((string) ($defaults['environment'] ?? '')));
        if (isset($defaults['credentials']) && is_array($defaults['credentials']) && !empty($defaults['credentials'])) {
            $credentialsByEnv = $defaults['credentials'];
            $selectedCredentials = $credentialsByEnv[$environmentValue]
                ?? $credentialsByEnv['production']
                ?? $credentialsByEnv[array_key_first($credentialsByEnv)] ?? null;

            if (is_array($selectedCredentials)) {
                $defaults = array_replace_recursive($defaults, $selectedCredentials);
            }

            unset($defaults['credentials']);
        }

        unset($defaults['environment']);

        foreach (['public_key', 'access_token', 'base_url', 'notification_url', 'statement_descriptor', 'auto_return'] as $key) {
            if (($envOverrides[$key] ?? '') !== '') {
                $defaults[$key] = $envOverrides[$key];
            }
        }

        foreach ($returnOverrides as $key => $value) {
            if ($value !== '') {
                $defaults['return_urls'][$key] = $value;
            }
        }

        $defaults['public_key'] = trim((string) ($defaults['public_key'] ?? ''));
        $defaults['access_token'] = trim((string) ($defaults['access_token'] ?? ''));
        $defaults['base_url'] = trim((string) ($defaults['base_url'] ?? '')) ?: 'https://api.mercadopago.com';
        $defaults['notification_url'] = trim((string) ($defaults['notification_url'] ?? ''));
        $defaults['statement_descriptor'] = trim((string) ($defaults['statement_descriptor'] ?? ''));
        $defaults['auto_return'] = trim((string) ($defaults['auto_return'] ?? ''));

        $returnUrls = $defaults['return_urls'] ?? [];
        if (!is_array($returnUrls)) {
            $returnUrls = [];
        }

        $defaults['return_urls'] = [
            'success' => trim((string) ($returnUrls['success'] ?? 'https://pagos2.homenet.cl/mercadopago_return.php')),
            'failure' => trim((string) ($returnUrls['failure'] ?? 'https://pagos2.homenet.cl/mercadopago_return.php')),
            'pending' => trim((string) ($returnUrls['pending'] ?? 'https://pagos2.homenet.cl/mercadopago_return.php')),
        ];

        $sharedConfig = $defaults;
        $normalizeCompanyId = static function (mixed $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            $normalized = preg_replace('/[^0-9K]/i', '', $value);

            return strtoupper($normalized ?? '');
        };

        $rawCompanies = (array) ($sharedConfig['companies'] ?? []);
        $defaultCompanyId = $normalizeCompanyId($sharedConfig['default_company_id'] ?? null);

        unset($sharedConfig['companies'], $sharedConfig['default_company_id']);

        $companies = [];
        foreach ($rawCompanies as $key => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $companyId = $normalizeCompanyId($profile['company_id'] ?? $profile['idempresa'] ?? $key);
            if ($companyId === '') {
                continue;
            }

            $profileData = $profile;
            if (isset($profileData['credentials']) && is_array($profileData['credentials']) && !empty($profileData['credentials'])) {
                $credentialsByEnv = $profileData['credentials'];
                $selectedCredentials = $credentialsByEnv[$environmentValue]
                    ?? $credentialsByEnv['production']
                    ?? $credentialsByEnv[array_key_first($credentialsByEnv)] ?? null;

                if (is_array($selectedCredentials)) {
                    $profileData = array_replace_recursive($profileData, $selectedCredentials);
                }

                unset($profileData['credentials']);
            }

            $label = trim((string) ($profileData['label'] ?? ''));
            $profileData['company_id'] = $companyId;
            $profileData['label'] = $label !== '' ? $label : $companyId;

            $companies[$companyId] = array_replace($sharedConfig, $profileData);
        }

        $sharedConfig['default_company_id'] = $defaultCompanyId !== '' ? $defaultCompanyId : null;
        $sharedConfig['companies'] = $companies;

        return $sharedConfig;
    })(),
];
