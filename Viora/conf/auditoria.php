<?php

function auditoria_huella_sql(string $prefix = ''): string
{
    $columns = explode(' ', 'evento fecha actor_id actor_nombre rol modulo accion descripcion tabla registro anterior nuevo ip estado seguimiento origen referencias');
    $values = array_map(static fn($column) => "CAST($prefix$column AS CHAR CHARACTER SET utf8mb4)", $columns);
    return 'CAST(SHA2(JSON_ARRAY(' . implode(',', $values) . '),256) AS CHAR CHARACTER SET ascii) COLLATE ascii_general_ci';
}

function auditoria_contexto(): array
{
    static $trace;
    if ($trace === null) {
        $hex = bin2hex(random_bytes(16));
        $trace = sprintf('%s-%s-4%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3),
            dechex((hexdec($hex[16]) & 3) | 8) . substr($hex, 17, 3), substr($hex, 20));
    }
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $external = $script === 'paypal_webhook.php';
    $authenticated = !$external && ($_SESSION['logged_in'] ?? false) === true;
    return [
        $authenticated ? ((int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0) ?: null) : null,
        $authenticated ? mb_substr((string)($_SESSION['nombre_usuario'] ?? $_SESSION['usuario'] ?? ''), 0, 100) : null,
        $authenticated ? mb_substr((string)($_SESSION['rol'] ?? ''), 0, 60) : null,
        filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null,
        $trace,
        $external ? 'Integración' : (PHP_SAPI === 'cli' ? 'Sistema' : 'Usuario'),
    ];
}

function viora_abrir_bd(): mysqli
{
    $db = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'viora',
        (int)(getenv('DB_PORT') ?: 3306),
        getenv('DB_SOCKET') ?: null
    );
    $db->set_charset('utf8mb4');
    return $db;
}

function auditoria_evento(string $module, string $action, string $description, bool $success, array $refs = [], bool $anonymous = false, ?array $actor = null, ?string $eventOrigin = null): void
{
    static $db;
    [$id, $name, $role, $ip, $trace, $origin] = $actor ?? $GLOBALS['viora_audit_actor'] ?? auditoria_contexto();
    $origin = $eventOrigin ?? $origin;
    if ($anonymous) $id = $name = $role = null;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db ??= viora_abrir_bd();
        $existing = $db->query("SHOW TABLES LIKE 'auditoria'")->num_rows;
        if (!$existing) return;
        $db->execute_query('INSERT INTO auditoria
            (evento,fecha,actor_id,actor_nombre,rol,modulo,accion,descripcion,ip,estado,seguimiento,origen,referencias)
            VALUES (UUID(),UTC_TIMESTAMP(6),?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $name, $role, $module, $action, $description, $ip, $success ? 'Exitosa' : 'Fallida', $trace, $origin, json_encode($refs, JSON_THROW_ON_ERROR)]);
    } catch (mysqli_sql_exception $error) {
        error_log('VIORA auditoría: evento independiente no persistido, código ' . $error->getCode() . ', seguimiento ' . $trace);
    }
}

