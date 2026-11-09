
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../conf/conexion.php';

try {
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401);
        throw new Exception('Sesión no iniciada');
    }

    $persona_id = filter_input(
        INPUT_GET,
        'persona_id',
        FILTER_VALIDATE_INT
    );

    if (!$persona_id || $persona_id <= 0) {
        throw new Exception('Cliente no válido');
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

    echo json_encode([
        'success' => true,
        'propiedades' => $propiedades
    ]);

} catch (Throwable $e) {
    error_log('Error obteniendo propiedades: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'No se pudieron cargar las propiedades'
    ]);
}
