<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../conf/conexion.php';
require_once 'ecf_service.php';

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

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        throw new Exception('Datos inválidos');
    }

    $facturaId = (int)($input['factura_id'] ?? 0);

    if ($facturaId <= 0) {
        throw new Exception('Factura inválida');
    }

    $stmt = $conexion->prepare("
        SELECT estado
        FROM factura
        WHERE id_facturas = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $facturaId);
    $stmt->execute();
    $factura = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$factura) {
        throw new Exception('Factura no encontrada');
    }

    if ($factura['estado'] === 'Anulada') {
        throw new Exception('La factura está Anulada');
    }

    // Solo se reenvía si la plataforma nunca recibió el documento.
    // Si ya tiene un e-CF, reenviar duplicaría la secuencia.
    $stmt = $conexion->prepare("
        SELECT estado, ecf_api_id
        FROM factura_ecf
        WHERE factura_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $facturaId);
    $stmt->execute();
    $ecfActual = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($ecfActual && !empty($ecfActual['ecf_api_id'])) {
        throw new Exception(
            'Esta factura ya tiene e-CF en estado ' . $ecfActual['estado'] .
            ' y no se puede reenviar'
        );
    }

    $ecf = ecf_enviar_factura($conexion, $facturaId);

    echo json_encode([
        'success' => true,
        'ecf' => $ecf
    ]);

} catch (Throwable $e) {
    error_log('Error reenviar_ecf VIORA: ' . $e->getMessage());

    http_response_code(
        http_response_code() >= 400
            ? http_response_code()
            : 400
    );

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
