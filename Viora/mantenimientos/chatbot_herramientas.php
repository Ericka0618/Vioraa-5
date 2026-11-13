<?php
// Herramientas de consulta de SOLO LECTURA para el asistente IA (solo administradores).
// El modelo nunca escribe SQL: solo elige una herramienta y sus parámetros, y cada
// consulta aquí es fija y parametrizada.

const CHATBOT_MAX_FILAS = 25;

function chatbot_declaraciones_herramientas(): array
{
    $texto = fn(string $d) => ['type' => 'string', 'description' => $d];
    $limite = ['type' => 'integer', 'description' => 'Máximo de filas a devolver (1-' . CHATBOT_MAX_FILAS . '). Por defecto 10.'];

    return [[
        'functionDeclarations' => [
            [
                'name' => 'resumen_general',
                'description' => 'Resumen global del sistema: totales de propiedades, contratos, clientes, facturas pendientes, saldo por cobrar, solicitudes y mantenimientos pendientes.',
                'parameters' => ['type' => 'object', 'properties' => new stdClass()],
            ],
            [
                'name' => 'buscar_clientes',
                'description' => 'Busca clientes/personas por nombre, apellido, cédula, correo o teléfono. Sin texto devuelve los más recientes.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'texto' => $texto('Texto a buscar.'),
                    'limite' => $limite,
                ]],
            ],
            [
                'name' => 'consultar_facturas',
                'description' => 'Lista facturas con cliente, periodo, total, monto pagado y saldo pendiente.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'estado' => $texto('Estado: Pendiente o Pagada.'),
                    'cliente' => $texto('Nombre, apellido o cédula del cliente.'),
                    'periodo' => $texto('Periodo, por ejemplo 2026-10.'),
                    'limite' => $limite,
                ]],
            ],
            [
                'name' => 'cuentas_por_cobrar',
                'description' => 'Saldo pendiente total agrupado por cliente (solo clientes con deuda), de mayor a menor.',
                'parameters' => ['type' => 'object', 'properties' => ['limite' => $limite]],
            ],
            [
                'name' => 'consultar_pagos',
                'description' => 'Lista pagos de facturas registrados, con cliente, monto y método de pago.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'desde' => $texto('Fecha inicial YYYY-MM-DD.'),
                    'hasta' => $texto('Fecha final YYYY-MM-DD.'),
                    'cliente' => $texto('Nombre, apellido o cédula del cliente.'),
                    'limite' => $limite,
                ]],
            ],
            [
                'name' => 'consultar_contratos',
                'description' => 'Lista contratos con cliente, propiedad, vigencia, monto de alquiler y de mantenimiento.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'estado' => $texto('Estado del contrato, por ejemplo Activo.'),
                    'cliente' => $texto('Nombre, apellido o cédula del cliente.'),
                    'vencen_antes_de' => $texto('Solo contratos que terminan antes de esta fecha YYYY-MM-DD.'),
                    'limite' => $limite,
                ]],
            ],
            [
                'name' => 'consultar_propiedades',
                'description' => 'Lista apartamentos con edificio, condominio, precio y estado.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'estado' => $texto('Disponible u Ocupado.'),
                    'tipo_operacion' => $texto('Alquiler o Venta.'),
                    'texto' => $texto('Texto a buscar en número, edificio o condominio.'),
                    'limite' => $limite,
                ]],
            ],
            [
                'name' => 'consultar_solicitudes',
                'description' => 'Lista solicitudes de reparación (tipo=reparacion), de propiedad (tipo=propiedad) o visitas a inmuebles agendadas por clientes (tipo=visita).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'tipo' => $texto('reparacion, propiedad o visita.'),
                    'estado' => $texto('Por ejemplo Pendiente, Completada o Aprobada.'),
                    'desde' => $texto('Solo tipo=visita: fecha de visita desde YYYY-MM-DD.'),
                    'hasta' => $texto('Solo tipo=visita: fecha de visita hasta YYYY-MM-DD.'),
                    'limite' => $limite,
                ], 'required' => ['tipo']],
            ],
            [
                'name' => 'consultar_mantenimientos',
                'description' => 'Lista mantenimientos de condominios con tipo, periodo, fecha, costo y estado.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'estado' => $texto('Programado o Realizado.'),
                    'limite' => $limite,
                ]],
            ],
        ],
    ]];
}

