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

$idUsuario = rep_id_sesion();
if ($idUsuario <= 0) {
    http_response_code(403);
    exit('No se pudo identificar la sesión. Inicia sesión nuevamente.');
}

try {
    $stmt = $conexion->prepare("SELECT p.id_personas, p.nombres, r.nombre AS rol_real
        FROM usuario u
        INNER JOIN persona p ON p.id_personas = u.persona_id
        INNER JOIN role r ON r.id_roles = u.id_rol
        WHERE u.id_usuarios = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1");
    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cliente || strcasecmp(trim((string)$cliente['rol_real']), 'Cliente') !== 0) {
        http_response_code(403);
        exit('Acceso reservado para clientes activos.');
    }

    // El menú compartido utiliza el rol de la sesión.
    $_SESSION['rol'] = 'Cliente';
    $idPersona = (int)$cliente['id_personas'];

    // Solo se pueden reportar reparaciones de apartamentos con solicitud aprobada.
    $stmt = $conexion->prepare("SELECT DISTINCT a.id_apartamentos, a.numero, e.nombre AS edificio, c.nombre AS condominio
        FROM solicitud_propiedad sp
        INNER JOIN apartamento a ON a.id_apartamentos = sp.apartamento_id
        INNER JOIN edificio e ON e.id_edificios = a.edificio_id
        INNER JOIN condominio c ON c.id_condominios = e.condominio_id
        WHERE sp.persona_id = ? AND sp.estado = 'Aprobada' AND sp.activo = 1
        ORDER BY c.nombre, e.nombre, a.numero");
    $stmt->bind_param('i', $idPersona);
    $stmt->execute();
    $apartamentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $idsApartamentos = array_map('intval', array_column($apartamentos, 'id_apartamentos'));
    $errores = [];
    $valores = ['apartamento_id' => '', 'ubicacion' => '', 'prioridad' => 'Media', 'descripcion' => ''];

    if (!isset($_SESSION['csrf_reparacion'])) {
        $_SESSION['csrf_reparacion'] = bin2hex(random_bytes(16));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tokenRecibido = (string)($_POST['csrf'] ?? '');
        if (!hash_equals($_SESSION['csrf_reparacion'], $tokenRecibido)) {
            $errores[] = 'La sesión del formulario expiró. Intenta enviarlo de nuevo.';
        }

        $valores['apartamento_id'] = (string)($_POST['apartamento_id'] ?? '');
        $valores['ubicacion'] = trim((string)($_POST['ubicacion'] ?? ''));
        $valores['prioridad'] = (string)($_POST['prioridad'] ?? 'Media');
        $valores['descripcion'] = trim((string)($_POST['descripcion'] ?? ''));

        $apartamentoId = (int)$valores['apartamento_id'];
        $apartamento = null;
        foreach ($apartamentos as $ap) {
            if ((int)$ap['id_apartamentos'] === $apartamentoId) {
                $apartamento = $ap;
            }
        }

        if ($apartamento === null) {
            $errores[] = 'Selecciona una propiedad válida.';
        }
        if (!in_array($valores['prioridad'], REP_PRIORIDADES, true)) {
            $errores[] = 'Selecciona una prioridad válida.';
        }
        if (mb_strlen($valores['descripcion'], 'UTF-8') < 10) {
            $errores[] = 'Describe la reparación con al menos 10 caracteres.';
        } elseif (mb_strlen($valores['descripcion'], 'UTF-8') > 2000) {
            $errores[] = 'La descripción no puede superar los 2000 caracteres.';
        }
        if (mb_strlen($valores['ubicacion'], 'UTF-8') > 150) {
            $errores[] = 'La ubicación no puede superar los 150 caracteres.';
        }

        if (!$errores) {
            $ubicacion = $valores['ubicacion'] !== '' ? $valores['ubicacion'] : null;
            $descripcion = $valores['descripcion'];
            $prioridad = $valores['prioridad'];
            $nombreCliente = (string)$cliente['nombres'];
            $etiquetaApto = $apartamento['edificio'] . ' · Apartamento ' . $apartamento['numero'];

            $conexion->begin_transaction();
            try {
                $stmt = $conexion->prepare("INSERT INTO solicitud_mantenimiento
                    (apartamento_id, persona_id, descripcion, ubicacion, prioridad, estado, activo)
                    VALUES (?, ?, ?, ?, ?, 'Pendiente', 1)");
                $stmt->bind_param('iisss', $apartamentoId, $idPersona, $descripcion, $ubicacion, $prioridad);
                $stmt->execute();
                $idSolicitud = (int)$conexion->insert_id;
                $stmt->close();

                rep_notificar(
                    $conexion,
                    $idUsuario,
                    'Solicitud de reparación recibida',
                    'Recibimos tu solicitud #' . $idSolicitud . ' para ' . $etiquetaApto . '. En la brevedad posible te estaremos contactando.'
                );

                $mensajeAdmin = 'Solicitud #' . $idSolicitud . ' de ' . $nombreCliente . ' (' . $etiquetaApto . '). Prioridad: ' . $prioridad . '.';
                foreach (rep_ids_administradores($conexion) as $idAdmin) {
                    rep_notificar($conexion, $idAdmin, 'Nueva solicitud de reparación', $mensajeAdmin);
                }

                $conexion->commit();
            } catch (Throwable $error) {
                $conexion->rollback();
                throw $error;
            }

            $_SESSION['reparacion_flash'] = 'Tu solicitud #' . $idSolicitud . ' fue recibida. En la brevedad posible te estaremos contactando.';
            header('Location: solicitar_reparacion.php');
            exit;
        }
    }

    $flash = $_SESSION['reparacion_flash'] ?? '';
    unset($_SESSION['reparacion_flash']);

    $stmt = $conexion->prepare("SELECT sm.id_solicitudes, sm.descripcion, sm.ubicacion, sm.prioridad, sm.estado,
            sm.fecha_solicitud, a.numero, e.nombre AS edificio
        FROM solicitud_mantenimiento sm
        INNER JOIN apartamento a ON a.id_apartamentos = sm.apartamento_id
        INNER JOIN edificio e ON e.id_edificios = a.edificio_id
        WHERE sm.persona_id = ? AND sm.activo = 1
        ORDER BY sm.fecha_solicitud DESC, sm.id_solicitudes DESC
        LIMIT 50");
    $stmt->bind_param('i', $idPersona);
    $stmt->execute();
    $solicitudes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conexion->prepare("SELECT titulo, mensaje, fecha_envio
        FROM notificacion
        WHERE usuario_id = ? AND tipo = 'reparacion' AND activo = 1
        ORDER BY id_notificaciones DESC
        LIMIT 10");
    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();
    $notificaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $error) {
    error_log('VIORA solicitar_reparacion: ' . $error->getMessage());
    http_response_code(500);
    exit('No se pudo cargar la página de reparaciones. Revisa el registro de errores de PHP.');
}

$csrf = $_SESSION['csrf_reparacion'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitar una reparación | VIORA</title>
    <link rel="stylesheet" href="../estilos/styleP.css?v=12">
    <style>
        :root { --v-verde:#0f382b; --v-fondo:#f4f6f5; --v-borde:#dfe9e3; --v-gris:#667c70; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--v-fondo); color:#203f33; font-family:Arial,sans-serif; }
        .diseño { display:flex; min-height:100vh; width:100%; align-items:stretch; }
        .diseño > .barra-lateral { flex-shrink:0; }
        .rep-contenido { flex:1; min-width:0; padding:30px 32px 55px; }
        .rep-cabecera h1 { margin:0 0 8px; font-size:26px; color:var(--v-verde); }
        .rep-subtitulo { margin:0 0 23px; color:var(--v-gris); font-size:14px; line-height:1.6; }
        .rep-grid { display:grid; grid-template-columns:minmax(0,1.1fr) minmax(0,1fr); gap:18px; align-items:start; }
        .rep-panel { background:#fff; border:1px solid var(--v-borde); border-radius:15px; padding:22px; box-shadow:0 5px 20px rgba(15,56,43,.045); }
        .rep-panel h2 { margin:0 0 15px; font-size:18px; color:var(--v-verde); }
        .rep-campo { display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
        .rep-campo label { font-size:13px; font-weight:700; color:#3c5e4e; }
        .rep-campo input, .rep-campo select, .rep-campo textarea { font:inherit; font-size:14px; padding:10px 12px; border:1px solid #cfdcd5; border-radius:9px; background:#fff; }
        .rep-campo textarea { min-height:120px; resize:vertical; }
        .rep-ayuda { font-size:12px; color:var(--v-gris); }
        .rep-boton { border:0; border-radius:10px; background:var(--v-verde); color:#fff; font-weight:700; font-size:14px; padding:12px 18px; cursor:pointer; }
        .rep-boton:hover { background:#18513e; }
        .rep-alerta { border-radius:12px; padding:14px 16px; margin-bottom:18px; font-size:14px; line-height:1.5; }
        .rep-alerta-ok { background:#e3f5eb; color:#087346; border:1px solid #bfe5d0; }
        .rep-alerta-error { background:#fdecec; color:#9b1c1c; border:1px solid #f5c2c2; }
        .rep-alerta ul { margin:6px 0 0 18px; padding:0; }
        .rep-lista { list-style:none; margin:0; padding:0; display:grid; gap:12px; }
        .rep-item { border:1px solid var(--v-borde); border-radius:12px; padding:14px; }
        .rep-item-cabecera { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:6px; }
        .rep-item strong { color:var(--v-verde); font-size:14px; }
        .rep-item p { margin:4px 0; font-size:13px; color:#3c5e4e; line-height:1.5; }
        .rep-meta { font-size:12px; color:var(--v-gris); }
        .rep-badge { display:inline-block; padding:4px 9px; border-radius:100px; font-size:11px; font-weight:700; background:#eef2f0; color:#4a6356; white-space:nowrap; }
        .rep-badge.pendiente { background:#fff4d6; color:#8a5a00; }
        .rep-badge.en-proceso { background:#e3edf9; color:#1d4f91; }
        .rep-badge.completada { background:#e3f5eb; color:#087346; }
        .rep-badge.cancelada { background:#f1f1f1; color:#666; }
        .rep-vacio { color:var(--v-gris); font-size:14px; }
        @media (max-width:900px) { .rep-grid { grid-template-columns:1fr; } .rep-contenido { padding:24px 20px; } }
        @media (max-width:650px) { .diseño { flex-direction:column; } .rep-contenido { padding:20px 15px 35px; } }
    </style>
</head>
<body>
<div class="diseño">
    <?php $menuActivo = 'solicitar_reparacion'; include __DIR__ . '/menu_lateral.php'; ?>
    <main class="rep-contenido">
        <header class="rep-cabecera">
            <h1>Solicitar una reparación</h1>
            <p class="rep-subtitulo">Hola, <?= rep_esc($cliente['nombres']) ?>. Describe el daño o la avería y te contactaremos para coordinar la reparación.</p>
        </header>

        <?php if ($flash !== ''): ?>
            <div class="rep-alerta rep-alerta-ok" role="status"><?= rep_esc($flash) ?></div>
        <?php endif; ?>

        <?php if ($errores): ?>
            <div class="rep-alerta rep-alerta-error" role="alert">
                <strong>Revisa el formulario:</strong>
                <ul><?php foreach ($errores as $error): ?><li><?= rep_esc($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="rep-grid">
            <section class="rep-panel" aria-labelledby="titulo-formulario">
                <h2 id="titulo-formulario">Datos de la reparación</h2>

                <?php if (!$apartamentos): ?>
                    <p class="rep-vacio">Solo puedes solicitar reparaciones de propiedades con solicitud aprobada. Cuando tengas una propiedad aprobada aparecerá aquí.</p>
                <?php else: ?>
                    <form method="post" action="solicitar_reparacion.php">
                        <input type="hidden" name="csrf" value="<?= rep_esc($csrf) ?>">

                        <div class="rep-campo">
                            <label for="apartamento_id">Propiedad</label>
                            <select id="apartamento_id" name="apartamento_id" required>
                                <option value="">Selecciona una propiedad</option>
                                <?php foreach ($apartamentos as $ap): ?>
                                    <option value="<?= (int)$ap['id_apartamentos'] ?>" <?= (string)$ap['id_apartamentos'] === $valores['apartamento_id'] ? 'selected' : '' ?>>
                                        <?= rep_esc($ap['edificio'] . ' · Apartamento ' . $ap['numero'] . ' (' . $ap['condominio'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rep-campo">
                            <label for="ubicacion">Ubicación del daño</label>
                            <input id="ubicacion" name="ubicacion" type="text" maxlength="150" value="<?= rep_esc($valores['ubicacion']) ?>" placeholder="Ej.: Cocina, baño principal, pasillo">
                        </div>

                        <div class="rep-campo">
                            <label for="prioridad">Prioridad</label>
                            <select id="prioridad" name="prioridad">
                                <?php foreach (REP_PRIORIDADES as $prioridad): ?>
                                    <option value="<?= rep_esc($prioridad) ?>" <?= $prioridad === $valores['prioridad'] ? 'selected' : '' ?>><?= rep_esc($prioridad) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rep-campo">
                            <label for="descripcion">Descripción del problema</label>
                            <textarea id="descripcion" name="descripcion" maxlength="2000" required placeholder="Describe qué pasó, desde cuándo y cualquier detalle que ayude a la reparación."><?= rep_esc($valores['descripcion']) ?></textarea>
                            <span class="rep-ayuda">Mínimo 10 caracteres, máximo 2000.</span>
                        </div>

                        <button type="submit" class="rep-boton">Enviar solicitud</button>
                    </form>
                <?php endif; ?>
            </section>

            <div>
                <section class="rep-panel" aria-labelledby="titulo-mis-solicitudes" style="margin-bottom:18px;">
                    <h2 id="titulo-mis-solicitudes">Mis solicitudes</h2>
                    <?php if (!$solicitudes): ?>
                        <p class="rep-vacio">Aún no has enviado solicitudes de reparación.</p>
                    <?php else: ?>
                        <ul class="rep-lista">
                            <?php foreach ($solicitudes as $s):
                                $claseEstado = strtolower(str_replace(' ', '-', (string)$s['estado']));
                            ?>
                                <li class="rep-item">
                                    <div class="rep-item-cabecera">
                                        <strong>Solicitud #<?= (int)$s['id_solicitudes'] ?></strong>
                                        <span class="rep-badge <?= rep_esc($claseEstado) ?>"><?= rep_esc($s['estado']) ?></span>
                                    </div>
                                    <p><?= rep_esc($s['edificio'] . ' · Apartamento ' . $s['numero']) ?><?= $s['ubicacion'] ? ' · ' . rep_esc($s['ubicacion']) : '' ?></p>
                                    <p><?= nl2br(rep_esc($s['descripcion'])) ?></p>
                                    <span class="rep-meta">Prioridad <?= rep_esc($s['prioridad']) ?> · <?= rep_esc(date('d/m/Y H:i', strtotime($s['fecha_solicitud']))) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="rep-panel" aria-labelledby="titulo-avisos">
                    <h2 id="titulo-avisos">Avisos</h2>
                    <?php if (!$notificaciones): ?>
                        <p class="rep-vacio">No tienes avisos de reparaciones.</p>
                    <?php else: ?>
                        <ul class="rep-lista">
                            <?php foreach ($notificaciones as $n): ?>
                                <li class="rep-item">
                                    <div class="rep-item-cabecera">
                                        <strong><?= rep_esc($n['titulo']) ?></strong>
                                    </div>
                                    <p><?= rep_esc($n['mensaje']) ?></p>
                                    <span class="rep-meta"><?= rep_esc(date('d/m/Y H:i', strtotime($n['fecha_envio']))) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
</div>
</body>
</html>
