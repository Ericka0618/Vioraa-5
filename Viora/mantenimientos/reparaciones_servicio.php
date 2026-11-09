<?php
// Funciones compartidas por el módulo de reparaciones y mantenimiento mensual.

const REP_ESTADOS_REPARACION = ['Pendiente', 'En proceso', 'Completada', 'Cancelada'];
const REP_PRIORIDADES = ['Baja', 'Media', 'Alta', 'Urgente'];
const REP_ESTADOS_MENSUAL = ['Programado', 'Realizado'];

function rep_esc($valor)
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

// Devuelve el id del usuario en sesión, aceptando ambas claves usadas por el login.
function rep_id_sesion()
{
    return (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
}

// Nombre del rol del usuario en sesión en minúsculas, o '' si no está activo.
function rep_rol_sesion(mysqli $conexion)
{
    $idUsuario = rep_id_sesion();
    $usuario = (string)($_SESSION['usuario'] ?? '');
    if ($idUsuario <= 0 && $usuario === '') {
        return '';
    }

    $sql = 'SELECT r.nombre FROM usuario u INNER JOIN role r ON r.id_roles = u.id_rol
            WHERE u.activo = 1 AND ' . ($idUsuario > 0 ? 'u.id_usuarios = ?' : 'u.usuario = ?') . ' LIMIT 1';
    $stmt = $conexion->prepare($sql);
    if ($idUsuario > 0) {
        $stmt->bind_param('i', $idUsuario);
    } else {
        $stmt->bind_param('s', $usuario);
    }
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $fila ? mb_strtolower(trim($fila['nombre']), 'UTF-8') : '';
}

function rep_es_admin(mysqli $conexion)
{
    return in_array(rep_rol_sesion($conexion), ['administrador', 'admin'], true);
}

// Usuario activo vinculado a una persona, o null si no tiene cuenta.
function rep_usuario_de_persona(mysqli $conexion, int $personaId)
{
    $stmt = $conexion->prepare('SELECT id_usuarios FROM usuario WHERE persona_id = ? AND activo = 1 ORDER BY id_usuarios LIMIT 1');
    $stmt->bind_param('i', $personaId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $fila ? (int)$fila['id_usuarios'] : null;
}

// Ids de los usuarios activos con rol administrador.
function rep_ids_administradores(mysqli $conexion)
{
    $resultado = $conexion->query("SELECT u.id_usuarios FROM usuario u
                                   INNER JOIN role r ON r.id_roles = u.id_rol
                                   WHERE u.activo = 1 AND LOWER(r.nombre) IN ('administrador', 'admin')");
    return array_map('intval', array_column($resultado->fetch_all(MYSQLI_ASSOC), 'id_usuarios'));
}

function rep_notificar(mysqli $conexion, int $usuarioId, string $titulo, string $mensaje, string $tipo = 'reparacion')
{
    $stmt = $conexion->prepare('INSERT INTO notificacion (usuario_id, titulo, mensaje, tipo) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $usuarioId, $titulo, $mensaje, $tipo);
    $stmt->execute();
    $stmt->close();
}
