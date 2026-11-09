<?php
session_start();
require_once __DIR__ . '/../conf/conexion.php';

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$rol = (string)($_SESSION['rol'] ?? '');
if (strcasecmp($rol, 'Administrador') !== 0 && strcasecmp($rol, 'Admin') !== 0) {
    header('Location: ../mantenimientos/catalogo.php');
    exit;
}

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pesos($n) { return 'RD$ ' . number_format((float)$n, 2); }
function pesosCorto($n) {
    $n = (float)$n;
    if ($n >= 1000000) return 'RD$ ' . round($n / 1000000, 1) . 'M';
    if ($n >= 10000) return 'RD$ ' . round($n / 1000, 1) . 'k';
    return pesos($n);
}
function fdate($f) { return $f ? date('d/m/Y', strtotime($f)) : '—'; }

// Ejecuta una consulta preparada y devuelve todas las filas.
function filas(mysqli $db, string $sql, string $tipos = '', array $params = []): array {
    try {
        $st = $db->prepare($sql);
        if ($tipos !== '') $st->bind_param($tipos, ...$params);
        $st->execute();
        $r = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $r;
    } catch (Throwable $e) {
        error_log('VIORA principal: ' . $e->getMessage());
        return [];
    }
}
function valor(mysqli $db, string $sql, string $tipos = '', array $params = []) {
    $f = filas($db, $sql, $tipos, $params);
    return $f ? array_values($f[0])[0] : 0;
}

$nombre = $_SESSION['nombre_usuario'] ?? 'Usuario';
$usuario = $_SESSION['usuario'] ?? 'Administrador';
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

$palabras = preg_split('/\s+/', trim($nombre));
if (count($palabras) >= 2) {
    $iniciales = mb_strtoupper(mb_substr($palabras[0], 0, 1) . mb_substr($palabras[1], 0, 1), 'UTF-8');
} else {
    $iniciales = mb_strtoupper(mb_substr($nombre, 0, 2, 'UTF-8'), 'UTF-8');
}

$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mesesCorto = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$fecha = date('j') . ' de ' . $meses[(int)date('n') - 1] . ' de ' . date('Y');
$anio = (int)date('Y');
$mesActual = (int)date('n');
$periodo = date('Y-m');
$periodoAnterior = date('Y-m', strtotime('first day of last month'));

