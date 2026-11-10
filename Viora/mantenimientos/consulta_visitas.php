<?php
// Consulta de visitas a inmuebles: visitas solicitadas por clientes desde el catálogo.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../login/login.php'); exit; }

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$rol = (string)($_SESSION['rol'] ?? '');
if (strcasecmp($rol, 'Administrador') !== 0 && strcasecmp($rol, 'Admin') !== 0) {
    http_response_code(403);
    exit('Acceso exclusivo del administrador.');
}

if (empty($_SESSION['csrf_visitas'])) {
    $_SESSION['csrf_visitas'] = bin2hex(random_bytes(32));
}

$estadosValidos = ['Pendiente', 'Aprobada', 'Rechazada', 'Cancelada'];
$mensaje = '';
$mensajeError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idVisita = filter_var($_POST['id_visita'] ?? null, FILTER_VALIDATE_INT);
    $nuevoEstado = (string)($_POST['estado'] ?? '');

    if (!hash_equals($_SESSION['csrf_visitas'], (string)($_POST['csrf_token'] ?? ''))) {
        $mensaje = 'La sesión del formulario expiró. Intente nuevamente.';
        $mensajeError = true;
    } elseif (!$idVisita || !in_array($nuevoEstado, $estadosValidos, true)) {
        $mensaje = 'Datos de actualización no válidos.';
        $mensajeError = true;
    } else {
        try {
            $st = $conexion->prepare('UPDATE visita_propiedad SET estado = ? WHERE id_visita = ?');
            $st->bind_param('si', $nuevoEstado, $idVisita);
            $st->execute();
            $st->close();
            $mensaje = "Visita VIS-" . str_pad((string)$idVisita, 3, '0', STR_PAD_LEFT) . " marcada como $nuevoEstado.";
        } catch (Throwable $ex) {
            error_log('VIORA consulta_visitas (actualizar): ' . $ex->getMessage());
            $mensaje = 'No se pudo actualizar la visita.';
            $mensajeError = true;
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));

$fechaValida = static fn($f) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;
if (!$fechaValida($desde)) $desde = '';
if (!$fechaValida($hasta)) $hasta = '';
if (!in_array($estado, $estadosValidos, true)) $estado = '';

$joins = "FROM visita_propiedad v
          INNER JOIN apartamento a ON a.id_apartamentos = v.apartamento_id
          LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
          LEFT JOIN condominio c ON c.id_condominios = e.condominio_id
          LEFT JOIN persona p ON p.id_personas = v.persona_id";

try {
    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(CONCAT_WS(' ', p.nombres, p.apellidos) LIKE ? OR p.cedula LIKE ? OR CONCAT_WS(' ', a.numero, e.nombre, c.nombre) LIKE ?)";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($estado !== '') {
        $where[] = 'v.estado = ?';
        $params[] = $estado;
        $types .= 's';
    }
    if ($desde !== '') {
        $where[] = 'DATE(v.fecha_visita) >= ?';
        $params[] = $desde;
        $types .= 's';
    }
    if ($hasta !== '') {
        $where[] = 'DATE(v.fecha_visita) <= ?';
        $params[] = $hasta;
        $types .= 's';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $conexion->prepare("SELECT v.estado, COUNT(*) AS cantidad $joins $whereSql GROUP BY v.estado");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $porEstado = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'cantidad', 'estado');
    $st->close();

    $st = $conexion->prepare("SELECT COUNT(*) AS proximas $joins $whereSql" . ($where ? ' AND' : ' WHERE')
        . " v.estado IN ('Pendiente','Aprobada') AND v.fecha_visita >= NOW()");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $proximas = (int)($st->get_result()->fetch_assoc()['proximas'] ?? 0);
    $st->close();

    $st = $conexion->prepare("SELECT v.id_visita, v.fecha_visita, v.fecha_solicitud, v.estado, v.observaciones,
                                     a.numero AS apartamento, e.nombre AS edificio, c.nombre AS condominio,
                                     p.nombres, p.apellidos, p.cedula, p.telefono, p.correo
                              $joins $whereSql
                              ORDER BY v.fecha_visita DESC, v.id_visita DESC LIMIT 300");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $visitas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} catch (Throwable $ex) {
    error_log('VIORA consulta_visitas: ' . $ex->getMessage());
    http_response_code(500);
    exit('No se pudieron consultar las visitas.');
}

