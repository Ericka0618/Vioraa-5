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

try {
    if (!rep_es_admin($conexion)) {
        http_response_code(403);
        exit('Acceso reservado para administradores.');
    }

    if (!isset($_SESSION['csrf_reparacion_admin'])) {
        $_SESSION['csrf_reparacion_admin'] = bin2hex(random_bytes(16));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $redireccion = 'reparaciones.php' . (isset($_GET['estado']) ? '?estado=' . urlencode((string)$_GET['estado']) : '');
        if (!hash_equals($_SESSION['csrf_reparacion_admin'], (string)($_POST['csrf'] ?? ''))) {
            $_SESSION['reparacion_flash_admin'] = ['tipo' => 'error', 'texto' => 'La sesión del formulario expiró. Intenta de nuevo.'];
            header('Location: ' . $redireccion);
            exit;
        }

        $idSolicitud = (int)($_POST['id_solicitudes'] ?? 0);
        $estadoNuevo = (string)($_POST['estado'] ?? '');
        $suplidorId = (int)($_POST['suplidor_id'] ?? 0);

        if (!in_array($estadoNuevo, REP_ESTADOS_REPARACION, true)) {
            $_SESSION['reparacion_flash_admin'] = ['tipo' => 'error', 'texto' => 'Selecciona un estado válido.'];
        } else {
            $stmt = $conexion->prepare('SELECT sm.estado, sm.persona_id, sm.apartamento_id FROM solicitud_mantenimiento sm WHERE sm.id_solicitudes = ? AND sm.activo = 1 LIMIT 1');
            $stmt->bind_param('i', $idSolicitud);
            $stmt->execute();
            $actual = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$actual) {
                $_SESSION['reparacion_flash_admin'] = ['tipo' => 'error', 'texto' => 'La solicitud no existe o fue eliminada.'];
            } else {
                $suplidorValor = null;
                if ($suplidorId > 0) {
                    $stmt = $conexion->prepare('SELECT id_suplidores FROM suplidor WHERE id_suplidores = ? AND activo = 1');
                    $stmt->bind_param('i', $suplidorId);
                    $stmt->execute();
                    $existeSuplidor = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $suplidorValor = $existeSuplidor ? $suplidorId : null;
                }

                $conexion->begin_transaction();
                try {
                    $stmt = $conexion->prepare('UPDATE solicitud_mantenimiento SET estado = ?, suplidor_id = ? WHERE id_solicitudes = ?');
                    $stmt->bind_param('sii', $estadoNuevo, $suplidorValor, $idSolicitud);
                    $stmt->execute();
                    $stmt->close();

                    if ($actual['estado'] !== $estadoNuevo) {
                        $idUsuarioCliente = rep_usuario_de_persona($conexion, (int)$actual['persona_id']);
                        if ($idUsuarioCliente !== null) {
                            rep_notificar(
                                $conexion,
                                $idUsuarioCliente,
                                'Actualización de tu solicitud de reparación',
                                'Tu solicitud #' . $idSolicitud . ' cambió a estado: ' . $estadoNuevo . '.'
                            );
                        }
                    }
                    $conexion->commit();
                    $_SESSION['reparacion_flash_admin'] = ['tipo' => 'ok', 'texto' => 'Solicitud #' . $idSolicitud . ' actualizada.'];
                } catch (Throwable $error) {
                    $conexion->rollback();
                    throw $error;
                }
            }
        }

        header('Location: ' . $redireccion);
        exit;
    }

    $filtroEstado = (string)($_GET['estado'] ?? '');
    if ($filtroEstado !== '' && !in_array($filtroEstado, REP_ESTADOS_REPARACION, true)) {
        $filtroEstado = '';
    }

    $sql = "SELECT sm.id_solicitudes, sm.descripcion, sm.ubicacion, sm.prioridad, sm.estado, sm.fecha_solicitud, sm.suplidor_id,
            p.nombres, p.apellidos, p.telefono AS telefono_cliente, p.correo AS correo_cliente,
            a.numero, e.nombre AS edificio, c.nombre AS condominio, s.nombre AS suplidor
        FROM solicitud_mantenimiento sm
        INNER JOIN persona p ON p.id_personas = sm.persona_id
        INNER JOIN apartamento a ON a.id_apartamentos = sm.apartamento_id
        INNER JOIN edificio e ON e.id_edificios = a.edificio_id
        INNER JOIN condominio c ON c.id_condominios = e.condominio_id
        LEFT JOIN suplidor s ON s.id_suplidores = sm.suplidor_id
        WHERE sm.activo = 1";
    if ($filtroEstado !== '') {
        $stmt = $conexion->prepare($sql . ' AND sm.estado = ? ORDER BY sm.fecha_solicitud DESC LIMIT 300');
        $stmt->bind_param('s', $filtroEstado);
    } else {
        $stmt = $conexion->prepare($sql . ' ORDER BY sm.fecha_solicitud DESC LIMIT 300');
    }
    $stmt->execute();
    $solicitudes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $suplidores = $conexion->query('SELECT id_suplidores, nombre, telefono, correo FROM suplidor WHERE activo = 1 ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);

    $flash = $_SESSION['reparacion_flash_admin'] ?? null;
    unset($_SESSION['reparacion_flash_admin']);
} catch (Throwable $error) {
    error_log('VIORA reparaciones: ' . $error->getMessage());
    http_response_code(500);
    exit('No se pudieron cargar las reparaciones. Revisa el registro de errores de PHP.');
}

