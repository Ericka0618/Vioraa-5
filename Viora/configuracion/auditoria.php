<?php
session_start();
require_once __DIR__ . '/../conf/conexion.php';
$vioraAuthRequireActiveRole = true;
require_once __DIR__ . '/../conf/require_admin.php';
require_once __DIR__ . '/../conf/auditoria_consulta.php';
require_once __DIR__ . '/../conf/auditoria_exportacion.php';
require_once __DIR__ . '/../conf/auditoria_ia.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
if (empty($_SESSION['auditoria_csrf'])) $_SESSION['auditoria_csrf'] = bin2hex(random_bytes(32));
if (empty($_SESSION['chatbot_csrf'])) $_SESSION['chatbot_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['auditoria_csrf'];
session_write_close();
$menuSesionCerrada = true;

function ae($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function auditoria_url(array $overrides = []): string
{
    return 'auditoria.php?' . http_build_query(array_replace($_GET, $overrides));
}

$input = $_GET;
$method = $_SERVER['REQUEST_METHOD'];
$intent = $method === 'POST' ? ($_POST['operacion'] ?? '') : ($_GET['vista'] ?? 'historial');
$intent = is_string($intent) ? $intent : '';
$operations = ['historial' => 'Consultar', 'detalle' => 'Ver detalles', 'seguimiento' => 'Seguimiento',
    'pdf' => 'Exportar PDF', 'xlsx' => 'Exportar Excel', 'ia' => 'Consultar IA'];
$action = $operations[$intent] ?? 'Acceso denegado';
$failure = null;
$detail = null;
$timeline = null;
$limited = false;
$ai = null;
$history = $charts = [];
$metrics = [];
$exportSnapshot = 0;
$report = 1;
$auditRefs = [];
$success = false;
try {
    if ($method === 'POST' && (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf']))) {
        http_response_code(403);
        throw new InvalidArgumentException('La sesión del formulario expiró. Recargue la página.');
    }
    if (!in_array($intent, $method === 'POST' ? ['pdf', 'xlsx', 'ia'] : ['historial', 'detalle', 'seguimiento'], true)) {
        http_response_code(405);
        throw new InvalidArgumentException('Operación inválida.');
    }
    if ($intent === 'detalle') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new InvalidArgumentException('Identificador de auditoría inválido.');
        $auditRefs = [['auditoria', (string)$id]];
        $detail = $conexion->execute_query('SELECT *, huella=' . auditoria_huella_sql() . ' AS integra FROM auditoria WHERE id=?', [$id])->fetch_assoc();
        if (!$detail) {
            http_response_code(404);
            throw new InvalidArgumentException('Operación no encontrada.');
        }
    } elseif ($intent === 'seguimiento') {
        $type = $_GET['entidad'] ?? '';
        $reference = $_GET['referencia'] ?? '';
        if (is_string($type) && is_string($reference) && trim($reference) !== '') {
            [$timeline, $limited] = auditoria_linea($conexion, $type, trim($reference));
            $auditRefs = [[$type, trim($reference)]];
        }
    } else {
        if ($intent === 'ia') {
            $question = $_POST['pregunta'] ?? '';
            $plan = auditoria_plan_gemini(is_string($question) ? trim($question) : '', $input);
            $input = array_replace($input, $plan);
            $ai = [];
            if (isset($plan['entidad'], $plan['referencia'])) {
                [$timeline, $limited] = auditoria_linea($conexion, $plan['entidad'], $plan['referencia']);
                $auditRefs = [[$plan['entidad'], $plan['referencia']]];
            }
        }
        [$where, $params] = auditoria_filtros($input);
        $reports = auditoria_reportes();
        $report = (int)($input['reporte'] ?? 1);
        $report = isset($reports[$report]) ? $report : 1;
        if ($intent === 'pdf' || $intent === 'xlsx') {
            if ($report >= 10 && $report <= 19 && $report !== 15 && $report !== 16 && $report !== 17
                && is_string($input['entidad'] ?? null) && is_string($input['referencia'] ?? null) && $input['referencia'] !== '') {
                [$related, $limited] = auditoria_linea($conexion, $input['entidad'], $input['referencia']);
                if ($limited) throw new InvalidArgumentException('La línea de tiempo supera el límite de exportación.');
                $auditRefs = [[$input['entidad'], $input['referencia']]];
                unset($input['reporte']);
                [$where, $params] = auditoria_filtros($input);
                $ids = array_column($related, 'id');
                $where .= $ids ? ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : ' AND 0';
                $params = array_merge($params, $ids);
            }
            $sortExport = match ($report) {
                2 => 'actor_nombre,actor_id,fecha DESC,id DESC',
                3 => 'modulo,fecha DESC,id DESC',
                default => $auditRefs ? 'fecha,id' : 'fecha DESC,id DESC',
            };
            $batch = filter_var($_POST['lote'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $snapshot = filter_var($_POST['corte'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (!$batch || ($batch > 1 && $snapshot === false) || $batch > intdiv(PHP_INT_MAX, 2000)) {
                throw new InvalidArgumentException('Lote de exportación inválido.');
            }
            if ($snapshot !== false) {
                $where .= ' AND a.id<=?';
                $params[] = $snapshot;
            }
            $offsetExport = ($batch - 1) * 2000;
            $rows = $conexion->execute_query("SELECT * FROM auditoria a WHERE $where ORDER BY $sortExport LIMIT 2001 OFFSET $offsetExport", $params)->fetch_all(MYSQLI_ASSOC);
            $exportContinues = count($rows) > 2000;
            if ($exportContinues) array_pop($rows);
            $exportTitle = $reports[$report][0] . ($batch > 1 || $exportContinues ? ' · Lote ' . $batch : '');
            $body = $intent === 'pdf' ? auditoria_pdf($rows, $exportTitle) : auditoria_xlsx($rows, $exportTitle);
            auditoria_evento('Auditoría', $action, $reports[$report][0], true, $auditRefs);
            header('Content-Type: ' . ($intent === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
            header('Content-Disposition: attachment; filename="VIORA-auditoria-' . $batch . '.' . $intent . '"');
            echo $body;
            exit;
        }
        $exportSnapshot = (int)$conexion->query('SELECT COALESCE(MAX(id),0) FROM auditoria')->fetch_row()[0];
        $metrics = auditoria_indicadores($conexion, $where, $params);
        $orderOptions = ['fecha' => 'fecha', 'usuario' => 'actor_nombre', 'modulo' => 'modulo', 'accion' => 'accion', 'estado' => 'estado'];
        $order = is_string($_GET['orden'] ?? null) ? ($orderOptions[$_GET['orden']] ?? 'fecha') : 'fecha';
        $direction = ($_GET['direccion'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        $pages = max(1, (int)ceil($metrics['total'] / 30));
        $page = min($pages, max(1, (int)($_GET['pagina'] ?? 1)));
        $offset = ($page - 1) * 30;
        $history = $conexion->execute_query("SELECT id,fecha,actor_nombre,origen,rol,modulo,accion,descripcion,estado
            FROM auditoria a WHERE $where ORDER BY $order $direction,id $direction LIMIT 30 OFFSET $offset", $params)->fetch_all(MYSQLI_ASSOC);
        foreach (['Actividad por fecha' => "DATE(fecha - INTERVAL 4 HOUR)", 'Actividad por usuario' => "IF(actor_id IS NULL,origen,CONCAT(COALESCE(actor_nombre,'Usuario'),' #',actor_id))",
            'Módulos con mayor actividad' => 'modulo', 'Actividad por operación' => 'accion'] as $label => $group) {
            $rank = $label === 'Actividad por fecha' ? 'etiqueta DESC' : 'cantidad DESC,etiqueta';
            $charts[$label] = $conexion->execute_query("SELECT $group etiqueta, COUNT(*) cantidad
                FROM auditoria a WHERE $where GROUP BY 1 ORDER BY $rank LIMIT 12", $params)->fetch_all(MYSQLI_ASSOC);
        }
        if ($ai !== null) $ai = $plan;
    }
    $success = true;
} catch (InvalidArgumentException $error) {
    if (http_response_code() < 400) http_response_code(422);
    $failure = $error->getMessage();
} catch (mysqli_sql_exception $error) {
    http_response_code(503);
    $failure = 'El servicio de auditoría no está disponible.';
    error_log('VIORA consulta de auditoría: código ' . $error->getCode());
} catch (Throwable $error) {
    http_response_code(502);
    $failure = $intent === 'ia' ? 'No se pudo completar la consulta con Gemini.' : 'No se pudo completar la operación.';
    error_log('VIORA auditoría: fallo en ' . $action . ' (' . get_class($error) . ')');
}
auditoria_evento('Auditoría', $action, $success ? 'Operación administrativa' : 'Operación administrativa fallida', $success, $auditRefs);
$entities = auditoria_entidades();
$get = static fn(string $key): string => is_scalar($input[$key] ?? null) ? (string)$input[$key] : '';
$summaryLabels = ['total' => 'Total de operaciones', 'exitosas' => 'Exitosas', 'fallidas' => 'Fallidas',
    'usuarios' => 'Usuarios activos', 'accesos' => 'Intentos de acceso fallidos', 'modificadas' => 'Registros modificados', 'creadas' => 'Registros creados',
    'fiscales' => 'Eventos fiscales', 'suscripciones' => 'Actividad de suscripciones',
    'eliminadas' => 'Registros eliminados', 'financieras' => 'Cambios financieros'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Auditoría y Trazabilidad · VIORA</title>
    <link rel="stylesheet" href="../estilos/styleP.css?v=6">
    <link rel="stylesheet" href="../estilos/moderno.css">
    <link rel="stylesheet" href="../estilos/reportes.css?v=1">
    <link rel="stylesheet" href="../estilos/auditoria.css">
</head>
<body><div class="diseño">
<?php include __DIR__ . '/../mantenimientos/menu_lateral.php'; ?>
<main class="contenido bi auditoria">
    <header class="bi-heading"><h1>Auditoría y Trazabilidad</h1></header>
    <div class="bi-tabs">
        <a class="bi-button" href="auditoria.php">Historial de operaciones</a>
        <a class="bi-button" href="auditoria.php?vista=seguimiento">Seguimiento de procesos</a>
    </div>
    <?php if ($failure): ?><p class="bi-panel bi-error" role="alert"><?= ae($failure) ?></p><?php endif; ?>
    <?php if ($detail): ?>
        <article class="bi-panel">
            <h2>Operación #<?= ae($detail['id']) ?></h2>
            <p><?= ae(auditoria_fecha($detail['fecha'])) ?> · <?= ae($detail['descripcion']) ?> · <?= ae($detail['estado']) ?></p>
            <dl class="audit-properties">
                <?php foreach (['evento'=>'Identificador de evento','actor_id'=>'ID de usuario','actor_nombre'=>'Usuario','rol'=>'Rol','modulo'=>'Módulo',
                    'accion'=>'Acción','tabla'=>'Tabla','registro'=>'Registro','ip'=>'Dirección IP','seguimiento'=>'Identificador de seguimiento',
                    'origen'=>'Origen','huella'=>'Huella de integridad','referencias'=>'Identificadores de referencia'] as $key=>$label): ?>
                    <dt><?= ae($label) ?></dt><dd><?= ae($detail[$key] ?? '—') ?></dd>
                <?php endforeach; ?>
            </dl>
            <p class="<?= $detail['integra'] ? '' : 'bi-error' ?>"><?= $detail['integra'] ? 'Huella verificada' : 'La huella no coincide' ?></p>
            <?php
            $before = json_decode($detail['anterior'] ?? 'null', true) ?? [];
            $after = json_decode($detail['nuevo'] ?? 'null', true) ?? [];
            $fields = array_unique(array_merge(array_keys($before), array_keys($after)));
            ?>
            <h2>Comparación de cambios</h2>
            <?php if (!$fields): ?><p>Sin valores publicables para esta operación.</p><?php else: ?>
                <div class="bi-table-wrap"><table>
                    <thead><tr><th scope="col">Campo</th><th scope="col">Valor anterior</th><th scope="col">Valor nuevo</th></tr></thead>
                    <tbody><?php foreach ($fields as $field): ?>
                        <tr class="<?= ($before[$field] ?? null) !== ($after[$field] ?? null) ? 'audit-changed' : '' ?>">
                            <th scope="row"><?= ae(ucfirst(str_replace('_', ' ', $field))) ?></th>
                            <td><?= ae($before[$field] ?? '—') ?></td><td><?= ae($after[$field] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
            <?php foreach (auditoria_documentos($detail) as $label=>$url): ?><a class="bi-result-link" href="<?= ae($url) ?>"><?= ae($label) ?></a><?php endforeach; ?>
            <a class="bi-result-link" href="<?= ae(auditoria_url(['vista'=>'historial','id'=>null,'seguimiento'=>$detail['seguimiento'],'pagina'=>null])) ?>">Ver operaciones con este seguimiento</a>
        </article>
    <?php elseif ($intent === 'seguimiento'): ?>
        <form class="bi-panel bi-filters">
            <input type="hidden" name="vista" value="seguimiento">
            <label>Entidad<select name="entidad">
                <?php foreach ($entities as $key=>$label): ?><option value="<?= ae($key) ?>" <?= $get('entidad') === $key ? 'selected' : '' ?>><?= ae($label) ?></option><?php endforeach; ?>
            </select></label>
            <label>Identificador<input name="referencia" value="<?= ae($get('referencia')) ?>" maxlength="100" required></label>
            <button class="bi-button">Buscar proceso</button>
        </form>
    <?php endif; ?>
    <?php if ($timeline !== null): ?>
        <section class="bi-panel">
            <h2>Línea de tiempo</h2>
            <?php if (!$timeline): ?><p>Sin eventos registrados para esta referencia.</p><?php endif; ?>
            <?php if ($limited): ?><p role="status">El resumen alcanzó el límite de expansión.</p><?php endif; ?>
            <?php if (preg_match('/^(?:[1-9][0-9]{0,18}|E[0-9]{12})$/D', $get('referencia'))): ?>
                <a href="../mantenimientos/trazabilidad.php?<?= ae(http_build_query(['entidad' => $get('entidad'), 'referencia' => $get('referencia')])) ?>">Abrir historial paginado del registro</a>
            <?php endif; ?>
            <ol class="audit-timeline">
                <?php foreach ($timeline as $event): ?>
                    <li><time><?= ae(auditoria_fecha($event['fecha'])) ?></time>
                        <strong><?= ae(auditoria_etapa($event)) ?></strong>
                        <span class="bi-pill <?= $event['estado'] === 'Fallida' ? 'bi-error' : '' ?>"><?= ae($event['estado']) ?></span>
                        <p><?= ae(($event['actor_nombre'] ?: $event['origen']) . ' · ' . $event['descripcion']) ?></p>
                        <p class="audit-refs"><?= ae($event['referencias']) ?></p>
                        <a class="bi-result-link" href="<?= ae(auditoria_url(['vista'=>'detalle','id'=>$event['id']])) ?>">Ver detalles</a>
                        <?php foreach (auditoria_documentos($event) as $label=>$url): ?><a class="bi-result-link" href="<?= ae($url) ?>"><?= ae($label) ?></a><?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($timeline && !$limited):
                $reportByEntity = ['contrato'=>10,'apartamento'=>11,'propiedad'=>11,'factura'=>12,'viora_factura'=>12,
                    'pago'=>13,'factura_pago'=>13,'viora_pago'=>13,'suscripcion'=>14,'solicitud_propiedad'=>18,
                    'solicitud_mantenimiento'=>19,'mantenimiento'=>19];
                if (isset($reportByEntity[$get('entidad')])): ?>
                    <form method="post" action="<?= ae(auditoria_url(['entidad'=>$get('entidad'),'referencia'=>$get('referencia'),'reporte'=>$reportByEntity[$get('entidad')]])) ?>">
                        <input type="hidden" name="csrf" value="<?= ae($csrf) ?>">
                        <button name="operacion" value="pdf">Exportar línea de tiempo PDF</button>
                        <button name="operacion" value="xlsx">Exportar línea de tiempo Excel</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if (in_array($intent, ['historial','ia','pdf','xlsx'], true)): ?>
        <form class="bi-panel bi-filters">
            <?php foreach (['desde'=>'Desde','hasta'=>'Hasta','q'=>'Buscar','actor_id'=>'ID de usuario','rol'=>'Rol',
                'modulo'=>'Módulo','accion'=>'Tipo de operación','seguimiento'=>'Identificador de seguimiento'] + $entities as $key=>$label): ?>
                <label><?= ae($label) ?><input type="<?= in_array($key, ['desde','hasta'], true) ? 'date' : 'text' ?>" name="<?= ae($key) ?>" value="<?= ae($get($key)) ?>"></label>
            <?php endforeach; ?>
            <label>Estado<select name="estado">
                <?php foreach ([''=>'Todos','Exitosa'=>'Exitosa','Fallida'=>'Fallida'] as $key=>$label): ?><option value="<?= ae($key) ?>" <?= $get('estado') === $key ? 'selected' : '' ?>><?= ae($label) ?></option><?php endforeach; ?>
            </select></label>
            <label>Reporte<select name="reporte">
                <?php foreach (auditoria_reportes() as $key=>[$label]): ?><option value="<?= $key ?>" <?= $report === $key ? 'selected' : '' ?>><?= ae($label) ?></option><?php endforeach; ?>
            </select></label>
            <button class="bi-button">Aplicar filtros</button>
            <a class="bi-result-link" href="auditoria.php">Limpiar filtros</a>
        </form>
        <?php if ($metrics): ?>
            <?php if ($ai !== null): ?><p class="bi-data-note" role="status">Gemini aplicó los filtros visibles. <?= ae($metrics['total']) ?> operaciones coinciden. Consulte sus registros y responsables en el historial.</p><?php endif; ?>
            <section class="bi-kpis" aria-label="Indicadores">
                <?php foreach ($summaryLabels as $key=>$label): ?><div class="bi-kpi"><span><?= ae($label) ?></span><strong><?= ae(number_format((int)$metrics[$key])) ?></strong></div><?php endforeach; ?>
            </section>
            <section class="audit-charts" aria-label="Gráficos de actividad">
                <?php foreach ($charts as $label=>$groups): ?>
                    <article class="bi-panel"><h2><?= ae($label) ?></h2>
                        <?php if (!$groups): ?><p>Sin operaciones.</p><?php endif; ?>
                        <?php $max = max([1, ...array_column($groups, 'cantidad')]); ?>
                        <?php foreach ($groups as $group): ?>
                            <div class="bi-bar-label"><span><?= ae($group['etiqueta']) ?></span><strong><?= ae($group['cantidad']) ?></strong></div>
                            <meter min="0" max="<?= ae($max) ?>" value="<?= ae($group['cantidad']) ?>" aria-label="<?= ae($group['etiqueta']) ?>"></meter>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </section>
            <section class="bi-panel">
                <h2>Historial de operaciones</h2>
                <form method="post" action="<?= ae('auditoria.php?' . http_build_query($input)) ?>">
                    <input type="hidden" name="csrf" value="<?= ae($csrf) ?>">
                    <input type="hidden" name="corte" value="<?= $exportSnapshot ?>">
                    <?php if ((int)$metrics['total'] > 2000): ?>
                        <label>Lote de hasta 2,000 eventos
                            <input type="number" min="1" max="<?= (int)ceil($metrics['total'] / 2000) ?>" value="1" name="lote" required>
                        </label>
                    <?php endif; ?>
                    <button name="operacion" value="pdf">Descargar PDF</button>
                    <button name="operacion" value="xlsx">Descargar Excel</button>
                </form>
                <div class="bi-table-wrap"><table><thead><tr><th scope="col">ID</th>
                    <?php foreach (['fecha'=>'Fecha y hora','usuario'=>'Usuario','rol'=>'Rol','modulo'=>'Módulo','accion'=>'Acción','descripcion'=>'Descripción','estado'=>'Estado'] as $key=>$label): ?>
                        <th scope="col"><?php if (isset($orderOptions[$key])): ?>
                            <a href="<?= ae('auditoria.php?' . http_build_query(array_replace($input, ['orden'=>$key, 'direccion'=>$orderOptions[$key] === $order && $direction === 'DESC' ? 'asc' : 'desc', 'pagina'=>1]))) ?>"><?= ae($label) ?></a>
                        <?php else: ?><?= ae($label) ?><?php endif; ?></th>
                    <?php endforeach; ?><th scope="col">Detalles</th>
                </tr></thead><tbody>
                    <?php foreach ($history as $row): ?><tr>
                        <td><?= ae($row['id']) ?></td><td><?= ae(auditoria_fecha($row['fecha'])) ?></td>
                        <td><?= ae($row['actor_nombre'] ?: $row['origen']) ?></td><td><?= ae($row['rol'] ?? '—') ?></td>
                        <td><?= ae($row['modulo']) ?></td><td><?= ae($row['accion']) ?></td><td><?= ae($row['descripcion']) ?></td>
                        <td><span class="bi-pill <?= $row['estado'] === 'Fallida' ? 'bi-error' : '' ?>"><?= ae($row['estado']) ?></span></td>
                        <td><a class="bi-result-link" href="<?= ae('auditoria.php?' . http_build_query(array_replace($input, ['vista'=>'detalle','id'=>$row['id']]))) ?>">Ver detalles</a></td>
                    </tr><?php endforeach; ?>
                    <?php if (!$history): ?><tr><td colspan="9">Sin operaciones para estos filtros.</td></tr><?php endif; ?>
                </tbody></table></div>
                <nav aria-label="Paginación del historial">
                    <span>Página <?= $page ?> de <?= $pages ?></span>
                    <?php if ($page > 1): ?><a class="bi-result-link" href="<?= ae('auditoria.php?' . http_build_query(array_replace($input, ['pagina'=>$page-1]))) ?>">Anterior</a><?php endif; ?>
                    <?php if ($page < $pages): ?><a class="bi-result-link" href="<?= ae('auditoria.php?' . http_build_query(array_replace($input, ['pagina'=>$page+1]))) ?>">Siguiente</a><?php endif; ?>
                </nav>
            </section>
        <?php endif; ?>
    <?php endif; ?>
    <section class="bi-panel bi-ai">
        <h2>Consultar auditoría con Gemini</h2>
        <form method="post" action="<?= ae(auditoria_url()) ?>">
            <input type="hidden" name="csrf" value="<?= ae($csrf) ?>">
            <label>Pregunta<textarea name="pregunta" maxlength="2000" rows="2" required placeholder="¿Cuántos intentos fallidos de inicio de sesión hubo hoy?"></textarea></label>
            <button class="bi-button" name="operacion" value="ia">Consultar</button>
        </form>
    </section>
</main></div></body></html>
