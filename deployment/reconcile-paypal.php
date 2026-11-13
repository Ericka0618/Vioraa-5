<?php
// Ejecutar por CLI desde un trabajo programado privado; nunca desde el navegador.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$completado = false;
register_shutdown_function(function () use (&$completado): void { if (!$completado) exit(1); });
set_exception_handler(function (Throwable $e): void { error_log('[VIORA conciliación] Fallo interno de conciliación.'); exit(1); });
$app = getenv('VIORA_APP_ROOT') ?: __DIR__ . '/../Viora';
require $app . '/conf/conexion.php';
require $app . '/login/paypal_service.php';
$cfg = require $app . '/conf/paypal_config.php';
$rows = $conexion->query("SELECT paypal_subscription_id FROM suscripcion WHERE paypal_subscription_id IS NOT NULL AND paypal_subscription_id NOT LIKE 'I-DEMO%'");
$failed = 0;
while ($row = $rows->fetch_assoc()) {
    try { paypal_sincronizar($conexion, $cfg, $row['paypal_subscription_id']); }
    catch (Throwable $e) {
        if ($e->getCode() === 409) { error_log('[VIORA conciliación] Advertencia: histórico legado pendiente; estado conservado.'); continue; }
        $failed++; error_log('[VIORA conciliación] ' . $e->getMessage());
    }
}
$completado = true;
exit($failed ? 1 : 0);
