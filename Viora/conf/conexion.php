<?php
if (is_readable('/etc/viora/bootstrap.php')) {
    require_once '/etc/viora/bootstrap.php';
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conexion = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'viora',
        (int)(getenv('DB_PORT') ?: 3306),
        getenv('DB_SOCKET') ?: null
    );
    $conexion->set_charset('utf8mb4');
} catch (mysqli_sql_exception $error) {
    error_log('VIORA database connection failed: ' . $error->getCode());
    http_response_code(503);
    exit('Servicio temporalmente no disponible.');
}