<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../conf/conexion.php';

$transaccion = false;

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
    $montoTexto = trim((string)($input['monto'] ?? ''));
    $metodo = trim((string)($input['metodo_pago'] ?? ''));
    $referencia = trim((string)($input['referencia'] ?? ''));
    $observaciones = trim((string)($input['observaciones'] ?? ''));

    if ($facturaId <= 0) {
        throw new Exception('Factura inválida');
    }

    if (!preg_match('/^\d+(\.\d{1,2})?$/', $montoTexto) || (float)$montoTexto <= 0) {
        throw new Exception('El monto debe ser mayor que cero y tener máximo 2 decimales');
    }

    if (!in_array($metodo, ['Efectivo', 'Transferencia', 'Tarjeta', 'Cheque'], true)) {
        throw new Exception('Método de pago no permitido');
    }

    if (mb_strlen($referencia) > 100) {
        throw new Exception('La referencia es demasiado larga');
    }

    // Trabajo en centavos para evitar errores de punto flotante.
    $montoCentavos = (int)round((float)$montoTexto * 100);

    $conexion->begin_transaction();
    $transaccion = true;

    // Bloqueo de la factura: dos pagos simultáneos se procesan en serie.
    $stmt = $conexion->prepare("
        SELECT id_facturas, total, monto_pagado, estado
        FROM factura
        WHERE id_facturas = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $facturaId);
    $stmt->execute();
    $factura = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$factura) {
        throw new Exception('Factura no encontrada');
    }

    if (!in_array($factura['estado'], ['Pendiente', 'Parcial'], true)) {
        throw new Exception('La factura está ' . $factura['estado'] . ' y no admite pagos');
    }

    $totalCentavos = (int)round((float)$factura['total'] * 100);
    $pagadoCentavos = (int)round((float)$factura['monto_pagado'] * 100);
    $saldoCentavos = $totalCentavos - $pagadoCentavos;

    if ($saldoCentavos <= 0) {
        throw new Exception('La factura no tiene saldo pendiente');
    }

    if ($montoCentavos > $saldoCentavos) {
        throw new Exception(
            'El monto supera el saldo pendiente de RD$ ' .
            number_format($saldoCentavos / 100, 2)
        );
    }

    $montoDecimal = $montoCentavos / 100;
    $referenciaDb = $referencia !== '' ? $referencia : null;
    $observacionesDb = $observaciones !== '' ? $observaciones : null;

    $stmtPago = $conexion->prepare("
        INSERT INTO factura_pago (
            factura_id,
            monto,
            metodo_pago,
            referencia,
            observaciones,
            usuario_id
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtPago->bind_param(
        'idsssi',
        $facturaId,
        $montoDecimal,
        $metodo,
        $referenciaDb,
        $observacionesDb,
        $usuarioId
    );
    $stmtPago->execute();
    $pagoId = $conexion->insert_id;
    $stmtPago->close();

    $nuevoPagadoCentavos = $pagadoCentavos + $montoCentavos;
    $nuevoSaldoCentavos = $totalCentavos - $nuevoPagadoCentavos;
    $nuevoEstado = $nuevoSaldoCentavos === 0 ? 'Pagada' : 'Parcial';

    $nuevoPagado = $nuevoPagadoCentavos / 100;
    $nuevoSaldo = $nuevoSaldoCentavos / 100;

    $stmtUpdate = $conexion->prepare("
        UPDATE factura
        SET monto_pagado = ?,
            saldo_pendiente = ?,
            estado = ?
        WHERE id_facturas = ?
    ");
    $stmtUpdate->bind_param('ddsi', $nuevoPagado, $nuevoSaldo, $nuevoEstado, $facturaId);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    $conexion->commit();
    $transaccion = false;

    echo json_encode([
        'success' => true,
        'message' => 'Pago registrado correctamente',
        'id_pagos' => $pagoId,
        'estado' => $nuevoEstado,
        'saldo_pendiente' => $nuevoSaldo
    ]);

} catch (Throwable $e) {
    if ($transaccion) {
        $conexion->rollback();
    }

    error_log('Error registrar_pago VIORA: ' . $e->getMessage());

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
