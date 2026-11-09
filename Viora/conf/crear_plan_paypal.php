
<?php

// Ejecutar solamente desde la computadora local.
// Eliminar este archivo después de crear el plan.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Acceso no permitido.');
}

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/paypal_config.php';

$baseUrl = $config['api_url'];

function paypalRequest(
    string $url,
    string $method,
    array $headers,
    ?array $body = null,
    ?array $credentials = null
): array {

    $curl = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] =
            json_encode($body, JSON_THROW_ON_ERROR);
    }

    if ($credentials !== null) {
        $options[CURLOPT_USERPWD] =
            $credentials['client_id'] . ':' .
            $credentials['client_secret'];
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new Exception($error);
    }

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $data = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        throw new Exception(
            "PayPal respondió HTTP $status: " .
            $response
        );
    }

    return is_array($data) ? $data : [];
}

try {

    // 1. Obtener token OAuth
    $curl = curl_init($baseUrl . '/v1/oauth2/token');

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD =>
            $config['client_id'] . ':' .
            $config['client_secret'],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: en_US'
        ],
        CURLOPT_POSTFIELDS =>
            'grant_type=client_credentials',
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        throw new Exception(curl_error($curl));
    }

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $tokenData = json_decode($response, true);

    if ($status !== 200 ||
        empty($tokenData['access_token'])) {
        throw new Exception(
            'No se pudo autenticar con PayPal Sandbox.'
        );
    }

    $token = $tokenData['access_token'];

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    // 2. Crear producto VIORA
    $producto = paypalRequest(
        $baseUrl . '/v1/catalogs/products',
        'POST',
        $headers,
        [
            'name' => 'VIORA',
            'description' =>
                'Plataforma de administración de condominios y propiedades',
            'type' => 'SERVICE',
            'category' => 'SOFTWARE'
        ]
    );

    $productId = $producto['id'] ?? null;

    if (!$productId) {
        throw new Exception(
            'PayPal no devolvió el Product ID.'
        );
    }

    // 3. Crear plan recurrente mensual
    $plan = paypalRequest(
        $baseUrl . '/v1/billing/plans',
        'POST',
        $headers,
        [
            'product_id' => $productId,
            'name' => 'VIORA Mensual',
            'description' =>
                'Suscripción mensual a VIORA por US$10',
            'status' => 'ACTIVE',
            'billing_cycles' => [
                [
                    'frequency' => [
                        'interval_unit' => 'MONTH',
                        'interval_count' => 1
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => '10.00',
                            'currency_code' => 'USD'
                        ]
                    ]
                ]
            ],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee' => [
                    'value' => '0',
                    'currency_code' => 'USD'
                ],
                'setup_fee_failure_action' => 'CONTINUE',
                'payment_failure_threshold' => 3
            ]
        ]
    );

    $planId = $plan['id'] ?? null;

    if (!$planId) {
        throw new Exception(
            'PayPal no devolvió el Plan ID.'
        );
    }

    echo "PRODUCTO Y PLAN CREADOS CORRECTAMENTE\n\n";
    echo "Producto: VIORA\n";
    echo "Product ID: " . $productId . "\n\n";
    echo "Plan: VIORA Mensual\n";
    echo "Precio: US$10.00 por mes\n";
    echo "Plan ID: " . $planId . "\n";

} catch (Throwable $e) {

    http_response_code(500);

    error_log(
        'Error creando plan PayPal: ' .
        $e->getMessage()
    );

    echo "No se pudo completar la operación.\n";
    echo "Revisa el registro de errores de PHP.";
}
