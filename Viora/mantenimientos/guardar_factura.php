<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../conf/conexion.php';
require_once __DIR__ . '/ecf_validacion.php';
require_once __DIR__ . '/ecf_service.php';

$transaccion = false;

try {
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401);
        throw new Exception('Sesión no iniciada');
    }

    // Verificar que el usuario tenga rol administrador.
    $usuarioId = (int)(
        $_SESSION['id_usuarios'] ??
        $_SESSION['id_usuario'] ?? 0
    );

    if ($usuarioId <= 0) {
        throw new Exception('Usuario no identificado');
    }

    $stmtRol = $conexion->prepare("
        SELECT r.nombre
        FROM usuario u
        INNER JOIN role r
            ON r.id_roles = u.id_rol
        WHERE u.id_usuarios = ?
          AND u.activo = 1
        LIMIT 1
    ");

    $stmtRol->bind_param('i', $usuarioId);
    $stmtRol->execute();

    $rol = $stmtRol->get_result()->fetch_assoc();
    $stmtRol->close();

    if (!$rol || !in_array(
        mb_strtolower(trim($rol['nombre'])),
        ['admin', 'administrador'],
        true
    )) {
        http_response_code(403);
        throw new Exception('Acceso exclusivo para administradores');
    }

    ecf_validar_peticion();
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        throw new Exception('Datos inválidos');
    }

    $contratoId = (int)($input['contrato_id'] ?? 0);
    $periodo = trim($input['periodo'] ?? '');
    $fecha = trim($input['fecha'] ?? '');
    $conceptos = $input['conceptos'] ?? [];
    $observaciones = trim($input['observaciones'] ?? '');
    $rncComprador = ecf_rnc_normalizar($input['rnc_comprador'] ?? null);
    $razonComprador = trim((string)($input['razon_social_comprador'] ?? ''));

    if ($rncComprador === null) {
        $razonComprador = null;
    } elseif ($razonComprador === '') {
        throw new Exception('La razón social del comprador es obligatoria cuando se indica el RNC');
    } elseif (mb_strlen($razonComprador) > 150) {
        throw new Exception('La razón social del comprador no puede tener más de 150 caracteres');
    }

    if ($contratoId <= 0) {
        throw new Exception('Contrato inválido');
    }

    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
        throw new Exception('Período inválido');
    }

    $fechaObj = DateTimeImmutable::createFromFormat(
        '!Y-m-d', $fecha
    );

    if (!$fechaObj ||
        $fechaObj->format('Y-m-d') !== $fecha ||
        $fecha > date('Y-m-d')) {
        throw new Exception('Fecha inválida');
    }

    if (!is_array($conceptos) || !$conceptos) {
        throw new Exception('Seleccione un concepto');
    }

    $conceptos = array_values(array_unique($conceptos));

    foreach ($conceptos as $concepto) {
        if (!in_array(
            $concepto,
            ['Alquiler', 'Mantenimiento'],
            true
        )) {
            throw new Exception('Concepto no permitido');
        }
    }

    $conexion->begin_transaction();
    $transaccion = true;

    // Bloqueo del contrato para serializar facturas
    // concurrentes de la misma propiedad.
    $stmt = $conexion->prepare("
        SELECT
            id_contratos,
            persona_id,
            fecha_inicio,
            fecha_fin,
            monto_alquiler,
            monto_mantenimiento

        FROM contrato

        WHERE id_contratos = ?
          AND activo = 1
          AND estado = 'Activo'
          AND tipo_operacion = 'Alquiler'

        FOR UPDATE
    ");

    $stmt->bind_param('i', $contratoId);
    $stmt->execute();

    $contrato = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$contrato || empty($contrato['persona_id'])) {
        throw new Exception('Contrato activo no encontrado');
    }

    // Verificar que el período pertenezca al contrato.
    $inicioPeriodo = $periodo . '-01';
    $finPeriodo = date(
        'Y-m-t',
        strtotime($inicioPeriodo)
    );

    if ($finPeriodo < $contrato['fecha_inicio'] ||
        (!empty($contrato['fecha_fin']) &&
         $inicioPeriodo > $contrato['fecha_fin'])) {
        throw new Exception(
            'El período está fuera de la vigencia del contrato'
        );
    }

    // No permitir cargos duplicados, incluso si
    // aparecen en otra factura no anulada.
    $stmtDuplicado = $conexion->prepare("
        SELECT fd.concepto
        FROM factura_detalle fd
        INNER JOIN factura f
            ON f.id_facturas = fd.factura_id
        WHERE f.contrato_id = ?
          AND fd.periodo = ?
          AND fd.concepto = ?
          AND fd.activo = 1
          AND f.estado <> 'Anulada'
        LIMIT 1
    ");

    $montos = [];
    $montosCentavos = [];

    foreach ($conceptos as $concepto) {
        $stmtDuplicado->bind_param(
            'iss',
            $contratoId,
            $periodo,
            $concepto
        );

        $stmtDuplicado->execute();

        if ($stmtDuplicado->get_result()->fetch_assoc()) {
            throw new Exception(
                "Ya existe una factura para $concepto " .
                "del período $periodo"
            );
        }

        $monto = $concepto === 'Alquiler'
            ? (float)$contrato['monto_alquiler']
            : (float)$contrato['monto_mantenimiento'];

        if ($monto <= 0) {
            throw new Exception(
                "El concepto $concepto no tiene monto válido"
            );
        }

        $montos[$concepto] = $monto;
        $montosCentavos[] = (int)round($monto * 100);
    }

    $stmtDuplicado->close();

    // El monto del contrato no incluye ITBIS: se suma encima.
    $ecfConfig = ecf_configuracion();
    $totales = ecf_calcular_totales(
        $montosCentavos,
        $ecfConfig['itbis_porcentaje']
    );

    $subtotal = $totales['base'] / 100;
    $total = $totales['total'] / 100;

    $personaId = (int)$contrato['persona_id'];

    // Número único basado en UUID de MySQL.
    $uuid = $conexion->query(
        "SELECT REPLACE(UUID(), '-', '') AS codigo"
    )->fetch_assoc()['codigo'];

    $numero = 'FAC-' . strtoupper($uuid);

    $stmtFactura = $conexion->prepare("
        INSERT INTO factura (
            numero_documento,
            contrato_id,
            persona_id,
            periodo,
            fecha,
            fecha_vencimiento,
            subtotal,
            total,
            monto_pagado,
            saldo_pendiente,
            estado,
            observaciones,
            rnc_comprador,
            razon_social_comprador,
            usuario_id
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, 0, ?, 'Pendiente', ?, ?, ?, ?
        )
    ");

    $stmtFactura->bind_param(
        'siisssdddsssi',
        $numero,
        $contratoId,
        $personaId,
        $periodo,
        $fecha,
        $finPeriodo,
        $subtotal,
        $total,
        $total,
        $observaciones,
        $rncComprador,
        $razonComprador,
        $usuarioId
    );

    $stmtFactura->execute();

    $facturaId = $conexion->insert_id;
    $stmtFactura->close();

    $stmtDetalle = $conexion->prepare("
        INSERT INTO factura_detalle (
            factura_id,
            concepto,
            descripcion,
            periodo,
            monto,
            activo
        )
        VALUES (?, ?, ?, ?, ?, 1)
    ");

    foreach ($montos as $concepto => $monto) {
        $descripcion = "$concepto del período $periodo";

        $stmtDetalle->bind_param(
            'isssd',
            $facturaId,
            $concepto,
            $descripcion,
            $periodo,
            $monto
        );

        $stmtDetalle->execute();
    }

    $stmtDetalle->close();

    $conexion->commit();
    $transaccion = false;

    // Fuera de la transacción: si DGII falla, la factura se conserva
    // y el envío queda registrado para reintentar.
    try {
        $ecf = ecf_enviar_factura($conexion, $facturaId);
    } catch (Throwable $e) {
        error_log('Error e-CF factura ' . $facturaId . ': ' . $e->getMessage());
        $ecf = ['estado' => 'ERROR_INTERNO', 'error' => 'No se pudo completar el registro fiscal. Consulte antes de reintentar.'];
    }

    $pdfGenerado = false;
    try {
        require_once __DIR__ . '/factura_documento.php';
        factura_documento_generar($conexion, (int)$facturaId);
        $pdfGenerado = true;
    } catch (Throwable $e) { error_log('PDF factura ' . $facturaId . ': ' . $e->getMessage()); }

    echo json_encode([
        'pdf_generado' => $pdfGenerado,
        'pdf_url' => 'factura_pdf.php?id=' . $facturaId,
        'success' => true,
        'message' => 'Factura guardada correctamente',
        'id_facturas' => $facturaId,
        'numero_documento' => $numero,
        'subtotal' => $subtotal,
        'itbis' => $totales['itbis'] / 100,
        'total' => $total,
        'estado' => 'Pendiente',
        'ecf' => $ecf
    ]);

} catch (Throwable $e) {
    if ($transaccion) {
        $conexion->rollback();
    }

    error_log(
        'Error guardar_factura VIORA: ' . $e->getMessage()
    );

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
