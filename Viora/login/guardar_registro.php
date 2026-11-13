<?php
session_start();

// Conexión a la base de datos VIORA
require_once '../conf/conexion.php';

// Activar excepciones de MySQLi
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Función para mostrar errores
function mostrarError($mensaje) {
    $_SESSION['registro_error'] = $mensaje;
    header("Location: registro.php");
    exit;
}

// Permitir únicamente solicitudes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: registro.php");
    exit;
}

// Verificar token CSRF
if (
    empty($_SESSION['csrf_registro']) ||
    !hash_equals(
        $_SESSION['csrf_registro'],
        $_POST['csrf'] ?? ''
    )
) {
    mostrarError("Solicitud inválida. Intenta nuevamente.");
}

// ========================================
// 1. RECIBIR DATOS DEL FORMULARIO
// ========================================

$nombres = trim($_POST['nombres'] ?? '');
$apellidos = trim($_POST['apellidos'] ?? '');
$cedula = trim($_POST['cedula'] ?? '');
$correo = strtolower(trim($_POST['correo'] ?? ''));
$telefono = trim($_POST['telefono'] ?? '');
$direccion = trim($_POST['direccion'] ?? '');
$usuario = trim($_POST['usuario'] ?? '');
$clave = $_POST['clave'] ?? '';
$confirmar = $_POST['confirmar_clave'] ?? '';

// ========================================
// 2. VALIDAR INFORMACIÓN
// ========================================

if (
    $nombres === '' ||
    $apellidos === '' ||
    $cedula === '' ||
    $correo === '' ||
    $telefono === '' ||
    $direccion === '' ||
    $usuario === '' ||
    $clave === '' ||
    $confirmar === ''
) {
    mostrarError("Todos los campos son obligatorios.");
}

// Validar correo electrónico
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    mostrarError("Introduce un correo electrónico válido.");
}

// Validar longitud de contraseña
if (strlen($clave) < 8) {
    mostrarError(
        "La contraseña debe tener al menos 8 caracteres."
    );
}

// Confirmar contraseñas
if ($clave !== $confirmar) {
    mostrarError("Las contraseñas no coinciden.");
}

// Verificar términos
if (!isset($_POST['terminos'])) {
    mostrarError(
        "Debes aceptar los términos y condiciones."
    );
}

// Validar formato de cédula
if (!preg_match('/^\d{3}-\d{7}-\d$/', $cedula)) {
    mostrarError(
        "La cédula debe tener el formato 000-0000000-0."
    );
}

// Validar formato de teléfono
if (!preg_match('/^\d{3}-\d{3}-\d{4}$/', $telefono)) {
    mostrarError(
        "El teléfono debe tener el formato 809-000-0000."
    );
}

// Validar tamaño de usuario
if (strlen($usuario) > 50) {
    mostrarError(
        "El nombre de usuario no puede superar 50 caracteres."
    );
}

// ========================================
// 3. PROCESAR REGISTRO
// ========================================

$transaccionIniciada = false;

