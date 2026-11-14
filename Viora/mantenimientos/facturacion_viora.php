<?php
// Facturas del contrato y registro de pagos.
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['ecf_csrf'] ??= bin2hex(random_bytes(32));
require_once __DIR__ . '/../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

if (empty($_SESSION['logged_in'])) { header('Location: ../login/login.php'); exit; }

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pesos($n) { return 'RD$ ' . number_format((float)$n, 2); }

$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$usuario = (string)($_SESSION['usuario'] ?? '');
$menuActivo = 'facturacion';

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

    $contratoId = (int)($_GET['contrato'] ?? 0);
    $contrato = null;
    $facturas = [];

    if ($contratoId > 0) {
        $st = $conexion->prepare("SELECT ct.id_contratos, ct.numero_contrato, p.nombres, p.apellidos, a.numero, e.nombre AS edificio
                                  FROM contrato ct
                                  INNER JOIN persona p ON p.id_personas = ct.persona_id
                                  LEFT JOIN apartamento a ON a.id_apartamentos = ct.apartamento_id
                                  LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
                                  WHERE ct.id_contratos = ? LIMIT 1");
        $st->bind_param('i', $contratoId);
        $st->execute();
        $contrato = $st->get_result()->fetch_assoc();
        $st->close();

        if ($contrato) {
            $st = $conexion->prepare("SELECT f.id_facturas, f.numero_documento, f.periodo, f.fecha, f.total, f.monto_pagado, f.saldo_pendiente, f.estado,
                                             fe.estado AS ecf_estado, fe.encf, fe.ecf_api_id, fe.error_mensaje
                                      FROM factura f
                                      LEFT JOIN factura_ecf fe ON fe.factura_id = f.id_facturas
                                      WHERE f.contrato_id = ?
                                      ORDER BY f.periodo DESC, f.id_facturas DESC");
            $st->bind_param('i', $contratoId);
            $st->execute();
            $facturas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
    }
} catch (Throwable $ex) {
    error_log('VIORA facturacion_viora: ' . $ex->getMessage());
    http_response_code(500);
    exit('No se pudieron consultar las facturas.');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pagos de facturas | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=11">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#153b30;font-family:Arial,sans-serif}
.diseño{display:flex;min-height:100vh}.fv-contenido{flex:1;min-width:0;padding:30px}
.fv-encabezado{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:23px}
.fv-encabezado h1{font-size:26px;margin:0 0 8px;color:#0f382b}.fv-encabezado p{margin:0;color:#6d8076;font-size:14px}
.fv-panel{background:white;border:1px solid #dce8e1;border-radius:14px;padding:19px;margin-bottom:20px}
.fv-panel h2{font-size:18px;margin:0 0 12px}
.fv-tabla-contenedor{overflow-x:auto}.fv-tabla{width:100%;border-collapse:collapse;min-width:900px}
.fv-tabla th{text-align:left;padding:12px;background:#eef5f1;color:#365b4b;font-size:12px}
.fv-tabla td{padding:12px;border-bottom:1px solid #edf1ee;font-size:13px;vertical-align:middle}
.fv-estado{display:inline-block;padding:5px 10px;border-radius:99px;font-weight:700;font-size:12px;background:#edf0fa;color:#4b5686}
.fv-estado.pagada{background:#e5f4ec;color:#176546}.fv-estado.parcial{background:#fff4df;color:#8a5a00}.fv-estado.anulada{background:#ffe5e5;color:#bd2929}
.fv-estado.aceptado{background:#e5f4ec;color:#176546}.fv-estado.rechazado{background:#ffe5e5;color:#bd2929}
.fv-pago{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.fv-pago input,.fv-pago select{padding:8px;border:1px solid #dce8e1;border-radius:8px;font:inherit;font-size:13px}
.fv-pago input[name=monto]{width:120px}.fv-pago input[name=referencia]{width:130px}
.fv-boton{border:0;border-radius:9px;background:#0f382b;color:white;padding:9px 12px;cursor:pointer;font-weight:700;white-space:nowrap}
.fv-boton:hover:not(:disabled){background:#19543e}.fv-boton:disabled{background:#9db3a7;cursor:not-allowed}
.fv-enlace{color:#0f382b;font-weight:700;text-decoration:none;margin-right:14px}
.fv-mensaje{display:none;padding:12px 14px;border-radius:9px;margin-bottom:15px;font-size:14px}
.fv-mensaje.exito{display:block;background:#e5f4ec;color:#176546}.fv-mensaje.error{display:block;background:#ffe5e5;color:#bd2929}
.fv-vacio{text-align:center;padding:30px;color:#6b8074}
.fv-nota{font-size:12px;color:#75877d;margin-top:10px}
</style>
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/menu_lateral.php'; ?>
<main class="fv-contenido">
<header class="fv-encabezado">
    <div>
        <h1>Pagos de facturas</h1>
        <p><?php if ($contrato): ?>Cliente: <?= esc($contrato['nombres'].' '.$contrato['apellidos']) ?> · <?= esc($contrato['edificio'].' · Apto. '.$contrato['numero']) ?> · Contrato <?= esc($contrato['numero_contrato']) ?><?php else: ?>Seleccione un contrato desde Cuentas por cobrar.<?php endif; ?></p>
    </div>
    <div>
        <a class="fv-enlace" href="cxc_clientes.php">← Cuentas por cobrar</a>
        <a class="fv-enlace" href="facturacion.php">+ Generar factura</a>
    </div>
</header>

<div id="mensaje" class="fv-mensaje" role="status" aria-live="polite"></div>

<section class="fv-panel">
<h2>Facturas del contrato</h2>
<?php if (!$contrato): ?>
    <div class="fv-vacio">No se encontró el contrato indicado.</div>
<?php elseif (!$facturas): ?>
    <div class="fv-vacio">Este contrato no tiene facturas. Genere una desde Facturación.</div>
<?php else: ?>
<div class="fv-tabla-contenedor">
<table class="fv-tabla">
<thead><tr><th>Factura</th><th>Período</th><th>Total</th><th>Pagado</th><th>Saldo</th><th>Estado</th><th>e-CF</th><th>Registrar pago</th></tr></thead>
<tbody>
<?php foreach ($facturas as $f):
    $estado = (string)$f['estado'];
    $clase = strtolower(str_replace(' ', '', $estado));
    $pagable = in_array($estado, ['Pendiente', 'Parcial'], true) && (float)$f['saldo_pendiente'] > 0;
    $ecfEstado = $f['ecf_estado'] ?? null;
    $ecfAceptado = in_array($ecfEstado, ['Aceptado', 'Aceptado Condicional'], true);
    $ecfReenviable = !$ecfAceptado && empty($f['ecf_api_id']);
?>
<tr>
    <td><strong><?= esc($f['numero_documento']) ?></strong><small><?= esc($f['fecha']) ?></small></td>
    <td><?= esc($f['periodo']) ?></td>
    <td><?= pesos($f['total']) ?></td>
    <td><?= pesos($f['monto_pagado']) ?></td>
    <td><strong><?= pesos($f['saldo_pendiente']) ?></strong></td>
    <td><span class="fv-estado <?= esc($clase) ?>"><?= esc($estado) ?></span></td>
    <td>
    <?php if ($ecfEstado === null): ?>
        <span class="fv-estado">Sin enviar</span>
    <?php else: ?>
        <span class="fv-estado <?= $ecfAceptado ? 'aceptado' : 'rechazado' ?>"><?= esc($ecfEstado) ?></span>
        <?php if (!empty($f['encf'])): ?><small><?= esc($f['encf']) ?></small><?php endif; ?>
        <?php if (!empty($f['error_mensaje'])): ?><small><?= esc($f['error_mensaje']) ?></small><?php endif; ?>
    <?php endif; ?>
    <?php if ($ecfReenviable): ?>
        <button type="button" class="fv-boton fv-reenviar" data-factura="<?= (int)$f['id_facturas'] ?>">Enviar e-CF</button>
    <?php endif; ?>
    </td>
    <td>
    <?php if ($pagable): ?>
        <form class="fv-pago" data-factura="<?= (int)$f['id_facturas'] ?>">
            <input type="number" name="monto" step="0.01" min="0.01" max="<?= esc(number_format((float)$f['saldo_pendiente'], 2, '.', '')) ?>" value="<?= esc(number_format((float)$f['saldo_pendiente'], 2, '.', '')) ?>" required aria-label="Monto">
            <select name="metodo_pago" required aria-label="Método de pago">
                <option value="Efectivo">Efectivo</option>
                <option value="Transferencia">Transferencia</option>
                <option value="Tarjeta">Tarjeta</option>
                <option value="Cheque">Cheque</option>
            </select>
            <input type="text" name="referencia" maxlength="100" placeholder="Referencia" aria-label="Referencia">
            <button type="submit" class="fv-boton">Registrar pago</button>
        </form>
    <?php else: ?>
        <span>Sin saldo pendiente</span>
    <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="fv-nota">Cada pago se registra una sola vez. Al cubrir el saldo, la factura pasa a Pagada y deja de aceptar pagos.</p>
<?php endif; ?>
</section>
</main>
</div>
<script>
(() => {
  const mensaje = document.getElementById('mensaje');
  const mostrar = (texto, tipo) => {
    mensaje.textContent = texto;
    mensaje.className = 'fv-mensaje ' + tipo;
  };
  document.querySelectorAll('form.fv-pago').forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const boton = form.querySelector('button');
      if (boton.disabled) return;
      const datos = {
        factura_id: Number(form.dataset.factura),
        monto: form.monto.value,
        metodo_pago: form.metodo_pago.value,
        referencia: form.referencia.value.trim()
      };
      if (!confirm('¿Registrar pago de ' + datos.monto + ' en la factura?')) return;
      boton.disabled = true;
      try {
        const respuesta = await fetch('registrar_pago.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode($_SESSION['ecf_csrf']) ?> },
          body: JSON.stringify(datos)
        });
        const resultado = await respuesta.json();
        if (!resultado.success) throw new Error(resultado.message || 'No se pudo registrar el pago.');
        mostrar('Pago registrado. Estado: ' + resultado.estado + '.', 'exito');
        setTimeout(() => location.reload(), 800);
      } catch (error) {
        mostrar(error.message, 'error');
        boton.disabled = false;
      }
    });
  });
  document.querySelectorAll('button.fv-reenviar').forEach(boton => {
    boton.addEventListener('click', async () => {
      boton.disabled = true;
      try {
        const respuesta = await fetch('reenviar_ecf.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode($_SESSION['ecf_csrf']) ?> },
          body: JSON.stringify({ factura_id: Number(boton.dataset.factura) })
        });
        const resultado = await respuesta.json();
        if (!resultado.success) throw new Error(resultado.message || 'No se pudo enviar el e-CF.');
        mostrar('Estado fiscal: ' + resultado.ecf.estado + '.', 'exito');
        setTimeout(() => location.reload(), 800);
      } catch (error) {
        mostrar(error.message, 'error');
        boton.disabled = false;
      }
    });
  });
})();
</script>
</body>
</html>
