<?php
// Consulta de clientes: personas registradas, con su cuenta de acceso y propiedades aprobadas.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../conf/conexion.php';

if (empty($_SESSION['logged_in'])) { header('Location: ../login/login.php'); exit; }

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$idUsuario = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);
$usuario = (string)($_SESSION['usuario'] ?? '');
$menuActivo = 'clientes';

$q = trim((string)($_GET['q'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));
$cuenta = trim((string)($_GET['cuenta'] ?? ''));

if (!in_array($estado, ['activo', 'inactivo'], true)) $estado = '';
if (!in_array($cuenta, ['si', 'no'], true)) $cuenta = '';

$tieneCuenta = "EXISTS (SELECT 1 FROM usuario u WHERE u.persona_id = p.id_personas AND u.activo = 1)";
$tienePropiedad = "EXISTS (SELECT 1 FROM solicitud_propiedad sp WHERE sp.persona_id = p.id_personas AND sp.estado = 'Aprobada' AND sp.activo = 1)";

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

    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(CONCAT_WS(' ', p.nombres, p.apellidos) LIKE ? OR p.cedula LIKE ? OR p.correo LIKE ? OR p.telefono LIKE ?)";
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if ($estado === 'activo') $where[] = 'p.activo = 1';
    if ($estado === 'inactivo') $where[] = 'p.activo = 0';
    if ($cuenta === 'si') $where[] = $tieneCuenta;
    if ($cuenta === 'no') $where[] = "NOT $tieneCuenta";
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $conexion->prepare("SELECT COUNT(*) AS total,
                                     COALESCE(SUM(p.activo = 1), 0) AS activos,
                                     COALESCE(SUM($tieneCuenta), 0) AS con_cuenta,
                                     COALESCE(SUM($tienePropiedad), 0) AS con_propiedad
                              FROM persona p $whereSql");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $resumen = $st->get_result()->fetch_assoc();
    $st->close();

    $st = $conexion->prepare("SELECT p.id_personas, p.nombres, p.apellidos, p.cedula, p.correo, p.telefono, p.direccion,
                                     p.fecha_registro, p.activo,
                                     (SELECT u.usuario FROM usuario u WHERE u.persona_id = p.id_personas AND u.activo = 1
                                      ORDER BY u.id_usuarios LIMIT 1) AS cuenta_usuario,
                                     (SELECT r.nombre FROM usuario u INNER JOIN role r ON r.id_roles = u.id_rol
                                      WHERE u.persona_id = p.id_personas AND u.activo = 1
                                      ORDER BY u.id_usuarios LIMIT 1) AS rol,
                                     (SELECT COUNT(*) FROM solicitud_propiedad sp
                                      WHERE sp.persona_id = p.id_personas AND sp.estado = 'Aprobada' AND sp.activo = 1) AS propiedades
                              FROM persona p
                              $whereSql
                              ORDER BY p.apellidos, p.nombres, p.id_personas
                              LIMIT 300");
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $clientes = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} catch (Throwable $ex) {
    error_log('VIORA clientes_consulta: ' . $ex->getMessage());
    http_response_code(500);
    exit('No se pudieron consultar los clientes.');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Consulta de clientes | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=11">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#153b30;font-family:Arial,sans-serif}
.diseño{display:flex;min-height:100vh}.cl-contenido{flex:1;min-width:0;padding:30px}
.cl-encabezado{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:23px}
.cl-encabezado h1{font-size:26px;margin:0 0 8px;color:#0f382b}.cl-encabezado p{margin:0;color:#6d8076;font-size:14px}
.cl-panel{background:white;border:1px solid #dce8e1;border-radius:14px;padding:19px;margin-bottom:20px}
.cl-panel h2{font-size:18px;margin:0 0 12px}
.cl-filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.cl-filtros label{display:flex;flex-direction:column;font-size:12px;color:#365b4b;gap:4px}
.cl-filtros input,.cl-filtros select{padding:9px;border:1px solid #dce8e1;border-radius:8px;font:inherit;font-size:13px;min-width:160px}
.cl-resumen{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.cl-tarjeta{flex:1;min-width:180px;background:white;border:1px solid #dce8e1;border-radius:12px;padding:14px}
.cl-tarjeta span{display:block;font-size:12px;color:#6d8076}.cl-tarjeta strong{font-size:18px;color:#0f382b}
.cl-tabla-contenedor{overflow-x:auto}.cl-tabla{width:100%;border-collapse:collapse;min-width:900px}
.cl-tabla th{text-align:left;padding:12px;background:#eef5f1;color:#365b4b;font-size:12px}
.cl-tabla td{padding:12px;border-bottom:1px solid #edf1ee;font-size:13px;vertical-align:middle}
.cl-tabla td.num,.cl-tabla th.num{text-align:right}
.cl-etiqueta{display:inline-block;padding:5px 10px;border-radius:99px;font-weight:700;font-size:12px;background:#edf0fa;color:#4b5686}
.cl-etiqueta.activo{background:#e5f4ec;color:#176546}.cl-etiqueta.inactivo{background:#ffe5e5;color:#bd2929}
.cl-enlace{color:#0f382b;font-weight:700;text-decoration:none;margin-right:14px}
.cl-boton{display:inline-block;border:0;border-radius:9px;background:#0f382b;color:white;padding:9px 12px;font-weight:700;text-decoration:none;cursor:pointer;font-size:13px}
.cl-boton:hover{background:#19543e}
.cl-vacio{text-align:center;padding:30px;color:#6b8074}
</style>
</head>
<body>
<div class="diseño">
<?php include __DIR__ . '/menu_lateral.php'; ?>
<main class="cl-contenido">

    <header class="cl-encabezado">
        <div>
            <h1>Consulta de clientes</h1>
            <p>Personas registradas, con su cuenta de acceso y propiedades aprobadas. Se muestran hasta 300 registros.</p>
        </div>
        <div>
            <a class="cl-enlace" href="consulta_cobros.php">Consulta de cobros</a>
        </div>
    </header>

    <section class="cl-panel">
        <h2>Filtros</h2>
        <form method="get" action="clientes_consulta.php" class="cl-filtros">
            <label>Nombre, cédula, correo o teléfono
                <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Ej. Juan, 001-...">
            </label>
            <label>Estado
                <select name="estado">
                    <option value="">Todos</option>
                    <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activo</option>
                    <option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
                </select>
            </label>
            <label>Con cuenta de acceso
                <select name="cuenta">
                    <option value="">Todos</option>
                    <option value="si" <?= $cuenta === 'si' ? 'selected' : '' ?>>Sí</option>
                    <option value="no" <?= $cuenta === 'no' ? 'selected' : '' ?>>No</option>
                </select>
            </label>
            <button type="submit" class="cl-boton">Buscar</button>
            <a class="cl-enlace" href="clientes_consulta.php">Limpiar</a>
        </form>
    </section>

    <div class="cl-resumen">
        <div class="cl-tarjeta"><span>Personas</span><strong><?= (int)$resumen['total'] ?></strong></div>
        <div class="cl-tarjeta"><span>Activas</span><strong><?= (int)$resumen['activos'] ?></strong></div>
        <div class="cl-tarjeta"><span>Con cuenta de acceso</span><strong><?= (int)$resumen['con_cuenta'] ?></strong></div>
        <div class="cl-tarjeta"><span>Con propiedad aprobada</span><strong><?= (int)$resumen['con_propiedad'] ?></strong></div>
    </div>

    <section class="cl-panel">
        <div class="cl-tabla-contenedor">
        <table class="cl-tabla">
            <thead>
                <tr>
                    <th>Cliente</th><th>Cédula</th><th>Contacto</th><th>Dirección</th>
                    <th>Cuenta</th><th class="num">Propiedades</th><th>Registro</th><th>Estado</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$clientes): ?>
                <tr><td colspan="9" class="cl-vacio">No hay clientes que coincidan con los filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($clientes as $c): ?>
                <tr>
                    <td><strong><?= esc(trim($c['nombres'] . ' ' . $c['apellidos'])) ?: '—' ?></strong></td>
                    <td><?= esc($c['cedula']) ?: '—' ?></td>
                    <td><?= esc($c['telefono']) ?: '—' ?><br><small><?= esc($c['correo']) ?></small></td>
                    <td><?= esc($c['direccion']) ?: '—' ?></td>
                    <td><?php if ($c['cuenta_usuario'] !== null): ?>
                            <?= esc($c['cuenta_usuario']) ?><br><small><?= esc($c['rol']) ?></small>
                        <?php else: ?>
                            <small>Sin cuenta</small>
                        <?php endif; ?></td>
                    <td class="num"><?= (int)$c['propiedades'] ?></td>
                    <td><?= esc(substr((string)$c['fecha_registro'], 0, 10)) ?></td>
                    <td><span class="cl-etiqueta <?= (int)$c['activo'] === 1 ? 'activo' : 'inactivo' ?>"><?= (int)$c['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                    <td><?php if ($c['cedula'] !== null && $c['cedula'] !== ''): ?>
                            <a class="cl-enlace" href="consulta_cobros.php?q=<?= urlencode($c['cedula']) ?>">Ver cobros</a>
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

</main>
</div>
</body>
</html>
