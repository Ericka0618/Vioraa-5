<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../conf/conexion.php';

if (empty($_SESSION['logged_in'])) {
    header('Location: ../login/login.php');
    exit;
}

function h($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$nombreUsuario = (string)($_SESSION['usuario'] ?? '');
if ($idUsuario <= 0 && $nombreUsuario === '') {
    http_response_code(403);
    exit('No se pudo identificar la sesión. Inicia sesión nuevamente.');
}

try {
    if ($idUsuario > 0) {
        $stmt = $conexion->prepare("SELECT p.id_personas, p.nombres, p.apellidos, r.nombre AS rol_real
            FROM usuario u
            INNER JOIN persona p ON p.id_personas = u.persona_id
            INNER JOIN role r ON r.id_roles = u.id_rol
            WHERE u.id_usuarios = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1");
        $stmt->bind_param('i', $idUsuario);
    } else {
        $stmt = $conexion->prepare("SELECT p.id_personas, p.nombres, p.apellidos, r.nombre AS rol_real
            FROM usuario u
            INNER JOIN persona p ON p.id_personas = u.persona_id
            INNER JOIN role r ON r.id_roles = u.id_rol
            WHERE u.usuario = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1");
        $stmt->bind_param('s', $nombreUsuario);
    }
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

    // El mantenimiento pertenece al contrato vinculado a la solicitud.
    // Se comprueban cliente, apartamento, operación y contrato activo.
    // Si no existe contrato coincidente, el valor será NULL.
    $stmt = $conexion->prepare("SELECT
            sp.id_solicitudes,
            sp.apartamento_id,
            sp.tipo_operacion,
            sp.fecha_solicitud,
            a.numero,
            a.piso,
            a.habitaciones,
            a.banos,
            a.precio_alquiler,
            (SELECT ct.monto_mantenimiento
             FROM contrato ct
             WHERE ct.solicitud_id = sp.id_solicitudes
               AND ct.apartamento_id = sp.apartamento_id
               AND ct.persona_id = sp.persona_id
               AND ct.tipo_operacion = sp.tipo_operacion
               AND ct.activo = 1
               AND ct.estado = 'Activo'
             ORDER BY ct.id_contratos DESC
             LIMIT 1) AS cuota_mantenimiento,
            e.nombre AS edificio,
            c.nombre AS condominio
        FROM solicitud_propiedad sp
        INNER JOIN apartamento a ON a.id_apartamentos = sp.apartamento_id
        INNER JOIN edificio e ON e.id_edificios = a.edificio_id
        INNER JOIN condominio c ON c.id_condominios = e.condominio_id
        WHERE sp.persona_id = ?
          AND sp.estado = 'Aprobada'
          AND sp.activo = 1
        ORDER BY sp.fecha_solicitud DESC, sp.id_solicitudes DESC");
    $stmt->bind_param('i', $idPersona);
    $stmt->execute();
    $propiedades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $error) {
    error_log('VIORA administrar_propiedad: ' . $error->getMessage());
    http_response_code(500);
    exit('No se pudieron consultar tus propiedades. Revisa el registro de errores de PHP.');
}

$totalAlquileres = 0;
$totalCompras = 0;
foreach ($propiedades as $propiedad) {
    if ($propiedad['tipo_operacion'] === 'Alquiler') {
        $totalAlquileres++;
    } else {
        $totalCompras++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrar mi propiedad | VIORA</title>
    <link rel="stylesheet" href="../estilos/styleP.css?v=12">
    <style>
        :root { --v-verde:#0f382b; --v-fondo:#f4f6f5; --v-borde:#dfe9e3; --v-gris:#667c70; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--v-fondo); color:#203f33; font-family:Arial,sans-serif; }
        .diseño { display:flex; min-height:100vh; width:100%; align-items:stretch; }
        .diseño > .barra-lateral { flex-shrink:0; }
        .propiedad-contenido { flex:1; min-width:0; padding:30px 32px 55px; }
        .propiedad-cabecera { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:23px; }
        .propiedad-cabecera h1 { margin:0 0 8px; font-size:26px; color:var(--v-verde); }
        .propiedad-subtitulo { margin:0; color:var(--v-gris); font-size:14px; line-height:1.6; }
        .propiedad-enlace { display:inline-flex; align-items:center; justify-content:center; text-decoration:none; padding:12px 17px; border-radius:10px; background:var(--v-verde); color:#fff; font-weight:700; font-size:13px; }
        .propiedad-enlace:hover { background:#18513e; color:#fff; }
        .propiedad-resumen { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin-bottom:24px; }
        .propiedad-indicador { background:#fff; border:1px solid var(--v-borde); border-radius:14px; padding:20px; }
        .propiedad-indicador strong { display:block; font-size:28px; color:var(--v-verde); margin-bottom:5px; }
        .propiedad-indicador span { font-size:13px; color:var(--v-gris); }
        .propiedad-seccion { margin:0 0 17px; font-size:19px; color:var(--v-verde); }
        .propiedad-tarjetas { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr)); gap:17px; align-items:stretch; }
        .propiedad-tarjeta { background:#fff; border:1px solid var(--v-borde); border-radius:15px; padding:22px; box-shadow:0 5px 20px rgba(15,56,43,.045); min-width:0; }
        .propiedad-tarjeta h2 { color:var(--v-verde); font-size:19px; line-height:1.35; margin:14px 0 8px; }
        .propiedad-etiqueta { display:inline-flex; background:#e3f5eb; color:#087346; border-radius:100px; padding:7px 11px; font-size:12px; font-weight:700; }
        .propiedad-ubicacion { color:var(--v-gris); font-size:14px; margin:0 0 16px; }
        .propiedad-detalles { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
        .propiedad-detalles span { background:#f3f7f5; padding:8px 10px; border-radius:8px; color:#536e60; font-size:12px; }
        .propiedad-pagos { border-top:1px solid var(--v-borde); padding-top:15px; margin-top:15px; }
        .propiedad-pagos h3 { color:var(--v-verde); font-size:17px; margin:4px 0 15px; }
        .propiedad-fila { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin:12px 0; font-size:14px; }
        .propiedad-fila strong { text-align:right; color:var(--v-verde); }
        .propiedad-aviso { background:#eff5f1; border-radius:10px; padding:13px; color:#3c5e4e; font-size:13px; line-height:1.6; margin:16px 0 0; }
        .propiedad-vacio { background:#fff; border:1px solid var(--v-borde); border-radius:14px; padding:32px; color:var(--v-gris); }
        .propiedad-vacio a { color:var(--v-verde); font-weight:700; }
        @media (max-width:900px) { .propiedad-contenido { padding:24px 20px; } }
        @media (max-width:650px) { .diseño { flex-direction:column; } .propiedad-resumen { grid-template-columns:1fr; } .propiedad-contenido { padding:20px 15px 35px; } }
    </style>
</head>
<body>
<div class="diseño">
    <?php $menuActivo = 'administrar_propiedad'; include __DIR__ . '/menu_lateral.php'; ?>
    <main class="propiedad-contenido">
        <header class="propiedad-cabecera">
            <div>
                <h1>Administrar mi propiedad</h1>
                <p class="propiedad-subtitulo">Hola, <?= h($cliente['nombres']) ?>. Consulta las propiedades que tienes aprobadas en VIORA.</p>
            </div>
            <a class="propiedad-enlace" href="administrar_solicitudes.php">Ver mis solicitudes →</a>
        </header>

        <section class="propiedad-resumen" aria-label="Resumen de propiedades">
            <div class="propiedad-indicador"><strong><?= count($propiedades) ?></strong><span>Propiedades aprobadas</span></div>
            <div class="propiedad-indicador"><strong><?= $totalAlquileres ?></strong><span>Solicitudes de alquiler aprobadas</span></div>
            <div class="propiedad-indicador"><strong><?= $totalCompras ?></strong><span>Solicitudes de compra aprobadas</span></div>
        </section>

        <h2 class="propiedad-seccion">Mis propiedades aprobadas</h2>
        <?php if (!$propiedades): ?>
            <div class="propiedad-vacio">Todavía no tienes propiedades aprobadas. <a href="catalogo.php">Explorar propiedades disponibles</a>.</div>
        <?php else: ?>
            <section class="propiedad-tarjetas" aria-label="Listado de propiedades aprobadas">
                <?php foreach ($propiedades as $p): ?>
                    <article class="propiedad-tarjeta">
                        <span class="propiedad-etiqueta">✓ <?= $p['tipo_operacion'] === 'Alquiler' ? 'Alquiler aprobado' : 'Compra aprobada' ?></span>
                        <h2><?= h($p['edificio']) ?> · Apartamento <?= h($p['numero']) ?></h2>
                        <p class="propiedad-ubicacion"><?= h($p['condominio']) ?></p>
                        <div class="propiedad-detalles">
                            <?php if ($p['piso'] !== null): ?><span>Piso <?= h($p['piso']) ?></span><?php endif; ?>
                            <?php if ($p['habitaciones'] !== null): ?><span><?= h($p['habitaciones']) ?> habitaciones</span><?php endif; ?>
                            <?php if ($p['banos'] !== null): ?><span><?= h($p['banos']) ?> baños</span><?php endif; ?>
                        </div>
                        <?php if ($p['tipo_operacion'] === 'Alquiler'): ?>
                            <div class="propiedad-pagos">
                                <h3>Pagos de la propiedad</h3>
                                <div class="propiedad-fila"><span>Renta mensual</span><strong><?= $p['precio_alquiler'] === null ? 'Por definir' : 'RD$ ' . number_format((float)$p['precio_alquiler'], 2) ?></strong></div>
                                <div class="propiedad-fila"><span>Mantenimiento mensual</span><strong><?= ($p['cuota_mantenimiento'] === null || (float)$p['cuota_mantenimiento'] <= 0) ? 'Por definir' : 'RD$ ' . number_format((float)$p['cuota_mantenimiento'], 2) ?></strong></div>
                            </div>
                        <?php else: ?>
                            <div class="propiedad-pagos">
                                <h3>Mantenimiento de la propiedad</h3>
                                <div class="propiedad-fila"><span>Mantenimiento mensual</span><strong><?= ($p['cuota_mantenimiento'] === null || (float)$p['cuota_mantenimiento'] <= 0) ? 'Por definir' : 'RD$ ' . number_format((float)$p['cuota_mantenimiento'], 2) ?></strong></div>
                            </div>
                            <p class="propiedad-aviso">Esta solicitud de compra fue aprobada. No se aplica renta mensual. El mantenimiento se consulta del contrato activo correspondiente; los cobros aún no están habilitados.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
