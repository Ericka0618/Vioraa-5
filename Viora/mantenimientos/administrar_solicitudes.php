<?php
session_start();
require_once '../conf/conexion.php';

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login/login.php');
    exit;
}

function e($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

$rol = mb_strtolower(trim((string)($_SESSION['rol'] ?? '')), 'UTF-8');
if ($rol !== 'cliente') {
    http_response_code(403);
    exit('Acceso exclusivo para clientes.');
}

$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$usuarioSesion = (string)($_SESSION['usuario'] ?? '');
if ($idUsuario <= 0 && $usuarioSesion === '') {
    http_response_code(403);
    exit('No se pudo identificar la sesión. Vuelve a iniciar sesión.');
}

try {
    if ($idUsuario > 0) {
        $stmt = $conexion->prepare('SELECT p.id_personas, p.nombres, p.apellidos FROM usuario u JOIN persona p ON p.id_personas = u.persona_id WHERE u.id_usuarios = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1');
        $stmt->bind_param('i', $idUsuario);
    } else {
        $stmt = $conexion->prepare('SELECT p.id_personas, p.nombres, p.apellidos FROM usuario u JOIN persona p ON p.id_personas = u.persona_id WHERE u.usuario = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1');
        $stmt->bind_param('s', $usuarioSesion);
    }
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    if (!$cliente) {
        http_response_code(403);
        exit('Tu usuario no está vinculado a un cliente activo.');
    }
    $personaId = (int)$cliente['id_personas'];

    // Los nombres de columnas coinciden con la estructura real de phpMyAdmin.
    $sql = "SELECT 'propiedad' AS categoria, sp.id_solicitudes AS id,
                   sp.tipo_operacion AS tipo, sp.estado,
                   sp.fecha_solicitud AS fecha, sp.observaciones,
                   a.numero, ed.nombre AS edificio, co.nombre AS condominio
            FROM solicitud_propiedad sp
            JOIN apartamento a ON a.id_apartamentos = sp.apartamento_id
            JOIN edificio ed ON ed.id_edificios = a.edificio_id
            JOIN condominio co ON co.id_condominios = ed.condominio_id
            WHERE sp.persona_id = ?
            UNION ALL
            SELECT 'visita' AS categoria, v.id_visita AS id,
                   'Visita' AS tipo, v.estado,
                   v.fecha_solicitud AS fecha, v.observaciones,
                   a.numero, ed.nombre AS edificio, co.nombre AS condominio
            FROM visita_propiedad v
            JOIN apartamento a ON a.id_apartamentos = v.apartamento_id
            JOIN edificio ed ON ed.id_edificios = a.edificio_id
            JOIN condominio co ON co.id_condominios = ed.condominio_id
            WHERE v.persona_id = ?
            ORDER BY fecha DESC, id DESC";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param('ii', $personaId, $personaId);
    $stmt->execute();
    $solicitudes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $ex) {
    error_log('VIORA administrar_solicitudes: ' . $ex->getMessage());
    http_response_code(500);
    exit('No se pudieron consultar las solicitudes. Revisa el registro de errores de PHP.');
}

$estados = ['Todas', 'Pendiente', 'En revision', 'Aprobada', 'Rechazada', 'Cancelada'];
$totales = array_fill_keys($estados, 0);
$totales['Todas'] = count($solicitudes);
foreach ($solicitudes as $s) {
    if (isset($totales[$s['estado']])) {
        $totales[$s['estado']]++;
    }
}
function etiquetaEstado($estado) {
    return $estado === 'En revision' ? 'En revisión' : $estado;
}
function claseEstado($estado) {
    return match ($estado) {
        'Pendiente' => 'pendiente',
        'En revision' => 'revision',
        'Aprobada' => 'aprobada',
        'Rechazada' => 'rechazada',
        'Cancelada' => 'cancelada',
        default => ''
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administrar solicitudes | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=7">
<style>
:root{--verde:#0f382b;--fondo:#f4f6f5;--borde:#e0e9e4}
*{box-sizing:border-box}
body{margin:0;background:var(--fondo);color:#203f33;font-family:Arial,sans-serif}
.solicitudes-contenido{padding:30px;min-width:0;flex:1}
.cabecera{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:22px}
.solicitudes-contenido h1{font-size:25px;margin:0 0 7px;color:var(--verde)}
.subtitulo{font-size:14px;color:#6d7e75;margin:0}
.enlace{display:inline-block;text-decoration:none;background:var(--verde);color:#fff;padding:11px 16px;border-radius:9px;font-size:13px;font-weight:700}
.resumen{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.resumen-item,.panel,.tarjeta{background:#fff;border:1px solid var(--borde);border-radius:13px;padding:17px}
.resumen-item strong{display:block;font-size:25px;color:var(--verde)}
.resumen-item span{color:#71837a;font-size:13px}
.panel{margin-bottom:16px}
.filtros{display:flex;gap:9px;flex-wrap:wrap}
.filtro{border:1px solid #d7e5dd;background:white;color:var(--verde);border-radius:8px;padding:9px 13px;cursor:pointer;font-weight:600}
.filtro.activo{background:var(--verde);color:white}
.lista{display:grid;grid-template-columns:repeat(auto-fit,minmax(285px,1fr));gap:15px}
.tarjeta h2{font-size:17px;color:var(--verde);margin:12px 0 7px}
.tarjeta p{font-size:13px;color:#65786d;line-height:1.55}
.fila{display:flex;justify-content:space-between;gap:10px;align-items:center}
.codigo{font-size:12px;color:#82918a}
.estado{font-size:12px;font-weight:700;border-radius:999px;padding:6px 10px;background:#edf0ef;color:#4f5d57}
.estado.pendiente{background:#fff2d8;color:#936000}
.estado.revision{background:#e8efff;color:#3157a2}
.estado.aprobada{background:#e2f6ea;color:#157747}
.estado.rechazada{background:#ffebeb;color:#a32929}
.estado.cancelada{background:#ededed;color:#666}
.detalle{margin:14px 0;padding:12px;border-radius:9px;background:#f5f8f6;font-size:13px;line-height:1.6}
.accion{display:inline-block;background:var(--verde);color:white;border-radius:8px;padding:10px 14px;text-decoration:none;font-size:13px;font-weight:700}
.vacio{padding:35px;text-align:center;background:#fff;border-radius:13px;color:#6a7d72}
.tarjeta[hidden],.vacio[hidden]{display:none!important}
@media(max-width:700px){.solicitudes-contenido{padding:18px}.lista{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="diseño">
<?php $menuActivo='administrar_solicitudes'; include 'menu_lateral.php'; ?>
<main class="solicitudes-contenido">
  <header class="cabecera">
    <div><h1>Administrar solicitudes</h1><p class="subtitulo">Hola, <?= e($cliente['nombres']) ?>. Consulta aquí el estado de tus solicitudes.</p></div>
    <a class="enlace" href="catalogo.php">Ver propiedades disponibles</a>
  </header>
  <section class="resumen" aria-label="Resumen de solicitudes">
    <?php foreach (['Todas','Pendiente','En revision','Aprobada','Rechazada'] as $estado): ?>
      <div class="resumen-item"><strong><?= (int)$totales[$estado] ?></strong><span><?= e($estado === 'Todas' ? 'Total de solicitudes' : etiquetaEstado($estado)) ?></span></div>
    <?php endforeach; ?>
  </section>
  <section class="panel" aria-label="Filtros">
    <div class="filtros">
      <?php foreach ($estados as $estado): ?>
      <button type="button" class="filtro <?= $estado === 'Todas' ? 'activo' : '' ?>" data-estado="<?= e($estado) ?>" aria-pressed="<?= $estado === 'Todas' ? 'true' : 'false' ?>"><?= e(etiquetaEstado($estado)) ?></button>
      <?php endforeach; ?>
    </div>
  </section>
  <?php if (!$solicitudes): ?>
    <div class="vacio">Todavía no has enviado solicitudes. Visita el catálogo para encontrar una propiedad.</div>
  <?php else: ?>
  <section class="lista" id="listaSolicitudes" aria-label="Mis solicitudes">
    <?php foreach ($solicitudes as $s): ?>
    <?php $estado = (string)$s['estado']; $aprobada = $estado === 'Aprobada' && $s['categoria'] === 'propiedad'; ?>
    <article class="tarjeta" data-estado="<?= e($estado) ?>">
      <div class="fila"><span class="codigo"><?= $s['categoria'] === 'visita' ? 'VIS' : 'SOL' ?>-<?= e(str_pad((string)$s['id'], 4, '0', STR_PAD_LEFT)) ?></span><span class="estado <?= e(claseEstado($estado)) ?>"><?= e(etiquetaEstado($estado)) ?></span></div>
      <h2><?= e($s['edificio']) ?> · Apartamento <?= e($s['numero']) ?></h2>
      <p><?= e($s['condominio']) ?></p>
      <div class="detalle"><strong>Solicitud:</strong> <?= e($s['tipo'] === 'Venta' ? 'Compra' : $s['tipo']) ?><br><strong>Fecha:</strong> <?= e(date('d/m/Y', strtotime($s['fecha']))) ?><br><strong>Estado:</strong> <?= e(etiquetaEstado($estado)) ?></div>
      <?php if ($estado === 'Pendiente'): ?><p>Tu solicitud está pendiente de revisión por el administrador.</p>
      <?php elseif ($estado === 'En revision'): ?><p>El administrador está revisando tu solicitud.</p>
      <?php elseif ($estado === 'Rechazada'): ?><p>Tu solicitud no fue aprobada.</p>
      <?php elseif ($estado === 'Cancelada'): ?><p>Esta solicitud fue cancelada.</p>
      <?php elseif ($estado === 'Aprobada'): ?><p><?= $s['categoria'] === 'visita' ? 'Tu visita fue aprobada.' : 'Tu solicitud de propiedad fue aprobada.' ?></p><?php endif; ?>
      <?php if ($aprobada): ?><a class="accion" href="administrar_propiedad.php">Administrar mi propiedad →</a><?php endif; ?>
    </article>
    <?php endforeach; ?>
  </section>
  <div class="vacio" id="sinCoincidencias" hidden>No tienes solicitudes con este estado.</div>
  <?php endif; ?>
</main>
</div>
<script>
const botones = document.querySelectorAll('.filtro');
const tarjetas = document.querySelectorAll('.tarjeta[data-estado]');
const vacio = document.getElementById('sinCoincidencias');
botones.forEach(b => b.addEventListener('click', () => {
  botones.forEach(x => { const activo = x === b; x.classList.toggle('activo', activo); x.setAttribute('aria-pressed', String(activo)); });
  let visibles = 0;
  tarjetas.forEach(t => { const mostrar = b.dataset.estado === 'Todas' || t.dataset.estado === b.dataset.estado; t.hidden = !mostrar; if (mostrar) visibles++; });
  if (vacio) vacio.hidden = visibles > 0;
}));
</script>
</body>
</html>
