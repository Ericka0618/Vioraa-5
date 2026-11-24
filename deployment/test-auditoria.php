<?php
// Integration fixtures for a fresh, isolated database built from the application schema.
if (PHP_SAPI !== 'cli' || getenv('DB_NAME') !== 'viora_audit_qa' || getenv('DB_HOST') !== '127.0.0.1') exit(2);
require __DIR__ . '/../Viora/conf/conexion.php';
require __DIR__ . '/../Viora/conf/auditoria_consulta.php';
require __DIR__ . '/../Viora/conf/auditoria_exportacion.php';
require __DIR__ . '/../Viora/conf/trazabilidad.php';

function verificar(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "OK $message\n";
}

function crear(mysqli $db, string $sql, array $params = []): int
{
    $db->execute_query($sql, $params);
    return $db->insert_id;
}

$db = $conexion;
$tag = 'QA-AUD-' . bin2hex(random_bytes(4));
$password = bin2hex(random_bytes(12));
$role = (int)($db->query("SELECT id_roles FROM role WHERE nombre='Administrador' LIMIT 1")->fetch_row()[0] ?? 0)
    ?: crear($db, "INSERT INTO role(nombre) VALUES ('Administrador')");
$clientRole = (int)($db->query("SELECT id_roles FROM role WHERE nombre='Cliente' LIMIT 1")->fetch_row()[0] ?? 0)
    ?: crear($db, "INSERT INTO role(nombre) VALUES ('Cliente')");