function chatbot_limite(array $args): int
{
    $n = (int)($args['limite'] ?? 10);
    return max(1, min(CHATBOT_MAX_FILAS, $n ?: 10));
}

function chatbot_texto(array $args, string $clave): string
{
    return mb_substr(trim((string)($args[$clave] ?? '')), 0, 80, 'UTF-8');
}

function chatbot_fecha(array $args, string $clave): string
{
    $v = trim((string)($args[$clave] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

function chatbot_like(string $t): string
{
    return '%' . addcslashes($t, '%_\\') . '%';
}

// Ejecuta una consulta preparada y devuelve todas las filas.
function chatbot_consulta(mysqli $db, string $sql, string $tipos = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Consulta no válida.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $filas;
}

// Acumula condiciones WHERE parametrizadas.
function chatbot_filtro(array &$where, string &$tipos, array &$params, string $cond, string $tipo, $valor): void
{
    $where[] = $cond;
    $tipos .= $tipo;
    $params[] = $valor;
}

function chatbot_ejecutar_herramienta(mysqli $db, string $nombre, array $args): array
{
    $limite = chatbot_limite($args);
    $where = [];
    $tipos = '';
    $params = [];
    $clienteSql = "CONCAT_WS(' ', p.nombres, p.apellidos, p.cedula) LIKE ?";

    switch ($nombre) {
        case 'resumen_general':
            $uno = fn(string $sql) => chatbot_consulta($db, $sql)[0] ?? [];
            return [
                'propiedades' => $uno("SELECT COUNT(*) total, SUM(estado='Disponible') disponibles, SUM(estado='Ocupado') ocupadas FROM apartamento WHERE activo=1"),
                'condominios_activos' => (int)($uno("SELECT COUNT(*) n FROM condominio WHERE activo=1")['n'] ?? 0),
                'edificios_activos' => (int)($uno("SELECT COUNT(*) n FROM edificio WHERE activo=1")['n'] ?? 0),
                'clientes_activos' => (int)($uno("SELECT COUNT(*) n FROM persona WHERE activo=1")['n'] ?? 0),
                'contratos_activos' => (int)($uno("SELECT COUNT(*) n FROM contrato WHERE activo=1 AND estado='Activo'")['n'] ?? 0),
                'facturas' => $uno("SELECT COUNT(*) total, SUM(estado='Pendiente') pendientes, SUM(estado='Pagada') pagadas, COALESCE(SUM(saldo_pendiente),0) saldo_por_cobrar FROM factura"),
                'solicitudes_reparacion_pendientes' => (int)($uno("SELECT COUNT(*) n FROM solicitud_mantenimiento WHERE activo=1 AND estado='Pendiente'")['n'] ?? 0),
                'solicitudes_propiedad_pendientes' => (int)($uno("SELECT COUNT(*) n FROM solicitud_propiedad WHERE activo=1 AND estado='Pendiente'")['n'] ?? 0),
                'mantenimientos_programados' => (int)($uno("SELECT COUNT(*) n FROM mantenimiento WHERE activo=1 AND estado='Programado'")['n'] ?? 0),
            ];

        case 'buscar_clientes':
            $t = chatbot_texto($args, 'texto');
            if ($t !== '') {
                chatbot_filtro($where, $tipos, $params, "CONCAT_WS(' ', p.nombres, p.apellidos, p.cedula, p.correo, p.telefono) LIKE ?", 's', chatbot_like($t));
            }
            $sql = "SELECT p.id_personas id, p.nombres, p.apellidos, p.cedula, p.correo, p.telefono, p.activo
                    FROM persona p" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY p.id_personas DESC LIMIT $limite";
            return ['clientes' => chatbot_consulta($db, $sql, $tipos, $params)];

        case 'consultar_facturas':
            if (($e = chatbot_texto($args, 'estado')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'f.estado = ?', 's', $e);
            }
            if (($c = chatbot_texto($args, 'cliente')) !== '') {
                chatbot_filtro($where, $tipos, $params, $clienteSql, 's', chatbot_like($c));
            }
            if (($pe = chatbot_texto($args, 'periodo')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'f.periodo LIKE ?', 's', chatbot_like($pe));
            }
            $sql = "SELECT f.numero_documento, CONCAT_WS(' ', p.nombres, p.apellidos) cliente, f.periodo, f.fecha, f.fecha_vencimiento,
                           f.total, f.monto_pagado, f.saldo_pendiente, f.estado
                    FROM factura f LEFT JOIN persona p ON p.id_personas = f.persona_id"
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY f.fecha DESC, f.id_facturas DESC LIMIT $limite";
            return ['facturas' => chatbot_consulta($db, $sql, $tipos, $params)];

        case 'cuentas_por_cobrar':
            $sql = "SELECT CONCAT_WS(' ', p.nombres, p.apellidos) cliente, p.telefono, COUNT(*) facturas_pendientes,
                           SUM(f.saldo_pendiente) saldo_total
                    FROM factura f JOIN persona p ON p.id_personas = f.persona_id
                    WHERE f.saldo_pendiente > 0
                    GROUP BY p.id_personas, p.nombres, p.apellidos, p.telefono
                    ORDER BY saldo_total DESC LIMIT $limite";
            return ['cuentas_por_cobrar' => chatbot_consulta($db, $sql)];

        case 'consultar_pagos':
            if (($d = chatbot_fecha($args, 'desde')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'fp.fecha_pago >= ?', 's', $d);
            }
            if (($h = chatbot_fecha($args, 'hasta')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'fp.fecha_pago <= ?', 's', $h);
            }
            if (($c = chatbot_texto($args, 'cliente')) !== '') {
                chatbot_filtro($where, $tipos, $params, $clienteSql, 's', chatbot_like($c));
            }
            $sql = "SELECT fp.fecha_pago, fp.monto, fp.metodo_pago, fp.referencia, f.numero_documento factura,
                           CONCAT_WS(' ', p.nombres, p.apellidos) cliente
                    FROM factura_pago fp
                    JOIN factura f ON f.id_facturas = fp.factura_id
                    LEFT JOIN persona p ON p.id_personas = f.persona_id"
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY fp.fecha_pago DESC, fp.id_pagos DESC LIMIT $limite";
            return ['pagos' => chatbot_consulta($db, $sql, $tipos, $params)];

        case 'consultar_contratos':
            if (($e = chatbot_texto($args, 'estado')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'c.estado = ?', 's', $e);
            }
            if (($c = chatbot_texto($args, 'cliente')) !== '') {
                chatbot_filtro($where, $tipos, $params, $clienteSql, 's', chatbot_like($c));
            }
            if (($v = chatbot_fecha($args, 'vencen_antes_de')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'c.fecha_fin < ?', 's', $v);
            }
            $sql = "SELECT c.numero_contrato, CONCAT_WS(' ', p.nombres, p.apellidos) cliente, c.tipo_operacion,
                           CONCAT_WS(' - ', co.nombre, e.nombre, a.numero) propiedad, c.fecha_inicio, c.fecha_fin,
                           c.monto_alquiler, c.monto_mantenimiento, c.estado
                    FROM contrato c
                    LEFT JOIN persona p ON p.id_personas = c.persona_id
                    LEFT JOIN apartamento a ON a.id_apartamentos = c.apartamento_id
                    LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
                    LEFT JOIN condominio co ON co.id_condominios = e.condominio_id"
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY c.fecha_fin ASC LIMIT $limite";
            return ['contratos' => chatbot_consulta($db, $sql, $tipos, $params)];

        case 'consultar_propiedades':
            $where[] = 'a.activo = 1';
            if (($e = chatbot_texto($args, 'estado')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'a.estado = ?', 's', $e);
            }
            if (($o = chatbot_texto($args, 'tipo_operacion')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'a.tipo_operacion = ?', 's', $o);
            }
            if (($t = chatbot_texto($args, 'texto')) !== '') {
                chatbot_filtro($where, $tipos, $params, "CONCAT_WS(' ', a.numero, e.nombre, co.nombre) LIKE ?", 's', chatbot_like($t));
            }
            $sql = "SELECT a.id_apartamentos id, CONCAT('APT-', LPAD(a.id_apartamentos, 3, '0')) codigo, co.nombre condominio, e.nombre edificio, a.numero, a.piso, a.habitaciones, a.banos, a.metros_cuadrados,
                           a.tipo_operacion, a.precio_alquiler, a.precio_venta, a.estado
                    FROM apartamento a
                    LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
                    LEFT JOIN condominio co ON co.id_condominios = e.condominio_id
                    WHERE " . implode(' AND ', $where) . " ORDER BY co.nombre, e.nombre, a.numero LIMIT $limite";
            return ['propiedades' => chatbot_consulta($db, $sql, $tipos, $params)];

        case 'consultar_solicitudes':
            $tipo = strtolower(chatbot_texto($args, 'tipo'));
            if ($tipo === 'visita') {
                if (($e = chatbot_texto($args, 'estado')) !== '') {
                    chatbot_filtro($where, $tipos, $params, 'v.estado = ?', 's', $e);
                }
                if (($d = chatbot_fecha($args, 'desde')) !== '') {
                    chatbot_filtro($where, $tipos, $params, 'DATE(v.fecha_visita) >= ?', 's', $d);
                }
                if (($h = chatbot_fecha($args, 'hasta')) !== '') {
                    chatbot_filtro($where, $tipos, $params, 'DATE(v.fecha_visita) <= ?', 's', $h);
                }
                $sql = "SELECT CONCAT('VIS-', LPAD(v.id_visita, 3, '0')) codigo, v.fecha_visita, CONCAT_WS(' ', p.nombres, p.apellidos) cliente,
                               p.telefono, CONCAT_WS(' - ', co.nombre, e.nombre, a.numero) propiedad, v.observaciones, v.estado
                        FROM visita_propiedad v
                        LEFT JOIN persona p ON p.id_personas = v.persona_id
                        LEFT JOIN apartamento a ON a.id_apartamentos = v.apartamento_id
                        LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
                        LEFT JOIN condominio co ON co.id_condominios = e.condominio_id"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY v.fecha_visita DESC LIMIT $limite";
                return ['visitas' => chatbot_consulta($db, $sql, $tipos, $params)];
            }
            $where[] = 's.activo = 1';
            if (($e = chatbot_texto($args, 'estado')) !== '') {
                chatbot_filtro($where, $tipos, $params, 's.estado = ?', 's', $e);
            }
            if ($tipo === 'propiedad') {
                $sql = "SELECT s.fecha_solicitud, CONCAT_WS(' ', p.nombres, p.apellidos) cliente, s.tipo_operacion,
                               CONCAT_WS(' - ', e.nombre, a.numero) propiedad, s.observaciones, s.estado
                        FROM solicitud_propiedad s
                        LEFT JOIN persona p ON p.id_personas = s.persona_id
                        LEFT JOIN apartamento a ON a.id_apartamentos = s.apartamento_id
                        LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY s.fecha_solicitud DESC LIMIT $limite";
            } else {
                $sql = "SELECT s.fecha_solicitud, CONCAT_WS(' ', p.nombres, p.apellidos) cliente,
                               CONCAT_WS(' - ', e.nombre, a.numero) propiedad, s.descripcion, s.ubicacion, s.prioridad, s.estado
                        FROM solicitud_mantenimiento s
                        LEFT JOIN persona p ON p.id_personas = s.persona_id
                        LEFT JOIN apartamento a ON a.id_apartamentos = s.apartamento_id
                        LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY s.fecha_solicitud DESC LIMIT $limite";
            }
            return ['solicitudes' => chatbot_consulta($db, $sql, $tipos, $params)];

        case 'consultar_mantenimientos':
            $where[] = 'm.activo = 1';
            if (($e = chatbot_texto($args, 'estado')) !== '') {
                chatbot_filtro($where, $tipos, $params, 'm.estado = ?', 's', $e);
            }
            $sql = "SELECT co.nombre condominio, m.tipo, m.periodo, m.descripcion, m.fecha_programada, m.fecha_realizacion,
                           m.costo, m.estado, s.nombre suplidor
                    FROM mantenimiento m
                    LEFT JOIN condominio co ON co.id_condominios = m.condominio_id
                    LEFT JOIN suplidor s ON s.id_suplidores = m.suplidor_id
                    WHERE " . implode(' AND ', $where) . " ORDER BY m.fecha_programada DESC LIMIT $limite";
            return ['mantenimientos' => chatbot_consulta($db, $sql, $tipos, $params)];
    }

    return ['error' => 'Herramienta desconocida.'];
}

// ---------------------------------------------------------------------------
// Herramientas del portal del CLIENTE: solo el catálogo público de propiedades
// disponibles (mismas reglas que catalogo.php). No exponen datos de personas.
// ---------------------------------------------------------------------------

function chatbot_declaraciones_cliente(): array
{
    $entero = fn(string $d) => ['type' => 'integer', 'description' => $d];
    $numero = fn(string $d) => ['type' => 'number', 'description' => $d];
    $texto = fn(string $d) => ['type' => 'string', 'description' => $d];

    return [[
        'functionDeclarations' => [
            [
                'name' => 'buscar_propiedades',
                'description' => 'Busca en el catálogo las propiedades DISPONIBLES que cumplan los criterios del cliente. '
                    . 'Todos los filtros son opcionales y se combinan; envía solo los que el cliente haya pedido.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'operacion' => ['type' => 'string', 'enum' => ['Alquiler', 'Venta'], 'description' => 'Alquiler o Venta (incluye las que ofrecen ambas).'],
                    'habitaciones_min' => $entero('Mínimo de habitaciones.'),
                    'habitaciones_max' => $entero('Máximo de habitaciones.'),
                    'habitaciones_exactas' => $entero('Número exacto de habitaciones.'),
                    'banos_min' => $entero('Mínimo de baños.'),
                    'banos_exactos' => $entero('Número exacto de baños.'),
                    'metros_min' => $numero('Mínimo de metros cuadrados.'),
                    'metros_max' => $numero('Máximo de metros cuadrados.'),
                    'alquiler_max' => $numero('Presupuesto máximo de alquiler mensual en RD$.'),
                    'venta_max' => $numero('Presupuesto máximo de compra en RD$.'),
                    'ubicacion' => $texto('Condominio, edificio, ciudad o provincia.'),
                    'piso_min' => $entero('Piso mínimo.'),
                    'ordenar' => ['type' => 'string', 'enum' => ['precio_asc', 'precio_desc', 'mas_grande', 'mas_habitaciones'], 'description' => 'Orden de los resultados.'],
                    'limite' => ['type' => 'integer', 'description' => 'Máximo de resultados (1-10). Por defecto 5.'],
                ]],
            ],
            [
                'name' => 'opciones_disponibles',
                'description' => 'Resumen de lo que hay en el catálogo: cantidad por tipo de operación, rangos de habitaciones, baños, metros y precios, y las ubicaciones. '
                    . 'Úsala para orientar al cliente cuando no sabe qué pedir o cuando una búsqueda no devuelve resultados.',
                'parameters' => ['type' => 'object', 'properties' => new stdClass()],
            ],
            [
                'name' => 'agendar_visita',
                'description' => 'Prepara una visita a una propiedad disponible cuando tengas fecha y hora. El servidor mostrará el resumen y pedirá confirmación al cliente antes de registrarla.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'apartamento_id' => $entero('ID de la propiedad, tomado de buscar_propiedades.'),
                    'fecha' => $texto('Fecha de la visita en formato YYYY-MM-DD.'),
                    'hora' => $texto('Hora de la visita en formato HH:MM (24 horas).'),
                    'observaciones' => $texto('Notas opcionales del cliente.'),
                    'confirmado' => ['type' => 'boolean', 'description' => 'Envía false: el servidor obtiene la confirmación en un turno posterior.'],
                ], 'required' => ['apartamento_id', 'fecha', 'hora', 'confirmado']],
            ],
            [
                'name' => 'solicitar_operacion',
                'description' => 'Prepara una solicitud de alquiler o compra. El servidor mostrará el resumen y pedirá confirmación al cliente antes de registrarla.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'apartamento_id' => $entero('ID de la propiedad, tomado de buscar_propiedades.'),
                    'operacion' => ['type' => 'string', 'enum' => ['Alquiler', 'Venta'], 'description' => 'Alquiler para rentar o Venta para comprar.'],
                    'observaciones' => $texto('Notas opcionales del cliente.'),
                    'confirmado' => ['type' => 'boolean', 'description' => 'Envía false: el servidor obtiene la confirmación en un turno posterior.'],
                ], 'required' => ['apartamento_id', 'operacion', 'confirmado']],
            ],
        ],
    ]];
}

