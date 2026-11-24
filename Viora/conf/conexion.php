<?php
if (is_readable('/etc/viora/bootstrap.php')) {
    require_once '/etc/viora/bootstrap.php';
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/auditoria.php';

try {
    $conexion = viora_abrir_bd();
    auditoria_peticion($conexion);
    $GLOBALS['viora_audit_connection'] = true;
} catch (mysqli_sql_exception $error) {
    error_log('VIORA database connection failed: ' . $error->getCode());
    http_response_code(503);
    exit('Servicio temporalmente no disponible.');
}
