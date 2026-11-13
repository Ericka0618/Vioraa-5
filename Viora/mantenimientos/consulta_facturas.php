<?php
// Consulta de facturas guardadas: listado con filtros y detalle por factura.
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['ecf_csrf'] ??= bin2hex(random_bytes(32));
require_once __DIR__ . '/../conf/conexion.php';

if (empty($_SESSION['logged_in'])) { header('Location: ../login/login.php'); exit; }

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pesos($n) { return 'RD$ ' . number_format((float)$n, 2); }
function icono_ojo(string $url, string $titulo): string
{
    return '<a class="cf-icono" href="' . esc($url) . '" target="_blank" rel="noopener" title="' . esc($titulo) . '" aria-label="' . esc($titulo) . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg></a>';
}

$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$usuario = (string)($_SESSION['usuario'] ?? '');
$menuActivo = 'facturacion';

$q = trim((string)($_GET['q'] ?? ''));
$periodo = trim((string)($_GET['periodo'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));
$idFactura = (int)($_GET['id'] ?? 0);

if (!in_array($estado, ['Pendiente', 'Parcial', 'Pagada', 'Anulada'], true)) $estado = '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) $periodo = '';

$joins = "FROM factura f
          LEFT JOIN persona p ON p.id_personas = f.persona_id
          LEFT JOIN contrato ct ON ct.id_contratos = f.contrato_id
          LEFT JOIN apartamento a ON a.id_apartamentos = ct.apartamento_id
          LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
          LEFT JOIN factura_ecf fe ON fe.factura_id = f.id_facturas";

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

    $detalle = null;
    $conceptos = [];
    $pagos = [];
    $notas = [];
    $facturas = [];

    if ($idFactura > 0) {
        $st = $conexion->prepare("SELECT f.*, p.nombres, p.apellidos, p.cedula, p.telefono, p.correo,
                                         ct.id_contratos, ct.numero_contrato, a.numero AS apto, e.nombre AS edificio,
                                         fe.encf, fe.estado AS ecf_estado, fe.codigo_seguridad, fe.dgii_url,
                                         fe.error_mensaje, fe.fecha_envio, fe.ecf_api_id, fe.tipo_ecf
                                  $joins
                                  WHERE f.id_facturas = ? LIMIT 1");
        $st->bind_param('i', $idFactura);
        $st->execute();
        $detalle = $st->get_result()->fetch_assoc();
        $st->close();

        if ($detalle) {
            $st = $conexion->prepare("SELECT concepto, descripcion, periodo, monto FROM factura_detalle
                                      WHERE factura_id = ? AND activo = 1 ORDER BY id_detalles");
            $st->bind_param('i', $idFactura);
            $st->execute();
            $conceptos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $st = $conexion->prepare("SELECT fecha_pago, monto, metodo_pago, referencia, observaciones
                                      FROM factura_pago WHERE factura_id = ? ORDER BY fecha_pago, id_pagos");
            $st->bind_param('i', $idFactura);
            $st->execute();
            $pagos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $st = $conexion->prepare("SELECT id_factura_ecf_nota, codigo_modificacion, encf_modificado, encf, estado,
                                             monto_centavos, error_mensaje, fecha_registro
                                      FROM factura_ecf_nota WHERE factura_id = ? ORDER BY id_factura_ecf_nota");
            $st->bind_param('i', $idFactura);
            $st->execute();
            $notas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
    } else {
        $where = [];
        $params = [];
        $types = '';

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(CONCAT_WS(' ', p.nombres, p.apellidos) LIKE ? OR p.cedula LIKE ? OR f.numero_documento LIKE ?)";
            array_push($params, $like, $like, $like);
            $types .= 'sss';
        }
        if ($periodo !== '') {
            $where[] = 'f.periodo = ?';
            $params[] = $periodo;
            $types .= 's';
        }
        if ($estado !== '') {
            $where[] = 'f.estado = ?';
            $params[] = $estado;
            $types .= 's';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT f.id_facturas, f.numero_documento, f.periodo, f.fecha, f.total, f.monto_pagado,
                       f.saldo_pendiente, f.estado, p.nombres, p.apellidos, p.cedula, a.numero AS apto,
                       e.nombre AS edificio, fe.estado AS ecf_estado, fe.encf
                $joins $whereSql
                ORDER BY f.fecha_registro DESC, f.id_facturas DESC LIMIT 300";

        $st = $conexion->prepare($sql);
        if ($params) $st->bind_param($types, ...$params);
        $st->execute();
        $facturas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }
} catch (Throwable $ex) {
    error_log('VIORA consulta_facturas: ' . $ex->getMessage());
    http_response_code(500);
    exit('No se pudieron consultar las facturas.');
}

$totalFiltrado = 0; $pagadoFiltrado = 0; $saldoFiltrado = 0;
foreach ($facturas as $f) {
    if ($f['estado'] === 'Anulada') continue;
    $totalFiltrado += (float)$f['total'];
    $pagadoFiltrado += (float)$f['monto_pagado'];
    $saldoFiltrado += (float)$f['saldo_pendiente'];
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $detalle ? 'Factura ' . esc($detalle['numero_documento']) : 'Consulta de facturas' ?> | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=11">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#153b30;font-family:Arial,sans-serif}
.diseño{display:flex;min-height:100vh}.cf-contenido{flex:1;min-width:0;padding:30px}
.cf-encabezado{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:23px}
.cf-encabezado h1{font-size:26px;margin:0 0 8px;color:#0f382b}.cf-encabezado p{margin:0;color:#6d8076;font-size:14px}
.cf-panel{background:white;border:1px solid #dce8e1;border-radius:14px;padding:19px;margin-bottom:20px}
.cf-panel h2{font-size:18px;margin:0 0 12px}
.cf-filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.cf-filtros label{display:flex;flex-direction:column;font-size:12px;color:#365b4b;gap:4px}
.cf-filtros input,.cf-filtros select{padding:9px;border:1px solid #dce8e1;border-radius:8px;font:inherit;font-size:13px;min-width:160px}
.cf-resumen{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.cf-tarjeta{flex:1;min-width:180px;background:white;border:1px solid #dce8e1;border-radius:12px;padding:14px}
.cf-tarjeta span{display:block;font-size:12px;color:#6d8076}.cf-tarjeta strong{font-size:18px;color:#0f382b}
.cf-tabla-contenedor{overflow-x:auto}.cf-tabla{width:100%;border-collapse:collapse;min-width:900px}
.cf-tabla th{text-align:left;padding:12px;background:#eef5f1;color:#365b4b;font-size:12px}
.cf-tabla td{padding:12px;border-bottom:1px solid #edf1ee;font-size:13px;vertical-align:middle}
.cf-tabla td.num,.cf-tabla th.num{text-align:right}
.cf-estado{display:inline-block;padding:5px 10px;border-radius:99px;font-weight:700;font-size:12px;background:#edf0fa;color:#4b5686}
.cf-estado.pagada,.cf-estado.aceptado{background:#e5f4ec;color:#176546}
.cf-estado.parcial{background:#fff4df;color:#8a5a00}
.cf-estado.anulada,.cf-estado.rechazado{background:#ffe5e5;color:#bd2929}
.cf-enlace{color:#0f382b;font-weight:700;text-decoration:none;margin-right:14px}
.cf-icono{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid #dce8e1;border-radius:8px;background:white;color:#0f382b;vertical-align:middle;text-decoration:none}
.cf-icono:hover{background:#eef5f1}.cf-icono svg{width:16px;height:16px}
.cf-boton{display:inline-block;border:0;border-radius:9px;background:#0f382b;color:white;padding:9px 12px;font-weight:700;text-decoration:none;cursor:pointer;font-size:13px}
.cf-boton:hover{background:#19543e}
.cf-datos{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 24px;font-size:14px}
.cf-datos div span{display:block;font-size:12px;color:#6d8076}
.cf-totales{margin-left:auto;width:320px;font-size:14px}.cf-totales div{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #edf1ee}
.cf-totales .final{font-weight:700;font-size:16px;color:#0f382b}
.cf-vacio{text-align:center;padding:30px;color:#6b8074}
.cf-error{color:#bd2929;font-size:13px;margin-top:8px}
.cf-form-nota{display:flex;flex-direction:column;gap:12px;max-width:760px}
.cf-form-nota label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#365b4b}
.cf-form-nota select,.cf-fila-linea input{padding:9px;border:1px solid #dce8e1;border-radius:8px;font:inherit;font-size:13px}
.cf-fila-linea{display:flex;gap:8px;align-items:center;margin-bottom:8px}
.cf-fila-linea .nota-desc{flex:1}
.cf-subtitulo{font-size:15px;margin:18px 0 8px;color:#0f382b}
</style>
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/menu_lateral.php'; ?>
<main class="cf-contenido">

<?php if ($idFactura > 0): ?>

    <header class="cf-encabezado">
        <div>
            <h1>Factura <?= esc($detalle['numero_documento'] ?? '') ?></h1>
            <p>Período <?= esc($detalle['periodo'] ?? '') ?> · Emitida el <?= esc($detalle['fecha'] ?? '') ?></p>
        </div>
        <div>
            <a class="cf-enlace" href="consulta_facturas.php">← Volver al listado</a>
            <?php if ($detalle && $detalle['id_contratos'] && $detalle['estado'] !== 'Anulada'): ?>
                <a class="cf-boton" href="facturacion_viora.php?contrato=<?= (int)$detalle['id_contratos'] ?>">Registrar pago</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$detalle): ?>
        <section class="cf-panel"><div class="cf-vacio">No se encontró la factura indicada.</div></section>
    <?php else: ?>

    <section class="cf-panel">
        <h2>Datos generales</h2>
        <div class="cf-datos">
            <div><span>Cliente</span><?= esc(trim(($detalle['nombres'] ?? '') . ' ' . ($detalle['apellidos'] ?? ''))) ?: '—' ?></div>
            <div><span>Cédula</span><?= esc($detalle['cedula'] ?? '') ?: '—' ?></div>
            <div><span>Teléfono</span><?= esc($detalle['telefono'] ?? '') ?: '—' ?></div>
            <div><span>Correo</span><?= esc($detalle['correo'] ?? '') ?: '—' ?></div>
            <div><span>Propiedad</span><?= esc(($detalle['edificio'] ?? '') . ($detalle['apto'] ? ' · Apto. ' . $detalle['apto'] : '')) ?: '—' ?></div>
            <div><span>Contrato</span><?= esc($detalle['numero_contrato'] ?? '') ?: '—' ?></div>
            <div><span>Vencimiento</span><?= esc($detalle['fecha_vencimiento'] ?? '') ?: '—' ?></div>
            <div><span>RNC comprador</span><?= esc($detalle['rnc_comprador'] ?? '') ?: '—' ?></div>
            <div><span>Razón social comprador</span><?= esc($detalle['razon_social_comprador'] ?? '') ?: '—' ?></div>
            <div><span>Estado</span><span class="cf-estado <?= esc(strtolower(str_replace(' ', '', $detalle['estado']))) ?>"><?= esc($detalle['estado']) ?></span></div>
        </div>
    </section>

    <section class="cf-panel">
        <h2>Conceptos</h2>
        <div class="cf-tabla-contenedor">
        <table class="cf-tabla">
            <thead><tr><th>Concepto</th><th>Descripción</th><th>Período</th><th class="num">Monto</th></tr></thead>
            <tbody>
            <?php if (!$conceptos): ?>
                <tr><td colspan="4" class="cf-vacio">Sin conceptos registrados.</td></tr>
            <?php endif; ?>
            <?php foreach ($conceptos as $c): ?>
                <tr>
                    <td><?= esc($c['concepto']) ?></td>
                    <td><?= esc($c['descripcion']) ?></td>
                    <td><?= esc($c['periodo']) ?></td>
                    <td class="num"><?= pesos($c['monto']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php $itbis = (float)$detalle['total'] - (float)$detalle['subtotal']; ?>
        <div class="cf-totales">
            <div><span>Subtotal</span><span><?= pesos($detalle['subtotal']) ?></span></div>
            <div><span>ITBIS 18%</span><span><?= pesos($itbis) ?></span></div>
            <div class="final"><span>Total</span><span><?= pesos($detalle['total']) ?></span></div>
            <div><span>Pagado</span><span><?= pesos($detalle['monto_pagado']) ?></span></div>
            <div class="final"><span>Saldo pendiente</span><span><?= pesos($detalle['saldo_pendiente']) ?></span></div>
        </div>
    </section>

    <section class="cf-panel">
        <h2>Pagos registrados</h2>
        <div class="cf-tabla-contenedor">
        <table class="cf-tabla">
            <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th>Observaciones</th><th class="num">Monto</th></tr></thead>
            <tbody>
            <?php if (!$pagos): ?>
                <tr><td colspan="5" class="cf-vacio">Esta factura no tiene pagos registrados.</td></tr>
            <?php endif; ?>
            <?php foreach ($pagos as $pg): ?>
                <tr>
                    <td><?= esc($pg['fecha_pago']) ?></td>
                    <td><?= esc($pg['metodo_pago']) ?></td>
                    <td><?= esc($pg['referencia']) ?></td>
                    <td><?= esc($pg['observaciones']) ?></td>
                    <td class="num"><?= pesos($pg['monto']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="cf-panel">
        <h2>Comprobante fiscal electrónico (e-CF)</h2>
        <?php if (empty($detalle['encf']) && empty($detalle['ecf_estado'])): ?>
            <div class="cf-vacio">Esta factura no tiene registro de e-CF.</div>
        <?php else: ?>
        <div class="cf-datos">
            <div><span>Tipo</span><?= esc($detalle['tipo_ecf'] ?? '') ?: '—' ?></div>
            <div><span>eNCF</span><?= esc($detalle['encf']) ?: '—' ?></div>
            <div><span>Estado</span><span class="cf-estado <?= esc(strtolower(str_replace(' ', '', (string)$detalle['ecf_estado']))) ?>"><?= esc($detalle['ecf_estado']) ?: '—' ?></span></div>
            <div><span>Código de seguridad</span><?= esc($detalle['codigo_seguridad']) ?: '—' ?></div>
            <div><span>Fecha de envío</span><?= esc($detalle['fecha_envio']) ?: '—' ?></div>
        </div>
        <?php if (!empty($detalle['error_mensaje'])): ?>
            <div class="cf-error">Error: <?= esc($detalle['error_mensaje']) ?></div>
        <?php endif; ?>
        <?php if (!empty($detalle['dgii_url'])): ?>
            <p><a class="cf-enlace" href="<?= esc($detalle['dgii_url']) ?>" target="_blank" rel="noopener">Ver en DGII</a></p>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="cf-panel">
        <h2>Notas de crédito e-CF (E34)</h2>
        <?php if (!$notas): ?>
            <div class="cf-vacio">Esta factura no tiene notas de crédito.</div>
        <?php else: ?>
        <div class="cf-tabla-contenedor">
        <table class="cf-tabla">
            <thead><tr><th>Tipo</th><th>eNCF modificado</th><th>eNCF nota</th><th>Estado</th><th class="num">Monto</th><th>Fecha</th><th>Error</th><th>PDF</th></tr></thead>
            <tbody>
            <?php foreach ($notas as $n): ?>
                <tr>
                    <td><?= esc(((int)$n['codigo_modificacion'] === 1 ? 'Anulación total' : 'Corrección de montos')) ?></td>
                    <td><?= esc($n['encf_modificado']) ?></td>
                    <td><?= esc($n['encf']) ?: '—' ?></td>
                    <td><span class="cf-estado <?= esc(strtolower(str_replace(' ', '', $n['estado']))) ?>"><?= esc($n['estado']) ?></span></td>
                    <td class="num"><?= pesos($n['monto_centavos'] / 100) ?></td>
                    <td><?= esc($n['fecha_registro']) ?></td>
                    <td><?= esc($n['error_mensaje']) ?: '—' ?></td>
                    <td><?= $n['encf'] ? icono_ojo('factura_pdf.php?nota=' . (int)$n['id_factura_ecf_nota'], 'Ver PDF de la nota de crédito') : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if (in_array($detalle['ecf_estado'] ?? '', ['Aceptado', 'Aceptado Condicional'], true)): ?>
        <h3 class="cf-subtitulo">Emitir nota de crédito</h3>
        <form id="form-nota-credito" class="cf-form-nota">
            <label>Tipo de nota
                <select id="nota-codigo">
                    <option value="1">1 · Anulación total (copia las líneas de la factura)</option>
                    <option value="3">3 · Corrección de montos (líneas manuales)</option>
                </select>
            </label>
            <div id="nota-lineas" hidden>
                <div id="nota-filas"></div>
                <button type="button" class="cf-boton" id="nota-agregar">+ Agregar línea</button>
            </div>
            <div>
                <button type="submit" class="cf-boton" id="nota-enviar">Emitir nota de crédito</button>
            </div>
            <div id="nota-mensaje" class="cf-error" hidden></div>
        </form>
        <script>
        (function () {
            const form = document.getElementById('form-nota-credito');
            const codigo = document.getElementById('nota-codigo');
            const bloque = document.getElementById('nota-lineas');
            const filas = document.getElementById('nota-filas');
            const agregar = document.getElementById('nota-agregar');
            const enviar = document.getElementById('nota-enviar');
            const mensaje = document.getElementById('nota-mensaje');
            const operacionFiscal = <?= json_encode(bin2hex(random_bytes(32))) ?>;
            const facturaId = <?= (int)$detalle['id_facturas'] ?>;

            function mostrarError(texto) {
                mensaje.textContent = texto;
                mensaje.hidden = false;
            }

            function nuevaFila() {
                const fila = document.createElement('div');
                fila.className = 'cf-fila-linea';
                fila.innerHTML =
                    '<input type="text" class="nota-desc" maxlength="150" placeholder="Descripción" required>' +
                    '<input type="number" class="nota-monto" min="0.01" step="0.01" placeholder="Monto sin ITBIS" required>' +
                    '<button type="button" class="cf-enlace nota-quitar">Quitar</button>';
                fila.querySelector('.nota-quitar').addEventListener('click', () => fila.remove());
                filas.appendChild(fila);
            }

            function actualizarTipo() {
                const manual = codigo.value === '3';
                bloque.hidden = !manual;
                if (manual && filas.children.length === 0) nuevaFila();
            }

            codigo.addEventListener('change', actualizarTipo);
            agregar.addEventListener('click', nuevaFila);

            form.addEventListener('submit', async (evento) => {
                evento.preventDefault();
                mensaje.hidden = true;

                // Se abre antes de confirmar para que el navegador no bloquee la ventana del PDF.
                const ventanaPdf = window.open('about:blank', '_blank');
                const cancelarVentana = () => { if (ventanaPdf) ventanaPdf.close(); };

                const manual = codigo.value === '3';
                const lineas = manual
                    ? Array.from(filas.querySelectorAll('.cf-fila-linea')).map((fila) => ({
                        descripcion: fila.querySelector('.nota-desc').value.trim(),
                        monto: fila.querySelector('.nota-monto').value
                    }))
                    : [];

                if (manual && lineas.length === 0) {
                    cancelarVentana();
                    mostrarError('Agregue al menos una línea.');
                    return;
                }

                const etiqueta = manual ? 'corrección de montos' : 'anulación total';
                if (!confirm('¿Emitir nota de crédito E34 (' + etiqueta + ')? Esto envía el documento a DGII.')) {
                    cancelarVentana();
                    return;
                }

                enviar.disabled = true;

                try {
                    const respuesta = await fetch('emitir_nota_credito.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode($_SESSION['ecf_csrf']) ?> },
                        body: JSON.stringify({
                            factura_id: facturaId,
                            operacion: operacionFiscal,
                            codigo_modificacion: Number(codigo.value),
                            lineas: lineas
                        })
                    });
                    const datos = await respuesta.json();
                    if (!datos.success) {
                        throw new Error(datos.message || 'No se pudo emitir la nota de crédito');
                    }
                    if (!datos.nota.id) { cancelarVentana(); mostrarError(datos.nota.error || 'Emisi?n fiscal deshabilitada.'); return; }
                    if (ventanaPdf) {
                        ventanaPdf.location.href = 'factura_pdf.php?nota=' + encodeURIComponent(datos.nota.id);
                    }
                    location.reload();
                } catch (error) {
                    cancelarVentana();
                    mostrarError(error.message);
                    enviar.disabled = false;
                }
            });
        })();
        </script>
        <?php else: ?>
        <p class="cf-vacio">La nota de crédito se puede emitir cuando el e-CF original esté aceptado por DGII.</p>
        <?php endif; ?>
    </section>

    <?php endif; ?>

<?php else: ?>

    <header class="cf-encabezado">
        <div>
            <h1>Consulta de facturas</h1>
            <p>Facturas guardadas por cliente. Se muestran hasta 300 resultados.</p>
        </div>
        <div>
            <a class="cf-enlace" href="facturacion.php">+ Generar factura</a>
        </div>
    </header>

    <section class="cf-panel">
        <h2>Filtros</h2>
        <form method="get" action="consulta_facturas.php" class="cf-filtros">
            <label>Cliente, cédula o factura
                <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Ej. Juan, 001-..., FAC-...">
            </label>
            <label>Período
                <input type="month" name="periodo" value="<?= esc($periodo) ?>">
            </label>
            <label>Estado
                <select name="estado">
                    <option value="">Todos</option>
                    <?php foreach (['Pendiente', 'Parcial', 'Pagada', 'Anulada'] as $op): ?>
                        <option value="<?= $op ?>" <?= $estado === $op ? 'selected' : '' ?>><?= $op ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="cf-boton">Buscar</button>
            <a class="cf-enlace" href="consulta_facturas.php">Limpiar</a>
        </form>
    </section>

    <div class="cf-resumen">
        <div class="cf-tarjeta"><span>Facturas</span><strong><?= count($facturas) ?></strong></div>
        <div class="cf-tarjeta"><span>Total facturado (sin anuladas)</span><strong><?= pesos($totalFiltrado) ?></strong></div>
        <div class="cf-tarjeta"><span>Pagado</span><strong><?= pesos($pagadoFiltrado) ?></strong></div>
        <div class="cf-tarjeta"><span>Saldo pendiente</span><strong><?= pesos($saldoFiltrado) ?></strong></div>
    </div>

    <section class="cf-panel">
        <div class="cf-tabla-contenedor">
        <table class="cf-tabla">
            <thead>
                <tr>
                    <th>Factura</th><th>Cliente</th><th>Propiedad</th><th>Período</th><th>Fecha</th>
                    <th class="num">Total</th><th class="num">Saldo</th><th>Estado</th><th>e-CF</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$facturas): ?>
                <tr><td colspan="10" class="cf-vacio">No hay facturas que coincidan con los filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($facturas as $f): ?>
                <tr>
                    <td><strong><?= esc($f['numero_documento']) ?></strong></td>
                    <td><?= esc(trim($f['nombres'] . ' ' . $f['apellidos'])) ?: '—' ?><br><small><?= esc($f['cedula']) ?></small></td>
                    <td><?= esc($f['edificio'] . ($f['apto'] ? ' · Apto. ' . $f['apto'] : '')) ?: '—' ?></td>
                    <td><?= esc($f['periodo']) ?></td>
                    <td><?= esc($f['fecha']) ?></td>
                    <td class="num"><?= pesos($f['total']) ?></td>
                    <td class="num"><?= pesos($f['saldo_pendiente']) ?></td>
                    <td><span class="cf-estado <?= esc(strtolower(str_replace(' ', '', $f['estado']))) ?>"><?= esc($f['estado']) ?></span></td>
                    <td><?= $f['ecf_estado'] ? '<span class="cf-estado ' . esc(strtolower(str_replace(' ', '', $f['ecf_estado']))) . '">' . esc($f['ecf_estado']) . '</span>' : '<small>Sin enviar</small>' ?></td>
                    <td><a class="cf-enlace" href="consulta_facturas.php?id=<?= (int)$f['id_facturas'] ?>">Ver</a><?= icono_ojo('factura_pdf.php?id=' . (int)$f['id_facturas'], 'Ver PDF de la factura') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

<?php endif; ?>

</main>
</div>
</body>
</html>
