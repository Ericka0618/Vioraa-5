<?php
// Runs only inside an isolated Docker/MySQL test network, never against Cloud SQL.
if (getenv('DB_HOST') !== 'invoice-hotfix-db' || getenv('DB_NAME') !== 'viora_invoice_hotfix_qa' || getenv('ECF_ENABLED') !== 'false') exit(90);
ob_start();
require_once '/var/www/html/mantenimientos/ecf_service.php';
$db = new mysqli('invoice-hotfix-db', 'root', '', 'viora_invoice_hotfix_qa');
$db->set_charset('utf8mb4');
$checks = [];
function check_image(string $name, bool $ok): void {
    global $checks;
    $checks[] = ['test' => $name, 'passed' => $ok];
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) throw new RuntimeException($name);
}
function fixture(string $table, array $values): void {
    global $db;
    foreach ($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC) as $field) {
        $name = $field['Field'];
        if (array_key_exists($name, $values) || $field['Null'] === 'YES' || $field['Default'] !== null || str_contains($field['Extra'], 'auto_increment')) continue;
        $type = $field['Type'];
        if (preg_match("/^enum\('([^']+)'/", $type, $m)) $values[$name] = $m[1];
        elseif (preg_match('/int|decimal|float|double/', $type)) $values[$name] = 1;
        elseif (str_contains($type, 'date') || str_contains($type, 'timestamp')) $values[$name] = '2026-01-01';
        else $values[$name] = 'QA-' . ($values['id_personas'] ?? $values['id_usuarios'] ?? 1);
    }
    $columns = implode(',', array_map(fn($n) => '`' . $n . '`', array_keys($values)));
    $q = $db->prepare('INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $args = array_values($values); $q->bind_param(str_repeat('s', count($args)), ...$args); $q->execute();
}
function image_request(string $path, string $sid = '', ?array $body = null, bool $csrf = true): array {
    $ch = curl_init('http://127.0.0.1:8080/mantenimientos/' . $path);
    $headers = ['Content-Type: application/json'];
    if ($csrf) $headers[] = 'X-CSRF-Token: qa-image-csrf';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 40, CURLOPT_COOKIE => 'VIORASID=' . $sid]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, substr($raw, $size), substr($raw, 0, $size)];
}
function image_session(string $sid, int $uid, string $role): void {
    // Seed a known test ID before Apache reads it with strict mode enabled.
    ini_set('session.use_strict_mode', '0');
    session_id($sid); session_start();
    $_SESSION = ['logged_in' => true, 'id_usuario' => $uid, 'rol' => $role, 'ecf_csrf' => 'qa-image-csrf'];
    session_write_close();
    ini_set('session.use_strict_mode', '1');
}

check_image('Linux service imports fiscal request validator', function_exists('ecf_validar_peticion'));
check_image('Linux fiscal config returns array on repeated reads', is_array(ecf_configuracion()) && is_array(ecf_configuracion()));
$db->query('SET FOREIGN_KEY_CHECKS=0');
fixture('role', ['id_roles' => 1, 'nombre' => 'Administrador']);
fixture('role', ['id_roles' => 2, 'nombre' => 'Cliente']);
foreach ([1, 2, 3] as $id) {
    fixture('persona', ['id_personas' => $id, 'nombres' => 'QA', 'apellidos' => 'Imagen ' . $id]);
    fixture('usuario', ['id_usuarios' => $id, 'id_rol' => $id === 1 ? 1 : 2, 'persona_id' => $id, 'usuario' => 'qa-image-' . $id, 'clave' => password_hash('unused-fixture', PASSWORD_DEFAULT), 'activo' => 1, 'estado_cuenta' => 'Activo']);
}
fixture('contrato', ['id_contratos' => 1, 'persona_id' => 2, 'fecha_inicio' => '2020-01-01', 'fecha_fin' => '2099-12-31', 'monto_alquiler' => '1000.00', 'monto_mantenimiento' => '100.00', 'activo' => 1, 'estado' => 'Activo', 'tipo_operacion' => 'Alquiler']);
$db->query('SET FOREIGN_KEY_CHECKS=1');
// Session files belong to www-data, the same user as Apache.
image_session('qa-image-admin', 1, 'Administrador');
image_session('qa-image-owner', 2, 'Cliente');
image_session('qa-image-other', 3, 'Cliente');
$body = ['contrato_id' => 1, 'periodo' => date('Y-m'), 'fecha' => date('Y-m-d'), 'conceptos' => ['Alquiler', 'Mantenimiento']];
[$csrfStatus, $csrfBody] = image_request('guardar_factura.php', 'qa-image-admin', $body, false);
if ($csrfStatus !== 403) echo 'Unexpected CSRF status: ' . $csrfStatus . ' body: ' . $csrfBody . "\n";
check_image('Image rejects invoice without CSRF', $csrfStatus === 403);
[$status, $response] = image_request('guardar_factura.php', 'qa-image-admin', $body);
$data = json_decode($response, true);
check_image('Image generates ordinary invoice while fiscal issuance disabled', $status === 200 && !empty($data['success']));
$fid = (int)$data['id_facturas'];
check_image('Image calculates rent maintenance and ITBIS', (float)$data['subtotal'] === 1100.0 && (float)$data['itbis'] === 198.0 && (float)$data['total'] === 1298.0);
check_image('Disabled fiscal result preserved', $data['ecf']['estado'] === 'PENDIENTE_CONFIGURACION');
check_image('Image automatically persists PDF', $data['pdf_generado'] === true && (int)$db->query('SELECT COUNT(*) FROM factura_documento')->fetch_row()[0] === 1);
check_image('Image prevents duplicate invoice retry', image_request('guardar_factura.php', 'qa-image-admin', $body)[0] === 400 && (int)$db->query('SELECT COUNT(*) FROM factura')->fetch_row()[0] === 1);
[$status, $pdf, $headers] = image_request('factura_pdf.php?id=' . $fid, 'qa-image-owner');
check_image('Image owner views PDF', $status === 200 && str_starts_with($pdf, '%PDF') && str_contains($headers, 'inline'));
[$status, $pdf, $headers] = image_request('factura_pdf.php?id=' . $fid . '&descargar=1', 'qa-image-owner');
check_image('Image owner downloads PDF', $status === 200 && str_contains($headers, 'attachment'));
check_image('Image rejects another client PDF access', image_request('factura_pdf.php?id=' . $fid, 'qa-image-other')[0] === 404);
[$status, $html] = image_request('mis_facturas.php', 'qa-image-owner');
check_image('Image invoice appears automatically in Mis Facturas', $status === 200 && str_contains($html, $data['numero_documento']));
[$status, $html] = image_request('consulta_facturas.php', 'qa-image-admin');
check_image('Image invoice appears in admin consultation', $status === 200 && str_contains($html, $data['numero_documento']));
check_image('Image rejects invoice GET', image_request('guardar_factura.php', 'qa-image-admin')[0] === 405);
echo json_encode(['checks' => $checks], JSON_UNESCAPED_UNICODE) . "\n";

ob_end_flush();