$csrf = $_SESSION['csrf_reparacion_admin'];
$claseEstado = static function ($estado) {
    return strtolower(str_replace(' ', '-', (string)$estado));
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reparaciones | VIORA</title>
    <link rel="stylesheet" href="../estilos/styleP.css?v=12">
    <style>
        :root { --v-verde:#0f382b; --v-fondo:#f4f6f5; --v-borde:#dfe9e3; --v-gris:#667c70; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--v-fondo); color:#203f33; font-family:Arial,sans-serif; }
        .diseño { display:flex; min-height:100vh; width:100%; align-items:stretch; }
        .diseño > .barra-lateral { flex-shrink:0; }
        .rep-contenido { flex:1; min-width:0; padding:30px 32px 55px; }
        .rep-cabecera { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:20px; }
        .rep-cabecera h1 { margin:0 0 6px; font-size:26px; color:var(--v-verde); }
        .rep-cabecera p { margin:0; color:var(--v-gris); font-size:14px; }
        .rep-filtro { display:flex; gap:8px; align-items:center; }
        .rep-filtro select, .rep-tabla select { font:inherit; font-size:13px; padding:8px 10px; border:1px solid #cfdcd5; border-radius:8px; background:#fff; }
        .rep-boton { border:0; border-radius:9px; background:var(--v-verde); color:#fff; font-weight:700; font-size:13px; padding:9px 14px; cursor:pointer; }
        .rep-boton:hover { background:#18513e; }
        .rep-alerta { border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:14px; }
        .rep-alerta.ok { background:#e3f5eb; color:#087346; border:1px solid #bfe5d0; }
        .rep-alerta.error { background:#fdecec; color:#9b1c1c; border:1px solid #f5c2c2; }
        .rep-tabla-wrap { background:#fff; border:1px solid var(--v-borde); border-radius:14px; overflow-x:auto; box-shadow:0 5px 20px rgba(15,56,43,.045); }
        .rep-tabla { width:100%; border-collapse:collapse; font-size:13px; min-width:980px; }
        .rep-tabla th { text-align:left; background:#f3f7f5; color:var(--v-verde); padding:12px; border-bottom:1px solid var(--v-borde); }
        .rep-tabla td { padding:12px; border-bottom:1px solid #eef3f0; vertical-align:top; }
        .rep-tabla tr:last-child td { border-bottom:0; }
        .rep-descripcion { max-width:280px; color:#3c5e4e; line-height:1.5; }
        .rep-meta { display:block; color:var(--v-gris); font-size:12px; margin-top:3px; }
        .rep-contacto { font-size:12px; color:#3c5e4e; line-height:1.6; }
        .rep-badge { display:inline-block; padding:4px 9px; border-radius:100px; font-size:11px; font-weight:700; background:#eef2f0; color:#4a6356; white-space:nowrap; }
        .rep-badge.pendiente { background:#fff4d6; color:#8a5a00; }
        .rep-badge.urgente { background:#fdecec; color:#9b1c1c; }
        .rep-badge.en-proceso { background:#e3edf9; color:#1d4f91; }
        .rep-badge.completada { background:#e3f5eb; color:#087346; }
        .rep-badge.cancelada { background:#f1f1f1; color:#666; }
        .rep-vacio { padding:28px; text-align:center; color:var(--v-gris); }
        .rep-accion { display:flex; flex-direction:column; gap:6px; min-width:190px; }
        @media (max-width:900px) { .rep-contenido { padding:24px 20px; } }
        @media (max-width:650px) { .diseño { flex-direction:column; } .rep-contenido { padding:20px 15px 35px; } }
    </style>
</head>
<body>
<div class="diseño">
    <?php $menuActivo = 'reparaciones'; include __DIR__ . '/menu_lateral.php'; ?>
    <main class="rep-contenido">
        <header class="rep-cabecera">
            <div>
                <h1>Solicitudes de reparación</h1>
                <p>Contacta al suplidor asignado y actualiza el estado de cada reparación. El cliente recibe un aviso al cambiar el estado.</p>
            </div>
            <form method="get" class="rep-filtro">
                <label for="estado">Estado</label>
                <select id="estado" name="estado" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach (REP_ESTADOS_REPARACION as $opcion): ?>
                        <option value="<?= rep_esc($opcion) ?>" <?= $filtroEstado === $opcion ? 'selected' : '' ?>><?= rep_esc($opcion) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </header>

        <?php if ($flash): ?>
            <div class="rep-alerta <?= $flash['tipo'] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= rep_esc($flash['texto']) ?></div>
        <?php endif; ?>

        <div class="rep-tabla-wrap">
            <?php if (!$solicitudes): ?>
                <p class="rep-vacio">No hay solicitudes de reparación<?= $filtroEstado !== '' ? ' en estado ' . rep_esc($filtroEstado) : '' ?>.</p>
            <?php else: ?>
                <table class="rep-tabla">
                    <thead>
                        <tr>
                            <th>Solicitud</th>
                            <th>Cliente y propiedad</th>
                            <th>Descripción</th>
                            <th>Suplidor asignado</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($solicitudes as $s): ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int)$s['id_solicitudes'] ?></strong>
                                    <span class="rep-meta"><?= rep_esc(date('d/m/Y H:i', strtotime($s['fecha_solicitud']))) ?></span>
                                    <span class="rep-badge <?= rep_esc($claseEstado($s['prioridad'])) ?>"><?= rep_esc($s['prioridad']) ?></span>
                                </td>
                                <td>
                                    <strong><?= rep_esc($s['nombres'] . ' ' . $s['apellidos']) ?></strong>
                                    <span class="rep-meta"><?= rep_esc($s['edificio'] . ' · Apto ' . $s['numero']) ?></span>
                                    <span class="rep-meta"><?= rep_esc($s['condominio']) ?></span>
                                    <div class="rep-contacto">
                                        <?= $s['telefono_cliente'] ? 'Tel: ' . rep_esc($s['telefono_cliente']) . '<br>' : '' ?>
                                        <?= $s['correo_cliente'] ? rep_esc($s['correo_cliente']) : '' ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="rep-descripcion"><?= nl2br(rep_esc($s['descripcion'])) ?></div>
                                    <?php if ($s['ubicacion']): ?><span class="rep-meta">Ubicación: <?= rep_esc($s['ubicacion']) ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['suplidor']): ?>
                                        <strong><?= rep_esc($s['suplidor']) ?></strong>
                                    <?php else: ?>
                                        <span class="rep-meta">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="rep-badge <?= rep_esc($claseEstado($s['estado'])) ?>"><?= rep_esc($s['estado']) ?></span>
                                </td>
                                <td>
                                    <form method="post" action="reparaciones.php<?= $filtroEstado !== '' ? '?estado=' . rep_esc(urlencode($filtroEstado)) : '' ?>" class="rep-accion">
                                        <input type="hidden" name="csrf" value="<?= rep_esc($csrf) ?>">
                                        <input type="hidden" name="id_solicitudes" value="<?= (int)$s['id_solicitudes'] ?>">
                                        <label>
                                            Suplidor
                                            <select name="suplidor_id">
                                                <option value="0">Sin asignar</option>
                                                <?php foreach ($suplidores as $sup): ?>
                                                    <option value="<?= (int)$sup['id_suplidores'] ?>" <?= (int)$s['suplidor_id'] === (int)$sup['id_suplidores'] ? 'selected' : '' ?>>
                                                        <?= rep_esc($sup['nombre']) ?><?= $sup['telefono'] ? ' · ' . rep_esc($sup['telefono']) : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            Estado
                                            <select name="estado">
                                                <?php foreach (REP_ESTADOS_REPARACION as $opcion): ?>
                                                    <option value="<?= rep_esc($opcion) ?>" <?= $s['estado'] === $opcion ? 'selected' : '' ?>><?= rep_esc($opcion) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <button type="submit" class="rep-boton">Guardar</button>
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
