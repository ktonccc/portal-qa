<?php

declare(strict_types=1);

return [
    // Cambia este valor a 'sandbox' cuando quieras operar con las llaves de prueba.
    'environment' => 'production',
    'default_company_id' => null,

    'credentials' => [
        'production' => [
            // 'public_key' => 'APP_USR-8eb7aa72-7434-440a-8496-40314c0ecde6',
            'public_key' => 'APP_USR-b77ae97f-9482-488f-8364-74b0e1eb5bb5',
            // 'access_token' => 'APP_USR-7789638414038630-111010-1c0392114c946c76f738cbfdcc9767e2-2977405996',
            'access_token' => 'APP_USR-3628270628318968-111010-05b69554f63d7492095eeeb623240a30-2977231622',

        ],
        'sandbox' => [
            'public_key' => 'APP_USR-82517690-dd11-4564-a4b9-238baf8f8d87',
            'access_token' => 'APP_USR-4899113447509925-111020-672180f6250933451e0b9c0c80f36d08-2980326481',
        ],
    ],

    'statement_descriptor' => 'HOMENET',
    'notification_url' => 'https://pagos2.homenet.cl/mercadopago_process.php',
    'return_urls' => [
        'success' => 'https://pagos2.homenet.cl/mercadopago_return.php',
        'failure' => 'https://pagos2.homenet.cl/mercadopago_return.php',
        'pending' => 'https://pagos2.homenet.cl/mercadopago_return.php',
    ],
    'companies' => [
        '764430824' => [
            'label' => 'Padre Las Casas',
        ],
        '765316081' => [
            'label' => 'Villarrica',
            'credentials' => [
                'production' => [
                    'public_key' => '',
                    'access_token' => '',
                ],
            ],
        ],
        '76734662K' => [
            'label' => 'Gorbea',
            'credentials' => [
                'production' => [
                    'public_key' => '',
                    'access_token' => '',
                ],
            ],
        ],
    ],
];
