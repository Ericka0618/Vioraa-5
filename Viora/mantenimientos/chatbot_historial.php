<?php
function chatbot_conversacion(mysqli $db, int $uid): int
{
    $id = (int)($_SESSION['chatbot_conversacion'] ?? 0);
    if ($id) {
        $q = $db->prepare("SELECT id_conversaciones FROM conversacion_ia WHERE id_conversaciones=? AND usuario_id=? AND activo=1 AND estado='Activa'");
        $q->bind_param('ii', $id, $uid); $q->execute();
        if ($q->get_result()->fetch_assoc()) return $id;
    }
    // Recuperar el historial propio después de volver a iniciar sesión.
    $q = $db->prepare("SELECT id_conversaciones FROM conversacion_ia WHERE usuario_id=? AND activo=1 AND estado='Activa' ORDER BY id_conversaciones DESC LIMIT 1");
    $q->bind_param('i', $uid); $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if ($row) $id = (int)$row['id_conversaciones'];
    else {
        $q = $db->prepare("INSERT INTO conversacion_ia (usuario_id) VALUES (?)");
        $q->bind_param('i', $uid); $q->execute(); $id = (int)$db->insert_id;
    }
    $_SESSION['chatbot_conversacion'] = $id;
    return $id;
}
function chatbot_historial(mysqli $db, int $id, int $limit, int $uid): array
{
    $limit = max(1, min(100, $limit));
    $q = $db->prepare("SELECT m.tipo_emisor,m.contenido FROM mensaje_ia m JOIN conversacion_ia c ON c.id_conversaciones=m.conversacion_id WHERE m.conversacion_id=? AND c.usuario_id=? AND c.activo=1 AND m.activo=1 ORDER BY m.id_mensajes DESC LIMIT $limit");
    $q->bind_param('ii', $id, $uid); $q->execute();
    return array_map(fn($r) => ['rol' => $r['tipo_emisor'] === 'USUARIO' ? 'user' : 'assistant', 'texto' => $r['contenido']], array_reverse($q->get_result()->fetch_all(MYSQLI_ASSOC)));
}
function chatbot_guardar_turno(mysqli $db, int $id, string $mensaje, string $respuesta, int $uid): void
{
    $db->begin_transaction();
    try {
        $q = $db->prepare("SELECT id_conversaciones FROM conversacion_ia WHERE id_conversaciones=? AND usuario_id=? AND activo=1 AND estado='Activa' FOR UPDATE");
        $q->bind_param('ii', $id, $uid); $q->execute();
        if (!$q->get_result()->fetch_assoc()) throw new RuntimeException('Conversación no autorizada o cerrada.');
        $q = $db->prepare("INSERT INTO mensaje_ia (conversacion_id,tipo_emisor,contenido) VALUES (?,'USUARIO',?),(?,'ASISTENTE',?)");
        $q->bind_param('isis', $id, $mensaje, $id, $respuesta); $q->execute();
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
