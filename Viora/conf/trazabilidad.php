<?php

require_once __DIR__ . '/auditoria_consulta.php';

function trazabilidad_identidad(mysqli $db): ?array
{
    if (($_SESSION['logged_in'] ?? false) !== true) return null;
    $id = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
    $user = $db->execute_query('SELECT u.id_usuarios, u.persona_id, r.nombre, p.activo AS persona_activa FROM usuario u
        JOIN role r ON r.id_roles=u.id_rol LEFT JOIN persona p ON p.id_personas=u.persona_id
        WHERE u.activo=1 AND r.activo=1 AND '
        . ($id ? 'u.id_usuarios=?' : 'u.usuario=?'), [$id ?: (string)($_SESSION['usuario'] ?? '')])->fetch_assoc();
    if (!$user) return null;
    $role = mb_strtolower(trim($user['nombre']));
    $user['admin'] = in_array($role, ['administrador', 'admin'], true);
    return $user['admin'] || ($role === 'cliente' && (int)$user['persona_activa'] === 1) ? $user : null;
}

function trazabilidad_registro(mysqli $db, array $user, string $type, string $id): ?array
{
    // Each entry defines a primary key, a display reference and an optional owner column.
    $kinds = [
        'persona' => ['id_personas', "CONCAT_WS(' ',nombres,apellidos)"],
        'condominio' => ['id_condominios', 'nombre'],
        'edificio' => ['id_edificios', 'nombre'],
        'apartamento' => ['id_apartamentos', 'numero'],
        'propiedad' => ['id_propiedades', "CONCAT(tipo_operacion,' · Apartamento #',apartamento_id)"],
        'solicitud_propiedad' => ['id_solicitudes', 'tipo_operacion', 'persona_id'],
        'visita_propiedad' => ['id_visita', 'NULL', 'persona_id'],
        'contrato' => ['id_contratos', 'numero_contrato', 'persona_id'],
        'solicitud_mantenimiento' => ['id_solicitudes', 'NULL', 'persona_id'],
        'mantenimiento' => ['id_mantenimientos', 'periodo'],
        'factura' => ['id_facturas', 'numero_documento', 'persona_id'],
        'pago' => ['id_pagos', 'NULL', 'persona_id'],
        'factura_pago' => ['id_pagos', 'NULL'],
        'viora_factura' => ['id_factura', 'NULL', 'persona_id'],
        'viora_pago' => ['id_pago', 'NULL', 'persona_id'],
        'usuario' => ['id_usuarios', 'nombre'],
        'role' => ['id_roles', 'nombre'],
        'suscripcion' => ['id_suscripcion', 'NULL', 'usuario_id'],
        'factura_ecf' => ['id_factura_ecf', 'encf'],
        'factura_ecf_nota' => ['id_factura_ecf_nota', 'encf'],
        'encf' => ['encf', 'NULL'],
    ];
    if (!isset($kinds[$type]) || !preg_match($type === 'encf' ? '/^E[0-9]{12}$/D' : '/^[1-9][0-9]{0,18}$/D', $id)) return null;
    [$pk, $label] = $kinds[$type];
    $owner = $kinds[$type][2] ?? null;
    $ownerId = $user[$owner === 'usuario_id' ? 'id_usuarios' : 'persona_id'];
    if (!$user['admin'] && !$owner) return null;
    $extra = match ($type) {
        'solicitud_propiedad','solicitud_mantenimiento' => ', fecha_solicitud AS fecha_registrada, estado',
        'factura' => ', fecha AS fecha_registrada, estado',
        'contrato' => ', estado, (activo=1 AND archivo_contrato IS NOT NULL AND LENGTH(archivo_contrato)>0) AS tiene_pdf',
        'suscripcion' => ', estado, paypal_subscription_id',
        'factura_ecf','factura_ecf_nota' => ', estado',
        default => '',
    };
    $params = [$id];
    if (!$user['admin']) $params[] = $ownerId;
    $row = $type !== 'encf' ? $db->execute_query("SELECT $label AS etiqueta $extra FROM $type WHERE $pk=?"
        . ($user['admin'] ? '' : " AND $owner=?"), $params)->fetch_assoc() : null;
    if (!$row && !$user['admin']) return null;
    return [
        'tipo' => $type, 'id' => $id, 'actual' => $row,
        'etiqueta' => (auditoria_entidades()[$type] ?? ['factura_ecf' => 'Comprobante fiscal',
            'factura_ecf_nota' => 'Nota de crédito', 'visita_propiedad' => 'Visita'][$type] ?? $type)
            . ' #' . $id . (empty($row['etiqueta']) || $row['etiqueta'] === $id ? '' : ' · ' . $row['etiqueta']),
        'owner' => $user['admin'] ? null : $owner,
        'owner_id' => $ownerId,
        'usuario_id' => $user['id_usuarios'],
        'admin' => $user['admin'],
    ];
}

