<?php

require_once __DIR__ . '/auditoria_catalogo.php';

const AUDITORIA_FINANCIERA = "modulo IN ('Facturación','Pagos','Fiscal','Suscripciones','Cuentas por cobrar')";

function auditoria_reportes(): array
{
    return [
        1 => ['Historial general', '1'],
        2 => ['Actividad por usuario', 'actor_id IS NOT NULL'],
        3 => ['Actividad por módulo', '1'],
        4 => ['Historial de accesos', "modulo IN ('Autenticación','Seguridad')"],
        5 => ['Intentos fallidos de autenticación', "accion='Inicio de sesión' AND estado='Fallida'"],
        6 => ['Registros creados', "accion='Crear'"],
        7 => ['Registros modificados', "accion='Modificar'"],
        8 => ['Registros eliminados', "accion='Eliminar'"],
        9 => ['Operaciones financieras', AUDITORIA_FINANCIERA],
        10 => ['Trazabilidad de contratos', "modulo='Contratos'"],
        11 => ['Trazabilidad de propiedades', "modulo='Propiedades'"],
        12 => ['Trazabilidad de facturas', "modulo IN ('Facturación','Fiscal') OR tabla IN ('factura_pago','viora_pago')"],
        13 => ['Trazabilidad de pagos', "modulo='Pagos'"],
        14 => ['Trazabilidad de suscripciones', "modulo='Suscripciones'"],
        15 => ['Historial de comprobantes fiscales', "modulo='Fiscal'"],
        16 => ['Operaciones fallidas', "estado='Fallida'"],
        17 => ['Cambios de roles y permisos', "tabla='role' OR (tabla='usuario' AND NOT (JSON_EXTRACT(anterior,'$.id_rol') <=> JSON_EXTRACT(nuevo,'$.id_rol')))"],
        18 => ['Seguimiento de solicitudes', "modulo='Solicitudes'"],
        19 => ['Trazabilidad de mantenimientos', "modulo='Mantenimiento'"],
        20 => ['Cambios de configuración', "modulo='Configuración' AND tabla IS NOT NULL"],
    ];
}

function auditoria_entidades(): array
{
    return [
        'persona' => 'Cliente', 'apartamento' => 'Apartamento', 'propiedad' => 'Propiedad',
        'contrato' => 'Contrato', 'factura' => 'Factura', 'viora_factura' => 'Factura VIORA',
        'solicitud_propiedad' => 'Solicitud de compra o alquiler', 'solicitud_mantenimiento' => 'Solicitud de mantenimiento',
        'mantenimiento' => 'Mantenimiento', 'pago' => 'Pago', 'factura_pago' => 'Pago de factura',
        'viora_pago' => 'Pago VIORA', 'suscripcion' => 'Suscripción', 'encf' => 'Comprobante fiscal',
        'usuario' => 'Usuario', 'condominio' => 'Condominio', 'edificio' => 'Edificio',
        'visita_propiedad' => 'Visita', 'factura_ecf' => 'Comprobante fiscal', 'factura_ecf_nota' => 'Nota de crédito',
    ];
}

