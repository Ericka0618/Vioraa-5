<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../conf/conexion.php';
require_once __DIR__ . '/reparaciones_servicio.php';

if (empty($_SESSION['logged_in'])) {
    header('Location: ../login/login.php');
    exit;
}

$mesActual = date('Y-m');
$mesValido = static function ($mes) {
    return is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1;
};

try {
    if (!rep_es_admin($conexion)) {
        http_response_code(403);
        exit('Acceso reservado para administradores.');
    }

    if (!isset($_SESSION['csrf_mantenimiento_mensual'])) {
        $_SESSION['csrf_mantenimiento_mensual'] = bin2hex(random_bytes(16));
    }

    $mes = (string)($_GET['mes'] ?? $mesActual);
    if (!$mesValido($mes)) {
        $mes = $mesActual;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $redireccion = 'mantenimiento_mensual.php?mes=' . urlencode($mes);
        if (!hash_equals($_SESSION['csrf_mantenimiento_mensual'], (string)($_POST['csrf'] ?? ''))) {
            $_SESSION['mantenimiento_flash'] = ['tipo' => 'error', 'texto' => 'La sesión del formulario expiró. Intenta de nuevo.'];
            header('Location: ' . $redireccion);
            exit;
        }

        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'generar') {
            $postMes = (string)($_POST['mes'] ?? '');
            if (!$mesValido($postMes)) {
                $_SESSION['mantenimiento_flash'] = ['tipo' => 'error', 'texto' => 'El mes seleccionado no es válido.'];
            } else {
                $fechaProgramada = $postMes . '-01';
                $descripcion = 'Mantenimiento mensual de áreas comunes (' . $postMes . ')';
                $tipo = 'Mensual';
                $estadoInicial = 'Programado';

                $condominios = $conexion->query('SELECT id_condominios FROM condominio WHERE activo = 1')->fetch_all(MYSQLI_ASSOC);
                $creados = 0;
                $conexion->begin_transaction();
                try {
                    $stmt = $conexion->prepare("INSERT INTO mantenimiento
                        (condominio_id, tipo, periodo, descripcion, fecha_programada, estado, activo)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE id_mantenimientos = id_mantenimientos");
                    foreach ($condominios as $condominio) {
                        $idCondominio = (int)$condominio['id_condominios'];
                        $stmt->bind_param('isssss', $idCondominio, $tipo, $postMes, $descripcion, $fechaProgramada, $estadoInicial);
                        $stmt->execute();
                        if ($stmt->affected_rows === 1) {
                            $creados++;
                        }
                    }
                    $stmt->close();
                    $conexion->commit();
                } catch (Throwable $error) {
                    $conexion->rollback();
                    throw $error;
                }

                $_SESSION['mantenimiento_flash'] = [
                    'tipo' => 'ok',
                    'texto' => $creados > 0
                        ? 'Se generaron ' . $creados . ' mantenimiento(s) para ' . $postMes . '.'
                        : 'Los mantenimientos de ' . $postMes . ' ya estaban generados. No se duplicó ninguno.',
                ];
            }
            $redireccion = 'mantenimiento_mensual.php?mes=' . urlencode($postMes);
        } elseif ($accion === 'actualizar') {
            $idMantenimiento = (int)($_POST['id_mantenimientos'] ?? 0);
            $estado = (string)($_POST['estado'] ?? '');
            $suplidorId = (int)($_POST['suplidor_id'] ?? 0);

            if (!in_array($estado, REP_ESTADOS_MENSUAL, true)) {
                $_SESSION['mantenimiento_flash'] = ['tipo' => 'error', 'texto' => 'Selecciona un estado válido.'];
            } else {
                $suplidorValor = null;
                if ($suplidorId > 0) {
                    $stmt = $conexion->prepare('SELECT id_suplidores FROM suplidor WHERE id_suplidores = ? AND activo = 1');
                    $stmt->bind_param('i', $suplidorId);
                    $stmt->execute();
                    $existe = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $suplidorValor = $existe ? $suplidorId : null;
                }

                $stmt = $conexion->prepare("SELECT id_mantenimientos FROM mantenimiento
                    WHERE id_mantenimientos = ? AND tipo = 'Mensual' AND activo = 1");
                $stmt->bind_param('i', $idMantenimiento);
                $stmt->execute();
                $existe = $stmt->get_result()->fetch_assoc() !== null;
                $stmt->close();

                if (!$existe) {
                    $_SESSION['mantenimiento_flash'] = ['tipo' => 'error', 'texto' => 'El mantenimiento no existe o fue eliminado.'];
                } else {
                    // Al marcar como realizado se guarda la fecha de hoy si no tenía una.
                    $fechaRealizacion = $estado === 'Realizado' ? date('Y-m-d') : null;
                    $stmt = $conexion->prepare("UPDATE mantenimiento
                        SET suplidor_id = ?, estado = ?,
                            fecha_realizacion = CASE WHEN ? = 'Realizado' THEN COALESCE(fecha_realizacion, ?) ELSE NULL END
                        WHERE id_mantenimientos = ?");
                    $stmt->bind_param('isssi', $suplidorValor, $estado, $estado, $fechaRealizacion, $idMantenimiento);
                    $stmt->execute();
                    $stmt->close();

                    $_SESSION['mantenimiento_flash'] = ['tipo' => 'ok', 'texto' => 'Mantenimiento actualizado.'];
                }
            }
        }

        header('Location: ' . $redireccion);
        exit;
    }

    $stmt = $conexion->prepare("SELECT m.id_mantenimientos, m.estado, m.suplidor_id, m.fecha_programada, m.fecha_realizacion,
            c.nombre AS condominio, s.nombre AS suplidor, s.telefono AS telefono_suplidor
        FROM mantenimiento m
        INNER JOIN condominio c ON c.id_condominios = m.condominio_id
        LEFT JOIN suplidor s ON s.id_suplidores = m.suplidor_id
        WHERE m.tipo = 'Mensual' AND m.periodo = ? AND m.activo = 1
        ORDER BY c.nombre");
    $stmt->bind_param('s', $mes);
    $stmt->execute();
    $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $suplidores = $conexion->query('SELECT id_suplidores, nombre, telefono FROM suplidor WHERE activo = 1 ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);

    $flash = $_SESSION['mantenimiento_flash'] ?? null;
    unset($_SESSION['mantenimiento_flash']);
} catch (Throwable $error) {
    error_log('VIORA mantenimiento_mensual: ' . $error->getMessage());
    http_response_code(500);
    exit('No se pudo cargar el mantenimiento mensual. Revisa el registro de errores de PHP.');
}

$csrf = $_SESSION['csrf_mantenimiento_mensual'];
$claseEstado = static function ($estado) {
    return strtolower(str_replace(' ', '-', (string)$estado));
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mantenimiento mensual | VIORA</title>
    <link rel="stylesheet" href="../estilos/styleP.css?v=12">
    <style>
        :root { --v-verde:#0f382b; --v-fondo:#f4f6f5; --v-borde:#dfe9e3; --v-gris:#667c70; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--v-fondo); color:#203f33; font-family:Arial,sans-serif; }
        .diseño { display:flex; min-height:100vh; width:100%; align-items:stretch; }
        .diseño > .barra-lateral { flex-shrink:0; }
        .mm-contenido { flex:1; min-width:0; padding:30px 32px 55px; }
        .mm-cabecera { display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px; margin-bottom:20px; }
        .mm-cabecera h1 { margin:0 0 6px; font-size:26px; color:var(--v-verde); }
        .mm-cabecera p { margin:0; color:var(--v-gris); font-size:14px; line-height:1.6; }
        .mm-form { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
        .mm-form label { display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#3c5e4e; }
        .mm-form input, .mm-tabla select { font:inherit; font-size:13px; padding:8px 10px; border:1px solid #cfdcd5; border-radius:8px; background:#fff; }
        .mm-boton { border:0; border-radius:9px; background:var(--v-verde); color:#fff; font-weight:700; font-size:13px; padding:9px 14px; cursor:pointer; }
        .mm-boton:hover { background:#18513e; }
        .mm-boton.secundario { background:#e3f5eb; color:#087346; }
        .mm-alerta { border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:14px; }
        .mm-alerta.ok { background:#e3f5eb; color:#087346; border:1px solid #bfe5d0; }
        .mm-alerta.error { background:#fdecec; color:#9b1c1c; border:1px solid #f5c2c2; }
        .mm-tabla-wrap { background:#fff; border:1px solid var(--v-borde); border-radius:14px; overflow-x:auto; box-shadow:0 5px 20px rgba(15,56,43,.045); }
        .mm-tabla { width:100%; border-collapse:collapse; font-size:13px; min-width:760px; }
        .mm-tabla th { text-align:left; background:#f3f7f5; color:var(--v-verde); padding:12px; border-bottom:1px solid var(--v-borde); }
        .mm-tabla td { padding:12px; border-bottom:1px solid #eef3f0; vertical-align:middle; }
        .mm-tabla tr:last-child td { border-bottom:0; }
        .mm-meta { display:block; color:var(--v-gris); font-size:12px; margin-top:3px; }
        .mm-badge { display:inline-block; padding:4px 9px; border-radius:100px; font-size:11px; font-weight:700; background:#fff4d6; color:#8a5a00; }
        .mm-badge.realizado { background:#e3f5eb; color:#087346; }
        .mm-vacio { padding:28px; text-align:center; color:var(--v-gris); }
        @media (max-width:900px) { .mm-contenido { padding:24px 20px; } }
        @media (max-width:650px) { .diseño { flex-direction:column; } .mm-contenido { padding:20px 15px 35px; } }
    </style>
</head>
<body>
<div class="diseño">
    <?php $menuActivo = 'mantenimiento_mensual'; include __DIR__ . '/menu_lateral.php'; ?>
    <main class="mm-contenido">
        <header class="mm-cabecera">
            <div>
                <h1>Mantenimiento mensual de áreas comunes</h1>
                <p>Genera el mantenimiento del mes para cada condominio activo. Cada condominio tiene un solo registro por mes.</p>
            </div>
            <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <form method="get" class="mm-form">
                    <label>Mes
                        <input type="month" name="mes" value="<?= rep_esc($mes) ?>" onchange="this.form.submit()">
                    </label>
                </form>
                <form method="post" class="mm-form" action="mantenimiento_mensual.php?mes=<?= rep_esc(urlencode($mes)) ?>">
                    <input type="hidden" name="csrf" value="<?= rep_esc($csrf) ?>">
                    <input type="hidden" name="accion" value="generar">
                    <input type="hidden" name="mes" value="<?= rep_esc($mes) ?>">
                    <button type="submit" class="mm-boton">Generar mantenimiento del mes</button>
                </form>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="mm-alerta <?= $flash['tipo'] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= rep_esc($flash['texto']) ?></div>
        <?php endif; ?>

        <div class="mm-tabla-wrap">
            <?php if (!$registros): ?>
                <p class="mm-vacio">No hay mantenimientos generados para <?= rep_esc($mes) ?>. Usa el botón “Generar mantenimiento del mes”.</p>
            <?php else: ?>
                <table class="mm-tabla">
                    <thead>
                        <tr>
                            <th>Condominio</th>
                            <th>Fecha programada</th>
                            <th>Suplidor</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $r): ?>
                            <tr>
                                <td><strong><?= rep_esc($r['condominio']) ?></strong></td>
                                <td>
                                    <?= rep_esc($r['fecha_programada'] ? date('d/m/Y', strtotime($r['fecha_programada'])) : '—') ?>
                                    <?php if ($r['fecha_realizacion']): ?>
                                        <span class="mm-meta">Realizado: <?= rep_esc(date('d/m/Y', strtotime($r['fecha_realizacion']))) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $r['suplidor'] ? rep_esc($r['suplidor']) : '<span class="mm-meta">Sin asignar</span>' ?>
                                </td>
                                <td><span class="mm-badge <?= rep_esc($claseEstado($r['estado'])) ?>"><?= rep_esc($r['estado']) ?></span></td>
                                <td>
                                    <form method="post" action="mantenimiento_mensual.php?mes=<?= rep_esc(urlencode($mes)) ?>" style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <input type="hidden" name="csrf" value="<?= rep_esc($csrf) ?>">
                                        <input type="hidden" name="accion" value="actualizar">
                                        <input type="hidden" name="id_mantenimientos" value="<?= (int)$r['id_mantenimientos'] ?>">
                                        <select name="suplidor_id" aria-label="Suplidor">
                                            <option value="0">Sin asignar</option>
                                            <?php foreach ($suplidores as $sup): ?>
                                                <option value="<?= (int)$sup['id_suplidores'] ?>" <?= (int)$r['suplidor_id'] === (int)$sup['id_suplidores'] ? 'selected' : '' ?>>
                                                    <?= rep_esc($sup['nombre']) ?><?= $sup['telefono'] ? ' · ' . rep_esc($sup['telefono']) : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="estado" aria-label="Estado">
                                            <?php foreach (REP_ESTADOS_MENSUAL as $opcion): ?>
                                                <option value="<?= rep_esc($opcion) ?>" <?= $r['estado'] === $opcion ? 'selected' : '' ?>><?= rep_esc($opcion) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="mm-boton secundario">Guardar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
