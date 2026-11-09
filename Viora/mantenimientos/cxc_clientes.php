<?php
// cxc/clientes.php - VIORA / Cuentas por cobrar
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../conf/conexion.php';
if (empty($_SESSION['logged_in'])) { header('Location: ../login/login.php'); exit; }
function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pesos($n) { return 'RD$ ' . number_format((float)$n, 2); }
$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$usuario = (string)($_SESSION['usuario'] ?? '');
if (!$idUsuario && $usuario === '') { http_response_code(403); exit('Sesión inválida.'); }
try {
    $sqlRol = 'SELECT r.nombre FROM usuario u INNER JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND ' . ($idUsuario ? 'u.id_usuarios=?' : 'u.usuario=?') . ' LIMIT 1';
    $st = $conexion->prepare($sqlRol);
    if ($idUsuario) $st->bind_param('i', $idUsuario); else $st->bind_param('s', $usuario);
    $st->execute();
    $filaRol = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$filaRol || !in_array(mb_strtolower(trim($filaRol['nombre'])), ['administrador','admin'], true)) {
        http_response_code(403); exit('Acceso exclusivo del administrador.');
    }
    $_SESSION['rol'] = 'Administrador';

    // Se usa la solicitud aprobada y su contrato ACTIVO correspondiente.
    // Se elige el contrato más reciente para evitar duplicar el mismo inmueble.
    $sql = "SELECT sp.id_solicitudes, sp.tipo_operacion,
                   p.id_personas, p.nombres, p.apellidos, p.telefono,
                   a.id_apartamentos, a.numero, e.nombre AS edificio, co.nombre AS condominio,
                   ct.id_contratos, ct.numero_contrato, ct.monto_alquiler, ct.monto_mantenimiento,
                   ct.fecha_inicio, ct.fecha_fin
            FROM solicitud_propiedad sp
            INNER JOIN persona p ON p.id_personas = sp.persona_id
            INNER JOIN apartamento a ON a.id_apartamentos = sp.apartamento_id
            INNER JOIN edificio e ON e.id_edificios = a.edificio_id
            INNER JOIN condominio co ON co.id_condominios = e.condominio_id
            INNER JOIN contrato ct ON ct.id_contratos = (
                SELECT MAX(c2.id_contratos)
                FROM contrato c2
                WHERE c2.solicitud_id = sp.id_solicitudes
                  AND c2.persona_id = sp.persona_id
                  AND c2.apartamento_id = sp.apartamento_id
                  AND c2.tipo_operacion = sp.tipo_operacion
                  AND c2.activo = 1 AND c2.estado = 'Activo'
            )
            WHERE sp.estado = 'Aprobada' AND sp.activo = 1 AND p.activo = 1
            ORDER BY p.nombres, p.apellidos, e.nombre, a.numero";
    $res = $conexion->query($sql);
    $filas = $res->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $ex) {
    error_log('VIORA CxC inquilinos: ' . $ex->getMessage());
    http_response_code(500);
    exit('No se pudo consultar Cuentas por cobrar. Revisa el registro de errores PHP y las relaciones de contrato.');
}
$totalRenta = 0; $totalMantenimiento = 0; $totalGeneral = 0;
foreach ($filas as &$f) {
    $f['renta'] = $f['tipo_operacion'] === 'Alquiler' ? (float)$f['monto_alquiler'] : 0.0;
    $f['mantenimiento'] = (float)$f['monto_mantenimiento'];
    $f['total'] = $f['renta'] + $f['mantenimiento'];
    $totalRenta += $f['renta']; $totalMantenimiento += $f['mantenimiento']; $totalGeneral += $f['total'];
}
unset($f);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cuentas por cobrar · Inquilinos | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=11">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#153b30;font-family:Arial,sans-serif}
.diseño{display:flex;min-height:100vh}.cxc-contenido{flex:1;min-width:0;padding:30px}
.cxc-encabezado{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:23px}
.cxc-encabezado h1{font-size:26px;margin:0 0 8px;color:#0f382b}.cxc-encabezado p{margin:0;color:#6d8076;font-size:14px}
.cxc-resumen{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:23px}
.cxc-indicador{background:white;border:1px solid #dce8e1;border-radius:14px;padding:20px}
.cxc-indicador strong{display:block;font-size:23px;margin-bottom:6px;color:#0f382b}.cxc-indicador span{font-size:13px;color:#667b70}
.cxc-panel{background:white;border:1px solid #dce8e1;border-radius:14px;padding:19px;box-shadow:0 5px 20px rgba(15,56,43,.03)}
.cxc-barra{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;margin-bottom:15px}
.cxc-barra h2{font-size:18px;margin:0}.cxc-buscar{width:min(360px,100%);padding:12px;border:1px solid #dce8e1;border-radius:9px;font:inherit}
.cxc-tabla-contenedor{overflow-x:auto}.cxc-tabla{width:100%;border-collapse:collapse;min-width:920px}
.cxc-tabla th{text-align:left;padding:14px 12px;background:#eef5f1;color:#365b4b;font-size:12px}
.cxc-tabla td{padding:16px 12px;border-bottom:1px solid #edf1ee;font-size:13px;vertical-align:middle}
.cxc-tabla td small{display:block;margin-top:5px;color:#71867a}.cxc-tabla strong{color:#0f382b}
.cxc-tipo{display:inline-block;padding:6px 10px;background:#e5f4ec;border-radius:99px;color:#176546;font-weight:700;font-size:12px}
.cxc-tipo.compra{background:#edf0fa;color:#4b5686}
.cxc-boton{border:0;border-radius:9px;background:#0f382b;color:white;padding:11px 13px;cursor:pointer;font-weight:700;white-space:nowrap}
.cxc-boton:hover{background:#19543e}.cxc-boton:focus-visible{outline:3px solid #6bb99b;outline-offset:2px}
.cxc-vacio{text-align:center;padding:35px;color:#6b8074}
.cxc-modal[hidden],tr[hidden]{display:none!important}.cxc-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:grid;place-items:center;padding:20px}
.cxc-dialogo{background:white;width:min(500px,100%);border-radius:17px;padding:25px;box-shadow:0 20px 70px #0003}
.cxc-dialogo h2{margin:0 0 12px}.cxc-dialogo p{line-height:1.5;color:#566e60}
.cxc-dialogo .cxc-detalle{display:flex;justify-content:space-between;gap:10px;margin:12px 0}
.cxc-dialogo .cxc-total{border-top:1px solid #dce8e1;padding-top:13px;font-weight:800}
.cxc-dialogo .cxc-acciones{display:flex;justify-content:flex-end;margin-top:22px}
.cxc-nota{font-size:12px;color:#75877d;margin-top:13px}
@media(max-width:1100px){.cxc-resumen{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.diseño{flex-direction:column}.cxc-contenido{padding:18px}.cxc-resumen{grid-template-columns:1fr 1fr}.cxc-indicador strong{font-size:18px}}

/* Modal de revisión de cobro */
.cxc-dialogo{position:relative;max-height:90vh;overflow-y:auto}
.cxc-cerrar-x{position:absolute;top:14px;right:14px;width:38px;height:38px;border:0;border-radius:9px;background:#edf3ef;color:#0f382b;font-size:27px;line-height:1;cursor:pointer}
.cxc-cerrar-x:hover{background:#ffe5e5;color:#bd2929}
.cxc-dialogo h2{padding-right:45px;color:#0f382b}
.cxc-dialogo .cxc-acciones{gap:10px;align-items:center;flex-wrap:wrap}
.cxc-acciones form{margin:0}
.cxc-cancelar{background:#e8f0eb;color:#0f382b}
.cxc-cancelar:hover{background:#d9e6dd}
.cxc-acciones .cxc-boton{min-height:42px}
.cxc-cerrar-x:focus-visible{outline:3px solid #6bb99b;outline-offset:2px}
@media(max-width:500px){.cxc-dialogo{padding:20px}.cxc-acciones form,.cxc-acciones .cxc-boton{width:100%}}
</style>
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/../mantenimientos/menu_lateral.php'; ?>
<main class="cxc-contenido">
<header class="cxc-encabezado"><div><h1>Cuentas por cobrar · Inquilinos</h1><p>Importes mensuales configurados en contratos activos.</p></div></header>
<section class="cxc-resumen" aria-label="Resumen">
<div class="cxc-indicador"><strong><?= count($filas) ?></strong><span>Propiedades con contrato activo</span></div>
<div class="cxc-indicador"><strong><?= pesos($totalRenta) ?></strong><span>Renta mensual total</span></div>
<div class="cxc-indicador"><strong><?= pesos($totalMantenimiento) ?></strong><span>Mantenimiento total</span></div>
<div class="cxc-indicador"><strong><?= pesos($totalGeneral) ?></strong><span>Total mensual configurado</span></div>
</section>
<section class="cxc-panel">
<div class="cxc-barra"><h2>Clientes y propiedades</h2><input class="cxc-buscar" type="search" id="buscarCobros" placeholder="Buscar cliente o apartamento..." aria-label="Buscar cliente o apartamento"></div>
<div class="cxc-tabla-contenedor"><table class="cxc-tabla"><thead><tr><th>Cliente</th><th>Propiedad</th><th>Operación</th><th>Renta</th><th>Mantenimiento</th><th>Total</th><th>Acción</th></tr></thead><tbody id="filasCobros">
<?php foreach($filas as $f): ?>
<tr data-busqueda="<?= esc(mb_strtolower($f['nombres'].' '.$f['apellidos'].' '.$f['edificio'].' '.$f['numero'].' '.$f['condominio'])) ?>">
<td><strong><?= esc($f['nombres'].' '.$f['apellidos']) ?></strong><small><?= esc($f['telefono']) ?></small></td>
<td><strong><?= esc($f['edificio'].' · Apto. '.$f['numero']) ?></strong><small><?= esc($f['condominio']) ?> · Contrato <?= esc($f['numero_contrato']) ?></small></td>
<td><span class="cxc-tipo <?= $f['tipo_operacion']==='Venta'?'compra':'' ?>"><?= esc($f['tipo_operacion']==='Venta'?'Compra':'Alquiler') ?></span></td>
<td><?= pesos($f['renta']) ?></td><td><?= pesos($f['mantenimiento']) ?></td><td><strong><?= pesos($f['total']) ?></strong></td>
<td><button type="button" class="cxc-boton" data-cliente="<?= esc($f['nombres'].' '.$f['apellidos']) ?>" data-propiedad="<?= esc($f['edificio'].' · Apto. '.$f['numero']) ?>" data-contrato="<?= (int)$f['id_contratos'] ?>" data-renta="<?= pesos($f['renta']) ?>" data-mantenimiento="<?= pesos($f['mantenimiento']) ?>" data-total="<?= pesos($f['total']) ?>">Procesar cobro</button></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (!$filas): ?><div class="cxc-vacio">No hay solicitudes aprobadas con contratos activos vinculados.</div><?php endif; ?>
<div id="sinResultados" class="cxc-vacio" hidden>No hay coincidencias con la búsqueda.</div>
<p class="cxc-nota">Estos valores son importes mensuales de referencia del contrato, no saldos vencidos. Para registrar pagos y calcular deudas reales se deben integrar vencimientos, comprobantes y pagos anteriores.</p>
</section>
</main></div>
<div id="modalCobro" class="cxc-modal" hidden>
  <section class="cxc-dialogo" role="dialog" aria-modal="true" aria-labelledby="tituloCobro" aria-describedby="notaCobro">
    <button type="button" class="cxc-cerrar-x" id="cerrarCobroX" aria-label="Cerrar sin procesar">&times;</button>
    <h2 id="tituloCobro">Revisar cobro</h2>
    <p id="detalleCliente"></p>
    <p id="detallePropiedad"></p>
    <p id="detalleContrato"></p>
    <div class="cxc-detalle"><span>Renta</span><strong id="detalleRenta"></strong></div>
    <div class="cxc-detalle"><span>Mantenimiento</span><strong id="detalleMantenimiento"></strong></div>
    <div class="cxc-detalle cxc-total"><span>Total mensual de referencia</span><strong id="detalleTotal"></strong></div>
    <p id="notaCobro" class="cxc-nota">Continuar abre las facturas de este contrato, donde puede registrar pagos.</p>
    <div class="cxc-acciones">
      <button type="button" class="cxc-boton cxc-cancelar" id="cerrarCobro">Cancelar</button>
      <form action="../mantenimientos/facturacion_viora.php" method="get" id="formFactura">
        <input type="hidden" name="contrato" id="contratoSeleccionado" value="">
        <button type="submit" class="cxc-boton">Procesar →</button>
      </form>
    </div>
  </section>
</div>
<script>
(() => {
  const modal = document.getElementById('modalCobro');
  const cerrar = document.getElementById('cerrarCobro');
  const cerrarX = document.getElementById('cerrarCobroX');
  const contrato = document.getElementById('contratoSeleccionado');
  const form = document.getElementById('formFactura');
  let botonAnterior = null;
  document.querySelectorAll('.cxc-boton[data-cliente]').forEach(boton => {
    boton.addEventListener('click', () => {
      botonAnterior = boton;
      const id = boton.dataset.contrato || '';
      if (!/^[1-9]\d*$/.test(id)) { alert('Contrato inválido.'); return; }
      const datos = {
        detalleCliente: 'Cliente: ' + (boton.dataset.cliente || ''),
        detallePropiedad: 'Propiedad: ' + (boton.dataset.propiedad || ''),
        detalleContrato: 'Contrato ID: ' + id,
        detalleRenta: boton.dataset.renta || 'RD$ 0.00',
        detalleMantenimiento: boton.dataset.mantenimiento || 'RD$ 0.00',
        detalleTotal: boton.dataset.total || 'RD$ 0.00'
      };
      Object.entries(datos).forEach(([campo, valor]) => {
        document.getElementById(campo).textContent = valor;
      });
      contrato.value = id;
      modal.hidden = false;
      cerrarX.focus();
    });
  });
  function cerrarModal() {
    modal.hidden = true;
    contrato.value = '';
    if (botonAnterior) botonAnterior.focus();
  }
  cerrar.addEventListener('click', cerrarModal);
  cerrarX.addEventListener('click', cerrarModal);
  modal.addEventListener('click', e => { if (e.target === modal) cerrarModal(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modal.hidden) cerrarModal();
  });
  form.addEventListener('submit', e => {
    if (!/^[1-9]\d*$/.test(contrato.value)) {
      e.preventDefault();
      alert('Selecciona un contrato válido.');
    }
  });
  const buscar = document.getElementById('buscarCobros');
  if (buscar) buscar.addEventListener('input', () => {
    const texto = buscar.value.trim().toLocaleLowerCase();
    const filas = document.querySelectorAll('#filasCobros tr');
    let visibles = 0;
    filas.forEach(fila => {
      const mostrar = (fila.dataset.busqueda || '').includes(texto);
      fila.hidden = !mostrar;
      if (mostrar) visibles++;
    });
    const sinResultados = document.getElementById('sinResultados');
    if (sinResultados) sinResultados.hidden = visibles > 0 || filas.length === 0;
  });
})();
</script>
</body>
</html>
