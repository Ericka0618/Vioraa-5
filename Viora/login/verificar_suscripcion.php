<?php
// login/verificar_suscripcion.php

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_start();

header('Content-Type: application/json; charset=utf-8');

// ========================================
// 1. RESPUESTAS JSON
// ========================================

function responder($codigo, $mensaje, $success = false)
{
    http_response_code($codigo);

    echo json_encode([
        'success' => $success,
        'message' => $mensaje
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// ========================================
// 2. VALIDAR SOLICITUD
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, 'Método no permitido.');
}

$idUsuario = (int) (
    $_SESSION['registro_pendiente_usuario'] ?? 0
);

if ($idUsuario <= 0) {
    responder(401, 'Tu sesión ha expirado.');
}
if (empty($_SESSION['paypal_csrf']) || !hash_equals($_SESSION['paypal_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    responder(403, 'Solicitud no autorizada. Recarga la página.');
}

$datos = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($datos)) {
    responder(400, 'Los datos recibidos no son válidos.');
}

$subscriptionId = $datos['subscriptionID'] ?? '';

if (
    !is_string($subscriptionId) ||
    !preg_match('/^I-[A-Z0-9]+$/i', $subscriptionId)
) {
    responder(400, 'Identificador de suscripción inválido.');
}

// ========================================
// 3. CONFIGURACIÓN
// ========================================

$planEsperado = getenv('PAYPAL_PLAN_ID') ?: '';

$rutaConexion = __DIR__ . '/../conf/conexion.php';

$rutaConfig = __DIR__ . '/../conf/paypal_config.php';

if (!is_file($rutaConexion)) {
    responder(500, 'No se encontró la conexión de MySQL.');
}

if (!is_file($rutaConfig)) {
    responder(
        500,
        'No se encontró la configuración privada de PayPal.'
    );
}

require_once __DIR__ . '/paypal_service.php';
try {
    require $rutaConexion;
    $config = require $rutaConfig;
    $resultado = paypal_sincronizar($conexion, $config, $subscriptionId, $idUsuario);
    $_SESSION['paypal_subscription_id'] = $subscriptionId;
    if (!$resultado['activo']) responder(409, 'PayPal todavía no confirma una suscripción activa y su pago de US$10.00. Reintenta la verificación.');
    unset($_SESSION['registro_pendiente_usuario']);
    unset($_SESSION['paypal_subscription_id']);
    responder(200, 'Suscripción verificada. Tu cuenta VIORA está activa.', true);
} catch (Throwable $e) {
    error_log('[VIORA PayPal] ' . $e->getMessage());
    responder(503, 'No se pudo verificar PayPal. Inténtalo nuevamente.');
}
