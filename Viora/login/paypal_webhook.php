<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/paypal_service.php';
require_once __DIR__ . '/paypal_events.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
try {
    $cfg = require __DIR__ . '/../conf/paypal_config.php';
    $webhook = getenv('PAYPAL_WEBHOOK_ID') ?: '';
    if ($webhook === '') { http_response_code(503); echo '{"error":"Webhook no configurado"}'; exit; }
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if (strlen($raw) > 1048576) { http_response_code(413); exit; }
    $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($event) || !paypal_webhook_validar($cfg, $event, $_SERVER, $webhook)) { http_response_code(400); exit; }
    require __DIR__ . '/../conf/conexion.php';
    echo json_encode(paypal_procesar_evento($conexion, $cfg, $event));
} catch (JsonException $e) { http_response_code(400); }
catch (Throwable $e) {
    error_log('[VIORA PayPal webhook] ' . $e->getMessage());
    http_response_code(503); echo '{"error":"No se pudo conciliar; reintentar"}';
}
