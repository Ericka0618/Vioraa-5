<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../conf/conexion.php';
require_once __DIR__ . '/ecf_validacion.php';
require_once __DIR__ . '/ecf_service.php';

try {
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401);
        throw new Exception('Sesión no iniciada');
    }

    $usuarioId = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);

    if ($usuarioId <= 0) {
        throw new Exception('Usuario no identificado');
    }

    $stmtRol = $conexion->prepare("
        SELECT r.nombre
        FROM usuario u
        INNER JOIN role r ON r.id_roles = u.id_rol
        WHERE u.id_usuarios = ? AND u.activo = 1
        LIMIT 1
    ");
    $stmtRol->bind_param('i', $usuarioId);
    $stmtRol->execute();
    $rol = $stmtRol->get_result()->fetch_assoc();
    $stmtRol->close();

    if (!$rol || !in_array(mb_strtolower(trim($rol['nombre'])), ['admin', 'administrador'], true)) {
        http_response_code(403);
        throw new Exception('Acceso exclusivo para administradores');
    }

    ecf_validar_peticion();
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        throw new Exception('Datos inválidos');
    }

    $facturaId = (int)($input['factura_id'] ?? 0);
    $codigoModificacion = (int)($input['codigo_modificacion'] ?? 0);
    $lineas = $input['lineas'] ?? [];

    if ($facturaId <= 0) {
        throw new Exception('Factura inválida');
    }

    if (!is_array($lineas)) {
        throw new Exception('Las líneas de la nota deben enviarse como lista');
    }

    // Una factura anulada internamente también puede necesitar
    // una nota E34 de anulación total, por eso no se filtra por estado.
    $ecf = ecf_emitir_nota_credito(
        $conexion,
        $facturaId,
        $codigoModificacion,
        $lineas,
        $usuarioId,
        is_string($input['operacion'] ?? null) ? $input['operacion'] : null
    );

    echo json_encode([
        'success' => true,
        'nota' => $ecf
    ]);

} catch (Throwable $e) {
    error_log('Error emitir_nota_credito VIORA: ' . $e->getMessage());

    http_response_code(
        http_response_code() >= 400
            ? http_response_code()
            : 400
    );

    echo json_encode([
        'success' => false,
        'message' => $e instanceof mysqli_sql_exception ? 'Operacion fiscal temporalmente no disponible.' : $e->getMessage()
    ]);
}
