<?php
// Respuestas JSON para las consultas de solo lectura de facturación.
function facturacion_json_iniciar(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ob_start();
    $GLOBALS['facturacion_json_nivel'] = ob_get_level();
    $GLOBALS['facturacion_json_completo'] = false;
    register_shutdown_function(function () {
        if (empty($GLOBALS['facturacion_json_completo'])) {
            facturacion_json_responder(503, ['success' => false, 'message' => 'Consulta temporalmente no disponible. Intente más tarde.']);
        }
    });
}

function facturacion_json_responder(int $codigo, array $datos): never
{
    $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    while (ob_get_level() >= ($GLOBALS['facturacion_json_nivel'] ?? 1)) ob_end_clean();
    $GLOBALS['facturacion_json_completo'] = true;
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

function facturacion_json_conexion(): mysqli
{
    if (!session_start()) throw new RuntimeException('Session unavailable');
    if (empty($_SESSION['logged_in'])) {
        facturacion_json_responder(401, ['success' => false, 'message' => 'La sesión venció. Inicie sesión nuevamente.']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        facturacion_json_responder(405, ['success' => false, 'message' => 'Método no permitido.']);
    }
    require __DIR__ . '/../conf/conexion.php';
    $uid = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
    $usuario = (string)($_SESSION['usuario'] ?? '');
    $q = $conexion->prepare('SELECT r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND ' . ($uid > 0 ? 'u.id_usuarios=?' : 'u.usuario=?') . ' LIMIT 1');
    if ($uid > 0) $q->bind_param('i', $uid); else $q->bind_param('s', $usuario);
    $q->execute(); $role = $q->get_result()->fetch_assoc(); $q->close();
    if (!$role || !in_array(strtolower(trim($role['nombre'])), ['admin', 'administrador'], true)) {
        facturacion_json_responder(403, ['success' => false, 'message' => 'Acceso exclusivo para administradores.']);
    }
    session_write_close();
    return $conexion;
}
