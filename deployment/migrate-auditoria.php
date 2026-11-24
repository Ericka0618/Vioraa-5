<?php
// Run with a dedicated migration account. No historical events are created.
if (PHP_SAPI !== 'cli' || empty($argv[1]) || getenv('DB_NAME') !== $argv[1]) {
    fwrite(STDERR, "Uso: configure DB_NAME y ejecute php deployment/migrate-auditoria.php NOMBRE_BASE\n");
    exit(2);
}
require __DIR__ . '/../Viora/conf/conexion.php';
require_once __DIR__ . '/../Viora/conf/auditoria_catalogo.php';

$lock = 'viora.audit.' . substr(hash('sha256', $argv[1]), 0, 32);
if (!(int)$conexion->execute_query('SELECT GET_LOCK(?, 15)', [$lock])->fetch_row()[0]) {
    throw new RuntimeException('Otra migración está en curso.');
}
$conexion->query("CREATE TABLE IF NOT EXISTS auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    evento CHAR(36) CHARACTER SET ascii NOT NULL UNIQUE,
    fecha DATETIME(6) NOT NULL,
    actor_id BIGINT NULL,
    actor_nombre VARCHAR(100) NULL,
    rol VARCHAR(60) NULL,
    modulo VARCHAR(60) NOT NULL,
    accion VARCHAR(60) NOT NULL,
    descripcion VARCHAR(180) NOT NULL,
    tabla VARCHAR(64) NULL,
    registro VARCHAR(255) NULL,
    anterior JSON NULL,
    nuevo JSON NULL,
    ip VARCHAR(45) NULL,
    estado ENUM('Exitosa','Fallida') NOT NULL,
    seguimiento CHAR(36) CHARACTER SET ascii NOT NULL,
    origen ENUM('Usuario','Sistema','Integración') NOT NULL,
    referencias JSON NOT NULL,
    huella CHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '',
    INDEX(fecha,id), INDEX(actor_id,fecha), INDEX(modulo,fecha),
    INDEX(accion,estado,fecha), INDEX(seguimiento,id), INDEX(tabla,registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
$conexion->query("CREATE TABLE IF NOT EXISTS auditoria_referencia (
    auditoria_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(64) NOT NULL,
    valor VARCHAR(255) NOT NULL,
    PRIMARY KEY (tipo,valor,auditoria_id),
    INDEX(auditoria_id),
    FOREIGN KEY(auditoria_id) REFERENCES auditoria(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

$existing = array_column($conexion->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")->fetch_all(MYSQLI_ASSOC), 'TRIGGER_NAME');
function audit_install_trigger(mysqli $db, array $installed, string $name, string $definition): void
{
    if (!in_array($name, $installed, true)) {
        $db->query("CREATE TRIGGER `$name` $definition");
    }
}

audit_install_trigger($conexion, $existing, 'viora_audit_seal', 'BEFORE INSERT ON auditoria FOR EACH ROW
    SET NEW.huella=' . auditoria_huella_sql('NEW.'));
audit_install_trigger($conexion, $existing, 'viora_audit_refs', "AFTER INSERT ON auditoria FOR EACH ROW
    BEGIN
        DECLARE i INT DEFAULT 0;
        DECLARE ref_tipo VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
        DECLARE ref_valor VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
        WHILE i < JSON_LENGTH(NEW.referencias) DO
            SET ref_tipo=JSON_UNQUOTE(JSON_EXTRACT(NEW.referencias, CONCAT('$[',i,'][0]')));
            SET ref_valor=JSON_UNQUOTE(JSON_EXTRACT(NEW.referencias, CONCAT('$[',i,'][1]')));
            IF ref_valor IS NOT NULL AND ref_valor NOT IN ('null','','0')
                AND NOT EXISTS (SELECT 1 FROM auditoria_referencia WHERE tipo=ref_tipo AND valor=ref_valor AND auditoria_id=NEW.id) THEN
                INSERT INTO auditoria_referencia VALUES (NEW.id,ref_tipo,ref_valor);
            END IF;
            SET i=i+1;
        END WHILE;
    END");
foreach (['auditoria', 'auditoria_referencia'] as $table) {
    foreach (['UPDATE', 'DELETE'] as $operation) {
        // Reference insertion deduplicates its input before reaching this trigger.
        audit_install_trigger($conexion, $existing, "viora_{$table}_deny_" . strtolower($operation),
            "BEFORE $operation ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Registro de auditoria inmutable'");
    }
}

$schema = [];
foreach ($conexion->query("SELECT c.TABLE_NAME,c.COLUMN_NAME,c.COLUMN_KEY,t.ENGINE FROM information_schema.COLUMNS c
    JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
    WHERE c.TABLE_SCHEMA=DATABASE() ORDER BY c.ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC) as $column) {
    $schema[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column;
}
$quote = static fn(string $v): string => "'" . $conexion->real_escape_string($v) . "'";
$count = 0;
foreach (auditoria_catalogo() as $table => [$module, $columns, $relationships]) {
    if (!isset($schema[$table])) throw new RuntimeException("Falta la tabla $table. Complete las migraciones previas.");
    $fields = $schema[$table];
    $keys = array_keys(array_filter($fields, static fn($c) => $c['COLUMN_KEY'] === 'PRI'));
    if (!$keys || reset($fields)['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException("Se requiere InnoDB y clave primaria en $table.");
    }
    $visible = array_intersect(explode(' ', $columns), array_keys($fields));
    $snapshot = static function (string $side) use ($visible, $quote): string {
        $pairs = [];
        foreach ($visible as $field) {
            $pairs[] = $quote($field);
            $pairs[] = "CAST($side.`$field` AS CHAR)";
        }
        return 'JSON_OBJECT(' . implode(',', $pairs) . ')';
    };
    foreach (['INSERT' => 'Crear', 'UPDATE' => 'Modificar', 'DELETE' => 'Eliminar'] as $op => $action) {
        $before = $op === 'INSERT' ? 'NULL' : $snapshot('OLD');
        $after = $op === 'DELETE' ? 'NULL' : $snapshot('NEW');
        $record = static function (string $side) use ($keys): string {
            $parts = array_map(static fn($k) => "CAST($side.`$k` AS CHAR)", $keys);
            if (count($keys) === 1) return $parts[0];
            $encoded = array_map(static fn($part) => "JSON_QUOTE($part)", $parts);
            return "CONCAT('[',CONCAT_WS(','," . implode(',', $encoded) . "),']')";
        };
        $refs = [];
        foreach ($op === 'UPDATE' ? ['OLD', 'NEW'] : [$op === 'DELETE' ? 'OLD' : 'NEW'] as $side) {
            $refs[] = 'JSON_ARRAY(' . $quote($table) . ',' . $record($side) . ')';
            foreach (explode(' ', $relationships) as $relationship) {
                if ($relationship === '') continue;
                [$field, $type] = explode(':', $relationship);
                if (isset($fields[$field])) $refs[] = 'JSON_ARRAY(' . $quote($type) . ",CAST($side.`$field` AS CHAR))";
            }
            if ($table === 'factura_documento' && isset($fields['tipo'], $fields['documento_id'])) {
                $refs[] = "JSON_ARRAY(IF($side.tipo='nota','factura_ecf_nota','factura'),CAST($side.documento_id AS CHAR))";
            }
        }
        $guard = '';
        if ($op === 'UPDATE') {
            $comparisons = array_map(static function ($field) {
                $field = str_replace('`', '``', $field);
                return "(BINARY OLD.`$field` <=> BINARY NEW.`$field`)";
            }, array_keys($fields));
            $guard = 'IF NOT (' . implode(' AND ', $comparisons) . ') THEN ';
        }
        $id = $record($op === 'DELETE' ? 'OLD' : 'NEW');
        $moduleSql = $quote($module);
        $actionSql = $quote($action);
        $description = $quote("$action · $table");
        $references = 'JSON_ARRAY(' . implode(',', $refs) . ')';
        $status = $op !== 'DELETE' && $module === 'Fiscal' && isset($fields['estado'])
            ? "IF(LOWER(NEW.estado) IN ('error','rechazado','rechazada','fallido','fallida','error_preparacion','pendiente_configuracion','pendiente_conciliacion','error_http','desconocido'),'Fallida','Exitosa')" : "'Exitosa'";
        $name = "viora_a1_" . substr(hash('sha256', $table . $op), 0, 20);
        $definition = "AFTER $op ON `$table` FOR EACH ROW BEGIN $guard
            INSERT INTO auditoria (evento,fecha,actor_id,actor_nombre,rol,modulo,accion,descripcion,tabla,registro,anterior,nuevo,ip,estado,seguimiento,origen,referencias)
            VALUES (UUID(),UTC_TIMESTAMP(6),@viora_audit_id,@viora_audit_name,@viora_audit_role,$moduleSql,$actionSql,$description,
                '$table',$id,$before,$after,@viora_audit_ip,$status,COALESCE(@viora_audit_trace,UUID()),COALESCE(@viora_audit_origin,'Sistema'),$references);
            " . ($guard ? 'END IF;' : '') . " END";
        audit_install_trigger($conexion, $existing, $name, $definition);
        $count++;
    }
}
printf("Triggers de negocio instalados o existentes: %d\n", $count);
