<?php
session_start();
require_once __DIR__ . '/../conf/conexion.php';
require_once __DIR__ . '/../conf/trazabilidad.php';
require_once __DIR__ . '/../conf/auditoria_exportacion.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

$user = trazabilidad_identidad($conexion);
if (empty($user['admin'])) {
    $GLOBALS['viora_audit_denied'] = true;
    auditoria_evento('Seguridad', 'Acceso denegado', 'Consulta de trazabilidad individual', false);
    http_response_code(($_SESSION['logged_in'] ?? false) ? 403 : 401);
    exit('Se requiere una sesión autorizada.');
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
$_SESSION['chatbot_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['trazabilidad_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['trazabilidad_csrf'];
if ($method === 'POST' && (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf']))) {
    auditoria_evento('Seguridad', 'Acceso denegado', 'Exportación de trazabilidad individual', false);
    http_response_code(403);
    exit('Recargue la página para renovar el formulario.');
}
$input = [];
foreach (['entidad','referencia','desde','hasta','estado','antes','fecha_cursor'] as $field) {
    if (is_string($_GET[$field] ?? null)) $input[$field] = $_GET[$field];
}
$type = $input['entidad'] ?? '';
$id = $input['referencia'] ?? '';
session_write_close();
$menuSesionCerrada = true;
try {
    $record = trazabilidad_registro($conexion, $user, $type, $id);
    if (!$record) {
        auditoria_evento('Seguridad', 'Acceso denegado', 'Registro de trazabilidad individual', false);
        http_response_code(404);
        exit('Registro no disponible.');
    }
    [$rows, $more] = trazabilidad_pagina($conexion, $record, $input);
    if (!$record['actual'] && !$rows && empty($input['desde']) && empty($input['hasta'])
        && empty($input['estado']) && empty($input['antes'])) {
        http_response_code(404);
        exit('Registro no disponible.');
    }
    $refs = [[$type, $id]];
    if ($method === 'POST') {
        $format = $_POST['formato'] ?? '';
        if (!in_array($format, ['pdf', 'xlsx'], true)) throw new InvalidArgumentException('Formato inválido.');
        $body = $format === 'pdf' ? auditoria_pdf($rows, $record['etiqueta']) : auditoria_xlsx($rows, $record['etiqueta']);
        auditoria_evento('Auditoría', 'Exportar ' . strtoupper($format), 'Trazabilidad individual', true, $refs);
        header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: attachment; filename="VIORA-trazabilidad.' . $format . '"');
        echo $body;
        exit;
    }
    auditoria_evento('Auditoría', 'Consultar', 'Trazabilidad individual', true, $refs);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    exit($e->getMessage());
} catch (Throwable $e) {
    error_log('VIORA trazabilidad individual: ' . $e->getCode());
    http_response_code(503);
    exit('La trazabilidad está temporalmente indisponible.');
}
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = static fn(array $query) => 'trazabilidad.php?' . http_build_query($query);
$first = array_diff_key($input, array_flip(['antes', 'fecha_cursor']));
$actual = $record['actual'];
$documents = [];
if ($actual && $type === 'factura') $documents['PDF de la factura'] = 'factura_pdf.php?id=' . $id;
if ($type === 'contrato' && !empty($actual['tiene_pdf'])) $documents['PDF del contrato'] = 'ver_contrato.php?id=' . $id;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trazabilidad · <?= $escape($record['etiqueta']) ?> | VIORA</title>
    <link rel="stylesheet" href="../estilos/styleP.css?v=6">
    <link rel="stylesheet" href="../estilos/moderno.css">
    <link rel="stylesheet" href="../estilos/reportes.css">
    <link rel="stylesheet" href="../estilos/auditoria.css">
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/menu_lateral.php'; ?>
<main class="contenido bi">
    <h1>Historial y trazabilidad</h1>
    <h2><?= $escape($record['etiqueta']) ?></h2>
    <?php if (!empty($actual['fecha_registrada']) || !empty($actual['estado'])): ?>
        <section class="bi-panel">
            <h3>Datos del registro</h3>
            <p><?= $escape(implode(' · ', array_filter([$actual['fecha_registrada'] ?? null, $actual['estado'] ?? null]))) ?></p>
        </section>
    <?php endif; ?>
    <?php foreach ($documents as $label => $href): ?>
        <p><a href="<?= $escape($href) ?>"><?= $escape($label) ?></a></p>
    <?php endforeach; ?>
    <form class="bi-panel bi-filters" method="get">
        <input type="hidden" name="entidad" value="<?= $escape($type) ?>">
        <input type="hidden" name="referencia" value="<?= $escape($id) ?>">
        <label>Desde <input type="date" name="desde" value="<?= $escape($input['desde'] ?? '') ?>"></label>
        <label>Hasta <input type="date" name="hasta" value="<?= $escape($input['hasta'] ?? '') ?>"></label>
        <label>Resultado <select name="estado">
            <option value="">Todos</option>
            <?php foreach (['Exitosa','Fallida'] as $status): ?>
            <option <?= ($input['estado'] ?? '') === $status ? 'selected' : '' ?>><?= $escape($status) ?></option>
            <?php endforeach; ?>
        </select></label>
        <button type="submit">Aplicar filtros</button>
    </form>
    <p>Hora de Santo Domingo · Eventos más recientes primero</p>
    <?php if (!$rows): ?>
        <p>No hay eventos de auditoría disponibles para este registro con los filtros aplicados.</p>
    <?php else: ?>
        <form method="post" action="<?= $escape($url($input)) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <button name="formato" value="pdf">Exportar esta página en PDF</button>
            <button name="formato" value="xlsx">Exportar esta página en Excel</button>
        </form>
        <ol class="audit-timeline">
        <?php foreach ($rows as $row): ?>
            <li>
                <h3><?= $escape(auditoria_etapa($row)) ?></h3>
                <?php $actor = $row['actor_nombre'] ?: (!$user['admin'] && $row['actor_id'] ? 'Personal autorizado' : $row['origen']); ?>
                <p><?= $escape(auditoria_fecha($row['fecha']) . ' · ' . $actor . ' · ' . $row['estado']) ?></p>
                <?php if ($row['tabla'] === null): ?><p><?= $escape($row['descripcion']) ?></p><?php endif; ?>
                <?php $changes = trazabilidad_cambios($row); ?>
                <?php if ($changes): ?>
                    <details<?= isset($changes['estado']) ? ' open' : '' ?>><summary>Campos modificados</summary>
                        <dl><?php foreach ($changes as $field => [$old, $new]): ?>
                            <dt><?= $escape(ucfirst(str_replace('_', ' ', $field))) ?></dt>
                            <dd><?= $escape(($old ?? '—') . ' → ' . ($new ?? '—')) ?></dd>
                        <?php endforeach; ?></dl>
                    </details>
                <?php endif; ?>
                <?php if ($user['admin']): ?>
                    <a href="../configuracion/auditoria.php?vista=detalle&amp;id=<?= $escape($row['id']) ?>">Ver referencias y detalle</a>
                    <?php foreach (auditoria_documentos($row) as $label => $href): ?>
                    <a href="<?= $escape($href) ?>"><?= $escape($label) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <nav aria-label="Páginas del historial">
        <?php if (isset($input['antes'])): ?><a href="<?= $escape($url($first)) ?>">Eventos más recientes</a><?php endif; ?>
        <?php if ($more): $last = end($rows); ?>
        <a href="<?= $escape($url($first + ['antes' => $last['id'], 'fecha_cursor' => $last['fecha']])) ?>">Eventos anteriores →</a>
        <?php endif; ?>
    </nav>
</main>
</div>
</body>
</html>
