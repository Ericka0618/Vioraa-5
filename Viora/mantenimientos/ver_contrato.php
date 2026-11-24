<?php
session_start();

require_once '../conf/conexion.php';

require_once __DIR__ . '/../conf/trazabilidad.php';

try {
    $user = trazabilidad_identidad($conexion);
    if (!$user) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) {
        http_response_code(400);
        exit('Contrato inválido.');
    }
    $q = $conexion->prepare('SELECT archivo_contrato FROM contrato WHERE id_contratos=? AND activo=1'
        . ($user['admin'] ? '' : ' AND persona_id=?'));
    if ($user['admin']) {
        $q->bind_param('i', $id);
    } else {
        $q->bind_param('ii', $id, $user['persona_id']);
    }
    $q->execute();

    $contrato = $q->get_result()->fetch_assoc();

    if (!$contrato) {
        http_response_code(404);
        exit('Contrato no encontrado.');
    }

    $GLOBALS['viora_audit_refs'] = [['contrato', (string)$id]];

    // Validar el nombre del archivo PDF.
    $nombreArchivo = (string)(
        $contrato['archivo_contrato'] ?? ''
    );

    if (
        !preg_match(
            '/^[a-f0-9]{40}\.pdf$/D',
            $nombreArchivo
        )
    ) {
        http_response_code(404);
        exit('PDF no disponible.');
    }

    // Carpeta privada donde se almacenan los PDF.
    $directorio = dirname(__DIR__, 2)
        . '/viora_documentos_privados';

    $archivo = $directorio . '/' . $nombreArchivo;

    // Verificar que el archivo existe.
    if (!is_file($archivo) || !is_readable($archivo)) {
        http_response_code(404);
        exit('Archivo PDF no encontrado.');
    }

    // Mostrar el PDF en el navegador.
    header('Content-Type: application/pdf');

    header(
        'Content-Disposition: inline; filename="contrato-'
        . $id . '.pdf"'
    );

    header('Content-Length: ' . filesize($archivo));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    readfile($archivo);
    exit;

} catch (Throwable $e) {

    error_log(
        'Error al visualizar contrato: ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Ocurrió un error al intentar visualizar el contrato.'
    );
}