function trazabilidad_pagina(mysqli $db, array $record, array $input = [], int $limit = 50): array
{
    $type = $record['tipo'];
    $id = $record['id'];
    $admin = $record['admin'];
    $invoiceTables = "'factura_detalle','factura_pago','factura_ecf','factura_ecf_nota','factura_documento','ecf_envio','ecf_nota_operacion'";
    $contractTables = "'contrato_detalle_persona','renovacion_contrato','ocupacion','documento','pago','factura','viora_factura'";
    $direct = match ($type) {
        'factura' => $invoiceTables,
        'contrato' => $admin ? $contractTables : "'renovacion_contrato','factura'",
        'solicitud_propiedad' => "'contrato','visita_propiedad'",
        'solicitud_mantenimiento' => $admin ? "'mantenimiento_solicitud'" : "''",
        'apartamento' => "'propiedad','ocupacion','visita_propiedad'",
        'edificio' => "'apartamento'",
        'condominio' => "'edificio','mantenimiento'",
        'persona' => "'persona_rol','usuario'",
        'role' => "'usuario'",
        'usuario' => "'suscripcion','suscripcion_pago'",
        'pago' => $admin ? "'transaccion_paypal','comprobante_fiscal'" : "''",
        'factura_ecf_nota' => "'ecf_envio','ecf_nota_operacion','factura_documento'",
        'encf' => "'factura_ecf','factura_ecf_nota','comprobante_fiscal','viora_factura'",
        default => "''",
    };
    // Related stages follow business keys in one direction. Shared client or apartment
    // references cannot expand a request into a second request.
    $scopes = ["SELECT ? AS tipo, ? AS valor, 'directo' AS tramo"];
    $scopeParams = [$type, $id];
    if ($admin && in_array($type, ['solicitud_propiedad', 'contrato'], true)) {
        if ($type === 'solicitud_propiedad') {
            $scopes[] = "SELECT 'contrato', CAST(id_contratos AS CHAR), 'contratos' FROM contrato WHERE solicitud_id=?";
            $scopeParams[] = $id;
        }
        $scopes[] = "SELECT 'factura', CAST(f.id_facturas AS CHAR), 'facturas' FROM factura f JOIN contrato c ON c.id_contratos=f.contrato_id WHERE "
            . ($type === 'contrato' ? 'c.id_contratos=?' : 'c.solicitud_id=?');
        $scopeParams[] = $id;
    }
    if ($admin && $type === 'solicitud_mantenimiento') {
        $scopes[] = "SELECT 'mantenimiento', CAST(mantenimiento_id AS CHAR), 'ejecucion'
            FROM mantenimiento_solicitud WHERE solicitud_id=?";
        $scopeParams[] = $id;
    }
    if ($admin && $type === 'persona') {
        $scopes[] = "SELECT 'usuario', CAST(id_usuarios AS CHAR), 'cuentas' FROM usuario WHERE persona_id=?";
        $scopeParams[] = $id;
    }
    if ($type === 'factura_ecf' && $admin) {
        $scopes[] = "SELECT 'factura', CAST(factura_id AS CHAR), 'fiscal' FROM factura_ecf WHERE id_factura_ecf=?";
        $scopeParams[] = $id;
    }
    if (!empty($record['actual']['paypal_subscription_id'])) {
        $scopes[] = "SELECT 'paypal_suscripcion', ?, 'paypal'";
        $scopeParams[] = $record['actual']['paypal_subscription_id'];
    }
    if ($admin && $type === 'suscripcion') {
        $scopes[] = "SELECT oldref.tipo, oldref.valor, 'paypal' FROM auditoria_referencia root
            JOIN auditoria a ON a.id=root.auditoria_id AND a.tabla='suscripcion'
            JOIN auditoria_referencia oldref ON oldref.auditoria_id=root.auditoria_id AND oldref.tipo='paypal_suscripcion'
            WHERE root.tipo='suscripcion' AND root.valor=?";
        $scopeParams[] = $id;
    }
    if ($admin && $type === 'propiedad') {
        $scopes[] = "SELECT 'apartamento', CAST(apartamento_id AS CHAR), 'ejecucion' FROM propiedad WHERE id_propiedades=?";
        $scopeParams[] = $id;
    }
    $scoped = "((s.tramo='directo' AND (a.tabla IN (s.tipo,$direct)"
        . ($admin ? ' OR a.tabla IS NULL' : '') . "))
        OR (s.tramo='contratos' AND (a.tabla IN ('contrato',$contractTables) OR a.tabla IS NULL))
        OR (s.tramo='facturas' AND (a.tabla IN ('factura',$invoiceTables) OR a.tabla IS NULL))
        OR (s.tramo='ejecucion' AND a.tabla=s.tipo)
        OR (s.tramo='cuentas' AND a.tabla IN ('usuario','suscripcion','suscripcion_pago'))
        OR (s.tramo='fiscal' AND a.tabla IN ('ecf_envio','ecf_nota_operacion'))
        OR (s.tramo='paypal' AND " . ($admin ? "(a.tabla IN ('suscripcion_pago','paypal_evento') OR a.tabla IS NULL)"
            : "a.tabla IN ('suscripcion_pago','paypal_evento')") . "))";
    [$where, $params] = auditoria_filtros(array_intersect_key($input, array_flip(['desde','hasta','modulo','accion','estado'])));
    $where .= " AND a.modulo<>'Auditoría'";
    $tables = array_values(array_intersect(array_keys(auditoria_catalogo()), $input['tablas'] ?? []));
    if ($tables) {
        $where .= ' AND a.tabla IN (' . implode(',', array_fill(0, count($tables), '?')) . ')';
        array_push($params, ...$tables);
    }
    if (isset($input['estado_nuevo'])) {
        $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(a.nuevo,'$.estado'))=?
            AND NOT (JSON_EXTRACT(a.anterior,'$.estado') <=> JSON_EXTRACT(a.nuevo,'$.estado'))";
        $params[] = $input['estado_nuevo'];
    }
    if (!empty($input['cambio_precio'])) {
        $where .= " AND (NOT (JSON_EXTRACT(a.anterior,'$.precio') <=> JSON_EXTRACT(a.nuevo,'$.precio'))
            OR NOT (JSON_EXTRACT(a.anterior,'$.precio_alquiler') <=> JSON_EXTRACT(a.nuevo,'$.precio_alquiler'))
            OR NOT (JSON_EXTRACT(a.anterior,'$.precio_venta') <=> JSON_EXTRACT(a.nuevo,'$.precio_venta')))";
    }
    if (!$admin) {
        // Authorize both snapshots for owner-bearing records and reject historical
        // events whose references connect the same entity type to multiple records.
        $owner = $record['owner'];
        $where .= " AND (a.tabla<>? OR (a.anterior IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(a.anterior,'$.$owner'))=?)
            AND (a.nuevo IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(a.nuevo,'$.$owner'))=?))
            AND (a.tabla NOT IN ('contrato','visita_propiedad','factura','suscripcion_pago') OR
                (a.anterior IS NULL OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.anterior,'$.persona_id')),
                    JSON_UNQUOTE(JSON_EXTRACT(a.anterior,'$.usuario_id')))=?)
                AND (a.nuevo IS NULL OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.nuevo,'$.persona_id')),
                    JSON_UNQUOTE(JSON_EXTRACT(a.nuevo,'$.usuario_id')))=?))";
        array_push($params, $type, (string)$record['owner_id'], (string)$record['owner_id'],
            (string)$record['owner_id'], (string)$record['owner_id']);
        $scoped .= " AND NOT EXISTS (SELECT 1 FROM auditoria_referencia other
            WHERE other.auditoria_id=a.id AND other.tipo=r.tipo AND other.valor<>r.valor)";
        // Children of a record that changed hands cannot be attributed to the current owner.
        $changedOwner = $db->execute_query("SELECT 1 FROM auditoria_referencia r JOIN auditoria a ON a.id=r.auditoria_id
            WHERE r.tipo=? AND r.valor=? AND a.tabla=? AND a.anterior IS NOT NULL AND a.nuevo IS NOT NULL
            AND NOT (JSON_EXTRACT(a.anterior,'$.$owner') <=> JSON_EXTRACT(a.nuevo,'$.$owner')) LIMIT 1",
            [$type, $id, $type])->fetch_row();
        if ($changedOwner) {
            $where .= ' AND a.tabla=?';
            $params[] = $type;
        }
    }
    $beforeId = filter_var($input['antes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $beforeDate = $input['fecha_cursor'] ?? '';
    if ($beforeId && is_string($beforeDate) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/D', $beforeDate)) {
        $where .= ' AND (a.fecha<? OR (a.fecha=? AND a.id<?))';
        array_push($params, $beforeDate, $beforeDate, $beforeId);
    }
    $sql = 'WITH alcance AS (' . implode(' UNION ALL ', $scopes) . ")
        SELECT a.* FROM auditoria a JOIN (
            SELECT DISTINCT r.auditoria_id, a.fecha FROM alcance s
            JOIN auditoria_referencia r ON r.tipo=s.tipo AND r.valor=s.valor
            JOIN auditoria a ON a.id=r.auditoria_id WHERE $where AND $scoped
            ORDER BY a.fecha DESC,r.auditoria_id DESC LIMIT " . ($limit + 1) . "
        ) p ON p.auditoria_id=a.id ORDER BY a.fecha DESC,a.id DESC";
    $rows = $db->execute_query($sql, array_merge($scopeParams, $params))->fetch_all(MYSQLI_ASSOC);
    $hasNext = count($rows) > $limit;
    if ($hasNext) array_pop($rows);
    if (!$admin) {
        foreach ($rows as &$row) {
            $row['actor_nombre'] = (int)$row['actor_id'] === (int)$record['usuario_id']
                ? 'Tu cuenta' : null;
            $row['rol'] = null;
            $row['seguimiento'] = '';
            $row['referencias'] = json_encode([[$type, $id]], JSON_THROW_ON_ERROR);
            foreach (['anterior', 'nuevo'] as $key) {
                if ($row[$key] !== null) {
                    $values = json_decode($row[$key], true) ?? [];
                    $row[$key] = json_encode(array_filter($values, static fn($field) => !str_ends_with($field, '_id'), ARRAY_FILTER_USE_KEY), JSON_THROW_ON_ERROR);
                }
            }
        }
        unset($row);
    }
    return [$rows, $hasNext];
}

function trazabilidad_cambios(array $row): array
{
    $before = json_decode($row['anterior'] ?? 'null', true) ?? [];
    $after = json_decode($row['nuevo'] ?? 'null', true) ?? [];
    $changes = [];
    foreach (array_keys($before + $after) as $key) {
        if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
            $changes[$key] = [$before[$key] ?? null, $after[$key] ?? null];
        }
    }
    return $changes;
}
