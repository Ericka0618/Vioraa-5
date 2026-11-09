<?php
// Vencimiento de contratos de alquiler y renovación (solo administrador).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../conf/conexion.php';

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../login/login.php'); exit; }

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fdate($f) { return $f ? date('d/m/Y', strtotime($f)) : '—'; }
function rd($n) { return 'RD$ ' . number_format((float)$n, 2); }

$rol = (string)($_SESSION['rol'] ?? '');
if (strcasecmp($rol, 'Administrador') !== 0 && strcasecmp($rol, 'Admin') !== 0) {
    http_response_code(403);
    exit('Acceso exclusivo del administrador.');
}

if (empty($_SESSION['csrf_vencimientos'])) {
    $_SESSION['csrf_vencimientos'] = bin2hex(random_bytes(32));
}

$fechaValida = static function ($f) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$f);
    return $d && $d->format('Y-m-d') === $f;
};

$mensaje = '';
$mensajeError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_vencimientos'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario expiró. Intente nuevamente.');
        }
        $idContrato = filter_var($_POST['id_contrato'] ?? null, FILTER_VALIDATE_INT);
        $nuevaFin = (string)($_POST['nueva_fecha_fin'] ?? '');
        $montoTxt = trim((string)($_POST['nuevo_monto'] ?? ''));
        $obs = trim((string)($_POST['observaciones'] ?? ''));

        if (!$idContrato || $idContrato < 1) throw new RuntimeException('Contrato no válido.');
        if (!$fechaValida($nuevaFin)) throw new RuntimeException('Indique una nueva fecha de vencimiento válida.');
        if (mb_strlen($obs) > 1000) throw new RuntimeException('Las observaciones son demasiado largas (máximo 1000 caracteres).');
        $nuevoMonto = null;
        if ($montoTxt !== '') {
            $nuevoMonto = filter_var($montoTxt, FILTER_VALIDATE_FLOAT);
            if ($nuevoMonto === false || !is_finite($nuevoMonto) || $nuevoMonto <= 0) {
                throw new RuntimeException('El nuevo monto debe ser un número mayor que cero.');
            }
        }

        $conexion->begin_transaction();
        $st = $conexion->prepare("SELECT numero_contrato, fecha_fin, monto_alquiler FROM contrato
                                  WHERE id_contratos = ? AND activo = 1 AND tipo_operacion = 'Alquiler' FOR UPDATE");
        $st->bind_param('i', $idContrato);
        $st->execute();
        $ct = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$ct) throw new RuntimeException('El contrato no existe o no es un alquiler activo.');

        $hoy = date('Y-m-d');
        $base = ($ct['fecha_fin'] && $ct['fecha_fin'] > $hoy) ? $ct['fecha_fin'] : $hoy;
        if ($nuevaFin <= $base) {
            throw new RuntimeException('La nueva fecha debe ser posterior a ' . fdate($base) . '.');
        }
        $montoFinal = $nuevoMonto ?? (float)$ct['monto_alquiler'];

        $st = $conexion->prepare('INSERT INTO renovacion_contrato (contrato_id, fecha_renovacion, nueva_fecha_fin, nuevo_monto, observaciones, activo) VALUES (?, ?, ?, ?, ?, 1)');
        $st->bind_param('issds', $idContrato, $hoy, $nuevaFin, $montoFinal, $obs);
        $st->execute();
        $st->close();

        $st = $conexion->prepare("UPDATE contrato SET fecha_fin = ?, monto_alquiler = ?, estado = 'Activo' WHERE id_contratos = ?");
        $st->bind_param('sdi', $nuevaFin, $montoFinal, $idContrato);
        $st->execute();
        $st->close();
        $conexion->commit();

        $mensaje = 'Contrato ' . $ct['numero_contrato'] . ' renovado hasta ' . fdate($nuevaFin) . '.';
    } catch (Throwable $ex) {
        try { $conexion->rollback(); } catch (Throwable $ignorado) {}
        if ($ex instanceof RuntimeException) {
            $mensaje = $ex->getMessage();
        } else {
            error_log('VIORA vencimiento_contratos: ' . $ex->getMessage());
            $mensaje = 'No se pudo renovar el contrato.';
        }
        $mensajeError = true;
    }
}

