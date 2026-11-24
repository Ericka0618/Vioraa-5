<?php

require_once __DIR__ . '/trazabilidad.php';

function trazabilidad_chat(mysqli $db, array $input, string $question): ?array
{
    $intent = '/\b(trazabilidad|historial|aprob[oó]|rechaz[oó]|rechazada|fall[oó]|modific[oó]|cambios|activ[oó]|ocurri[oó]|pas[oó]|registr[oó]|gener[oó])\b/iu';
    if (!preg_match($intent, $question)) return null;
    $entities = [
        'solicitud_mantenimiento' => 'solicitud de (?:mantenimiento|reparaci[oó]n)',
        'solicitud_propiedad' => 'solicitud(?: de (?:alquiler|compra|venta))?',
        'factura' => 'factura', 'contrato' => 'contrato', 'apartamento' => 'apartamento',
        'propiedad' => 'propiedad', 'persona' => 'cliente', 'condominio' => 'condominio',
        'edificio' => 'edificio', 'suscripcion' => 'suscripci[oó]n',
        'mantenimiento' => 'mantenimiento', 'pago' => 'pago',
        'encf' => 'comprobante fiscal|e-ncf',
    ];
    $selection = is_array($input['pagina'] ?? null) ? $input['pagina'] : [];
    $query = is_array($selection['registro'] ?? null) ? $selection['registro'] : [];
    $type = $query['entidad'] ?? '';
    $id = $query['referencia'] ?? '';
    if (!is_string($type) || !is_string($id)) $type = $id = '';
    foreach ($entities as $candidate => $pattern) {
        if (preg_match('/\b(?:' . $pattern . ')\s+#?([1-9][0-9]{0,18})\b/iu', $question, $matches)) {
            [$type, $id] = [$candidate, $matches[1]];
            break;
        }
    }
    if (preg_match('/\bE[0-9]{12}\b/i', $question, $matches)) [$type, $id] = ['encf', strtoupper($matches[0])];
    if ($id === '' && !preg_match('/\b(?:' . implode('|', $entities) . ')\b/iu', $question)) return null;
    $user = trazabilidad_identidad($db);
    if (!$user) return ['respuesta' => 'Tu sesión no tiene acceso a la trazabilidad.'];

    if ($id === '' && $user['admin'] && preg_match('/\b(?:solicitud|cliente)\s+de\s+([\p{L}][\p{L} .’\'-]+)[?.!]*$/iu', $question, $name)) {
        $isRequest = preg_match('/\bsolicitud\s+de\s+/iu', $question);
        $lookup = $isRequest
            ? "SELECT s.id_solicitudes FROM solicitud_propiedad s JOIN persona p ON p.id_personas=s.persona_id"
            : "SELECT p.id_personas FROM persona p";
        $found = $db->execute_query($lookup . " WHERE CONCAT_WS(' ',p.nombres,p.apellidos)=? LIMIT 2", [trim($name[1])])->fetch_all(MYSQLI_NUM);
        if (count($found) > 1) return ['respuesta' => 'Hay varios registros con ese nombre. Indica el número del registro que quieres consultar.'];
        if ($found) [$type, $id] = [$isRequest ? 'solicitud_propiedad' : 'persona', (string)$found[0][0]];
    }
    if ($type === '' || $id === '') return ['respuesta' => 'Indica el tipo y número del registro, o abre su opción Ver trazabilidad y pregunta desde allí.'];
    $record = trazabilidad_registro($db, $user, $type, $id);
    if (!$record) return ['respuesta' => 'Ese registro no está disponible para tu cuenta.'];
    $filters = [];
    if (preg_match('/\b(modific[oó]|cambios|precio)\b/iu', $question)) $filters['accion'] = 'Modificar';
    if (preg_match('/\b(cu[aá]ndo)\b.*\b(registr[oó]|gener[oó])\b/iu', $question)) $filters['accion'] = 'Crear';
    if (preg_match('/\bfall[oó]\b/iu', $question)) $filters['estado'] = 'Fallida';
    $status = match (true) {
        (bool)preg_match('/\baprob[oó]\b/iu', $question) => 'Aprobada',
        (bool)preg_match('/\brechaz(?:[oó]|ada)\b/iu', $question) => 'Rechazada',
        (bool)preg_match('/\bactiv[oó]\b/iu', $question) => 'Activo',
        default => null,
    };
    if ($status !== null) {
        $filters['estado_nuevo'] = $status;
        $filters['tablas'] = [$type];
    } elseif (preg_match('/\bprecio\b/iu', $question)) {
        $filters['cambio_precio'] = true;
        $filters['tablas'] = [$type];
    } elseif (preg_match('/\bregistr[oó]\b.*\bpago\b/iu', $question)) {
        $filters['tablas'] = ['pago','factura_pago','viora_pago','suscripcion_pago'];
        $filters['accion'] = 'Crear';
    } elseif (($filters['accion'] ?? '') === 'Crear') {
        $filters['tablas'] = [$type];
    }
    [$rows, $more] = trazabilidad_pagina($db, $record, $filters);
    if (!$rows && !$record['actual']) return ['respuesta' => 'No hay evidencia disponible para ese registro.'];

    $lines = [];
    foreach (array_slice($rows, 0, 6) as $row) {
        $actor = $row['actor_nombre'] ?: ($row['actor_id'] ? 'un usuario cuyo nombre no está disponible para esta consulta' : $row['origen']);
        $lines[] = 'El ' . auditoria_fecha($row['fecha']) . ' (Santo Domingo), ' . $actor
            . ' figura en el evento «' . auditoria_etapa($row) . '» con resultado ' . mb_strtolower($row['estado'])
            . ' (evento #' . $row['id'] . ').';
    }
    $answer = $record['etiqueta'] . ".\n\n" . ($lines ? implode("\n", $lines) : 'No encontré evidencia suficiente en los eventos consultados.');
    if ($more || count($rows) > 6) $answer .= "\nHay más eventos en la vista de trazabilidad.";
    if (preg_match('/\b(?:por qu[eé]|motivo|causa)\b/iu', $question)) $answer .= "\nLos eventos resumidos no permiten establecer por sí solos el motivo.";
    auditoria_evento('Auditoría', 'Consultar IA', 'Chat general: trazabilidad individual', true, [[$type, $id]]);
    $result = ['respuesta' => $answer];
    if (preg_match('/\b(?:reporte|informe|exporta(?:r)?|descarga(?:r)?)\b/iu', $question)) {
        $result['trazabilidad'] = ['entidad' => $type, 'referencia' => $id];
    }
    return $result;
}