$totalVisitas = array_sum($porEstado);
$menuActivo = 'consulta_visitas';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Consulta de visitas a inmuebles | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=11">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#153b30;font-family:Arial,sans-serif}
.diseño{display:flex;min-height:100vh}.cv-contenido{flex:1;min-width:0;padding:30px}
.cv-encabezado{margin-bottom:23px}
.cv-encabezado h1{font-size:26px;margin:0 0 8px;color:#0f382b}.cv-encabezado p{margin:0;color:#6d8076;font-size:14px}
.cv-panel{background:white;border:1px solid #dce8e1;border-radius:14px;padding:19px;margin-bottom:20px}
.cv-panel h2{font-size:18px;margin:0 0 12px}
.cv-filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.cv-filtros label{display:flex;flex-direction:column;font-size:12px;color:#365b4b;gap:4px}
.cv-filtros input,.cv-filtros select{padding:9px;border:1px solid #dce8e1;border-radius:8px;font:inherit;font-size:13px;min-width:160px}
.cv-resumen{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.cv-tarjeta{flex:1;min-width:150px;background:white;border:1px solid #dce8e1;border-radius:12px;padding:14px}
.cv-tarjeta span{display:block;font-size:12px;color:#6d8076}.cv-tarjeta strong{font-size:20px;color:#0f382b}
.cv-tabla-contenedor{overflow-x:auto}.cv-tabla{width:100%;border-collapse:collapse;min-width:900px}
.cv-tabla th{text-align:left;padding:12px;background:#eef5f1;color:#365b4b;font-size:12px}
.cv-tabla td{padding:12px;border-bottom:1px solid #edf1ee;font-size:13px;vertical-align:top}
.cv-estado{display:inline-block;padding:5px 10px;border-radius:99px;font-weight:700;font-size:12px}
.cv-estado.pendiente{background:#fff2d8;color:#936000}.cv-estado.aprobada{background:#e2f6ea;color:#157747}
.cv-estado.rechazada{background:#ffebeb;color:#a32929}.cv-estado.cancelada{background:#ededed;color:#666}
.cv-enlace{color:#0f382b;font-weight:700;text-decoration:none;margin-right:14px}
.cv-boton{display:inline-block;border:0;border-radius:9px;background:#0f382b;color:white;padding:9px 12px;font-weight:700;text-decoration:none;cursor:pointer;font-size:13px}
.cv-boton:hover{background:#19543e}
.cv-acciones{display:flex;gap:6px;flex-wrap:wrap}
.cv-acciones form{margin:0}
.cv-mini{border:1px solid #cfe0d6;background:#fff;color:#0f382b;border-radius:7px;padding:6px 9px;font-size:12px;font-weight:700;cursor:pointer}
.cv-mini:hover{background:#eef5f1}.cv-mini.ok{background:#157747;color:#fff;border-color:#157747}
.cv-mini.no{color:#a32929;border-color:#efc4c4}
.cv-vacio{text-align:center;padding:30px;color:#6b8074}
.cv-aviso{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;background:#e2f6ea;color:#157747}
.cv-aviso.error{background:#ffebeb;color:#a32929}
.cv-pasada{color:#8a9a92}
</style>
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/menu_lateral.php'; ?>
<main class="cv-contenido">

    <header class="cv-encabezado">
        <h1>Consulta de visitas a inmuebles</h1>
        <p>Visitas solicitadas por los clientes desde el catálogo. Se muestran hasta 300 registros, de la fecha de visita más reciente a la más antigua.</p>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="cv-aviso <?= $mensajeError ? 'error' : '' ?>" role="status"><?= esc($mensaje) ?></div>
    <?php endif; ?>

    <section class="cv-panel">
        <h2>Filtros</h2>
        <form method="get" action="consulta_visitas.php" class="cv-filtros">
            <label>Cliente, cédula o inmueble
                <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Ej. Juan, 001-..., A-101">
            </label>
            <label>Estado
                <select name="estado">
                    <option value="">Todos</option>
                    <?php foreach ($estadosValidos as $op): ?>
                        <option value="<?= $op ?>" <?= $estado === $op ? 'selected' : '' ?>><?= $op ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Visita desde
                <input type="date" name="desde" value="<?= esc($desde) ?>">
            </label>
            <label>Visita hasta
                <input type="date" name="hasta" value="<?= esc($hasta) ?>">
            </label>
            <button type="submit" class="cv-boton">Buscar</button>
            <a class="cv-enlace" href="consulta_visitas.php">Limpiar</a>
        </form>
    </section>

    <div class="cv-resumen">
        <div class="cv-tarjeta"><span>Visitas</span><strong><?= (int)$totalVisitas ?></strong></div>
        <div class="cv-tarjeta"><span>Próximas (pendientes o aprobadas)</span><strong><?= $proximas ?></strong></div>
        <?php foreach ($estadosValidos as $op): ?>
            <div class="cv-tarjeta"><span><?= $op ?></span><strong><?= (int)($porEstado[$op] ?? 0) ?></strong></div>
        <?php endforeach; ?>
    </div>

    <section class="cv-panel">
        <div class="cv-tabla-contenedor">
        <table class="cv-tabla">
            <thead>
                <tr>
                    <th>Código</th><th>Fecha y hora de visita</th><th>Cliente</th><th>Contacto</th>
                    <th>Inmueble</th><th>Observaciones</th><th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$visitas): ?>
                <tr><td colspan="8" class="cv-vacio">No hay visitas que coincidan con los filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($visitas as $v):
                $fechaVisita = strtotime((string)$v['fecha_visita']);
                $pasada = $fechaVisita !== false && $fechaVisita < time();
            ?>
                <tr>
                    <td><strong>VIS-<?= str_pad((string)(int)$v['id_visita'], 3, '0', STR_PAD_LEFT) ?></strong><br>
                        <small>Solicitada <?= esc(date('d/m/Y', strtotime((string)$v['fecha_solicitud']))) ?></small></td>
                    <td class="<?= $pasada ? 'cv-pasada' : '' ?>">
                        <?= $fechaVisita !== false ? esc(date('d/m/Y h:i A', $fechaVisita)) : '—' ?>
                        <?php if ($pasada): ?><br><small>Fecha ya transcurrida</small><?php endif; ?>
                    </td>
                    <td><?= esc(trim($v['nombres'] . ' ' . $v['apellidos'])) ?: '—' ?><br><small><?= esc($v['cedula']) ?></small></td>
                    <td><?= esc($v['telefono']) ?: '—' ?><br><small><?= esc($v['correo']) ?></small></td>
                    <td><?= esc($v['edificio']) ?> · Apto. <?= esc($v['apartamento']) ?><br><small><?= esc($v['condominio']) ?></small></td>
                    <td><?= esc($v['observaciones']) ?: '—' ?></td>
                    <td><span class="cv-estado <?= esc(strtolower($v['estado'])) ?>"><?= esc($v['estado']) ?></span></td>
                    <td>
                        <div class="cv-acciones">
                        <?php
                        $botones = [
                            'Aprobada' => ['Aprobar', 'ok'],
                            'Rechazada' => ['Rechazar', 'no'],
                            'Cancelada' => ['Cancelar', ''],
                            'Pendiente' => ['Reabrir', ''],
                        ];
                        $transiciones = [
                            'Pendiente' => ['Aprobada', 'Rechazada', 'Cancelada'],
                            'Aprobada' => ['Cancelada', 'Pendiente'],
                            'Rechazada' => ['Pendiente'],
                            'Cancelada' => ['Pendiente'],
                        ][$v['estado']] ?? [];
                        foreach ($transiciones as $nuevo):
                            [$texto, $clase] = $botones[$nuevo];
                        ?>
                            <form method="post" action="consulta_visitas.php?<?= esc(http_build_query(array_filter(['q' => $q, 'estado' => $estado, 'desde' => $desde, 'hasta' => $hasta], 'strlen'))) ?>"
                                  <?= $nuevo === 'Pendiente' ? '' : 'onsubmit="return confirm(\'¿' . esc($texto) . ' esta visita?\')"' ?>>
                                <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_visitas']) ?>">
                                <input type="hidden" name="id_visita" value="<?= (int)$v['id_visita'] ?>">
                                <input type="hidden" name="estado" value="<?= esc($nuevo) ?>">
                                <button type="submit" class="cv-mini <?= $clase ?>"><?= esc($texto) ?></button>
                            </form>
                        <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

</main>
</div>
</body>
</html>
