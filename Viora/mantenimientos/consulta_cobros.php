<?php
// Consulta de cobros: pagos registrados sobre facturas, con filtros y resumen.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../conf/conexion.php';

if (empty($_SESSION['logged_in'])) { header('Location: ../login/login.php'); exit; }

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pesos($n) { return 'RD$ ' . number_format((float)$n, 2); }

$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$usuario = (string)($_SESSION['usuario'] ?? '');
$menuActivo = 'cobros';

$q = trim((string)($_GET['q'] ?? ''));
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
$metodo = trim((string)($_GET['metodo'] ?? ''));

$fechaValida = static fn($f) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;
if (!$fechaValida($desde)) $desde = '';
if (!$fechaValida($hasta)) $hasta = '';
if (!in_array($metodo, ['Efectivo', 'Transferencia', 'Tarjeta', 'Cheque'], true)) $metodo = '';

$joins = "FROM factura_pago fp
          INNER JOIN factura f ON f.id_facturas = fp.factura_id
          LEFT JOIN persona p ON p.id_personas = f.persona_id
          LEFT JOIN usuario u ON u.id_usuarios = fp.usuario_id";

try {
    $sqlRol = 'SELECT r.nombre FROM usuario u INNER JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND ' . ($idUsuario ? 'u.id_usuarios=?' : 'u.usuario=?') . ' LIMIT 1';
    $st = $conexion->prepare($sqlRol);
    if ($idUsuario) $st->bind_param('i', $idUsuario); else $st->bind_param('s', $usuario);
    $st->execute();
    $filaRol = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$filaRol || !in_array(mb_strtolower(trim($filaRol['nombre'])), ['administrador', 'admin'], true)) {
        http_response_code(403); exit('Acceso exclusivo del administrador.');
    }

    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(CONCAT_WS(' ', p.nombres, p.apellidos) LIKE ? OR p.cedula LIKE ? OR f.numero_documento LIKE ?)";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($desde !== '') {
        $where[] = 'DATE(fp.fecha_pago) >= ?';
        $params[] = $desde;
        $types .= 's';
    }
    if ($hasta !== '') {
        $where[] = 'DATE(fp.fecha_pago) <= ?';
        $params[] = $hasta;
        $types .= 's';
    }
    if ($metodo !== '') {
        $where[] = 'fp.metodo_pago = ?';
        $params[] = $metodo;
        $types .= 's';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $conexion->prepare("SELECT COUNT(*) AS cantidad, COALESCE(SUM(fp.monto), 0) AS total $joins $whereSql");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $resumen = $st->get_result()->fetch_assoc();
    $st->close();

    $st = $conexion->prepare("SELECT fp.metodo_pago, COUNT(*) AS cantidad, SUM(fp.monto) AS total $joins $whereSql GROUP BY fp.metodo_pago ORDER BY total DESC");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $porMetodo = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $st = $conexion->prepare("SELECT fp.id_pagos, fp.fecha_pago, fp.monto, fp.metodo_pago, fp.referencia, fp.observaciones,
                                     f.id_facturas, f.numero_documento, f.periodo, f.estado AS factura_estado,
                                     p.nombres, p.apellidos, p.cedula, u.usuario AS registrado_por
                              $joins $whereSql
                              ORDER BY fp.fecha_pago DESC, fp.id_pagos DESC LIMIT 300");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $cobros = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} catch (Throwable $ex) {
    error_log('VIORA consulta_cobros: ' . $ex->getMessage());
    http_response_code(500);
    exit('No se pudieron consultar los cobros.');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Consulta de cobros | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=11">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#153b30;font-family:Arial,sans-serif}
.diseño{display:flex;min-height:100vh}.cb-contenido{flex:1;min-width:0;padding:30px}
.cb-encabezado{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:23px}
.cb-encabezado h1{font-size:26px;margin:0 0 8px;color:#0f382b}.cb-encabezado p{margin:0;color:#6d8076;font-size:14px}
.cb-panel{background:white;border:1px solid #dce8e1;border-radius:14px;padding:19px;margin-bottom:20px}
.cb-panel h2{font-size:18px;margin:0 0 12px}
.cb-filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.cb-filtros label{display:flex;flex-direction:column;font-size:12px;color:#365b4b;gap:4px}
.cb-filtros input,.cb-filtros select{padding:9px;border:1px solid #dce8e1;border-radius:8px;font:inherit;font-size:13px;min-width:160px}
.cb-resumen{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.cb-tarjeta{flex:1;min-width:180px;background:white;border:1px solid #dce8e1;border-radius:12px;padding:14px}
.cb-tarjeta span{display:block;font-size:12px;color:#6d8076}.cb-tarjeta strong{font-size:18px;color:#0f382b}
.cb-tabla-contenedor{overflow-x:auto}.cb-tabla{width:100%;border-collapse:collapse;min-width:800px}
.cb-tabla th{text-align:left;padding:12px;background:#eef5f1;color:#365b4b;font-size:12px}
.cb-tabla td{padding:12px;border-bottom:1px solid #edf1ee;font-size:13px;vertical-align:middle}
.cb-tabla td.num,.cb-tabla th.num{text-align:right}
.cb-estado{display:inline-block;padding:5px 10px;border-radius:99px;font-weight:700;font-size:12px;background:#edf0fa;color:#4b5686}
.cb-estado.pagada{background:#e5f4ec;color:#176546}.cb-estado.parcial{background:#fff4df;color:#8a5a00}.cb-estado.anulada{background:#ffe5e5;color:#bd2929}
.cb-enlace{color:#0f382b;font-weight:700;text-decoration:none;margin-right:14px}
.cb-boton{display:inline-block;border:0;border-radius:9px;background:#0f382b;color:white;padding:9px 12px;font-weight:700;text-decoration:none;cursor:pointer;font-size:13px}
.cb-boton:hover{background:#19543e}
.cb-vacio{text-align:center;padding:30px;color:#6b8074}
</style>
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/menu_lateral.php'; ?>
<main class="cb-contenido">

    <header class="cb-encabezado">
        <div>
            <h1>Consulta de cobros</h1>
            <p>Pagos registrados sobre facturas. Se muestran hasta 300 cobros más recientes.</p>
        </div>
        <div>
            <a class="cb-enlace" href="consulta_facturas.php">Consulta de facturas</a>
        </div>
    </header>

    <section class="cb-panel">
        <h2>Filtros</h2>
        <form method="get" action="consulta_cobros.php" class="cb-filtros">
            <label>Cliente, cédula o factura
                <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Ej. Juan, 001-..., FAC-...">
            </label>
            <label>Desde
                <input type="date" name="desde" value="<?= esc($desde) ?>">
            </label>
            <label>Hasta
                <input type="date" name="hasta" value="<?= esc($hasta) ?>">
            </label>
            <label>Método de pago
                <select name="metodo">
                    <option value="">Todos</option>
                    <?php foreach (['Efectivo', 'Transferencia', 'Tarjeta', 'Cheque'] as $op): ?>
                        <option value="<?= $op ?>" <?= $metodo === $op ? 'selected' : '' ?>><?= $op ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="cb-boton">Buscar</button>
            <a class="cb-enlace" href="consulta_cobros.php">Limpiar</a>
        </form>
    </section>

    <div class="cb-resumen">
        <div class="cb-tarjeta"><span>Cobros</span><strong><?= (int)$resumen['cantidad'] ?></strong></div>
        <div class="cb-tarjeta"><span>Total cobrado</span><strong><?= pesos($resumen['total']) ?></strong></div>
        <?php foreach ($porMetodo as $pm): ?>
            <div class="cb-tarjeta"><span><?= esc($pm['metodo_pago']) ?> (<?= (int)$pm['cantidad'] ?>)</span><strong><?= pesos($pm['total']) ?></strong></div>
        <?php endforeach; ?>
    </div>

    <section class="cb-panel">
        <div class="cb-tabla-contenedor">
        <table class="cb-tabla">
            <thead>
                <tr>
                    <th>Fecha</th><th>Cliente</th><th>Factura</th><th>Período</th><th>Método</th>
                    <th>Referencia</th><th class="num">Monto</th><th>Registrado por</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$cobros): ?>
                <tr><td colspan="9" class="cb-vacio">No hay cobros que coincidan con los filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($cobros as $c): ?>
                <tr>
                    <td><?= esc($c['fecha_pago']) ?></td>
                    <td><?= esc(trim($c['nombres'] . ' ' . $c['apellidos'])) ?: '—' ?><br><small><?= esc($c['cedula']) ?></small></td>
                    <td><strong><?= esc($c['numero_documento']) ?></strong><br><span class="cb-estado <?= esc(strtolower(str_replace(' ', '', $c['factura_estado']))) ?>"><?= esc($c['factura_estado']) ?></span></td>
                    <td><?= esc($c['periodo']) ?></td>
                    <td><?= esc($c['metodo_pago']) ?></td>
                    <td><?= esc($c['referencia']) ?: '—' ?></td>
                    <td class="num"><?= pesos($c['monto']) ?></td>
                    <td><?= esc($c['registrado_por']) ?: '—' ?></td>
                    <td><a class="cb-enlace" href="consulta_facturas.php?id=<?= (int)$c['id_facturas'] ?>">Ver factura</a></td>
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