function auditoria_filtros(array $input): array
{
    $where = [];
    $parameters = [];
    foreach (['desde', 'hasta'] as $key) {
        $date = $input[$key] ?? '';
        if (!is_string($date) || $date === '') continue;
        $local = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/Santo_Domingo'));
        if (!$local || $local->format('Y-m-d') !== $date) throw new InvalidArgumentException('Fecha inválida.');
        if ($key === 'hasta') $local = $local->modify('+1 day');
        $where[] = $key === 'desde' ? 'fecha>=?' : 'fecha<?';
        $parameters[] = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    foreach (['actor_id','rol','modulo','accion','estado','seguimiento'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_scalar($value) || trim((string)$value) === '') continue;
        $value = mb_substr(trim((string)$value), 0, 100);
        if ($key === 'actor_id' && !ctype_digit($value)) throw new InvalidArgumentException('ID de usuario inválido.');
        $where[] = "$key=?";
        $parameters[] = $value;
    }
    $search = $input['q'] ?? '';
    if (is_string($search) && trim($search) !== '') {
        $where[] = "LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',id,actor_nombre,rol,modulo,accion,descripcion,tabla,registro)))>0";
        $parameters[] = mb_substr(trim($search), 0, 150);
    }
    foreach (auditoria_entidades() as $type => $label) {
        $value = $input[$type] ?? '';
        if (!is_scalar($value) || trim((string)$value) === '') continue;
        $where[] = 'EXISTS (SELECT 1 FROM auditoria_referencia r WHERE r.auditoria_id=a.id AND r.tipo=? AND r.valor=?)';
        array_push($parameters, $type, mb_substr(trim((string)$value), 0, 255));
    }
    $report = (int)($input['reporte'] ?? 1);
    $where[] = '(' . (auditoria_reportes()[$report] ?? auditoria_reportes()[1])[1] . ')';
    return [implode(' AND ', $where), $parameters];
}

function auditoria_indicadores(mysqli $db, string $where, array $params): array
{
    return $db->execute_query("SELECT COUNT(*) total, COUNT(DISTINCT actor_id) usuarios,
        COALESCE(SUM(estado='Exitosa'),0) exitosas, COALESCE(SUM(estado='Fallida'),0) fallidas,
        COALESCE(SUM(accion='Inicio de sesión' AND estado='Fallida'),0) accesos,
        COALESCE(SUM(accion='Modificar'),0) modificadas, COALESCE(SUM(accion='Eliminar'),0) eliminadas,
        COALESCE(SUM(" . AUDITORIA_FINANCIERA . " AND tabla IS NOT NULL),0) financieras
        FROM auditoria a WHERE $where", $params)->fetch_assoc();
}

function auditoria_fecha(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/Santo_Domingo'))->format('Y-m-d H:i:s');
}

function auditoria_linea(mysqli $db, string $type, string $id): array
{
    if (!isset(auditoria_entidades()[$type]) || $id === '' || mb_strlen($id) > 100) {
        throw new InvalidArgumentException('Seleccione una entidad y un identificador válidos.');
    }
    // Strong entities connect workflow stages. Client and apartment are shared context
    // and cannot expand a workflow into other clients' contracts or properties.
    $strong = array_flip(explode(' ', 'contrato solicitud_propiedad solicitud_mantenimiento mantenimiento factura viora_factura factura_pago viora_pago pago suscripcion paypal_suscripcion factura_ecf factura_ecf_nota encf'));
    $queue = [[$type, $id]];
    $seen = $events = $clients = [];
    $limited = false;
    $catalog = auditoria_catalogo();
    while ($queue && count($seen) < 150 && count($events) < 1500) {
        [$kind, $value] = array_shift($queue);
        $key = "$kind:$value";
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $rows = $db->execute_query('SELECT a.* FROM auditoria_referencia r JOIN auditoria a ON a.id=r.auditoria_id
            WHERE r.tipo=? AND r.valor=? AND a.modulo<>\'Auditoría\' ORDER BY r.auditoria_id DESC LIMIT 1501', [$kind, $value])->fetch_all(MYSQLI_ASSOC);
        $related = [];
        foreach ($rows as $row) {
            $events[$row['id']] = $row;
            if (count($events) > 1500) {
                $limited = true;
                break;
            }
            $refs = json_decode($row['referencias'], true, 20, JSON_THROW_ON_ERROR);
            foreach ($refs as [$refType, $refId]) {
                if (!is_scalar($refId) || (string)$refId === '' || (string)$refId === '0') continue;
                if (isset($strong[$refType]) || ($type === 'propiedad' && $kind === 'propiedad' && $refType === 'apartamento')) {
                    $related[$refType . ':' . $refId] = [$refType, (string)$refId];
                }
                if (in_array($type, ['contrato', 'solicitud_propiedad'], true) && $refType === 'persona'
                    && in_array($row['tabla'], ['contrato','solicitud_propiedad'], true)) {
                    $clients[(string)$refId] = (string)$refId;
                }
            }
            // Include account creation and subscriptions for this client's account.
            if ($row['tabla'] === 'usuario' && $row['registro'] && $type === 'persona') {
                $related['usuario:' . $row['registro']] = ['usuario', $row['registro']];
            }
        }
        // Follow parent relations from pre-existing entities without creating historical events.
        if (isset($catalog[$kind]) && ctype_digit($value)) {
            $columns = $db->execute_query("SELECT COLUMN_NAME,COLUMN_KEY FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$kind])->fetch_all(MYSQLI_ASSOC);
            $pk = array_column(array_filter($columns, static fn($c) => $c['COLUMN_KEY'] === 'PRI'), 'COLUMN_NAME');
            if (count($pk) === 1 && preg_match('/^\w+$/D', $pk[0])) {
                $allowed = array_column($columns, 'COLUMN_NAME');
                foreach (explode(' ', $catalog[$kind][2]) as $relation) {
                    if (!$relation) continue;
                    [$field, $refType] = explode(':', $relation);
                    $follow = isset($strong[$refType]) || ($kind === 'propiedad' && $type === 'propiedad' && $refType === 'apartamento');
                    if (!$follow || !in_array($field, $allowed, true)) continue;
                    $parent = $db->execute_query("SELECT `$field` FROM `$kind` WHERE `{$pk[0]}`=?", [$value])->fetch_row()[0] ?? null;
                    if ($parent) $related[$refType . ':' . $parent] = [$refType, (string)$parent];
                }
            }
        }
        foreach ($related as $nextKey => $reference) if (!isset($seen[$nextKey])) $queue[$nextKey] = $reference;
    }
    foreach (array_slice($clients, 0, 15) as $client) {
        $onboarding = $db->execute_query("SELECT a.* FROM auditoria_referencia r JOIN auditoria a ON a.id=r.auditoria_id
            WHERE r.tipo='persona' AND r.valor=? AND a.tabla IN ('persona','persona_rol','usuario') AND a.accion='Crear'
            ORDER BY a.id DESC LIMIT 31", [$client])->fetch_all(MYSQLI_ASSOC);
        $accounts = array_values(array_filter(array_column(array_filter($onboarding, static fn($r) => $r['tabla'] === 'usuario'), 'registro')));
        $current = $db->execute_query('SELECT id_usuarios FROM usuario WHERE persona_id=? ORDER BY id_usuarios LIMIT 31', [$client])->fetch_all(MYSQLI_NUM);
        $accounts = array_unique(array_merge($accounts, array_column($current, 0)));
        if (count($onboarding) > 30 || count($accounts) > 30) $limited = true;
        foreach (array_slice($accounts, 0, 30) as $account) {
            $subscriptions = $db->execute_query("SELECT a.* FROM auditoria_referencia r JOIN auditoria a ON a.id=r.auditoria_id
                WHERE r.tipo='usuario' AND r.valor=? AND a.tabla IN ('suscripcion','suscripcion_pago') ORDER BY a.id DESC LIMIT 31",
                [(string)$account])->fetch_all(MYSQLI_ASSOC);
            if (count($subscriptions) > 30) $limited = true;
            $onboarding = array_merge($onboarding, array_slice($subscriptions, 0, 30));
        }
        foreach ($onboarding as $event) {
            $events[$event['id']] = $event;
            if (count($events) > 1500) break 2;
        }
    }
    uasort($events, static fn($a, $b) => [$a['fecha'], $a['id']] <=> [$b['fecha'], $b['id']]);
    return [array_slice(array_values($events), 0, 1500), $limited || (bool)$queue || count($events) > 1500 || count($clients) > 15];
}

function auditoria_etapa(array $row): string
{
    $label = [
        'persona' => 'Registro de cliente', 'usuario' => 'Cuenta de usuario', 'solicitud_propiedad' => 'Solicitud de propiedad',
        'contrato' => 'Contrato', 'ocupacion' => 'Ocupación de la propiedad', 'factura' => 'Factura y cuentas por cobrar',
        'viora_factura' => 'Factura VIORA', 'factura_pago' => 'Aplicación de pago', 'pago' => 'Registro de pago',
        'viora_pago' => 'Pago VIORA', 'factura_ecf' => 'Comprobante fiscal', 'factura_ecf_nota' => 'Nota de crédito',
        'suscripcion' => 'Suscripción', 'suscripcion_pago' => 'Pago de suscripción',
        'solicitud_mantenimiento' => 'Solicitud de mantenimiento', 'ecf_envio' => 'Envío fiscal',
    ][$row['tabla']] ?? $row['modulo'];
    $values = json_decode($row['nuevo'] ?? 'null', true) ?? [];
    return implode(' · ', array_filter([$label, $row['accion'], $values['tipo_operacion'] ?? null, $values['estado'] ?? null]));
}

function auditoria_documentos(array $row): array
{
    $refs = json_decode($row['referencias'], true) ?? [];
    $documents = [];
    foreach ($refs as [$type, $id]) {
        if (!is_scalar($id) || !ctype_digit((string)$id)) continue;
        if ($type === 'contrato') $documents["Contrato #$id"] = '../mantenimientos/ver_contrato.php?id=' . $id;
        if ($type === 'factura') $documents["Factura #$id"] = '../mantenimientos/factura_pdf.php?id=' . $id;
        if ($type === 'factura_ecf_nota') $documents["Nota de crédito #$id"] = '../mantenimientos/factura_pdf.php?nota=' . $id;
    }
    return $documents;
}