// Misma regla de disponibilidad que el catálogo del cliente.
const CHATBOT_SQL_CATALOGO = "FROM apartamento a
    INNER JOIN edificio e ON e.id_edificios = a.edificio_id
    INNER JOIN condominio c ON c.id_condominios = e.condominio_id
    WHERE a.activo = 1 AND a.disponible = 1 AND a.estado = 'Disponible'
      AND NOT EXISTS (SELECT 1 FROM solicitud_propiedad sp WHERE sp.apartamento_id = a.id_apartamentos AND sp.estado = 'Aprobada')";

function chatbot_numero(array $args, string $clave): ?float
{
    if (!isset($args[$clave]) || !is_numeric($args[$clave])) {
        return null;
    }
    $v = (float)$args[$clave];
    return $v >= 0 ? $v : null;
}

// $personaId proviene de la sesión (resuelto en chatbot_api.php), nunca de los argumentos del modelo.
function chatbot_ejecutar_cliente(mysqli $db, string $nombre, array $args, ?int $personaId = null): array
{
    if ($nombre === 'agendar_visita') {
        return chatbot_agendar_visita($db, $personaId, $args);
    }
    if ($nombre === 'solicitar_operacion') {
        return chatbot_solicitar_operacion($db, $personaId, $args);
    }

    if ($nombre === 'opciones_disponibles') {
        $resumen = chatbot_consulta($db, "SELECT COUNT(*) total,
                SUM(a.tipo_operacion IN ('Alquiler','Ambos')) en_alquiler,
                SUM(a.tipo_operacion IN ('Venta','Ambos')) en_venta,
                MIN(a.habitaciones) hab_min, MAX(a.habitaciones) hab_max,
                MIN(a.banos) banos_min, MAX(a.banos) banos_max,
                MIN(a.metros_cuadrados) metros_min, MAX(a.metros_cuadrados) metros_max,
                MIN(a.precio_alquiler) alquiler_min, MAX(a.precio_alquiler) alquiler_max,
                MIN(a.precio_venta) venta_min, MAX(a.precio_venta) venta_max " . CHATBOT_SQL_CATALOGO)[0] ?? [];
        $ubicaciones = chatbot_consulta($db, "SELECT c.nombre condominio, c.ciudad, COUNT(*) propiedades "
            . CHATBOT_SQL_CATALOGO . " GROUP BY c.id_condominios, c.nombre, c.ciudad ORDER BY c.nombre");
        return ['resumen' => $resumen, 'ubicaciones' => $ubicaciones];
    }

    if ($nombre !== 'buscar_propiedades') {
        return ['error' => 'Herramienta desconocida.'];
    }

    $where = [];
    $tipos = '';
    $params = [];

    $operacion = chatbot_texto($args, 'operacion');
    if ($operacion === 'Alquiler') {
        $where[] = "a.tipo_operacion IN ('Alquiler','Ambos')";
    } elseif ($operacion === 'Venta') {
        $where[] = "a.tipo_operacion IN ('Venta','Ambos')";
    }

    $filtrosNumericos = [
        ['habitaciones_min', 'a.habitaciones >= ?'],
        ['habitaciones_max', 'a.habitaciones <= ?'],
        ['habitaciones_exactas', 'a.habitaciones = ?'],
        ['banos_min', 'a.banos >= ?'],
        ['banos_exactos', 'a.banos = ?'],
        ['piso_min', 'a.piso >= ?'],
        ['metros_min', 'a.metros_cuadrados >= ?'],
        ['metros_max', 'a.metros_cuadrados <= ?'],
        ['alquiler_max', 'a.precio_alquiler <= ?'],
        ['venta_max', 'a.precio_venta <= ?'],
    ];
    foreach ($filtrosNumericos as [$clave, $cond]) {
        $v = chatbot_numero($args, $clave);
        if ($v !== null) {
            chatbot_filtro($where, $tipos, $params, $cond, 'd', $v);
        }
    }

    if (($u = chatbot_texto($args, 'ubicacion')) !== '') {
        chatbot_filtro($where, $tipos, $params, "CONCAT_WS(' ', c.nombre, e.nombre, c.ciudad, c.provincia) LIKE ?", 's', chatbot_like($u));
    }

    // Para ordenar por precio se usa el precio de la operación pedida.
    $colPrecio = $operacion === 'Venta' ? 'a.precio_venta' : ($operacion === 'Alquiler' ? 'a.precio_alquiler' : 'COALESCE(a.precio_alquiler, a.precio_venta)');
    $orden = [
        'precio_asc' => "$colPrecio ASC",
        'precio_desc' => "$colPrecio DESC",
        'mas_grande' => 'a.metros_cuadrados DESC',
        'mas_habitaciones' => 'a.habitaciones DESC, a.banos DESC',
    ][chatbot_texto($args, 'ordenar')] ?? 'a.id_apartamentos DESC';

    $limite = max(1, min(10, (int)($args['limite'] ?? 5) ?: 5));

    $sql = "SELECT a.id_apartamentos id, CONCAT('APT-', LPAD(a.id_apartamentos, 3, '0')) codigo,
                   e.nombre edificio, a.numero apartamento, c.nombre condominio, c.ciudad, a.piso,
                   a.habitaciones, a.banos, a.metros_cuadrados, a.tipo_operacion,
                   a.precio_alquiler, a.precio_venta, LEFT(COALESCE(a.descripcion, ''), 200) descripcion "
        . CHATBOT_SQL_CATALOGO . ($where ? ' AND ' . implode(' AND ', $where) : '')
        . " ORDER BY $orden LIMIT $limite";

    $filas = chatbot_consulta($db, $sql, $tipos, $params);
    return ['total_encontradas' => count($filas), 'propiedades' => $filas];
}

// ---------------------------------------------------------------------------
// Acciones del cliente: registrar una visita o una solicitud de alquiler/compra.
// Replican las reglas de catalogo.php. Solo se ejecutan con confirmado=true,
// que el modelo envía únicamente después de que el cliente confirme los datos.
// ---------------------------------------------------------------------------

// Persona del cliente autenticado; misma regla que catalogo.php (usuario y persona activos).
function chatbot_persona_cliente(mysqli $db, int $idUsuario): ?int
{
    if ($idUsuario <= 0) {
        return null;
    }
    $filas = chatbot_consulta($db, 'SELECT p.id_personas FROM usuario u
        INNER JOIN persona p ON p.id_personas = u.persona_id
        WHERE u.id_usuarios = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1', 'i', [$idUsuario]);
    return isset($filas[0]['id_personas']) ? (int)$filas[0]['id_personas'] : null;
}

function chatbot_accion_previa(?int $personaId, array $args): ?array
{
    if (!$personaId) {
        return ['error' => 'Tu cuenta no está vinculada a un cliente activo. Contacta al administrador.'];
    }
    if (($args['confirmado'] ?? false) !== true) {
        return ['error' => 'Aún no hay confirmación del cliente. Resume los datos y pregúntale si los confirma.'];
    }
    $id = filter_var($args['apartamento_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        return ['error' => 'Indica la propiedad usando un apartamento_id obtenido de buscar_propiedades.'];
    }
    return null;
}

function chatbot_propiedad_disponible(mysqli $db, int $apartamentoId): ?array
{
    $filas = chatbot_consulta($db, "SELECT a.id_apartamentos id, CONCAT('APT-', LPAD(a.id_apartamentos, 3, '0')) codigo,
            e.nombre edificio, a.numero apartamento, a.tipo_operacion "
        . CHATBOT_SQL_CATALOGO . " AND a.id_apartamentos = ? LIMIT 1", 'i', [$apartamentoId]);
    return $filas[0] ?? null;
}

function chatbot_agendar_visita(mysqli $db, ?int $personaId, array $args): array
{
    if ($error = chatbot_accion_previa($personaId, $args)) {
        return $error;
    }
    $apartamentoId = (int)$args['apartamento_id'];
    $fecha = chatbot_fecha($args, 'fecha');
    $hora = trim((string)($args['hora'] ?? ''));
    $observaciones = trim((string)($args['observaciones'] ?? ''));
    if (mb_strlen($observaciones) > 500) {
        return ['error' => 'Las observaciones son demasiado largas (máximo 500 caracteres).'];
    }

    $propiedad = chatbot_propiedad_disponible($db, $apartamentoId);
    if (!$propiedad) {
        return ['error' => 'Esa propiedad ya no está disponible. Busca otras opciones.'];
    }

    $fechaHora = $fecha !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $fecha . ' ' . $hora) : false;
    if (!$fechaHora || $fechaHora->format('Y-m-d H:i') !== $fecha . ' ' . $hora) {
        return ['error' => 'La fecha u hora no es válida. Usa fecha YYYY-MM-DD y hora HH:MM.'];
    }
    if ($fechaHora <= new DateTimeImmutable()) {
        return ['error' => 'La visita debe programarse para una fecha y hora futura.'];
    }

    $fechaSQL = $fechaHora->format('Y-m-d H:i:s');
    $duplicada = chatbot_consulta($db, "SELECT id_visita FROM visita_propiedad
        WHERE apartamento_id = ? AND persona_id = ? AND fecha_visita = ? AND estado IN ('Pendiente','Aprobada') LIMIT 1",
        'iis', [$apartamentoId, $personaId, $fechaSQL]);
    if ($duplicada) {
        return ['error' => 'Ya tienes una visita registrada para esa propiedad en esa fecha y hora.'];
    }

    $stmt = $db->prepare('INSERT INTO visita_propiedad (apartamento_id, persona_id, fecha_visita, observaciones) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $apartamentoId, $personaId, $fechaSQL, $observaciones);
    $stmt->execute();
    $stmt->close();

    return [
        'ok' => true,
        'mensaje' => 'Visita registrada. Queda pendiente de aprobación por el administrador.',
        'codigo' => $propiedad['codigo'],
        'edificio' => $propiedad['edificio'],
        'apartamento' => $propiedad['apartamento'],
        'fecha_visita' => $fechaHora->format('d/m/Y H:i'),
    ];
}

function chatbot_solicitar_operacion(mysqli $db, ?int $personaId, array $args): array
{
    if ($error = chatbot_accion_previa($personaId, $args)) {
        return $error;
    }
    $apartamentoId = (int)$args['apartamento_id'];
    $operacion = trim((string)($args['operacion'] ?? ''));
    $observaciones = trim((string)($args['observaciones'] ?? ''));
    if (!in_array($operacion, ['Alquiler', 'Venta'], true)) {
        return ['error' => 'La operación debe ser Alquiler (rentar) o Venta (comprar).'];
    }
    if (mb_strlen($observaciones) > 500) {
        return ['error' => 'Las observaciones son demasiado largas (máximo 500 caracteres).'];
    }

    $propiedad = chatbot_propiedad_disponible($db, $apartamentoId);
    if (!$propiedad) {
        return ['error' => 'Esa propiedad ya no está disponible. Busca otras opciones.'];
    }
    if (!in_array($propiedad['tipo_operacion'], [$operacion, 'Ambos'], true)) {
        return ['error' => "Esta propiedad no está disponible para $operacion."];
    }

    $duplicada = chatbot_consulta($db, "SELECT id_solicitudes FROM solicitud_propiedad
        WHERE apartamento_id = ? AND persona_id = ? AND tipo_operacion = ? AND activo = 1
          AND estado IN ('Pendiente','En revision') LIMIT 1",
        'iis', [$apartamentoId, $personaId, $operacion]);
    if ($duplicada) {
        return ['error' => 'Ya tienes una solicitud pendiente de esa operación para esta propiedad.'];
    }

    $stmt = $db->prepare('INSERT INTO solicitud_propiedad (apartamento_id, persona_id, tipo_operacion, observaciones) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $apartamentoId, $personaId, $operacion, $observaciones);
    $stmt->execute();
    $stmt->close();

    return [
        'ok' => true,
        'mensaje' => 'Solicitud registrada. Queda pendiente de revisión por el administrador.',
        'operacion' => $operacion === 'Alquiler' ? 'alquiler' : 'compra',
        'codigo' => $propiedad['codigo'],
        'edificio' => $propiedad['edificio'],
        'apartamento' => $propiedad['apartamento'],
    ];
}

