<?php
session_start();

require_once __DIR__ . '/../conf/conexion.php';

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?error=method_not_allowed');
    exit;
}

$auditLoginSuccess = false;
register_shutdown_function(static function () use (&$auditLoginSuccess): void {
    if (!$auditLoginSuccess) {
        auditoria_evento('Autenticación', 'Inicio de sesión', 'Intento sin sesión autorizada', false, [], true);
    }
});

// Protección contra fuerza bruta: validar intentos fallidos
$maxIntentos = 5;
$tiempoBloqueo = 900; // 15 minutos en segundos
$intentosFallidos = (int)($_SESSION['login_intentos_fallidos'] ?? 0);
$ultimoIntento = (int)($_SESSION['login_ultimo_intento'] ?? 0);
$ahora = time();

// Si hay bloqueo activo, rechazar
if ($intentosFallidos >= $maxIntentos && ($ahora - $ultimoIntento) < $tiempoBloqueo) {
    header('Location: login.php?error=too_many_attempts');
    exit;
}

// Limpiar intentos si pasó el tiempo de bloqueo
if (($ahora - $ultimoIntento) >= $tiempoBloqueo) {
    $_SESSION['login_intentos_fallidos'] = 0;
    $intentosFallidos = 0;
}

// Recibir datos
$user = trim($_POST['username'] ?? '');
$pass = $_POST['password'] ?? '';

if ($user === '' || $pass === '') {
    $_SESSION['login_intentos_fallidos'] = $intentosFallidos + 1;
    $_SESSION['login_ultimo_intento'] = $ahora;
    header('Location: login.php?error=empty_fields');
    exit;
}

// Buscar usuario y su rol
$stmt = $conexion->prepare("
    SELECT
        u.id_usuarios,
        u.nombre,
        u.usuario,
        u.clave,
        u.activo,
        u.estado_cuenta,
        r.nombre AS rol
    FROM usuario u
    INNER JOIN role r
        ON r.id_roles = u.id_rol
    WHERE u.usuario = ?
    LIMIT 1
");

$stmt->bind_param("s", $user);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();

$stmt->close();

// Verificar usuario
if (!$row) {
    $_SESSION['login_intentos_fallidos'] = $intentosFallidos + 1;
    $_SESSION['login_ultimo_intento'] = $ahora;
    header('Location: login.php?error=invalid_credentials');
    exit;
}

// Verificar contraseña
$claveValida = password_verify($pass, $row['clave']);

// Compatibilidad temporal con contraseñas antiguas
if (!$claveValida) {
    $claveValida = hash_equals(
        (string)$row['clave'],
        $pass
    );
}

if (!$claveValida) {
    $_SESSION['login_intentos_fallidos'] = $intentosFallidos + 1;
    $_SESSION['login_ultimo_intento'] = $ahora;
    header('Location: login.php?error=invalid_credentials');
    exit;
}

// Reanudar exclusivamente un registro pendiente, después de verificar su contraseña.
// No concede una sesión de acceso ni reactiva cuentas suspendidas.
if (trim($row['rol']) === 'Cliente' && $row['estado_cuenta'] === 'Pendiente de pago') {
    session_regenerate_id(true);
    unset($_SESSION['logged_in']);
    $_SESSION['registro_pendiente_usuario'] = (int)$row['id_usuarios'];
    header('Location: suscripcion.php');
    exit;
}

// Verificar cuenta activa
if ((int)$row['activo'] !== 1) {
    header('Location: login.php?error=inactive');
    exit;
}

// Identificar rol
$rol = trim($row['rol']);

// Los clientes deben tener la cuenta activa
if ($rol === 'Cliente') {

    if ($row['estado_cuenta'] !== 'Activo') {
        header('Location: login.php?error=subscription_required');
        exit;
    }

    // Comprobar suscripción registrada
    $consulta = $conexion->prepare("
        SELECT id_suscripcion
        FROM suscripcion
        WHERE usuario_id = ?
          AND estado = 'Activo'
          AND (fecha_proximo_pago IS NULL OR fecha_proximo_pago > UTC_TIMESTAMP())
        LIMIT 1
    ");

    $consulta->bind_param(
        "i",
        $row['id_usuarios']
    );

    $consulta->execute();
    $suscripcion = $consulta->get_result()->fetch_assoc();
    $consulta->close();

    if (!$suscripcion) {
        session_regenerate_id(true);
        unset($_SESSION['logged_in'], $_SESSION['paypal_subscription_id']);
        $_SESSION['registro_pendiente_usuario'] = (int)$row['id_usuarios'];
        header('Location: suscripcion.php');
        exit;
    }
}

// Regenerar sesión después de autenticar
session_regenerate_id(true);

// Limpiar contador de intentos fallidos
unset($_SESSION['login_intentos_fallidos'], $_SESSION['login_ultimo_intento']);

// Guardar datos en sesión
$_SESSION['logged_in'] = true;
$_SESSION['id_usuarios'] = $row['id_usuarios'];  // Estandarizar nombre de variable
$_SESSION['nombre_usuario'] = $row['nombre'];
$_SESSION['usuario'] = $row['usuario'];
$_SESSION['rol'] = $rol;

// Redirigir según el rol
if (in_array($rol, ['Cliente', 'Administrador'], true)) {
    $auditLoginSuccess = true;
    auditoria_evento('Autenticación', 'Inicio de sesión', 'Sesión autorizada', true,
        [['usuario', (string)$row['id_usuarios']]], false, auditoria_contexto());
}

if ($rol === 'Cliente') {
    header('Location: ../mantenimientos/catalogo.php');
    exit;
}

if ($rol === 'Administrador') {
    header('Location: principal.php');
    exit;
}

// Otros roles no autorizados
session_unset();
session_destroy();

header('Location: login.php?error=unauthorized');
exit;
