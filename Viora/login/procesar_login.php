
<?php
session_start();

require_once __DIR__ . '/../conf/conexion.php';

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?error=method_not_allowed');
    exit;
}

// Recibir datos
$user = trim($_POST['username'] ?? '');
$pass = $_POST['password'] ?? '';

if ($user === '' || $pass === '') {
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
    header('Location: login.php?error=invalid_credentials');
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
        header('Location: login.php?error=subscription_required');
        exit;
    }
}

// Regenerar sesión después de autenticar
session_regenerate_id(true);

// Guardar datos en sesión
$_SESSION['logged_in'] = true;
$_SESSION['id_usuario'] = $row['id_usuarios'];
$_SESSION['nombre_usuario'] = $row['nombre'];
$_SESSION['usuario'] = $row['usuario'];
$_SESSION['rol'] = $rol;

// Redirigir según el rol
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
