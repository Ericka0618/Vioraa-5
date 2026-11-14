<?php
// Synthetic, non-fiscal fixtures. Refuse production destinations and nonempty users.
if (getenv('DB_NAME') !== 'viora_csrf_qa' || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'invoice-csrf-db'], true)) exit(90);
$db = new mysqli(getenv('DB_HOST'), 'root', '', 'viora_csrf_qa', (int)(getenv('DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');
if ((int)$db->query('SELECT COUNT(*) FROM usuario')->fetch_row()[0] !== 0) throw new RuntimeException('QA fixture must be empty');
function seed_csrf(string $table, array $values): void {
    global $db;
    foreach ($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC) as $f) {
        if (array_key_exists($f['Field'], $values) || $f['Null'] === 'YES' || $f['Default'] !== null || str_contains($f['Extra'], 'auto_increment')) continue;
        if (preg_match("/^enum\('([^']+)'/", $f['Type'], $m)) $value = $m[1];
        elseif (preg_match('/int|decimal|float|double/', $f['Type'])) $value = 1;
        elseif (preg_match('/date|timestamp/', $f['Type'])) $value = '2026-01-01';
        else $value = 'QA-' . ($values['id_usuarios'] ?? 1);
        $values[$f['Field']] = $value;
    }
    $columns = implode(',', array_map(fn($k) => '`' . $k . '`', array_keys($values)));
    $q = $db->prepare('INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $args = array_values($values); $q->bind_param(str_repeat('s', count($args)), ...$args); $q->execute();
}
$db->query('SET FOREIGN_KEY_CHECKS=0');
seed_csrf('role', ['id_roles' => 1, 'nombre' => 'Administrador']);
seed_csrf('role', ['id_roles' => 2, 'nombre' => 'Cliente']);
foreach ([1, 2, 3] as $id) {
    seed_csrf('persona', ['id_personas' => $id, 'nombres' => 'QA', 'apellidos' => 'Navegador ' . $id]);
    seed_csrf('usuario', ['id_usuarios' => $id, 'id_rol' => $id === 1 ? 1 : 2, 'persona_id' => $id, 'usuario' => 'qa-browser-' . $id, 'clave' => password_hash(getenv('QA_BROWSER_PASSWORD') ?: 'isolated-browser-fixture', PASSWORD_DEFAULT), 'activo' => 1, 'estado_cuenta' => 'Activo']);
    if ($id !== 1) seed_csrf('suscripcion', ['usuario_id' => $id, 'estado' => 'Activo', 'fecha_proximo_pago' => '2099-12-31']);
}
seed_csrf('condominio', ['id_condominios' => 1, 'nombre' => 'QA Condominio', 'direccion' => 'Entorno aislado']);
seed_csrf('edificio', ['id_edificios' => 1, 'condominio_id' => 1, 'nombre' => 'QA Edificio']);
foreach ([1, 2] as $id) {
    seed_csrf('apartamento', ['id_apartamentos' => $id, 'edificio_id' => 1, 'numero' => 'QA-' . $id]);
    seed_csrf('contrato', ['id_contratos' => $id, 'apartamento_id' => $id, 'numero_contrato' => 'QA-CSRF-' . $id, 'persona_id' => 2, 'fecha_inicio' => '2020-01-01', 'fecha_fin' => '2099-12-31', 'monto_alquiler' => 1000 * $id, 'monto_mantenimiento' => 100 * $id, 'activo' => 1, 'estado' => 'Activo', 'tipo_operacion' => 'Alquiler']);
}
$db->query('SET FOREIGN_KEY_CHECKS=1');
echo "Isolated browser fixture initialized\n";
