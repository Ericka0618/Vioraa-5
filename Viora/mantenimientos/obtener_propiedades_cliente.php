<?php
require_once __DIR__ . '/facturacion_json.php';
facturacion_json_iniciar();
try {
    $conexion = facturacion_json_conexion();
    $persona_id = filter_input(
        INPUT_GET,
        'persona_id',
        FILTER_VALIDATE_INT
    );

    if (!$persona_id || $persona_id <= 0) {
        facturacion_json_responder(400, ['success' => false, 'message' => 'Cliente no valido.']);
    }

    $sql = "
        SELECT
            c.id_contratos,
            c.numero_contrato,
            c.fecha_inicio,
            c.fecha_fin,
            c.monto_alquiler,
            c.monto_mantenimiento,

            a.id_apartamentos,
            a.numero AS apartamento,
            a.piso,

            e.nombre AS edificio,
            co.nombre AS condominio

        FROM contrato c

        INNER JOIN persona p ON p.id_personas=c.persona_id AND p.activo=1

        INNER JOIN apartamento a
            ON a.id_apartamentos = c.apartamento_id

        INNER JOIN edificio e
            ON e.id_edificios = a.edificio_id

        INNER JOIN condominio co
            ON co.id_condominios = e.condominio_id

        WHERE c.persona_id = ?
          AND c.activo = 1
          AND c.estado = 'Activo'
          AND c.tipo_operacion = 'Alquiler'

        ORDER BY c.id_contratos DESC
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param('i', $persona_id);
    $stmt->execute();

    $resultado = $stmt->get_result();

    $propiedades = [];

    while ($fila = $resultado->fetch_assoc()) {
        $fila['total_mensual'] =
            (float)$fila['monto_alquiler'] +
            (float)$fila['monto_mantenimiento'];

        $propiedades[] = $fila;
    }

    $stmt->close();

    facturacion_json_responder(200, ['success' => true, 'propiedades' => $propiedades]);
} catch (Throwable $e) {
    error_log('[VIORA facturacion propiedades] Consulta fallida: ' . $e->getCode());
    facturacion_json_responder(503, ['success' => false, 'message' => 'Consulta temporalmente no disponible. Intente mas tarde.']);
}