function auditoria_peticion(mysqli $db): void
{
    $actor = auditoria_contexto();
    if ($actor[0]) {
        $current = $db->execute_query('SELECT u.nombre,r.nombre AS rol FROM usuario u JOIN role r ON r.id_roles=u.id_rol
            WHERE u.id_usuarios=?', [$actor[0]])->fetch_assoc();
        if ($current) {
            $actor[1] = $current['nombre'];
            $actor[2] = $current['rol'];
            $GLOBALS['viora_audit_actor'] = $actor;
        }
    }
    $db->execute_query('SET @viora_audit_id=?, @viora_audit_name=?, @viora_audit_role=?, @viora_audit_ip=?, @viora_audit_trace=?, @viora_audit_origin=?', $actor);
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $modules = [
        'chatbot_api.php' => 'IA', 'reportes_api.php' => 'Reportes', 'reportes.php' => 'Reportes',
        'catalogo.php' => 'Propiedades', 'consulta_facturas.php' => 'Facturación', 'mis_facturas.php' => 'Facturación',
        'cxc_clientes.php' => 'Cuentas por cobrar', 'consulta_cobros.php' => 'Cuentas por cobrar',
        'factura_pdf.php' => 'Facturación', 'ver_contrato.php' => 'Contratos',
        'paypal_webhook.php' => 'Suscripciones', 'verificar_suscripcion.php' => 'Suscripciones',
        'reconcile-paypal.php' => 'Suscripciones', 'guardar_registro.php' => 'Usuarios',
        'registrar_pago.php' => 'Pagos', 'guardar_factura.php' => 'Facturación',
        'administrar_solicitudes.php' => 'Solicitudes', 'contratos.php' => 'Contratos',
        'reenviar_ecf.php' => 'Fiscal', 'emitir_nota_credito.php' => 'Fiscal',
        'ecf_consultar.php' => 'Fiscal', 'solicitar_reparacion.php' => 'Mantenimiento',
        'apartamento.php' => 'Propiedades', 'administrar_propiedad.php' => 'Propiedades',
        'condominio.php' => 'Condominios', 'edificio.php' => 'Edificios',
        'clientes.php' => 'Clientes', 'clientes_consulta.php' => 'Clientes',
        'usuarios.php' => 'Usuarios', 'roles.php' => 'Configuración',
        'consulta_visitas.php' => 'Visitas', 'solicitudes.php' => 'Solicitudes',
        'reparaciones.php' => 'Mantenimiento', 'mantenimiento_mensual.php' => 'Mantenimiento',
        'facturacion.php' => 'Facturación', 'facturacion_viora.php' => 'Facturación',
        'buscar_clientes_factura.php' => 'Facturación', 'obtener_propiedades_cliente.php' => 'Facturación',
        'vencimiento_contratos.php' => 'Contratos', 'consultar_ecf.php' => 'Fiscal', 'consultar_ecf_alias.php' => 'Fiscal',
    ];
    if (in_array($script, ['procesar_login.php', 'logout.php', 'auditoria.php', 'migrate-auditoria.php'], true)) return;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    if (!isset($modules[$script]) && $method !== 'POST') return;
    register_shutdown_function(static function () use ($script, $method, $modules, $actor): void {
        $fatal = in_array(error_get_last()['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        $status = (int)http_response_code();
        $denied = in_array($status, [401, 403], true);
        foreach (headers_list() as $header) {
            if (preg_match('~^Location:\s*(?:[^?]*/)?login\.php(?:\?|$)~i', $header)) $denied = true;
        }
        if (!isset($modules[$script]) && !$fatal && $status < 400) return;
        if (!empty($GLOBALS['viora_audit_denied'])) return;
        $refs = $GLOBALS['viora_audit_refs'] ?? [];
        $success = !$fatal && !$denied && $status < 400 && empty($GLOBALS['viora_audit_failed']);
        auditoria_evento($modules[$script] ?? 'Operaciones', $denied ? 'Acceso denegado' : ($method === 'GET' ? 'Consultar' : 'Procesar'),
            "$method $script", $success, $refs, false, $actor);
    });
}

function auditoria_fallo(array $refs = []): void
{
    $GLOBALS['viora_audit_failed'] = true;
    if ($refs) $GLOBALS['viora_audit_refs'] = $refs;
}

function auditoria_arrancar_peticion_externa(string $module): void
{
    register_shutdown_function(static function () use ($module): void {
        if (!empty($GLOBALS['viora_audit_connection'])) return;
        $status = (int)http_response_code();
        if ($status >= 400) {
            auditoria_evento($module, in_array($status, [401, 403], true) ? 'Acceso denegado' : 'Procesar',
                basename($_SERVER['SCRIPT_FILENAME'] ?? ''), false);
        }
    });
}
