<?php
// Administrative authorization must run before reading or changing module data.
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login/login.php');
    exit;
}
$vioraAuthId = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$vioraAuthUser = (string)($_SESSION['usuario'] ?? '');
$vioraAuth = $conexion->prepare('SELECT r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND ' . ($vioraAuthId > 0 ? 'u.id_usuarios=?' : 'u.usuario=?') . ' LIMIT 1');
if ($vioraAuthId > 0) { $vioraAuth->bind_param('i', $vioraAuthId); }
else { $vioraAuth->bind_param('s', $vioraAuthUser); }
$vioraAuth->execute();
$vioraAuthRole = $vioraAuth->get_result()->fetch_assoc();
$vioraAuth->close();
if (!$vioraAuthRole || !in_array(strtolower(trim($vioraAuthRole['nombre'])), ['administrador', 'admin'], true)) {
    http_response_code(403);
    exit('Acceso exclusivo para administradores.');
}
unset($vioraAuthId, $vioraAuthUser, $vioraAuth, $vioraAuthRole);