<?php
session_start();

require_once '../conf/conexion.php';

// Verificar que el usuario inició sesión.
if (
    empty($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    http_response_code(403);
    exit('Acceso denegado. Debes iniciar sesión.');
}

// Identificar al usuario autenticado.
$usuarioId = (int)(
    $_SESSION['id_usuarios']
    ?? $_SESSION['id_usuario']
    ?? 0
);

// Si la sesión no tiene ID, buscar por nombre de usuario.
$nombreUsuario = $_SESSION['usuario'] ?? '';

try {

    // Verificar el rol administrador en la tabla role.
    if ($usuarioId > 0) {

        $check = $conexion->prepare("
            SELECT r.nombre
            FROM usuario u
            INNER JOIN role r
                ON r.id_roles = u.id_rol
            WHERE u.id_usuarios = ?
              AND u.activo = 1
              AND r.activo = 1
            LIMIT 1
        ");

        $check->bind_param("i", $usuarioId);

    } elseif ($nombreUsuario !== '') {

        $check = $conexion->prepare("
            SELECT r.nombre
            FROM usuario u
            INNER JOIN role r
                ON r.id_roles = u.id_rol
            WHERE u.usuario = ?
              AND u.activo = 1
              AND r.activo = 1
            LIMIT 1
        ");

        $check->bind_param("s", $nombreUsuario);

    } else {
        http_response_code(403);
        exit('No se pudo identificar al usuario.');
    }

    $check->execute();

    $rol = $check->get_result()->fetch_assoc();

    // Permitir únicamente al administrador.
    if (
        !$rol ||
        mb_strtolower(trim($rol['nombre'])) !== 'administrador'
    ) {
        http_response_code(403);
        exit('Solo el administrador puede visualizar contratos.');
    }

    // Obtener el ID del contrato.
    $id = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

    if (!$id || $id <= 0) {
        http_response_code(400);
        exit('Contrato inválido.');
    }

    // Buscar el documento asociado al contrato.
    $q = $conexion->prepare("
        SELECT archivo_contrato
        FROM contrato
        WHERE id_contratos = ?
          AND activo = 1
        LIMIT 1
    ");

    $q->bind_param("i", $id);
    $q->execute();

    $contrato = $q->get_result()->fetch_assoc();

    if (!$contrato) {
        http_response_code(404);
        exit('Contrato no encontrado.');
    }

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