$filtro = (string)($_GET['filtro'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$filtrosValidos = ['vencidos', '30', '60', '90', 'vigentes'];
if (!in_array($filtro, $filtrosValidos, true)) $filtro = '';

$base = "SELECT ct.id_contratos, ct.numero_contrato, ct.fecha_inicio, ct.fecha_fin, ct.monto_alquiler, ct.monto_mantenimiento,
                p.nombres, p.apellidos, p.correo, p.telefono, a.numero AS apartamento, e.nombre AS edificio,
                DATEDIFF(ct.fecha_fin, CURDATE()) AS dias,
                (SELECT COUNT(*) FROM renovacion_contrato r WHERE r.contrato_id = ct.id_contratos AND r.activo = 1) AS renovaciones
         FROM contrato ct
         LEFT JOIN persona p ON p.id_personas = ct.persona_id
         JOIN apartamento a ON a.id_apartamentos = ct.apartamento_id
         LEFT JOIN edificio e ON e.id_edificios = a.edificio_id
         WHERE ct.activo = 1 AND ct.tipo_operacion = 'Alquiler' AND ct.fecha_fin IS NOT NULL";
$tipos = '';
$params = [];
if ($q !== '') {
    $base .= " AND (ct.numero_contrato LIKE ? OR CONCAT_WS(' ', p.nombres, p.apellidos) LIKE ? OR a.numero LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
    $tipos = 'sss';
}
$st = $conexion->prepare($base . ' ORDER BY ct.fecha_fin ASC LIMIT 500');
if ($tipos !== '') $st->bind_param($tipos, ...$params);
$st->execute();
$todos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$res = ['vencidos' => 0, '30' => 0, '60' => 0, '90' => 0, 'vigentes' => 0];
foreach ($todos as &$c) {
    $d = (int)$c['dias'];
    if ($d < 0) { $c['grupo'] = 'vencidos'; $res['vencidos']++; }
    else {
        if ($d <= 30) $res['30']++;
        if ($d <= 60) $res['60']++;
        if ($d <= 90) $res['90']++;
        $c['grupo'] = $d <= 30 ? 'critico' : ($d <= 90 ? 'proximo' : 'vigente');
        if ($d > 90) $res['vigentes']++;
    }
}
unset($c);

$filas = array_values(array_filter($todos, static function ($c) use ($filtro) {
    $d = (int)$c['dias'];
    return match ($filtro) {
        'vencidos' => $d < 0,
        '30' => $d >= 0 && $d <= 30,
        '60' => $d >= 0 && $d <= 60,
        '90' => $d >= 0 && $d <= 90,
        'vigentes' => $d > 90,
        default => true,
    };
}));

$hist = $conexion->query("SELECT r.fecha_renovacion, r.nueva_fecha_fin, r.nuevo_monto, r.observaciones, ct.numero_contrato
                          FROM renovacion_contrato r JOIN contrato ct ON ct.id_contratos = r.contrato_id
                          WHERE r.activo = 1 ORDER BY r.fecha_renovacion DESC, r.id_renovaciones DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vencimiento de contratos | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=12">
<style>
.vc-contenido{flex:1;min-width:0;padding:24px 32px}
.vc-encabezado h1{margin:0 0 4px;font-size:24px}
.vc-encabezado p{margin:0 0 18px;color:#66756e;font-size:13px}
.vc-aviso{padding:12px 16px;border-radius:10px;margin-bottom:16px;background:#e6f6ee;color:#0b6b48;border:1px solid #bfe6d2;font-size:14px}
.vc-aviso.error{background:#fdecec;color:#a52a2a;border-color:#f3c4c4}
.vc-resumen{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.vc-res{display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid #e3e9e5;border-radius:14px;padding:14px 16px;transition:transform .15s,box-shadow .2s}
.vc-res:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(15,56,43,.1)}
.vc-res.activo{border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.18)}
.vc-res span{display:block;font-size:12px;color:#66756e}
.vc-res strong{font-size:26px}
.vc-res.rojo strong{color:#c0392b}.vc-res.naranja strong{color:#d4780a}.vc-res.verde strong{color:#0b6b48}
.vc-panel{background:#fff;border:1px solid #e3e9e5;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 1px 2px rgba(15,56,43,.04)}
.vc-panel h2{margin:0 0 12px;font-size:16px}
.vc-buscar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.vc-buscar input{flex:1;min-width:200px;padding:10px 12px;border:1px solid #d5ded9;border-radius:10px;font:inherit}
.vc-boton{padding:10px 16px;background:#0f382b;color:#fff;border:0;border-radius:10px;cursor:pointer;font-weight:700;font:inherit;font-weight:700}
.vc-boton.sec{background:#fff;color:#0f382b;border:1px solid #c9ddd3}
.vc-enlace{color:#0f382b;font-weight:600;text-decoration:none}
.vc-tabla{overflow-x:auto}
.vc-tabla table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.vc-tabla th,.vc-tabla td{padding:11px 10px;text-align:left;border-bottom:1px solid #eef2ef;vertical-align:top}
.vc-tabla small{display:block;color:#7a8a83;font-size:11px}
.vc-badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.vc-badge.vencido{background:#fde8e8;color:#b02a2a}.vc-badge.critico{background:#fff0dc;color:#b45309}
.vc-badge.proximo{background:#fff8d6;color:#8a6d00}.vc-badge.vigente{background:#e2f6ec;color:#0b6b48}
.vc-fondo{position:fixed;inset:0;background:rgba(10,42,32,.45);display:none;align-items:center;justify-content:center;padding:16px;z-index:70}
.vc-fondo.abierto{display:flex}
.vc-modal{background:#fff;border-radius:18px;width:min(480px,100%);padding:22px;box-shadow:0 30px 70px rgba(10,42,32,.35)}
.vc-modal h3{margin:0 0 4px}
.vc-modal p{margin:0 0 14px;font-size:13px;color:#66756e}
.vc-modal label{display:block;font-size:13px;font-weight:600;margin-bottom:12px}
.vc-modal input,.vc-modal textarea{display:block;width:100%;margin-top:5px;padding:10px 12px;border:1px solid #d5ded9;border-radius:10px;font:inherit;box-sizing:border-box}
.vc-acciones{display:flex;gap:10px;justify-content:flex-end;margin-top:6px}
.vc-vacio{padding:24px;text-align:center;color:#66756e}
@media(max-width:700px){.vc-contenido{padding:14px}}
</style>
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/menu_lateral.php'; ?>
<main class="vc-contenido">

    <header class="vc-encabezado">
        <h1>Vencimiento de contratos</h1>
        <p>Contratos de alquiler activos según su fecha de vencimiento. Desde aquí puede renovarlos.</p>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="vc-aviso <?= $mensajeError ? 'error' : '' ?>" role="status"><?= esc($mensaje) ?></div>
    <?php endif; ?>

    <section class="vc-resumen" aria-label="Resumen">
        <a class="vc-res rojo <?= $filtro === 'vencidos' ? 'activo' : '' ?>" href="?filtro=vencidos&amp;q=<?= urlencode($q) ?>"><span>Vencidos</span><strong><?= $res['vencidos'] ?></strong></a>
        <a class="vc-res naranja <?= $filtro === '30' ? 'activo' : '' ?>" href="?filtro=30&amp;q=<?= urlencode($q) ?>"><span>Vencen en 30 días</span><strong><?= $res['30'] ?></strong></a>
        <a class="vc-res naranja <?= $filtro === '60' ? 'activo' : '' ?>" href="?filtro=60&amp;q=<?= urlencode($q) ?>"><span>Vencen en 60 días</span><strong><?= $res['60'] ?></strong></a>
        <a class="vc-res <?= $filtro === '90' ? 'activo' : '' ?>" href="?filtro=90&amp;q=<?= urlencode($q) ?>"><span>Vencen en 90 días</span><strong><?= $res['90'] ?></strong></a>
        <a class="vc-res verde <?= $filtro === 'vigentes' ? 'activo' : '' ?>" href="?filtro=vigentes&amp;q=<?= urlencode($q) ?>"><span>Vigentes (más de 90 días)</span><strong><?= $res['vigentes'] ?></strong></a>
    </section>

    <section class="vc-panel">
        <form method="get" class="vc-buscar">
            <input type="hidden" name="filtro" value="<?= esc($filtro) ?>">
            <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Buscar por contrato, cliente o apartamento">
            <button type="submit" class="vc-boton">Buscar</button>
            <a class="vc-enlace" href="vencimiento_contratos.php">Limpiar</a>
        </form>
    </section>

    <section class="vc-panel vc-tabla">
        <?php if (!$filas): ?>
            <div class="vc-vacio">No hay contratos para mostrar con este filtro.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr><th>Contrato</th><th>Cliente</th><th>Inmueble</th><th>Alquiler</th><th>Inicio</th><th>Vencimiento</th><th>Estado</th><th>Acción</th></tr>
            </thead>
            <tbody>
            <?php foreach ($filas as $c):
                $d = (int)$c['dias'];
                $cliente = trim(($c['nombres'] ?? '') . ' ' . ($c['apellidos'] ?? '')) ?: '—';
                if ($c['grupo'] === 'vencidos') { $clase = 'vencido'; $txt = 'Vencido hace ' . abs($d) . ' d'; }
                elseif ($d === 0) { $clase = 'critico'; $txt = 'Vence hoy'; }
                else { $clase = $c['grupo']; $txt = 'En ' . $d . ' días'; }
            ?>
                <tr>
                    <td><strong><?= esc($c['numero_contrato']) ?></strong>
                        <?php if ((int)$c['renovaciones'] > 0): ?><small><?= (int)$c['renovaciones'] ?> renovación(es)</small><?php endif; ?></td>
                    <td><?= esc($cliente) ?><small><?= esc($c['telefono']) ?> <?= esc($c['correo']) ?></small></td>
                    <td><?= esc($c['edificio']) ?> · Apto. <?= esc($c['apartamento']) ?></td>
                    <td><?= rd($c['monto_alquiler']) ?></td>
                    <td><?= fdate($c['fecha_inicio']) ?></td>
                    <td><?= fdate($c['fecha_fin']) ?></td>
                    <td><span class="vc-badge <?= $clase ?>"><?= esc($txt) ?></span></td>
                    <td><button type="button" class="vc-boton sec vc-renovar"
                        data-id="<?= (int)$c['id_contratos'] ?>"
                        data-numero="<?= esc($c['numero_contrato']) ?>"
                        data-fin="<?= esc($c['fecha_fin']) ?>"
                        data-monto="<?= esc($c['monto_alquiler']) ?>">Renovar</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <?php if ($hist): ?>
    <section class="vc-panel vc-tabla">
        <h2>Últimas renovaciones</h2>
        <table>
            <thead><tr><th>Contrato</th><th>Fecha</th><th>Nuevo vencimiento</th><th>Monto</th><th>Observaciones</th></tr></thead>
            <tbody>
            <?php foreach ($hist as $r): ?>
                <tr><td><?= esc($r['numero_contrato']) ?></td><td><?= fdate($r['fecha_renovacion']) ?></td><td><?= fdate($r['nueva_fecha_fin']) ?></td><td><?= rd($r['nuevo_monto']) ?></td><td><?= esc($r['observaciones'] ?: '—') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
</main>
</div>

<div class="vc-fondo" id="vcFondo" role="dialog" aria-modal="true" aria-labelledby="vcTitulo">
    <form class="vc-modal" method="post" action="vencimiento_contratos.php?filtro=<?= esc($filtro) ?>&amp;q=<?= esc(urlencode($q)) ?>">
        <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_vencimientos']) ?>">
        <input type="hidden" name="id_contrato" id="vcId">
        <h3 id="vcTitulo">Renovar contrato</h3>
        <p id="vcInfo"></p>
        <label>Nueva fecha de vencimiento
            <input type="date" name="nueva_fecha_fin" id="vcFin" required>
        </label>
        <label>Nuevo monto de alquiler (RD$)
            <input type="number" name="nuevo_monto" id="vcMonto" min="0.01" step="0.01">
        </label>
        <label>Observaciones
            <textarea name="observaciones" rows="3" maxlength="1000"></textarea>
        </label>
        <div class="vc-acciones">
            <button type="button" class="vc-boton sec" id="vcCancelar">Cancelar</button>
            <button type="submit" class="vc-boton">Confirmar renovación</button>
        </div>
    </form>
</div>

<script>
(function () {
    var fondo = document.getElementById('vcFondo');
    function cerrar() { fondo.classList.remove('abierto'); }
    document.querySelectorAll('.vc-renovar').forEach(function (b) {
        b.addEventListener('click', function () {
            var fin = b.dataset.fin, hoy = new Date().toISOString().slice(0, 10);
            var base = fin > hoy ? fin : hoy;
            var sug = new Date(base + 'T00:00:00');
            sug.setFullYear(sug.getFullYear() + 1);
            document.getElementById('vcId').value = b.dataset.id;
            document.getElementById('vcInfo').textContent = 'Contrato ' + b.dataset.numero + ' · vence el ' + fin.split('-').reverse().join('/');
            var campo = document.getElementById('vcFin');
            campo.value = sug.toISOString().slice(0, 10);
            document.getElementById('vcMonto').value = b.dataset.monto;
            fondo.classList.add('abierto');
            campo.focus();
        });
    });
    document.getElementById('vcCancelar').addEventListener('click', cerrar);
    fondo.addEventListener('click', function (e) { if (e.target === fondo) cerrar(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
})();
</script>
</body>
</html>
