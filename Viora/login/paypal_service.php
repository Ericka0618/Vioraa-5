<?php
// Solo Sandbox. Ninguna URL recibida del navegador/proveedor se utiliza como destino.
function paypal_api(array $cfg, string $ruta, ?string $token = null, ?array $body = null): array
{
    if (empty($cfg['client_id']) || empty($cfg['client_secret'])) {
        throw new RuntimeException('Faltan credenciales de PayPal Sandbox.');
    }
    if (($cfg['api_url'] ?? '') !== 'https://api-m.sandbox.paypal.com') {
        throw new RuntimeException('Solo se permite PayPal Sandbox.');
    }
    $ch = curl_init($cfg['api_url'] . $ruta);
    $headers = ['Accept: application/json'];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30];
    if ($token === null) {
        $options[CURLOPT_USERPWD] = $cfg['client_id'] . ':' . $cfg['client_secret'];
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = 'grant_type=client_credentials';
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        $headers[] = 'Authorization: Bearer ' . $token;
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('PayPal no disponible (HTTP ' . $status . ').');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Respuesta PayPal inválida.');
    return $data;
}

function paypal_token(array $cfg): string
{
    $token = paypal_api($cfg, '/v1/oauth2/token')['access_token'] ?? '';
    if (!is_string($token) || $token === '') throw new RuntimeException('Falta token PayPal.');
    return $token;
}

function paypal_webhook_validar(array $cfg, array $event, array $server, string $webhook, ?callable $request = null): bool
{
    if ($webhook === '' || empty($event['id']) || empty($event['event_type'])) return false;
    $verification = ['webhook_id' => $webhook, 'webhook_event' => $event];
    foreach (['auth_algo','cert_url','transmission_id','transmission_sig','transmission_time'] as $key) {
        $header = 'HTTP_PAYPAL_' . strtoupper($key);
        if (empty($server[$header]) || !is_string($server[$header])) return false;
        $verification[$key] = $server[$header];
    }
    $request ??= 'paypal_api';
    $token = $request($cfg, '/v1/oauth2/token')['access_token'] ?? '';
    if (!$token) throw new RuntimeException('Falta token PayPal.');
    $result = $request($cfg, '/v1/notifications/verify-webhook-signature', $token, $verification);
    return ($result['verification_status'] ?? '') === 'SUCCESS';
}

function paypal_fecha(?string $value): ?string
{
    if (!$value) return null;
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function paypal_plan_validar(array $plan): void
{
    $regular = array_values(array_filter($plan['billing_cycles'] ?? [], fn($c) => ($c['tenure_type'] ?? '') === 'REGULAR'));
    if (count($regular) !== 1 || ($regular[0]['frequency']['interval_unit'] ?? '') !== 'MONTH'
        || (int)($regular[0]['frequency']['interval_count'] ?? 0) !== 1
        || ($regular[0]['pricing_scheme']['fixed_price']['currency_code'] ?? '') !== 'USD'
        || !is_numeric($regular[0]['pricing_scheme']['fixed_price']['value'] ?? null)
        || (float)$regular[0]['pricing_scheme']['fixed_price']['value'] !== 10.0) {
        throw new RuntimeException('El plan debe ser mensual de US$10.00.');
    }
}

// Fuente de verdad: consulta actual al proveedor incluso si el webhook llega repetido o fuera de orden.
function paypal_sincronizar(mysqli $db, array $cfg, string $id, ?int $usuarioEsperado = null, ?callable $request = null, ?array $ajuste = null): array
{
    $lock = 'viora_paypal_' . substr(hash('sha256', $id), 0, 45);
    $q = $db->prepare('SELECT GET_LOCK(?, 5)');
    $q->bind_param('s', $lock); $q->execute();
    if ((int)$q->get_result()->fetch_row()[0] !== 1) throw new RuntimeException('Conciliación PayPal en curso.');
    try { return paypal_sincronizar_interno($db, $cfg, $id, $usuarioEsperado, $request, $ajuste); }
    finally { $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->bind_param('s', $lock); $q->execute(); }
}

require_once __DIR__ . '/paypal_reconciliation.php';