$adminPerson = crear($db, "INSERT INTO persona(nombres,apellidos,cedula) VALUES ('Prueba','Auditoría',?)", [$tag . '-A']);
$client = crear($db, "INSERT INTO persona(nombres,apellidos,cedula) VALUES ('Prueba','Cliente',?)", [$tag . '-C']);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$admin = crear($db, "INSERT INTO usuario(nombre,usuario,clave,id_rol,persona_id,estado_cuenta)
    VALUES ('Auditoría QA',?,?,?,?,'Activo')", [$tag, $passwordHash, $role, $adminPerson]);
$clientUser = crear($db, "INSERT INTO usuario(nombre,usuario,clave,id_rol,persona_id,estado_cuenta)
    VALUES ('Cliente QA',?,?,?,?,'Activo')", [$tag . '-C', $passwordHash, $clientRole, $client]);
$providerSubscription = 'I-QAAUD' . strtoupper(substr($tag, -8));
$subscription = crear($db, "INSERT INTO suscripcion(usuario_id,estado,paypal_subscription_id) VALUES (?,'Activo',?)", [$clientUser, $providerSubscription]);
$_SESSION = ['logged_in' => true, 'id_usuarios' => $admin, 'nombre_usuario' => 'Auditoría QA', 'rol' => 'Administrador'];
$db->execute_query("SET @viora_audit_id=?,@viora_audit_name='Auditoría QA',@viora_audit_role='Administrador',@viora_audit_origin='Usuario'", [$admin]);
$condo = crear($db, 'INSERT INTO condominio(nombre,direccion) VALUES (?,?)', [$tag, 'Dirección privada de prueba']);
$building = crear($db, 'INSERT INTO edificio(nombre,condominio_id) VALUES (?,?)', [$tag, $condo]);
$apartment = crear($db, "INSERT INTO apartamento(edificio_id,numero,precio_alquiler) VALUES (?,'QA',15000)", [$building]);
$db->execute_query('UPDATE apartamento SET precio_alquiler=18000 WHERE id_apartamentos=?', [$apartment]);
$db->execute_query('UPDATE apartamento SET precio_alquiler=18000 WHERE id_apartamentos=?', [$apartment]);
$changes = $db->execute_query("SELECT * FROM auditoria WHERE tabla='apartamento' AND registro=? AND accion='Modificar'", [(string)$apartment])->fetch_all(MYSQLI_ASSOC);
verificar(count($changes) === 1 && (int)$changes[0]['actor_id'] === $admin, 'Modificación atribuida y actualización sin cambios omitida');
verificar(json_decode($changes[0]['anterior'], true)['precio_alquiler'] === '15000.00'
    && json_decode($changes[0]['nuevo'], true)['precio_alquiler'] === '18000.00', 'Valores anterior y nuevo correctos');

$db->begin_transaction();
$temp = crear($db, 'INSERT INTO condominio(nombre,direccion) VALUES (?,?)', [$tag . '-ROLLBACK', 'QA']);
auditoria_evento('Condominios', 'Procesar', 'Fallo QA intencional', false);
$db->rollback();
verificar(!(int)$db->execute_query("SELECT COUNT(*) FROM auditoria WHERE tabla='condominio' AND registro=?", [(string)$temp])->fetch_row()[0]
    && (int)$db->query("SELECT COUNT(*) FROM auditoria WHERE descripcion='Fallo QA intencional' AND estado='Fallida'")->fetch_row()[0] > 0,
    'La reversión elimina el cambio y conserva el fallo independiente');

$disposable = crear($db, 'INSERT INTO condominio(nombre,direccion) VALUES (?,?)', [$tag . '-DELETE', 'QA']);
$db->execute_query('DELETE FROM condominio WHERE id_condominios=?', [$disposable]);
verificar((int)$db->execute_query("SELECT COUNT(*) FROM auditoria WHERE tabla='condominio' AND registro=? AND accion='Eliminar'", [(string)$disposable])->fetch_row()[0] === 1, 'La eliminación conserva su evento');

$request = crear($db, "INSERT INTO solicitud_propiedad(persona_id,apartamento_id,tipo_operacion) VALUES (?,?,'Alquiler')", [$client, $apartment]);
$second = viora_abrir_bd();
$second->execute_query("UPDATE solicitud_propiedad SET estado='Aprobada' WHERE id_solicitudes=?", [$request]);
$second->query("SET @viora_audit_origin='Integración'");
$second->execute_query("INSERT INTO paypal_evento(paypal_event_id,tipo,paypal_subscription_id)
    VALUES (?,'PAYMENT.SALE.COMPLETED',?)", [$tag . '-WEBHOOK', $providerSubscription]);
$second->execute_query("INSERT INTO suscripcion_pago(paypal_transaction_id,paypal_subscription_id,usuario_id,monto,moneda,fecha_pago,estado)
    VALUES (?,?,?,10,'USD',UTC_TIMESTAMP(),'Completado')", [$tag . '-PAYPAL', $providerSubscription, $clientUser]);
$contract = crear($db, "INSERT INTO contrato(apartamento_id,persona_id,solicitud_id,numero_contrato,fecha_inicio,monto_alquiler)
    VALUES (?,?,?,?,'2026-11-01',18000)", [$apartment, $client, $request, $tag]);
$invoice = crear($db, "INSERT INTO factura(numero_documento,contrato_id,persona_id,periodo,fecha,total,saldo_pendiente,estado)
    VALUES (?,?,?,'2026-11','2026-11-01',18000,18000,'Pendiente')", [$tag, $contract, $client]);
$db->begin_transaction();
$payment = crear($db, "INSERT INTO factura_pago(factura_id,monto,metodo_pago) VALUES (?,18000,'Efectivo')", [$invoice]);
$db->execute_query("UPDATE factura SET monto_pagado=18000,saldo_pendiente=0,estado='Pagada' WHERE id_facturas=?", [$invoice]);
$db->commit();
$ecf = crear($db, "INSERT INTO factura_ecf(factura_id,encf,estado) VALUES (?,?,'Aceptado')", [$invoice, 'E31' . str_pad((string)$invoice, 10, '0', STR_PAD_LEFT)]);
$db->execute_query("INSERT INTO factura_documento(tipo,documento_id,factura_id,contenido,sha256)
    VALUES ('factura',?,?,?,?)", [$invoice, $invoice, 'BLOB privado de prueba', hash('sha256', 'BLOB privado de prueba')]);
$openInvoice = crear($db, "INSERT INTO factura(numero_documento,contrato_id,persona_id,periodo,fecha,total,saldo_pendiente,estado)
    VALUES (?,?,?,'2026-12','2026-12-01',18000,18000,'Pendiente')", [$tag . '-WEB', $contract, $client]);
require_once __DIR__ . '/../Viora/mantenimientos/ecf_service.php';
$previousEcf = getenv('ECF_ENABLED');
putenv('ECF_ENABLED=false');
$fiscalAttempt = ecf_enviar_documento([], [], $db, $openInvoice);
$previousEcf === false ? putenv('ECF_ENABLED') : putenv('ECF_ENABLED=' . $previousEcf);
verificar($fiscalAttempt['estado'] === 'PENDIENTE_CONFIGURACION'
    && (int)$db->execute_query("SELECT COUNT(*) FROM auditoria a JOIN auditoria_referencia r ON r.auditoria_id=a.id
        WHERE r.tipo='factura' AND r.valor=? AND a.accion='Enviar e-CF' AND a.estado='Fallida'", [(string)$openInvoice])->fetch_row()[0] === 1,
    'El bloqueo fiscal queda registrado sin llamar al proveedor');
require_once __DIR__ . '/../Viora/mantenimientos/factura_documento.php';
$previousBucket = getenv('INVOICE_PDF_BUCKET');
putenv('INVOICE_PDF_BUCKET=!');
$document = factura_documento_generar($db, $openInvoice);
$previousBucket === false ? putenv('INVOICE_PDF_BUCKET') : putenv('INVOICE_PDF_BUCKET=' . $previousBucket);
verificar(str_starts_with($document, '%PDF-')
    && (int)$db->execute_query("SELECT COUNT(*) FROM auditoria a JOIN auditoria_referencia r ON r.auditoria_id=a.id
        WHERE r.tipo='factura' AND r.valor=? AND a.accion='Replicar PDF' AND a.estado='Fallida'", [(string)$openInvoice])->fetch_row()[0] === 1,
    'El fallo de réplica de Storage queda registrado y el PDF permanece disponible');
[$events, $limited] = auditoria_linea($db, 'contrato', (string)$contract);
$tables = array_column($events, 'tabla');
verificar(!$limited && !array_diff(['persona','usuario','suscripcion','solicitud_propiedad','contrato','factura','factura_pago','factura_ecf','factura_documento'], $tables),
    'Cliente, suscripción, solicitud, contrato, factura, e-CF, documento y pago correlacionados entre conexiones');
[$clientEvents] = auditoria_linea($db, 'persona', (string)$client);
verificar(in_array('suscripcion', array_column($clientEvents, 'tabla'), true), 'El historial del cliente conecta la suscripción');
[$subscriptionEvents] = auditoria_linea($db, 'suscripcion', (string)$subscription);
verificar(!array_diff(['paypal_evento','suscripcion_pago'], array_column($subscriptionEvents, 'tabla'))
    && in_array('Integración', array_column($subscriptionEvents, 'origen'), true), 'La suscripción conecta webhook y pago de prueba con origen de integración');
$purchase = crear($db, "INSERT INTO solicitud_propiedad(persona_id,apartamento_id,tipo_operacion) VALUES (?,?,'Venta')", [$client, $apartment]);
$db->execute_query("UPDATE solicitud_propiedad SET estado='Rechazada' WHERE id_solicitudes=?", [$purchase]);
[$purchaseEvents] = auditoria_linea($db, 'solicitud_propiedad', (string)$purchase);
verificar(in_array('Solicitud de propiedad · Modificar · Venta · Rechazada', array_map('auditoria_etapa', $purchaseEvents), true)
    && !in_array('contrato', array_column($purchaseEvents, 'tabla'), true), 'La solicitud de compra no mezcla el contrato de alquiler del mismo cliente');
$repair = crear($db, "INSERT INTO solicitud_mantenimiento(apartamento_id,persona_id,descripcion) VALUES (?,?,'Reparación QA')", [$apartment, $client]);
$maintenance = crear($db, "INSERT INTO mantenimiento(condominio_id,descripcion) VALUES (?,'Mantenimiento QA')", [$condo]);
$db->execute_query("INSERT INTO mantenimiento_solicitud(mantenimiento_id,solicitud_id) VALUES (?,?)", [$maintenance,$repair]);
[$repairEvents] = auditoria_linea($db, 'solicitud_mantenimiento', (string)$repair);
verificar(!array_diff(['solicitud_mantenimiento','mantenimiento','mantenimiento_solicitud'], array_column($repairEvents, 'tabla')), 'La solicitud de mantenimiento conecta con su ejecución');
$adminIdentity = trazabilidad_identidad($db);
$owned = trazabilidad_registro($db, $adminIdentity, 'solicitud_propiedad', (string)$request);
[$firstPage, $more] = trazabilidad_pagina($db, $owned, [], 2);
$last = end($firstPage);
[$nextPage] = trazabilidad_pagina($db, $owned, ['antes' => $last['id'], 'fecha_cursor' => $last['fecha']]);
verificar($more && !array_intersect(array_column($firstPage, 'id'), array_column($nextPage, 'id'))
    && !array_diff(['contrato', 'factura', 'factura_pago', 'factura_ecf'], array_column(array_merge($firstPage, $nextPage), 'tabla')),
    'Historial individual con cursor y vínculos financieros exactos');
[$unrelated] = trazabilidad_pagina($db, trazabilidad_registro($db, $adminIdentity, 'solicitud_propiedad', (string)$purchase));
verificar(array_unique(array_column($unrelated, 'tabla')) === ['solicitud_propiedad'], 'El historial individual excluye otros trámites de la misma persona');
$_SESSION['id_usuarios'] = $clientUser;
$clientIdentity = trazabilidad_identidad($db);
$clientInvoice = trazabilidad_registro($db, $clientIdentity, 'factura', (string)$invoice);
[$ownRows] = trazabilidad_pagina($db, $clientInvoice);
verificar($ownRows && !array_filter(array_column($ownRows, 'actor_nombre'))
    && !trazabilidad_registro($db, $clientIdentity, 'persona', (string)$adminPerson)
    && !trazabilidad_registro($db, array_replace($clientIdentity, ['persona_id' => $adminPerson]), 'factura', (string)$invoice),
    'La vista de cliente comprueba titularidad y protege los actores internos');
$_SESSION['id_usuarios'] = $admin;

[$where, $params] = auditoria_filtros(['factura' => $invoice, 'estado' => 'Exitosa', 'reporte' => 12]);
verificar((int)auditoria_indicadores($db, $where, $params)['total'] >= 4, 'Filtros combinados y reporte de facturas');
[$where, $params] = auditoria_filtros(['q' => "' OR 1=1 /* QA"]);
verificar((int)auditoria_indicadores($db, $where, $params)['total'] === 0, 'El texto de búsqueda se trata como parámetro');
verificar((int)$db->query('SELECT COUNT(*) FROM auditoria WHERE huella<>' . auditoria_huella_sql())->fetch_row()[0] === 0, 'Todas las huellas coinciden');
try {
    $db->execute_query("UPDATE auditoria SET descripcion='alterada' WHERE id=?", [$changes[0]['id']]);
    throw new RuntimeException('La actualización de auditoría fue aceptada.');
} catch (mysqli_sql_exception $error) {
    verificar($error->getSqlState() === '45000', 'La base bloquea la edición de auditoría');
}
verificar(!(int)$db->execute_query('SELECT COUNT(*) FROM auditoria WHERE LOCATE(?, CONCAT_WS(\' \',anterior,nuevo,referencias))>0', [$passwordHash])->fetch_row()[0],
    'La auditoría excluye la credencial almacenada');
verificar(str_starts_with(auditoria_pdf($events, 'Prueba'), '%PDF-'), 'PDF generado');
verificar(str_starts_with(auditoria_xlsx($events, 'Prueba'), "PK\x03\x04"), 'XLSX generado');
foreach (auditoria_reportes() as $number => [$title]) {
    [$filter, $values] = auditoria_filtros(['reporte' => $number]);
    $sample = $db->execute_query("SELECT * FROM auditoria a WHERE $filter ORDER BY id DESC LIMIT 2", $values)->fetch_all(MYSQLI_ASSOC);
    verificar(str_starts_with(auditoria_pdf($sample, $title), '%PDF-')
        && str_starts_with(auditoria_xlsx($sample, $title), "PK\x03\x04"), "Reporte $number en PDF y XLSX");
}
if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode(['admin'=>$tag,'cliente'=>$tag . '-C','password'=>$password,'contrato'=>$contract,
        'solicitud'=>$request,'rechazada'=>$purchase,'reparacion'=>$repair,'suscripcion'=>$subscription,'factura'=>$invoice,'factura_web'=>$openInvoice,'persona'=>$client,'apartamento'=>$apartment,'detalle'=>$changes[0]['id']], JSON_THROW_ON_ERROR));
}
