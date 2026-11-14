<?php
// La identidad y la titularidad siempre proceden de la base, nunca del navegador.
function factura_identidad(mysqli $db): array
{
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401); exit('Sesión requerida.');
    }
    $id = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
    $st = $db->prepare('SELECT u.persona_id, r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.id_usuarios=? AND u.activo=1');
    $st->bind_param('i', $id);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    $rol = mb_strtolower(trim($u['nombre'] ?? ''));
    if (!$u || !in_array($rol, ['admin', 'administrador', 'cliente'], true)) {
        http_response_code(403); exit('Acceso denegado.');
    }
    $admin = in_array($rol, ['admin', 'administrador'], true);
    if (!$admin && (int)$u['persona_id'] <= 0) {
        http_response_code(403); exit('Cliente sin identidad asociada.');
    }
    return ['admin' => $admin, 'persona_id' => (int)$u['persona_id']];
}