try {

    // ------------------------------------
    // BUSCAR ROL CLIENTE
    // ------------------------------------

    $consulta = $conexion->prepare(
        "SELECT id_roles
         FROM role
         WHERE nombre = 'Cliente'
         AND activo = 1
         LIMIT 1"
    );

    $consulta->execute();
    $consulta->bind_result($idRol);

    if (!$consulta->fetch()) {
        $consulta->close();

        mostrarError(
            "El rol Cliente no existe en la base de datos."
        );
    }

    $consulta->close();

    // ------------------------------------
    // VERIFICAR CORREO Y CÉDULA
    // ------------------------------------

    $consulta = $conexion->prepare(
        "SELECT id_personas
         FROM persona
         WHERE correo = ? OR cedula = ?
         LIMIT 1"
    );

    $consulta->bind_param(
        "ss",
        $correo,
        $cedula
    );

    $consulta->execute();
    $consulta->store_result();

    if ($consulta->num_rows > 0) {
        $consulta->close();

        mostrarError(
            "El correo o la cédula ya están registrados."
        );
    }

    $consulta->close();

    // ------------------------------------
    // VERIFICAR NOMBRE DE USUARIO
    // ------------------------------------

    $consulta = $conexion->prepare(
        "SELECT id_usuarios
         FROM usuario
         WHERE usuario = ?
         LIMIT 1"
    );

    $consulta->bind_param("s", $usuario);
    $consulta->execute();
    $consulta->store_result();

    if ($consulta->num_rows > 0) {
        $consulta->close();

        mostrarError(
            "Ese nombre de usuario ya existe."
        );
    }

    $consulta->close();

    // ------------------------------------
    // PROTEGER CONTRASEÑA
    // ------------------------------------

    $claveSegura = password_hash(
        $clave,
        PASSWORD_DEFAULT
    );

    $nombreCompleto = $nombres . " " . $apellidos;

    // ====================================
    // 4. INICIAR TRANSACCIÓN
    // ====================================

    $conexion->begin_transaction();
    $transaccionIniciada = true;

    // ------------------------------------
    // GUARDAR PERSONA
    // ------------------------------------

    $consulta = $conexion->prepare(
        "INSERT INTO persona
        (
            nombres,
            apellidos,
            cedula,
            correo,
            telefono,
            direccion,
            activo
        )
        VALUES (?, ?, ?, ?, ?, ?, 1)"
    );

    $consulta->bind_param(
        "ssssss",
        $nombres,
        $apellidos,
        $cedula,
        $correo,
        $telefono,
        $direccion
    );

    $consulta->execute();

    $idPersona = $conexion->insert_id;

    $consulta->close();

    // ====================================
    // 5. CREAR USUARIO
    // ====================================

    $estadoCuenta = "Pendiente de pago";
    $activo = 0;

    $consulta = $conexion->prepare(
        "INSERT INTO usuario
        (
            nombre,
            usuario,
            clave,
            id_rol,
            persona_id,
            activo,
            estado_cuenta
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    // CORRECCIÓN DE BIND_PARAM
    // 3 cadenas + 3 enteros + 1 cadena

    $tipos = "sss" . "iii" . "s";

    $consulta->bind_param(
        $tipos,
        $nombreCompleto,
        $usuario,
        $claveSegura,
        $idRol,
        $idPersona,
        $activo,
        $estadoCuenta
    );

    $consulta->execute();

    $idUsuario = $conexion->insert_id;

    $consulta->close();

    // ====================================
    // 6. REGISTRAR ROL DE PERSONA
    // ====================================

    $tipoRol = "Cliente";

    $consulta = $conexion->prepare(
        "INSERT INTO persona_rol
        (
            persona_id,
            tipo_rol,
            fecha_registro,
            activo
        )
        VALUES (?, ?, CURDATE(), 1)"
    );

    $consulta->bind_param(
        "is",
        $idPersona,
        $tipoRol
    );

    $consulta->execute();
    $consulta->close();

    // ====================================
    // 7. CONFIRMAR REGISTRO
    // ====================================

    $conexion->commit();
    $transaccionIniciada = false;

    // Renovar identificador de sesión
    session_regenerate_id(true);

    // Eliminar token utilizado
    unset($_SESSION['csrf_registro']);

    // Guardar referencia temporal del usuario
    // No representa una sesión autenticada
    $_SESSION['registro_pendiente_usuario'] = $idUsuario;

    // ====================================
    // 8. REDIRIGIR A SUSCRIPCIÓN
    // ====================================

    header("Location: suscripcion.php");
    exit;

} catch (Throwable $e) {

    // Revertir cambios si hubo un error
    if ($transaccionIniciada) {
        $conexion->rollback();
    }

    // Guardar detalles técnicos en el log
    error_log(
        "Error en registro VIORA: " . $e->getMessage()
    );

    // Mostrar mensaje seguro al usuario
    mostrarError(
        "No se pudo completar el registro. Verifica tus datos e intenta nuevamente."
    );
}

?>