// Métricas
$totalApts = (int)valor($conexion, "SELECT COUNT(*) FROM apartamento WHERE activo = 1");
$aptsLibres = (int)valor($conexion, "SELECT COUNT(*) FROM apartamento WHERE activo = 1 AND disponible = 1 AND estado = 'Disponible'");
$cobradoMes = (float)valor($conexion, "SELECT COALESCE(SUM(monto),0) FROM factura_pago WHERE DATE_FORMAT(fecha_pago,'%Y-%m') = ?", 's', [$periodo]);
$cobradoPrev = (float)valor($conexion, "SELECT COALESCE(SUM(monto),0) FROM factura_pago WHERE DATE_FORMAT(fecha_pago,'%Y-%m') = ?", 's', [$periodoAnterior]);
$variacion = $cobradoPrev > 0 ? (($cobradoMes - $cobradoPrev) / $cobradoPrev) * 100 : null;
$facturasMes = (int)valor($conexion, "SELECT COUNT(*) FROM factura WHERE periodo = ? AND estado <> 'Anulada'", 's', [$periodo]);
$facturasPend = (int)valor($conexion, "SELECT COUNT(*) FROM factura WHERE periodo = ? AND estado IN ('Pendiente','Parcial')", 's', [$periodo]);
$repPend = (int)valor($conexion, "SELECT COUNT(*) FROM solicitud_mantenimiento WHERE activo = 1 AND estado = 'Pendiente'");
$porCobrar = (float)valor($conexion, "SELECT COALESCE(SUM(saldo_pendiente),0) FROM factura WHERE estado IN ('Pendiente','Parcial')");
$facturasConSaldo = (int)valor($conexion, "SELECT COUNT(*) FROM factura WHERE estado IN ('Pendiente','Parcial')");
$contratosActivos = (int)valor($conexion, "SELECT COUNT(*) FROM contrato WHERE activo = 1 AND estado = 'Activo'");
$porVencer = (int)valor($conexion, "SELECT COUNT(*) FROM contrato WHERE activo = 1 AND tipo_operacion = 'Alquiler' AND fecha_fin IS NOT NULL AND fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$solicPend = (int)valor($conexion, "SELECT COUNT(*) FROM solicitud_propiedad WHERE activo = 1 AND estado IN ('Pendiente','En revision')");
$visitasPend = (int)valor($conexion, "SELECT COUNT(*) FROM visita_propiedad WHERE estado = 'Pendiente'");

// Gráfico: cobros por mes del año actual
$cobrosMes = array_fill(1, 12, 0.0);
foreach (filas($conexion, "SELECT MONTH(fecha_pago) m, SUM(monto) t FROM factura_pago WHERE YEAR(fecha_pago) = ? GROUP BY MONTH(fecha_pago)", 'i', [$anio]) as $r) {
    $cobrosMes[(int)$r['m']] = (float)$r['t'];
}
$maximo = max($cobrosMes);
$tope = 1000;
if ($maximo > 0) {
    // Escala con un paso "redondo" (4 divisiones) que cubra el máximo.
    $paso = $maximo / 4;
    $mag = pow(10, floor(log10($paso)));
    foreach ([1, 1.25, 1.5, 2, 2.5, 3, 4, 5, 6, 7.5, 10] as $c) {
        if ($c * $mag >= $paso) { $paso = $c * $mag; break; }
    }
    $tope = $paso * 4;
}
$marcas = [];
foreach ([1, 0.75, 0.5, 0.25, 0] as $f) {
    $v = $tope * $f;
    $marcas[] = $v >= 1000000 ? round($v / 1000000, 2) . 'M' : ($v >= 1000 ? round($v / 1000, 1) . 'k' : (string)round($v));
}

// Listas
$vencimientos = filas($conexion, "SELECT ct.numero_contrato, ct.fecha_fin, DATEDIFF(ct.fecha_fin, CURDATE()) dias,
        TRIM(CONCAT(COALESCE(p.nombres,''),' ',COALESCE(p.apellidos,''))) cliente, a.numero apto
    FROM contrato ct LEFT JOIN persona p ON p.id_personas = ct.persona_id
    JOIN apartamento a ON a.id_apartamentos = ct.apartamento_id
    WHERE ct.activo = 1 AND ct.tipo_operacion = 'Alquiler' AND ct.fecha_fin IS NOT NULL AND ct.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY ct.fecha_fin ASC LIMIT 5");
$pendientes = filas($conexion, "SELECT * FROM (
    SELECT 'Solicitud' tipo, CONCAT(p.nombres,' ',p.apellidos) cliente, CONCAT(s.tipo_operacion,' · Apto. ',a.numero) detalle, s.fecha_solicitud fecha
    FROM solicitud_propiedad s JOIN persona p ON p.id_personas = s.persona_id JOIN apartamento a ON a.id_apartamentos = s.apartamento_id
    WHERE s.activo = 1 AND s.estado IN ('Pendiente','En revision')
    UNION ALL
    SELECT 'Visita', CONCAT(p.nombres,' ',p.apellidos), CONCAT('Apto. ',a.numero,' · ',DATE_FORMAT(v.fecha_visita,'%d/%m/%Y %H:%i')), v.fecha_solicitud
    FROM visita_propiedad v JOIN persona p ON p.id_personas = v.persona_id JOIN apartamento a ON a.id_apartamentos = v.apartamento_id
    WHERE v.estado = 'Pendiente'
    UNION ALL
    SELECT 'Reparación', CONCAT(p.nombres,' ',p.apellidos), CONCAT('Apto. ',a.numero,' · ',LEFT(m.descripcion,40)), m.fecha_solicitud
    FROM solicitud_mantenimiento m JOIN persona p ON p.id_personas = m.persona_id JOIN apartamento a ON a.id_apartamentos = m.apartamento_id
    WHERE m.activo = 1 AND m.estado = 'Pendiente'
  ) t ORDER BY fecha DESC LIMIT 6");

// Notificaciones del usuario
$notifs = $idUsuario ? filas($conexion, "SELECT titulo, mensaje, fecha_envio, estado FROM notificacion WHERE usuario_id = ? AND activo = 1 ORDER BY fecha_envio DESC LIMIT 6", 'i', [$idUsuario]) : [];
$notifSinLeer = 0;
foreach ($notifs as $n) { if ($n['estado'] === 'Pendiente') $notifSinLeer++; }

// Búsqueda global
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) $q = mb_substr($q, 0, 80);
$res = ['clientes' => [], 'contratos' => [], 'facturas' => [], 'apartamentos' => []];
if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';
    $res['clientes'] = filas($conexion, "SELECT nombres, apellidos, cedula, correo FROM persona WHERE activo = 1 AND (CONCAT_WS(' ',nombres,apellidos) LIKE ? OR cedula LIKE ? OR correo LIKE ?) LIMIT 5", 'sss', [$like, $like, $like]);
    $res['contratos'] = filas($conexion, "SELECT ct.numero_contrato, ct.tipo_operacion, ct.fecha_fin, a.numero apto FROM contrato ct JOIN apartamento a ON a.id_apartamentos = ct.apartamento_id WHERE ct.numero_contrato LIKE ? LIMIT 5", 's', [$like]);
    $res['facturas'] = filas($conexion, "SELECT numero_documento, periodo, total, estado FROM factura WHERE numero_documento LIKE ? LIMIT 5", 's', [$like]);
    $res['apartamentos'] = filas($conexion, "SELECT a.numero, a.estado, e.nombre edificio FROM apartamento a JOIN edificio e ON e.id_edificios = a.edificio_id WHERE a.numero LIKE ? OR e.nombre LIKE ? LIMIT 5", 'ss', [$like, $like]);
}
$hayResultados = array_sum(array_map('count', $res)) > 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel principal - VIORA</title>
  <link rel="stylesheet" href="../estilos/styleP.css?v=9">
  <style>
    .pi-notif{position:relative}
    .pi-notif summary{list-style:none;cursor:pointer}
    .pi-notif summary::-webkit-details-marker{display:none}
    .pi-notif-lista{position:absolute;right:0;top:48px;width:320px;max-width:86vw;background:#fff;border:1px solid var(--borde);border-radius:14px;box-shadow:0 14px 40px rgba(15,56,43,.18);z-index:40;overflow:hidden}
    .pi-notif-lista h3{margin:0;padding:12px 16px;font-size:14px;border-bottom:1px solid var(--borde)}
    .pi-notif-item{padding:10px 16px;border-bottom:1px solid #f0f3f1;font-size:12px}
    .pi-notif-item strong{display:block;font-size:13px;margin-bottom:2px}
    .pi-notif-item.nueva{background:#f1faf5}
    .pi-notif-item small{display:block;color:var(--tenue)}
    .pi-vacio{padding:18px 16px;color:var(--tenue);font-size:13px}
    .pi-fila{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:24px}
    .pi-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:22px;margin-bottom:22px}
    .pi-lista{list-style:none;margin:0;padding:0}
    .pi-lista li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #eef2ef;font-size:13px}
    .pi-lista li:last-child{border-bottom:0}
    .pi-lista small{display:block;color:var(--tenue);font-size:11px;margin-top:2px}
    .pi-tag{display:inline-block;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
    .pi-tag.rojo{background:#fde8e8;color:#b02a2a}.pi-tag.naranja{background:#fff0dc;color:#b45309}.pi-tag.verde{background:#e2f6ec;color:#0b6b48}.pi-tag.azul{background:#e0ecff;color:#1d4ed8}
    .pi-enlace{display:inline-block;margin-top:10px;font-size:13px;font-weight:700;color:#0f382b;text-decoration:none}
    .pi-enlace:hover{text-decoration:underline}
    .pi-busqueda{margin-bottom:22px}
    .pi-busqueda h2{margin:0 0 10px;font-size:17px}
    .pi-res-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
    .pi-res-grid h3{margin:0 0 6px;font-size:12px;letter-spacing:.6px;text-transform:uppercase;color:var(--tenue)}
    a.tarjeta{text-decoration:none;color:inherit;display:block}
    .barra-columna .barra{min-height:2px}
    @media(max-width:1100px){.pi-fila{grid-template-columns:repeat(2,minmax(0,1fr))}.pi-grid{grid-template-columns:1fr}}
    @media(max-width:600px){.pi-fila{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="diseño">
    <?php include __DIR__ . '/../mantenimientos/menu_lateral.php'; ?>

    <main class="contenido">
      <header class="contenido__superior">
        <div class="panel-info">
          <h1>Panel general</h1>
          <span><?= esc($fecha) ?></span>
        </div>

        <form class="barra-busqueda" action="principal.php" method="get" role="search">
          <span aria-hidden="true">⌕</span>
          <input type="search" name="q" value="<?= esc($q) ?>" maxlength="80" placeholder="Buscar clientes, contratos, facturas, apartamentos..." aria-label="Buscar">
        </form>

        <div class="usuario-acciones">
          <details class="pi-notif">
            <summary class="notif-badge" aria-label="Notificaciones" title="Notificaciones">
              ♧<?php if ($notifSinLeer > 0): ?><span class="notif-punto"></span><?php endif; ?>
            </summary>
            <div class="pi-notif-lista">
              <h3>Notificaciones<?= $notifSinLeer ? ' (' . $notifSinLeer . ' nuevas)' : '' ?></h3>
              <?php if (!$notifs): ?>
                <div class="pi-vacio">No tienes notificaciones.</div>
              <?php else: foreach ($notifs as $n): ?>
                <div class="pi-notif-item <?= $n['estado'] === 'Pendiente' ? 'nueva' : '' ?>">
                  <strong><?= esc($n['titulo']) ?></strong>
                  <?= esc($n['mensaje']) ?>
                  <small><?= esc(date('d/m/Y H:i', strtotime($n['fecha_envio']))) ?></small>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </details>
          <div class="perfil-usuario">
            <div class="avatar"><?= esc($iniciales) ?></div>
            <div class="info-usuario-detalles">
              <span class="nombre"><?= esc($nombre) ?></span>
              <span class="rol"><?= esc($usuario) ?></span>
            </div>
            <a class="salir" href="logout.php" title="Cerrar sesión">⌄</a>
          </div>
        </div>
      </header>

      <?php if ($q !== ''): ?>
      <section class="panel-grafico pi-busqueda" aria-label="Resultados de búsqueda">
        <h2>Resultados para «<?= esc($q) ?>»</h2>
        <?php if (mb_strlen($q) < 2): ?>
          <p class="pi-vacio">Escribe al menos 2 caracteres.</p>
        <?php elseif (!$hayResultados): ?>
          <p class="pi-vacio">No se encontraron coincidencias.</p>
        <?php else: ?>
        <div class="pi-res-grid">
          <?php if ($res['clientes']): ?><div><h3>Clientes</h3><ul class="pi-lista">
            <?php foreach ($res['clientes'] as $r): ?><li><span><?= esc($r['nombres'] . ' ' . $r['apellidos']) ?><small><?= esc($r['cedula']) ?> · <?= esc($r['correo']) ?></small></span></li><?php endforeach; ?>
            </ul><a class="pi-enlace" href="../mantenimientos/clientes_consulta.php">Ver clientes →</a></div><?php endif; ?>
          <?php if ($res['contratos']): ?><div><h3>Contratos</h3><ul class="pi-lista">
            <?php foreach ($res['contratos'] as $r): ?><li><span><?= esc($r['numero_contrato']) ?><small><?= esc($r['tipo_operacion']) ?> · Apto. <?= esc($r['apto']) ?> · vence <?= fdate($r['fecha_fin']) ?></small></span></li><?php endforeach; ?>
            </ul><a class="pi-enlace" href="../mantenimientos/contratos.php">Ver contratos →</a></div><?php endif; ?>
          <?php if ($res['facturas']): ?><div><h3>Facturas</h3><ul class="pi-lista">
            <?php foreach ($res['facturas'] as $r): ?><li><span><?= esc($r['numero_documento']) ?><small><?= esc($r['periodo']) ?> · <?= pesos($r['total']) ?></small></span><span class="pi-tag azul"><?= esc($r['estado']) ?></span></li><?php endforeach; ?>
            </ul><a class="pi-enlace" href="../mantenimientos/consulta_facturas.php">Ver facturas →</a></div><?php endif; ?>
          <?php if ($res['apartamentos']): ?><div><h3>Apartamentos</h3><ul class="pi-lista">
            <?php foreach ($res['apartamentos'] as $r): ?><li><span>Apto. <?= esc($r['numero']) ?><small><?= esc($r['edificio']) ?></small></span><span class="pi-tag verde"><?= esc($r['estado']) ?></span></li><?php endforeach; ?>
            </ul><a class="pi-enlace" href="../mantenimientos/apartamento.php">Ver apartamentos →</a></div><?php endif; ?>
        </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="tarjetas" aria-label="Resumen de métricas">
        <a class="tarjeta" href="../mantenimientos/apartamento.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Apartamentos</span><span class="tarjeta__icono">▦</span></div>
          <div class="tarjeta__valor"><?= $totalApts ?></div>
          <div class="tarjeta__pie"><span class="indicador positivo"><?= $aptsLibres ?> disponibles</span><span>en catálogo</span></div>
        </a>
        <a class="tarjeta" href="../mantenimientos/consulta_cobros.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Cobrado este mes</span><span class="tarjeta__icono">↗</span></div>
          <div class="tarjeta__valor"><?= pesosCorto($cobradoMes) ?></div>
          <div class="tarjeta__pie">
            <?php if ($variacion === null): ?><span class="indicador neutro">Sin mes previo</span>
            <?php else: ?><span class="indicador <?= $variacion >= 0 ? 'positivo' : 'alerta' ?>"><?= ($variacion >= 0 ? '+' : '') . number_format($variacion, 1) ?>%</span><span>vs. mes anterior</span><?php endif; ?>
          </div>
        </a>
        <a class="tarjeta" href="../mantenimientos/consulta_facturas.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Facturas del mes</span><span class="tarjeta__icono">▤</span></div>
          <div class="tarjeta__valor"><?= $facturasMes ?></div>
          <div class="tarjeta__pie"><span class="indicador <?= $facturasPend ? 'alerta' : 'positivo' ?>"><?= $facturasPend ?> por cobrar</span><span><?= esc($periodo) ?></span></div>
        </a>
        <a class="tarjeta" href="../mantenimientos/reparaciones.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Reparaciones pendientes</span><span class="tarjeta__icono">◷</span></div>
          <div class="tarjeta__valor"><?= $repPend ?></div>
          <div class="tarjeta__pie"><span>Por revisar</span><span class="indicador <?= $repPend ? 'alerta' : 'positivo' ?>"><?= $repPend ? 'Pendiente' : 'Al día' ?></span></div>
        </a>
      </section>

      <section class="pi-fila" aria-label="Indicadores adicionales">
        <a class="tarjeta" href="../mantenimientos/cxc_clientes.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Cuentas por cobrar</span><span class="tarjeta__icono">$</span></div>
          <div class="tarjeta__valor"><?= pesosCorto($porCobrar) ?></div>
          <div class="tarjeta__pie"><span><?= $facturasConSaldo ?> facturas con saldo</span></div>
        </a>
        <a class="tarjeta" href="../mantenimientos/contratos.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Contratos activos</span><span class="tarjeta__icono">✎</span></div>
          <div class="tarjeta__valor"><?= $contratosActivos ?></div>
          <div class="tarjeta__pie"><span>Alquiler y venta</span></div>
        </a>
        <a class="tarjeta" href="../mantenimientos/vencimiento_contratos.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Vencen en 30 días</span><span class="tarjeta__icono">⏳</span></div>
          <div class="tarjeta__valor"><?= $porVencer ?></div>
          <div class="tarjeta__pie"><span class="indicador <?= $porVencer ? 'alerta' : 'positivo' ?>"><?= $porVencer ? 'Requieren atención' : 'Sin urgencias' ?></span></div>
        </a>
        <a class="tarjeta" href="../mantenimientos/solicitudes.php">
          <div class="tarjeta__fila"><span class="tarjeta__etiqueta">Solicitudes y visitas</span><span class="tarjeta__icono">☰</span></div>
          <div class="tarjeta__valor"><?= $solicPend + $visitasPend ?></div>
          <div class="tarjeta__pie"><span><?= $solicPend ?> solicitudes · <?= $visitasPend ?> visitas</span></div>
        </a>
      </section>

      <section class="pi-grid">
        <article class="panel-grafico">
          <div class="panel__encabezado">
            <div><h2>Cobros por mes</h2><p>Pagos de facturas recibidos de enero a <?= esc($meses[$mesActual - 1]) ?></p></div>
            <span class="etiqueta-periodo"><?= $anio ?></span>
          </div>
          <div class="grafico">
            <div class="grafico__marcas"><?php foreach ($marcas as $m): ?><span><?= esc($m) ?></span><?php endforeach; ?></div>
            <div class="grafico__zona">
              <div class="lineas-guia"><i></i><i></i><i></i><i></i><i></i></div>
              <div class="barras">
                <?php for ($m = 1; $m <= $mesActual; $m++):
                  $pct = $maximo > 0 ? round($cobrosMes[$m] / $tope * 100, 1) : 0; ?>
                  <div class="barra-columna">
                    <div class="barra" style="height: <?= $pct ?>%" title="<?= esc($mesesCorto[$m - 1] . ': ' . pesos($cobrosMes[$m])) ?>"></div>
                    <span><?= esc($mesesCorto[$m - 1]) ?></span>
                  </div>
                <?php endfor; ?>
              </div>
            </div>
          </div>
        </article>

        <article class="panel-grafico">
          <div class="panel__encabezado">
            <div><h2>Contratos por vencer</h2><p>Próximos 90 días y vencidos</p></div>
          </div>
          <?php if (!$vencimientos): ?>
            <div class="pi-vacio">No hay contratos próximos a vencer.</div>
          <?php else: ?>
          <ul class="pi-lista">
            <?php foreach ($vencimientos as $v):
              $d = (int)$v['dias'];
              [$cl, $tx] = $d < 0 ? ['rojo', 'Vencido hace ' . abs($d) . ' d'] : ($d === 0 ? ['naranja', 'Vence hoy'] : ($d <= 30 ? ['naranja', 'En ' . $d . ' días'] : ['verde', 'En ' . $d . ' días'])); ?>
              <li><span><?= esc($v['numero_contrato']) ?> · <?= esc($v['cliente'] ?: '—') ?><small>Apto. <?= esc($v['apto']) ?> · <?= fdate($v['fecha_fin']) ?></small></span><span class="pi-tag <?= $cl ?>"><?= esc($tx) ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <a class="pi-enlace" href="../mantenimientos/vencimiento_contratos.php">Gestionar vencimientos →</a>
        </article>
      </section>

      <section class="paneles">
        <article class="panel-grafico">
          <div class="panel__encabezado">
            <div><h2>Pendientes por atender</h2><p>Solicitudes, visitas y reparaciones recientes</p></div>
          </div>
          <?php if (!$pendientes): ?>
            <div class="pi-vacio">No hay trámites pendientes.</div>
          <?php else: ?>
          <ul class="pi-lista">
            <?php foreach ($pendientes as $p): ?>
              <li><span><?= esc($p['cliente']) ?><small><?= esc($p['detalle']) ?> · <?= esc(date('d/m/Y', strtotime($p['fecha']))) ?></small></span><span class="pi-tag azul"><?= esc($p['tipo']) ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </article>
      </section>
    </main>
  </div>
</body>
</html>
