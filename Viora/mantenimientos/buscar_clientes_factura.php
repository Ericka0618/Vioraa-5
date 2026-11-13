<?php
require_once __DIR__ . '/facturacion_json.php';
facturacion_json_iniciar();
try {
    $conexion = facturacion_json_conexion();
    $sql = "
        SELECT DISTINCT
            p.id_personas,
            CONCAT(p.nombres, ' ', p.apellidos) AS nombre,
            p.cedula,
            p.correo,
            p.telefono

        FROM persona p

        INNER JOIN contrato c
            ON c.persona_id = p.id_personas

        WHERE p.activo = 1
          AND c.activo = 1
          AND c.estado = 'Activo'
          AND c.tipo_operacion = 'Alquiler'

        ORDER BY nombre ASC
    ";

    $resultado = $conexion->query($sql);

    $clientes = [];

    while ($fila = $resultado->fetch_assoc()) {
        $clientes[] = $fila;
    }

    facturacion_json_responder(200, ['success' => true, 'clientes' => $clientes]);
} catch (Throwable $e) {
    error_log('[VIORA facturacion clientes] Consulta fallida: ' . $e->getCode());
    facturacion_json_responder(503, ['success' => false, 'message' => 'Consulta temporalmente no disponible. Intente mas tarde.']);
}
