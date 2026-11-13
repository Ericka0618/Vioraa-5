<?php
require_once __DIR__ . '/ecf_validacion.php';

// Envío de e-CF a la plataforma IMECF: E31 (crédito fiscal),
// E32 (factura de consumo) y E34 (nota de crédito).

function ecf_configuracion(): array
{
    return require __DIR__ . '/../conf/ecf.php';
}

// Trabaja en centavos enteros y redondea medio hacia arriba.
function ecf_itbis_centavos(int $montoCentavos, int $porcentaje): int
{
    return intdiv($montoCentavos * $porcentaje + 50, 100);
}

// El ITBIS se calcula por concepto para que coincida con
// el total que DGII valida línea por línea.
function ecf_calcular_totales(array $montosCentavos, int $porcentaje): array
{
    $base = 0;
    $itbis = 0;

    foreach ($montosCentavos as $monto) {
        $base += $monto;
        $itbis += ecf_itbis_centavos($monto, $porcentaje);
    }

    return [
        'base' => $base,
        'itbis' => $itbis,
        'total' => $base + $itbis,
    ];
}

function ecf_formato(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

function ecf_texto($valor): ?string
{
    if ($valor === null || $valor === '' || !is_scalar($valor)) {
        return null;
    }

    return (string)$valor;
}

function ecf_registrar(
    mysqli $conexion,
    int $facturaId,
    string $tipo,
    string $estado,
    ?string $ecfApiId,
    ?string $encf,
    ?string $codigoSeguridad,
    ?string $dgiiUrl,
    ?string $error,
    ?string $fechaEmisionEcf = null
): void {
    $stmt = $conexion->prepare("
        INSERT INTO factura_ecf (
            factura_id,
            ecf_api_id,
            encf,
            tipo_ecf,
            estado,
            codigo_seguridad,
            dgii_url,
            error_mensaje,
            fecha_emision_ecf,
            intentos,
            fecha_envio
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            tipo_ecf = VALUES(tipo_ecf),
            ecf_api_id = COALESCE(VALUES(ecf_api_id), ecf_api_id),
            encf = COALESCE(VALUES(encf), encf),
            estado = VALUES(estado),
            codigo_seguridad = COALESCE(VALUES(codigo_seguridad), codigo_seguridad),
            dgii_url = COALESCE(VALUES(dgii_url), dgii_url),
            error_mensaje = VALUES(error_mensaje),
            fecha_emision_ecf = COALESCE(VALUES(fecha_emision_ecf), fecha_emision_ecf),
            intentos = intentos + 1,
            fecha_envio = NOW()
    ");

    $stmt->bind_param(
        'issssssss',
        $facturaId,
        $ecfApiId,
        $encf,
        $tipo,
        $estado,
        $codigoSeguridad,
        $dgiiUrl,
        $error,
        $fechaEmisionEcf
    );

    $stmt->execute();
    $stmt->close();
}

function ecf_registrar_nota(
    mysqli $conexion,
    int $notaId,
    string $estado,
    ?string $ecfApiId,
    ?string $encf,
    ?string $codigoSeguridad,
    ?string $dgiiUrl,
    ?string $error
): void {
    $stmt = $conexion->prepare("
        UPDATE factura_ecf_nota
        SET estado = ?,
            ecf_api_id = COALESCE(?, ecf_api_id),
            encf = COALESCE(?, encf),
            codigo_seguridad = COALESCE(?, codigo_seguridad),
            dgii_url = COALESCE(?, dgii_url),
            error_mensaje = ?,
            intentos = intentos + 1,
            fecha_envio = NOW()
        WHERE id_factura_ecf_nota = ?
    ");

    $stmt->bind_param(
        'ssssssi',
        $estado,
        $ecfApiId,
        $encf,
        $codigoSeguridad,
        $dgiiUrl,
        $error,
        $notaId
    );

    $stmt->execute();
    $stmt->close();
}

// Acepta el RNC con o sin guiones (9 u 11 dígitos). Devuelve null si está vacío.
function ecf_rnc_normalizar($valor): ?string
{
    $texto = trim((string)($valor ?? ''));

    if ($texto === '') {
        return null;
    }

    $rnc = str_replace(['-', ' '], '', $texto);

    if (!ctype_digit($rnc) || !in_array(strlen($rnc), [9, 11], true)) {
        throw new Exception('El RNC del comprador debe tener 9 u 11 dígitos');
    }

    return $rnc;
}

function ecf_estado_aceptado(string $estado): bool
{
    return in_array($estado, ['Aceptado', 'Aceptado Condicional'], true);
}

// Las notas que no llegaron a DGII o fueron rechazadas no reducen el saldo.
function ecf_nota_activa(string $estado): bool
{
    return !in_array($estado, ['ERROR_PREPARACION', 'PENDIENTE_CONFIGURACION'], true) &&
        stripos($estado, 'Rechaz') === false;
}

// E31 cuando la factura tiene RNC del comprador; E32 en el resto.
function ecf_tipo_factura(mysqli $conexion, int $facturaId): string
{
    $stmt = $conexion->prepare("
        SELECT rnc_comprador
        FROM factura
        WHERE id_facturas = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $facturaId);
    $stmt->execute();
    $factura = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($factura['rnc_comprador']) ? 'E31' : 'E32';
}

// La fecha de emisión la toma IMECF en zona de República Dominicana.
function ecf_ahora(): DateTime
{
    return new DateTime('now', new DateTimeZone('America/Santo_Domingo'));
}

function ecf_bloque_emisor(array $cfg, string $fechaEmision): array
{
    $emisor = $cfg['emisor'];

    return [
        'RNCEmisor' => $emisor['RNCEmisor'],
        'RazonSocialEmisor' => $emisor['RazonSocialEmisor'],
        'NombreComercial' => $emisor['NombreComercial'],
        'DireccionEmisor' => $emisor['DireccionEmisor'],
        'TablaTelefonoEmisor' => [
            'TelefonoEmisor' => [$emisor['TelefonoEmisor']],
        ],
        'CorreoEmisor' => $emisor['CorreoEmisor'],
        'FechaEmision' => $fechaEmision,
    ];
}

// Cada línea lleva el monto sin ITBIS; el ITBIS se calcula en Totales.
function ecf_bloque_items(array $lineas): array
{
    $items = [];

    foreach ($lineas as $indice => $linea) {
        $items[] = [
            'NumeroLinea' => (string)($indice + 1),
            'IndicadorFacturacion' => '1',
            'NombreItem' => mb_substr($linea['descripcion'], 0, 80),
            'IndicadorBienoServicio' => '2',
            'CantidadItem' => '1',
            'UnidadMedida' => '43',
            'PrecioUnitarioItem' => ecf_formato($linea['centavos']),
            'MontoItem' => ecf_formato($linea['centavos']),
        ];
    }

    return $items;
}

function ecf_bloque_totales(array $totales, int $porcentaje): array
{
    return [
        'ITBIS1' => (string)$porcentaje,
        'MontoGravadoI1' => ecf_formato($totales['base']),
        'MontoGravadoTotal' => ecf_formato($totales['base']),
        'MontoExento' => '0.00',
        'MontoNoFacturable' => '0.00',
        'TotalITBIS' => ecf_formato($totales['itbis']),
        'TotalITBIS1' => ecf_formato($totales['itbis']),
        'MontoTotal' => ecf_formato($totales['total']),
    ];
}

function ecf_bloque_comprador(string $rnc, string $razonSocial): array
{
    return [
        'RNCComprador' => $rnc,
        'RazonSocialComprador' => $razonSocial,
    ];
}

// Líneas de la factura en centavos, sin ITBIS.
function ecf_lineas_factura(mysqli $conexion, int $facturaId): array
{
    $stmt = $conexion->prepare("
        SELECT descripcion, monto
        FROM factura_detalle
        WHERE factura_id = ? AND activo = 1
        ORDER BY id_detalles
    ");
    $stmt->bind_param('i', $facturaId);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $lineas = [];

    while ($fila = $resultado->fetch_assoc()) {
        $lineas[] = [
            'descripcion' => $fila['descripcion'],
            'centavos' => ecf_centavos($fila['monto']),
        ];
    }

    $stmt->close();

    return $lineas;
}

function ecf_construir_payload(
    mysqli $conexion,
    int $facturaId,
    array $cfg,
    string $tipo
): array {
    if (!in_array($tipo, ['E31', 'E32'], true)) throw new InvalidArgumentException('Tipo de factura no permitido.');
    $stmt = $conexion->prepare("
        SELECT fecha, total, rnc_comprador, razon_social_comprador
        FROM factura
        WHERE id_facturas = ? AND estado <> 'Anulada'
        LIMIT 1
    ");
    $stmt->bind_param('i', $facturaId);
    $stmt->execute();
    $factura = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$factura) {
        throw new Exception('Factura no encontrada o anulada');
    }

    $lineas = ecf_lineas_factura($conexion, $facturaId);

    if (!$lineas) {
        throw new Exception('La factura no tiene conceptos');
    }

    $totales = ecf_calcular_totales(
        array_column($lineas, 'centavos'),
        $cfg['itbis_porcentaje']
    );

    if (ecf_centavos($factura['total']) !== $totales['total']) {
        throw new RuntimeException('El total de la factura no coincide con sus conceptos e ITBIS.');
    }

    $fecha = DateTime::createFromFormat('!Y-m-d', $factura['fecha']);
    if (!$fecha || $fecha->format('Y-m-d') !== $factura['fecha']) throw new RuntimeException('Fecha de factura inválida.');
    foreach ($lineas as $linea) {
        if ($linea['centavos'] <= 0 || trim($linea['descripcion']) === '') throw new RuntimeException('Concepto de factura inválido.');
    }
    $fechaEmision = $fecha->format('d-m-Y');

    $encabezado = [
        'Version' => '1.0',
        'IdDoc' => [
            'TipoeCF' => $tipo === 'E31' ? '31' : '32',
            'TipoPago' => '1',
            'TipoIngresos' => '01',
            'TablaFormasPago' => [
                'FormaDePago' => [[
                    'FormaPago' => 1,
                    'MontoPago' => ecf_formato($totales['total']),
                ]],
            ],
            'IndicadorMontoGravado' => '0',
            'IndicadorEnvioDiferido' => '1',
            'IndicadorServicioTodoIncluido' => '0',
        ],
        'Emisor' => ecf_bloque_emisor($cfg, $fechaEmision),
    ];

    if ($tipo === 'E31') {
        // Campo opcional: solo aplica si los precios incluyen ITBIS, y los nuestros no lo incluyen.
        unset($encabezado['IdDoc']['IndicadorServicioTodoIncluido']);

        if (empty($factura['rnc_comprador']) || empty($factura['razon_social_comprador'])) {
            throw new Exception('La factura E31 requiere RNC y razón social del comprador');
        }

        $encabezado['Comprador'] = ecf_bloque_comprador(
            ecf_rnc_normalizar($factura['rnc_comprador']),
            $factura['razon_social_comprador']
        );
    } elseif ($totales['total'] >= 25000000) {
        throw new Exception('Las facturas de RD$250,000.00 o más requieren RNC del comprador');
    }

    $encabezado['Totales'] = ecf_bloque_totales(
        $totales,
        $cfg['itbis_porcentaje']
    );

    return [
        'ECF' => [
            'Encabezado' => $encabezado,
            'DetallesItems' => [
                'Item' => ecf_bloque_items($lineas),
            ],
            'FechaHoraFirma' => ecf_ahora()->format('d-m-Y H:i:s'),
        ],
    ];
}

// Payload de E34. Las líneas van en montos base (sin ITBIS),
// igual que las de la factura original.
function ecf_payload_nota(
    array $cfg,
    array $nota,
    array $lineas,
    ?array $comprador
): array {
    ecf_validar_nota($nota, $lineas, $comprador);
    $totales = ecf_calcular_totales(
        array_column($lineas, 'centavos'),
        $cfg['itbis_porcentaje']
    );

    $encabezado = [
        'Version' => '1.0',
        'IdDoc' => [
            'TipoeCF' => '34',
            'IndicadorNotaCredito' => '0',
            'IndicadorMontoGravado' => '0',
            'TipoPago' => '1',
            'TipoIngresos' => '01',
        ],
        'Emisor' => ecf_bloque_emisor($cfg, ecf_ahora()->format('d-m-Y')),
    ];

    if ($comprador !== null) {
        $encabezado['Comprador'] = $comprador;
    }

    $encabezado['Totales'] = ecf_bloque_totales(
        $totales,
        $cfg['itbis_porcentaje']
    );

    return [
        'ECF' => [
            'Encabezado' => $encabezado,
            'DetallesItems' => [
                'Item' => ecf_bloque_items($lineas),
            ],
            'InformacionReferencia' => [
                'NCFModificado' => $nota['encf_modificado'],
                'FechaNCFModificado' => DateTime::createFromFormat(
                    'Y-m-d',
                    $nota['fecha_encf_modificado']
                )->format('d-m-Y'),
                'CodigoModificacion' => (string)$nota['codigo_modificacion'],
            ],
            'FechaHoraFirma' => ecf_ahora()->format('d-m-Y H:i:s'),
        ],
    ];
}

// Convierte las líneas capturadas en el formulario a centavos.
function ecf_lineas_entrada(array $entrada): array
{
    $lineas = [];

    foreach ($entrada as $linea) {
        $descripcion = trim((string)($linea['descripcion'] ?? ''));
        $monto = $linea['monto'] ?? null;

        if ($descripcion === '' || !is_numeric($monto) || (float)$monto <= 0) {
            throw new Exception('Cada línea de la nota necesita descripción y un monto mayor que cero');
        }

        $lineas[] = [
            'descripcion' => $descripcion,
            'centavos' => ecf_centavos($monto),
        ];
    }

    if (!$lineas) {
        throw new Exception('La nota de crédito necesita al menos una línea');
    }

    return $lineas;
}

function ecf_post(array $cfg, string $ruta, array $datos): array
{
    if (getenv('ECF_ENABLED') !== 'true') {
        throw new RuntimeException('La emision fiscal esta deshabilitada hasta su autorizacion y validacion.');
    }
    if (!ecf_url_segura($cfg['base_url']) || empty($cfg['api_key'])) {
        throw new RuntimeException('Se requieren HTTPS y credenciales del proveedor fiscal.');
    }
    $ch = curl_init($cfg['base_url'] . $ruta);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $cfg['api_key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);

    $cuerpo = curl_exec($ch);

    if ($cuerpo === false) {
        curl_close($ch);
        throw new RuntimeException(
            'No se pudo confirmar la conexión con la plataforma fiscal.'
        );
    }

    $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decodificado = json_decode($cuerpo, true);

    return [
        'codigo' => $codigo,
        'datos' => is_array($decodificado) ? $decodificado : [],
        'raw' => $cuerpo,
    ];
}

function ecf_mensaje_error(array $datos, string $raw, int $codigo): string
{
    error_log('[VIORA e-CF] Respuesta no válida del proveedor HTTP ' . $codigo);
    return 'El proveedor fiscal no confirmó la emisión (HTTP ' . $codigo . '). Consulte el estado antes de reintentar.';
}

// Envía un documento armado a la plataforma y devuelve el resultado
// normalizado. No lanza excepciones por fallos de red o de DGII.
function ecf_enviar_documento(array $cfg, array $payload, ?mysqli $conexion = null, ?int $facturaId = null, ?int $notaId = null): array
{
    if (getenv('ECF_ENABLED') !== 'true') {
        return ['estado' => 'PENDIENTE_CONFIGURACION', 'error' => 'Emisión fiscal deshabilitada; requiere autorización y configuración del proveedor.'];
    }
    if ($cfg['api_key'] === '') {
        return [
            'estado' => 'PENDIENTE_CONFIGURACION',
            'error' => 'Falta la variable de entorno ECF_API_KEY',
        ];
    }

    try {
        ecf_validar_configuracion($cfg, 'E' . ($payload['ECF']['Encabezado']['IdDoc']['TipoeCF'] ?? ''));
    } catch (InvalidArgumentException $e) {
        return ['estado' => 'PENDIENTE_CONFIGURACION', 'error' => $e->getMessage()];
    }
    if (!$conexion || !$facturaId) throw new RuntimeException('La emisión requiere auditoría vinculada a una factura.');
    $auditoriaId = ecf_auditar_inicio($conexion, $facturaId, $notaId, $payload);
    try {
        $respuesta = ecf_post($cfg, '/api/v1/ecf/send', $payload);
        ecf_auditar_respuesta($conexion, $auditoriaId, $respuesta, $cfg['api_key']);
    } catch (RuntimeException $e) {
        error_log('[VIORA e-CF] Fallo de transporte; conciliar antes de reenviar.');
        try {
            $q = $conexion->prepare("UPDATE ecf_envio SET estado='PENDIENTE_CONCILIACION' WHERE id_envio=?");
            $q->bind_param('i', $auditoriaId); $q->execute();
        } catch (Throwable $registroError) { error_log('[VIORA e-CF] Auditoría incompleta; conservar bloqueo de envío.'); }
        return ['estado' => 'PENDIENTE_CONCILIACION', 'error' => 'No se conoce si el proveedor recibió el documento. Consultar antes de reenviar.'];
    }

    return ecf_normalizar_respuesta($respuesta);
}

function ecf_normalizar_respuesta(array $respuesta): array
{
    $datos = $respuesta['datos'];
    $codigo = $respuesta['codigo'];
    if ($codigo < 200 || $codigo >= 300 || !ecf_texto($datos['id'] ?? null)) {
        return [
            'estado' => 'PENDIENTE_CONCILIACION',
            'error' => ecf_mensaje_error($datos, $respuesta['raw'], $codigo),
        ];
    }

    $id = ecf_texto($datos['id']);
    $encf = ecf_texto($datos['encf'] ?? null);
    $estado = ecf_texto($datos['estado'] ?? null) ?? 'DESCONOCIDO';
    // Los estados no reconocidos quedan en auditoría; no se confunden con aceptación.
    if (!in_array($estado, ['Aceptado', 'Aceptado Condicional', 'Rechazado', 'En Proceso', 'Pendiente', 'Firmado', 'Enviado'], true)) {
        $estado = 'PENDIENTE_CONCILIACION';
    }
    if (strlen($id) > 64 || ($encf !== null && !preg_match('/^E(?:31|32|34)\d{10}$/D', $encf))) {
        return ['estado' => 'PENDIENTE_CONCILIACION', 'error' => 'Identificadores fiscales inválidos; consultar al proveedor antes de reenviar.'];
    }
    if (ecf_estado_aceptado($estado) && $encf === null) $estado = 'PENDIENTE_CONCILIACION';

    return [
        'estado' => mb_substr($estado, 0, 60),
        'ecf_api_id' => $id,
        'encf' => $encf,
        'codigo_seguridad' => ecf_texto($datos['codigoSeguridad'] ?? null),
        'dgii_url' => ecf_texto($datos['dgiiUrl'] ?? null),
        'error' => empty($datos['errorMessage']) ? null : 'El proveedor informó una incidencia. Consulte el registro fiscal privado.',
    ];
}

// Serializa todas las rutas de emisión. Un resultado incierto no autoriza otro envío.
function ecf_enviar_factura(mysqli $conexion, int $facturaId): array
{
    $lock = 'viora_ecf_' . $facturaId;
    $q = $conexion->prepare('SELECT GET_LOCK(?, 5)');
    $q->bind_param('s', $lock); $q->execute();
    if ((int)$q->get_result()->fetch_row()[0] !== 1) throw new RuntimeException('Emisión en curso. Intente consultar más tarde.');
    try {
        $q = $conexion->prepare('SELECT estado,encf,ecf_api_id,error_mensaje FROM factura_ecf WHERE factura_id=?');
        $q->bind_param('i', $facturaId); $q->execute();
        $existing = $q->get_result()->fetch_assoc();
        if ($existing && (!empty($existing['ecf_api_id']) || !empty($existing['encf'])
            || !in_array($existing['estado'], ['PENDIENTE_CONFIGURACION','ERROR_PREPARACION'], true))) {
            return ['estado' => $existing['estado'], 'encf' => $existing['encf'], 'error' => $existing['error_mensaje']];
        }
        // Persistir antes del POST: incluso una caída del proceso impide una segunda emisión automática.
        if (getenv('ECF_ENABLED') === 'true') {
            ecf_registrar($conexion, $facturaId, ecf_tipo_factura($conexion, $facturaId), 'ENVIANDO', null, null, null, null, null);
        }
        return ecf_enviar_factura_interno($conexion, $facturaId);
    } finally {
        $q = $conexion->prepare('SELECT RELEASE_LOCK(?)'); $q->bind_param('s', $lock); $q->execute();
    }
}

// Envía la factura (E31 o E32) y guarda el resultado en factura_ecf.
// Los fallos quedan registrados para poder reintentarlos después.
function ecf_enviar_factura_interno(mysqli $conexion, int $facturaId): array
{
    $cfg = ecf_configuracion();
    $tipo = ecf_tipo_factura($conexion, $facturaId);

    try {
        $payload = ecf_construir_payload($conexion, $facturaId, $cfg, $tipo);
    } catch (Throwable $e) {
        $mensaje = $e instanceof mysqli_sql_exception ? 'No se pudo preparar el registro fiscal.' : $e->getMessage();
        ecf_registrar($conexion, $facturaId, $tipo, 'ERROR_PREPARACION',
            null, null, null, null, $mensaje);

        return ['estado' => 'ERROR_PREPARACION', 'error' => $mensaje];
    }

    $resultado = ecf_enviar_documento($cfg, $payload, $conexion, $facturaId);

    // Fecha de emisión del encabezado original; no confundir con la hora de firma.
    $fechaEmisionEcf = DateTime::createFromFormat(
        'd-m-Y',
        $payload['ECF']['Encabezado']['Emisor']['FechaEmision']
    )->format('Y-m-d');

    ecf_registrar(
        $conexion,
        $facturaId,
        $tipo,
        $resultado['estado'],
        $resultado['ecf_api_id'] ?? null,
        $resultado['encf'] ?? null,
        $resultado['codigo_seguridad'] ?? null,
        $resultado['dgii_url'] ?? null,
        $resultado['error'] ?? null,
        $fechaEmisionEcf
    );

    return [
        'estado' => $resultado['estado'],
        'encf' => $resultado['encf'] ?? null,
        'error' => $resultado['error'] ?? null,
    ];
}

// Emite una nota de crédito E34 sobre una factura con e-CF aceptado.
// Código 1 anula el total; código 3 corrige montos con líneas nuevas.
// El saldo se valida con la factura bloqueada (FOR UPDATE) y la nota
// se guarda como PENDIENTE antes de enviarla, para no duplicar saldo.
function ecf_emitir_nota_credito(
    mysqli $conexion,
    int $facturaId,
    int $codigoModificacion,
    array $lineasEntrada,
    ?int $usuarioId,
    ?string $operacion = null
): array {
    if (!in_array($codigoModificacion, [1, 3], true)) {
        throw new Exception('Código de modificación no soportado: use 1 (anulación total) o 3 (corrección de montos)');
    }

    $cfg = ecf_configuracion();
    if (getenv('ECF_ENABLED') !== 'true') return ['id' => null, 'estado' => 'PENDIENTE_CONFIGURACION', 'error' => 'Emisión fiscal deshabilitada. No se creó una nota.'];
    ecf_validar_configuracion($cfg, 'E34');
    if (!is_string($operacion) || !preg_match('/^[a-f0-9]{64}$/D', $operacion)) throw new InvalidArgumentException('Falta el identificador de la operación fiscal.');
    $solicitudHash = hash('sha256', json_encode([$facturaId, $codigoModificacion, $lineasEntrada], JSON_THROW_ON_ERROR));

    $conexion->begin_transaction();

    try {
        $stmt = $conexion->prepare("
            SELECT fecha, total, rnc_comprador, razon_social_comprador
            FROM factura
            WHERE id_facturas = ?
            FOR UPDATE
        ");
        $stmt->bind_param('i', $facturaId);
        $stmt->execute();
        $factura = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$factura) {
            throw new Exception('Factura no encontrada');
        }

        $q = $conexion->prepare('SELECT o.solicitud_sha256,n.id_factura_ecf_nota,n.estado,n.encf,n.error_mensaje FROM ecf_nota_operacion o JOIN factura_ecf_nota n ON n.id_factura_ecf_nota=o.nota_id WHERE o.operacion=? AND o.factura_id=?');
        $q->bind_param('si', $operacion, $facturaId); $q->execute(); $previa=$q->get_result()->fetch_assoc();
        if ($previa) {
            if (!hash_equals($previa['solicitud_sha256'], $solicitudHash)) throw new InvalidArgumentException('La operación ya corresponde a otra propuesta. Recargue el formulario.');
            $conexion->commit();
            return ['id'=>(int)$previa['id_factura_ecf_nota'],'estado'=>$previa['estado'],'encf'=>$previa['encf'],'error'=>$previa['error_mensaje']];
        }
        $stmt = $conexion->prepare("
            SELECT encf, estado, fecha_emision_ecf
            FROM factura_ecf
            WHERE factura_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $facturaId);
        $stmt->execute();
        $ecf = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ecf || empty($ecf['encf']) || !ecf_estado_aceptado($ecf['estado'])) {
            throw new Exception('La factura no tiene un e-CF aceptado por DGII');
        }

        if (empty($ecf['fecha_emision_ecf'])) {
            throw new Exception('No se conoce la fecha de emisión con la que se envió el e-CF; no se puede emitir la nota');
        }

        $lineasFactura = ecf_lineas_factura($conexion, $facturaId);

        if (!$lineasFactura) {
            throw new Exception('La factura no tiene conceptos');
        }

        $totalFactura = ecf_calcular_totales(
            array_column($lineasFactura, 'centavos'),
            $cfg['itbis_porcentaje']
        )['total'];

        if (ecf_centavos($factura['total']) !== $totalFactura) throw new InvalidArgumentException('El total original no coincide con los conceptos e ITBIS.');
        ecf_validar_nota(['encf_modificado' => $ecf['encf'], 'fecha_encf_modificado' => $ecf['fecha_emision_ecf'], 'codigo_modificacion' => $codigoModificacion], $lineasFactura, null);
        $stmt = $conexion->prepare("
            SELECT estado, monto_centavos
            FROM factura_ecf_nota
            WHERE factura_id = ?
        ");
        $stmt->bind_param('i', $facturaId);
        $stmt->execute();
        $notas = $stmt->get_result();

        $acreditado = 0;

        while ($nota = $notas->fetch_assoc()) {
            if (ecf_nota_activa($nota['estado'])) {
                if (!ecf_estado_aceptado($nota['estado'])) {
                    throw new InvalidArgumentException('Existe una nota pendiente o incierta. Concíliela con el proveedor antes de emitir otra.');
                }
                $acreditado += (int)$nota['monto_centavos'];
            }
        }

        $stmt->close();

        if ($codigoModificacion === 1 && $acreditado > 0) {
            throw new Exception('No se puede anular totalmente: la factura ya tiene notas de crédito activas');
        }

        $disponible = $totalFactura - $acreditado;

        if ($disponible <= 0) {
            throw new Exception('La factura ya no tiene saldo disponible para acreditar');
        }

        $lineas = $codigoModificacion === 1
            ? $lineasFactura
            : ecf_lineas_entrada($lineasEntrada);

        $montoNota = ecf_calcular_totales(
            array_column($lineas, 'centavos'),
            $cfg['itbis_porcentaje']
        )['total'];

        if ($montoNota > $disponible) {
            throw new Exception(
                'El monto de la nota (RD$ ' . ecf_formato($montoNota) .
                ') supera el saldo disponible (RD$ ' . ecf_formato($disponible) . ')'
            );
        }

        $stmt = $conexion->prepare("
            INSERT INTO factura_ecf_nota (
                factura_id,
                codigo_modificacion,
                encf_modificado,
                fecha_encf_modificado,
                monto_centavos,
                lineas_json,
                usuario_id,
                estado
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, 'ENVIANDO')
        ");
        $lineasJson = json_encode($lineas, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param(
            'iissisi',
            $facturaId,
            $codigoModificacion,
            $ecf['encf'],
            $ecf['fecha_emision_ecf'],
            $montoNota,
            $lineasJson,
            $usuarioId
        );
        $stmt->execute();
        $notaId = (int)$conexion->insert_id;
        $stmt->close();
        $q = $conexion->prepare('INSERT INTO ecf_nota_operacion (operacion,factura_id,nota_id,solicitud_sha256) VALUES (?,?,?,?)');
        $q->bind_param('siis', $operacion, $facturaId, $notaId, $solicitudHash); $q->execute();

        $conexion->commit();
    } catch (Throwable $e) {
        $conexion->rollback();
        throw $e;
    }

    $comprador = !empty($factura['rnc_comprador'])
        ? ecf_bloque_comprador(
            $factura['rnc_comprador'],
            (string)$factura['razon_social_comprador']
        )
        : null;

    $payload = ecf_payload_nota(
        $cfg,
        [
            'codigo_modificacion' => $codigoModificacion,
            'encf_modificado' => $ecf['encf'],
            'fecha_encf_modificado' => $ecf['fecha_emision_ecf'],
        ],
        $lineas,
        $comprador
    );

    $resultado = ecf_enviar_documento($cfg, $payload, $conexion, $facturaId, $notaId);

    ecf_registrar_nota(
        $conexion,
        $notaId,
        $resultado['estado'],
        $resultado['ecf_api_id'] ?? null,
        $resultado['encf'] ?? null,
        $resultado['codigo_seguridad'] ?? null,
        $resultado['dgii_url'] ?? null,
        $resultado['error'] ?? null
    );

    return [
        'id' => $notaId,
        'estado' => $resultado['estado'],
        'encf' => $resultado['encf'] ?? null,
        'error' => $resultado['error'] ?? null,
    ];
}
