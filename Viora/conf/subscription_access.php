<?php
function viora_subscription_access(mysqli $db, int $uid): bool
{
    $q = $db->prepare("SELECT u.activo,u.estado_cuenta,r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.id_usuarios=?");
    $q->bind_param('i', $uid); $q->execute(); $u = $q->get_result()->fetch_assoc();
    if (!$u || (int)$u['activo'] !== 1) return false;
    if (strcasecmp(trim($u['nombre']), 'Cliente') !== 0) return true;
    if ($u['estado_cuenta'] !== 'Activo') return false;
    $q = $db->prepare("SELECT id_suscripcion FROM suscripcion WHERE usuario_id=? AND estado='Activo' AND (fecha_proximo_pago IS NULL OR fecha_proximo_pago>UTC_TIMESTAMP()) LIMIT 1");
    $q->bind_param('i', $uid); $q->execute();
    return (bool)$q->get_result()->fetch_assoc();
}
