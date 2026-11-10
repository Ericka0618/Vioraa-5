
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';

try {
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401);
        throw new Exception('Sesión no iniciada');
    }

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

    echo json_encode([
        'success' => true,
        'clientes' => $clientes
    ]);

} catch (Throwable $e) {
    error_log('Error buscando clientes: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'No se pudieron cargar los clientes'
    ]);
}
