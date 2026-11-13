<?php
session_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['logged_in'])) { http_response_code(401); echo '{"error":"Sesión no iniciada"}'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
try {
require __DIR__ . '/../conf/conexion.php';
$uid = (int)($_SESSION['id_usuario'] ?? $_SESSION['id_usuarios'] ?? 0);
$q = $conexion->prepare('SELECT r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.id_usuarios=? AND u.activo=1');
$q->bind_param('i', $uid); $q->execute();
$role = $q->get_result()->fetch_assoc();
if (!$role || !in_array(strtolower(trim($role['nombre'])), ['admin','administrador'], true)) { http_response_code(403); echo '{"error":"Acceso exclusivo para administradores"}'; exit; }
$id = filter_var($_GET['factura_id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); echo '{"error":"Factura inválida"}'; exit; }
    $q = $conexion->prepare('SELECT factura_id,tipo_ecf,estado,ecf_api_id,encf,fecha_emision_ecf,fecha_envio,intentos FROM factura_ecf WHERE factura_id=?');
    $q->bind_param('i', $id); $q->execute(); $row = $q->get_result()->fetch_assoc();
    if (!$row) { http_response_code(404); echo '{"error":"Sin registro fiscal"}'; exit; }
    $q = $conexion->prepare('SELECT id_factura_ecf_nota,estado,encf,ecf_api_id,fecha_envio FROM factura_ecf_nota WHERE factura_id=? ORDER BY id_factura_ecf_nota');
    $q->bind_param('i', $id); $q->execute(); $notas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['ecf' => $row, 'notas' => $notas, 'fuente' => 'registro_local', 'consulta_proveedor_disponible' => false,
        'aviso' => 'Estado local; no acredita el estado actual del proveedor ni de DGII. Falta el contrato documentado de consulta.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[VIORA consulta e-CF] ' . $e->getCode());
    http_response_code(503); echo '{"error":"Consulta temporalmente no disponible"}';
}
