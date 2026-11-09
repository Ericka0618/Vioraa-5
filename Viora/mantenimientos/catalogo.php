<?php

session_start();

require_once '../conf/conexion.php';

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {

    header('Location: ../login/login.php');

    exit;

}

$nombre = $_SESSION['nombre_usuario'] ?? 'Usuario';

$usuario = $_SESSION['usuario'] ?? '';

$menuActivo = 'catalogo';

if (empty($_SESSION['csrf_catalogo'])) {
    $_SESSION['csrf_catalogo'] = bin2hex(random_bytes(32));
}

function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function fotoApartamento($archivo) {

    if (!is_string($archivo) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/i', $archivo)) return '';

    return '../uploads/apartamentos/' . rawurlencode($archivo);

}

// Identificar al cliente autenticado desde la tabla usuario.

// La relación con persona se obtiene en el servidor, nunca desde el formulario.

$clienteActual = null;

$idSesion = (int)($_SESSION['id_usuarios'] ?? $_SESSION['id_usuario'] ?? 0);

if ($idSesion > 0) {

    $stmtCliente = $conexion->prepare(

        'SELECT p.id_personas, p.nombres, p.apellidos

         FROM usuario u INNER JOIN persona p ON p.id_personas = u.persona_id

         WHERE u.id_usuarios = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1'

    );

    $stmtCliente->bind_param('i', $idSesion);

    $stmtCliente->execute();

    $clienteActual = $stmtCliente->get_result()->fetch_assoc();

} else if ($usuario !== '') {

    $stmtCliente = $conexion->prepare(

        'SELECT p.id_personas, p.nombres, p.apellidos

         FROM usuario u INNER JOIN persona p ON p.id_personas = u.persona_id

         WHERE u.usuario = ? AND u.activo = 1 AND p.activo = 1 LIMIT 1'

    );

    $stmtCliente->bind_param('s', $usuario);

    $stmtCliente->execute();

    $clienteActual = $stmtCliente->get_result()->fetch_assoc();

}

// El permiso se obtiene de la BD, no de un parámetro enviado por el navegador.
$esAdministrador = false;
if ($idSesion > 0) {
    $stRol = $conexion->prepare("SELECT r.nombre FROM usuario u INNER JOIN role r ON r.id_roles=u.id_rol WHERE u.id_usuarios=? AND u.activo=1 LIMIT 1");
    $stRol->bind_param('i', $idSesion);
    $stRol->execute();
    $rolActual = $stRol->get_result()->fetch_assoc();
    $esAdministrador = in_array(mb_strtolower(trim($rolActual['nombre'] ?? '')), ['admin','administrador'], true);
}

// Registrar solicitudes desde la ficha del catálogo.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'solicitud') {

    header('Content-Type: application/json; charset=utf-8');

    try {

        if (!hash_equals($_SESSION['csrf_catalogo'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new Exception('La sesión del formulario ha expirado. Actualiza la página.');
        }
        $tipoSolicitud = $_POST['tipo_solicitud'] ?? '';

        $apartamentoId = filter_var($_POST['apartamento_id'] ?? null, FILTER_VALIDATE_INT);

        $personaId = (int)($clienteActual['id_personas'] ?? 0);

        $operacion = $_POST['operacion'] ?? '';

        $fecha = trim($_POST['fecha_visita'] ?? '');

        $hora = trim($_POST['hora_visita'] ?? '');

        $observaciones = trim($_POST['observaciones'] ?? '');

        if (!$personaId) throw new Exception('Tu cuenta no está vinculada a un cliente activo. Contacta al administrador.');

        if (!in_array($tipoSolicitud, ['visita', 'operacion'], true) || !$apartamentoId) {

            throw new Exception('Completa los datos obligatorios.');

        }

        if (mb_strlen($observaciones) > 500) throw new Exception('Las observaciones son demasiado largas.');

        $check = $conexion->prepare("SELECT tipo_operacion FROM apartamento WHERE id_apartamentos = ? AND activo = 1 AND disponible = 1 AND estado = 'Disponible' AND NOT EXISTS (SELECT 1 FROM solicitud_propiedad sp WHERE sp.apartamento_id = apartamento.id_apartamentos AND sp.estado = 'Aprobada') LIMIT 1");

        $check->bind_param('i', $apartamentoId);

        $check->execute();

        $apartamento = $check->get_result()->fetch_assoc();

        if (!$apartamento) throw new Exception('El apartamento ya no está disponible.');

        $check = $conexion->prepare('SELECT id_personas FROM persona WHERE id_personas = ? AND activo = 1 LIMIT 1');

        $check->bind_param('i', $personaId);

        $check->execute();

        if (!$check->get_result()->fetch_assoc()) throw new Exception('Selecciona un cliente activo.');

        if ($tipoSolicitud === 'visita') {

            $fechaHora = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $fecha . ' ' . $hora);

            if (!$fechaHora || $fechaHora->format('Y-m-d H:i') !== $fecha . ' ' . $hora || $fechaHora <= new DateTimeImmutable()) {

                throw new Exception('Selecciona una fecha y hora futura válida.');

            }

            $stmt = $conexion->prepare('INSERT INTO visita_propiedad (apartamento_id, persona_id, fecha_visita, observaciones) VALUES (?, ?, ?, ?)');

            $fechaSQL = $fechaHora->format('Y-m-d H:i:s');

            $stmt->bind_param('iiss', $apartamentoId, $personaId, $fechaSQL, $observaciones);

        } else {

            if (!in_array($operacion, ['Alquiler', 'Venta'], true) || !in_array($apartamento['tipo_operacion'], [$operacion, 'Ambos'], true)) {

                throw new Exception('La operación seleccionada no está disponible para esta propiedad.');

            }

            $stmt = $conexion->prepare('INSERT INTO solicitud_propiedad (apartamento_id, persona_id, tipo_operacion, observaciones) VALUES (?, ?, ?, ?)');

            $stmt->bind_param('iiss', $apartamentoId, $personaId, $operacion, $observaciones);

        }

        $stmt->execute();

        echo json_encode([
            'ok' => true,
            'msg' => 'Solicitud enviada correctamente.',
            'tipo' => $tipoSolicitud,
            'operacion' => $tipoSolicitud === 'operacion' ? $operacion : ''
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {

        http_response_code($e instanceof mysqli_sql_exception ? 500 : 400);
        $mensaje = $e instanceof mysqli_sql_exception ? 'No se pudo registrar la solicitud. Verifica que hayas creado las tablas SQL.' : $e->getMessage();

        echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);

    }

    exit;

}

$sql = "SELECT a.id_apartamentos, a.numero, a.piso, a.habitaciones, a.banos,

               a.metros_cuadrados, a.descripcion, a.precio_alquiler, a.precio_venta, a.tipo_operacion, a.imagen,

               e.nombre AS edificio, c.nombre AS condominio

        FROM apartamento a

        INNER JOIN edificio e ON e.id_edificios = a.edificio_id

        INNER JOIN condominio c ON c.id_condominios = e.condominio_id

        WHERE a.activo = 1 AND a.disponible = 1 AND a.estado = 'Disponible'
          AND NOT EXISTS (SELECT 1 FROM solicitud_propiedad sp WHERE sp.apartamento_id = a.id_apartamentos AND sp.estado = 'Aprobada')

        ORDER BY a.id_apartamentos DESC";

$resultado = $conexion->query($sql);

$apartamentos = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];

$condominios = [];

foreach ($apartamentos as $ap) $condominios[$ap['condominio']] = true;

$condominios = array_keys($condominios);

sort($condominios, SORT_NATURAL | SORT_FLAG_CASE);

?>

<!DOCTYPE html>

<html lang="es">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Catálogo de Propiedades | VIORA</title>

<link rel="stylesheet" href="../estilos/styleP.css?v=3">


<style>

:root{--verde:#0c392b;--fondo:#f4f6f5;--borde:#e3ebe7;--gris:#64756f}

.catalogo{padding-bottom:40px}.catalogo h1,.catalogo h2,.catalogo h3{color:#133b30}

.catalogo-encabezado{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px}

.catalogo-encabezado h1{font-size:23px;margin:0 0 5px}.catalogo-encabezado p{margin:0;color:var(--gris);font-size:13px}

.catalogo-usuario{font-size:13px;color:var(--gris)}

.catalogo-panel{background:#fff;border:1px solid var(--borde);border-radius:15px;padding:16px;margin-bottom:16px;box-shadow:0 3px 14px rgba(12,57,43,.03)}

.catalogo-filtros{display:flex;align-items:center;gap:12px;flex-wrap:wrap}

.catalogo-filtros input,.catalogo-filtros select{border:1px solid #dce6e1;background:#fff;border-radius:9px;padding:11px 13px;color:#243d35;font:inherit;min-height:43px}

.catalogo-filtros input{flex:2;min-width:190px}.catalogo-filtros select{flex:1;min-width:150px}

.catalogo-boton{display:inline-flex;justify-content:center;align-items:center;gap:7px;padding:11px 17px;border-radius:9px;background:var(--verde);color:#fff;text-decoration:none;font-size:13px;font-weight:700;border:0;cursor:pointer;white-space:nowrap}

.catalogo-boton:hover{background:#165b45;color:#fff}

.catalogo-aviso{background:#eaf9f1;border:1px solid #d4eee0;border-radius:12px;padding:14px 17px;color:#235e46;font-size:13px;margin-bottom:17px}

.catalogo-resumen{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:18px 0 12px;color:var(--gris);font-size:13px}.catalogo-resumen strong{color:var(--verde);font-size:15px}

.catalogo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:17px}

.propiedad{background:#fff;border:1px solid var(--borde);border-radius:14px;overflow:hidden;box-shadow:0 3px 12px rgba(20,50,35,.04);min-width:0}

.propiedad-foto{height:205px;position:relative;background:#e8eeea;overflow:hidden}

.propiedad-foto img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s}.propiedad:hover .propiedad-foto img{transform:scale(1.025)}

.propiedad-sin-foto{height:100%;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:7px;color:#7b9588;font-size:13px}

.propiedad-etiqueta{position:absolute;top:12px;left:12px;background:#fff;padding:6px 10px;border-radius:7px;color:#20513f;font-weight:700;font-size:11px}

.propiedad-disponible{position:absolute;top:12px;right:12px;background:#e8fff3;padding:6px 10px;border-radius:7px;color:#087747;font-weight:700;font-size:11px}

.propiedad-cuerpo{padding:15px}.propiedad-codigo{font-size:10px;color:#85968e;margin-bottom:7px}

.propiedad-titulo{font-size:15px;line-height:1.4;margin:0 0 8px;font-weight:750}

.propiedad-ubicacion,.propiedad-datos{font-size:12px;color:#6c8075;margin:6px 0;line-height:1.5}

.propiedad-precio{font-size:20px;font-weight:800;color:var(--verde);margin:13px 0}.propiedad-precio small{font-size:11px;color:#899990;font-weight:400}

.propiedad-ver{display:block;width:100%;padding:10px 12px;text-align:left;background:#fff;color:var(--verde);border:1px solid #dce8e2;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer}.propiedad-ver:hover{background:#eef8f2}

.catalogo-vacio{text-align:center;padding:55px 18px;background:#fff;border:1px solid var(--borde);border-radius:14px;color:var(--gris)}

.ficha-fondo{display:none;position:fixed;inset:0;background:rgba(8,30,23,.65);z-index:9999;align-items:center;justify-content:center;padding:20px}.ficha-fondo.abierto{display:flex}

.ficha{background:#fff;border-radius:15px;width:min(620px,100%);max-height:90vh;overflow-y:auto;position:relative}.ficha img{width:100%;height:265px;object-fit:cover;background:#e8eeea}

.ficha-cuerpo{padding:23px}.ficha-cuerpo h2{margin:0 0 8px}.ficha-cuerpo p{color:#61756a;line-height:1.6}.ficha-cerrar{position:absolute;top:12px;right:12px;background:#fff;border:0;border-radius:50%;width:35px;height:35px;cursor:pointer;font-size:20px;box-shadow:0 2px 8px #0002}

.ficha-detalles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:17px 0}.ficha-detalles div{background:#f4f7f5;padding:11px;border-radius:8px;font-size:13px;color:#304c40}

@media(max-width:1150px){.catalogo-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}

@media(max-width:700px){.catalogo-grid{grid-template-columns:1fr}.catalogo-encabezado{align-items:flex-start}.catalogo-filtros>*{width:100%;flex:auto}.propiedad-foto{height:220px}.ficha img{height:190px}}

.ficha-acciones{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e3ebe7}

.ficha-acciones button{flex:1;min-width:130px;padding:12px;border:1px solid #0c392b;border-radius:9px;background:#0c392b;color:white;font-weight:700;cursor:pointer}

.ficha-acciones button.secundario{background:white;color:#0c392b}

.solicitud-fondo{display:none;position:fixed;inset:0;background:rgba(8,30,23,.68);z-index:10001;align-items:center;justify-content:center;padding:15px}

.solicitud-fondo.abierto{display:flex}

.solicitud-caja{width:min(440px,100%);max-height:90vh;overflow:auto;background:white;border-radius:14px;padding:23px;position:relative}

.solicitud-caja h2{margin:0 0 16px;color:#133b30}

.solicitud-caja label{display:block;margin:12px 0 5px;font-weight:600;font-size:13px}

.solicitud-caja input,.solicitud-caja select,.solicitud-caja textarea{width:100%;padding:10px;border:1px solid #dce6e1;border-radius:8px;font:inherit;box-sizing:border-box}

.solicitud-caja .catalogo-boton{width:100%;margin-top:18px}

.solicitud-cliente{background:#f4f7f5;border:1px solid #dce6e1;border-radius:8px;padding:12px;color:#133b30;font-weight:600}

.solicitud-error{color:#a12727;font-size:13px;margin-top:10px}

</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>

<div class="diseño">

<?php include 'menu_lateral.php'; ?>

<main class="contenido catalogo">

  <header class="catalogo-encabezado">

    <div><h1>Propiedades Disponibles</h1><p>Catálogo de apartamentos disponibles para alquiler y venta en VIORA</p></div>

    <div class="catalogo-usuario"><?= esc($nombre) ?> · <?= esc($usuario) ?></div>

  </header>

  <section class="catalogo-panel" aria-label="Filtros de propiedades">

    <div class="catalogo-filtros">

      <input id="buscar" type="search" placeholder="Buscar apartamento, edificio o ubicación..." aria-label="Buscar propiedades">

      <select id="filtroOperacion" aria-label="Tipo de operación"><option value="">Alquiler y venta</option><option value="Alquiler">Alquiler</option><option value="Venta">Venta</option></select>

      <select id="filtroCondominio" aria-label="Filtrar por condominio"><option value="">Todos los condominios</option><?php foreach($condominios as $c): ?><option value="<?= esc($c) ?>"><?= esc($c) ?></option><?php endforeach; ?></select>

      <select id="orden" aria-label="Ordenar propiedades"><option value="recientes">Más recientes</option><option value="precio_asc">Menor precio</option><option value="precio_desc">Mayor precio</option></select>

      <?php if ($esAdministrador): ?>
      <a class="catalogo-boton" href="apartamento.php">+ Añadir apartamento</a>
      <?php endif; ?>


    </div>

  </section>

  <div class="catalogo-aviso">✓ Este catálogo se actualiza con los registros de Mantenimiento de Apartamentos. Solo muestra apartamentos activos, disponibles y con estado «Disponible».</div>

  <div class="catalogo-resumen"><strong id="contador"><?= count($apartamentos) ?> propiedades disponibles</strong><span>Precios en pesos dominicanos (RD$)</span></div>

  <section id="tarjetas" class="catalogo-grid" aria-label="Listado de apartamentos">

  <?php foreach($apartamentos as $ap):

    $foto = fotoApartamento($ap['imagen']);

    $precioAlquiler = $ap['precio_alquiler'] === null ? null : (float)$ap['precio_alquiler'];

    $precioVenta = $ap['precio_venta'] === null ? null : (float)$ap['precio_venta'];

    $tipo = $ap['tipo_operacion'];

    $precio = $tipo === 'Venta' ? $precioVenta : $precioAlquiler;

    $busqueda = $ap['numero'].' '.$ap['edificio'].' '.$ap['condominio'].' '.($ap['descripcion'] ?? '');

  ?>

  <article class="propiedad" data-id="<?= (int)$ap['id_apartamentos'] ?>" data-busqueda="<?= esc($busqueda) ?>" data-operacion="<?= esc($tipo) ?>" data-condominio="<?= esc($ap['condominio']) ?>" data-precio="<?= $precio === null ? '' : esc($precio) ?>">

    <div class="propiedad-foto">

      <?php if($foto): ?><img src="<?= esc($foto) ?>" alt="Apartamento <?= esc($ap['numero']) ?>" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><div class="propiedad-sin-foto" hidden>⌂<span>Imagen no disponible</span></div>

      <?php else: ?><div class="propiedad-sin-foto">⌂<span>Sin fotografía</span></div><?php endif; ?>

      <span class="propiedad-etiqueta"><?= esc($tipo === 'Ambos' ? 'Alquiler y venta' : $tipo) ?></span><span class="propiedad-disponible">● Disponible</span>

    </div>

    <div class="propiedad-cuerpo">

      <div class="propiedad-codigo">APT-<?= str_pad((string)$ap['id_apartamentos'],3,'0',STR_PAD_LEFT) ?></div>

      <h3 class="propiedad-titulo"><?= esc($ap['edificio']) ?> · Apartamento <?= esc($ap['numero']) ?></h3>

      <p class="propiedad-ubicacion">⌖ <?= esc($ap['condominio']) ?></p>

      <p class="propiedad-datos"><?= esc($ap['habitaciones'] ?? '—') ?> hab. · <?= esc($ap['banos'] ?? '—') ?> baños · <?= esc($ap['metros_cuadrados'] ?? '—') ?> m² · Piso <?= esc($ap['piso'] ?? '—') ?></p>

      <div class="propiedad-precio"><?php if($tipo !== 'Venta'): ?>

        <div><?= $precioAlquiler === null ? 'Alquiler a consultar' : 'RD$ '.number_format($precioAlquiler,2) ?> <small>/ mes</small></div>

      <?php endif; ?>

      <?php if($tipo !== 'Alquiler'): ?>

        <div><?= $precioVenta === null ? 'Venta a consultar' : 'RD$ '.number_format($precioVenta,2) ?> <small>venta</small></div>

      <?php endif; ?></div>

      <button type="button" class="propiedad-ver" data-ficha="<?= (int)$ap['id_apartamentos'] ?>">Ver ficha completa ↗</button>

    </div>

  </article>

  <?php endforeach; ?>

  </section>

  <div id="sinResultados" class="catalogo-vacio" <?= count($apartamentos) ? 'hidden' : '' ?>>No hay apartamentos disponibles que coincidan con los filtros.</div>

</main>

</div>

<div id="fichaFondo" class="ficha-fondo" role="presentation">

  <section class="ficha" role="dialog" aria-modal="true" aria-labelledby="fichaTitulo">

    <button type="button" id="cerrarFicha" class="ficha-cerrar" aria-label="Cerrar ficha">×</button>

    <div id="fichaImagen"></div>

    <div class="ficha-cuerpo"><h2 id="fichaTitulo"></h2><p id="fichaLugar"></p><div id="fichaPrecio" class="propiedad-precio"></div><div id="fichaDetalles" class="ficha-detalles"></div><p id="fichaDescripcion"></p><div class="ficha-acciones" id="fichaAcciones"></div></div>

  </section>

</div>

<div id="solicitudFondo" class="solicitud-fondo">

  <section class="solicitud-caja" role="dialog" aria-modal="true" aria-labelledby="solicitudTitulo">

    <button type="button" id="cerrarSolicitud" class="ficha-cerrar" aria-label="Cerrar">×</button>

    <h2 id="solicitudTitulo">Nueva solicitud</h2>

    <form id="formSolicitud">

      <input type="hidden" name="ajax" value="solicitud">
        <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_catalogo']) ?>">

      <input type="hidden" name="apartamento_id" id="solApartamento">

      <input type="hidden" name="tipo_solicitud" id="solTipo">

      <input type="hidden" name="operacion" id="solOperacion">

      <label>Cliente que solicita</label>

      <div class="solicitud-cliente" aria-live="polite">

        <?= $clienteActual ? esc($clienteActual['nombres'] . ' ' . $clienteActual['apellidos']) : 'Tu cuenta no tiene una persona activa asociada' ?>

      </div>

      <div id="camposVisita">

        <label for="solFecha">Fecha de visita *</label>

        <input id="solFecha" type="date" name="fecha_visita">

        <label for="solHora">Hora de visita *</label>

        <input id="solHora" type="time" name="hora_visita">

      </div>

      <label for="solObservaciones">Observaciones (opcional)</label>

      <textarea id="solObservaciones" name="observaciones" rows="3" maxlength="500" placeholder="Escribe algún detalle de la solicitud"></textarea>

      <p class="solicitud-error" id="solError" role="alert" hidden></p>

      <button type="submit" class="catalogo-boton" id="solGuardar" <?= $clienteActual ? '' : 'disabled' ?>>Enviar solicitud</button>

    </form>

  </section>

</div>

<script>

const propiedades = <?= json_encode(array_map(static function($a){

  return [

    'id'=>(int)$a['id_apartamentos'], 'numero'=>$a['numero'], 'edificio'=>$a['edificio'],

    'condominio'=>$a['condominio'], 'piso'=>$a['piso'], 'habitaciones'=>$a['habitaciones'],

    'banos'=>$a['banos'], 'metros'=>$a['metros_cuadrados'], 'descripcion'=>$a['descripcion'],

    'precioAlquiler'=>$a['precio_alquiler'] === null ? null : (float)$a['precio_alquiler'],

    'precioVenta'=>$a['precio_venta'] === null ? null : (float)$a['precio_venta'], 'tipo'=>$a['tipo_operacion'],

    'imagen'=>fotoApartamento($a['imagen'])

  ];

}, $apartamentos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const filtroOperacion=document.getElementById('filtroOperacion');

const buscar=document.getElementById('buscar'), filtro=document.getElementById('filtroCondominio'), orden=document.getElementById('orden');

const tarjetas=document.getElementById('tarjetas'), contador=document.getElementById('contador'), vacio=document.getElementById('sinResultados');

const normalizar=t=>(t||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();

function aplicarFiltros(){

  const q=normalizar(buscar.value.trim()), c=filtro.value;

  const items=Array.from(tarjetas.querySelectorAll('.propiedad'));

  items.sort((a,b)=>{

    if(orden.value==='precio_asc')return ((a.dataset.precio===''?Infinity:Number(a.dataset.precio))-(b.dataset.precio===''?Infinity:Number(b.dataset.precio)));

    if(orden.value==='precio_desc')return (Number(b.dataset.precio||-1)-Number(a.dataset.precio||-1));

    return Number(b.dataset.id)-Number(a.dataset.id);

  });

  let visibles=0;

  for(const item of items){

    const mostrar=normalizar(item.dataset.busqueda).includes(q)&&(!c||item.dataset.condominio===c)&&(!filtroOperacion.value||item.dataset.operacion===filtroOperacion.value||item.dataset.operacion==='Ambos');

    item.hidden=!mostrar;

    if(mostrar)visibles++;

    tarjetas.appendChild(item);

  }

  contador.textContent=visibles+' '+(visibles===1?'propiedad disponible':'propiedades disponibles');

  vacio.hidden=visibles!==0;

}

[buscar,filtro,orden,filtroOperacion].forEach(el=>el.addEventListener(el===buscar?'input':'change',aplicarFiltros));

const fondo=document.getElementById('fichaFondo'), cerrar=document.getElementById('cerrarFicha');

function colocarTexto(id,texto){document.getElementById(id).textContent=texto;}

const moneda=n=>'RD$ '+Number(n).toLocaleString('es-DO',{minimumFractionDigits:2,maximumFractionDigits:2});

document.querySelectorAll('[data-ficha]').forEach(btn=>btn.addEventListener('click',()=>{

  const p=propiedades.find(x=>x.id===Number(btn.dataset.ficha)); if(!p)return;

  colocarTexto('fichaTitulo',p.edificio+' · Apartamento '+p.numero);

  colocarTexto('fichaLugar',p.condominio);

  const precios=[];

  if(p.tipo!=='Venta') precios.push('Alquiler: '+(p.precioAlquiler===null?'A consultar':moneda(p.precioAlquiler)+' / mes'));

  if(p.tipo!=='Alquiler') precios.push('Venta: '+(p.precioVenta===null?'A consultar':moneda(p.precioVenta)));

  colocarTexto('fichaPrecio',precios.join(' · '));

  colocarTexto('fichaDescripcion',p.descripcion||'Sin descripción adicional.');

  const detalles=document.getElementById('fichaDetalles'); detalles.replaceChildren();

  for(const [etiqueta,valor] of [['Piso',p.piso],['Habitaciones',p.habitaciones],['Baños',p.banos],['Superficie',p.metros===null?null:p.metros+' m²']]){

    const celda=document.createElement('div');celda.textContent=etiqueta+': '+(valor??'No indicado');detalles.appendChild(celda);

  }

  const imgCont=document.getElementById('fichaImagen');imgCont.replaceChildren();

  if(p.imagen){const img=document.createElement('img');img.src=p.imagen;img.alt='Apartamento '+p.numero;imgCont.appendChild(img);}

  const acciones=document.getElementById('fichaAcciones');

  acciones.replaceChildren();

  const agregar=(texto,tipo,operacion,secundario=false)=>{

    const boton=document.createElement('button');boton.type='button';boton.textContent=texto;

    if(secundario)boton.className='secundario';

    boton.addEventListener('click',()=>abrirSolicitud(p.id,tipo,operacion));

    acciones.appendChild(boton);

  };

  agregar('📅 Agendar visita','visita','',true);

  if(p.tipo==='Alquiler'||p.tipo==='Ambos') agregar('Rentar','operacion','Alquiler');

  if(p.tipo==='Venta'||p.tipo==='Ambos') agregar('Comprar','operacion','Venta');

  fondo.classList.add('abierto');cerrar.focus();

}));

function cerrarModal(){fondo.classList.remove('abierto');}

cerrar.addEventListener('click',cerrarModal);

fondo.addEventListener('click',e=>{if(e.target===fondo)cerrarModal();});

document.addEventListener('keydown',e=>{if(e.key==='Escape')cerrarModal();});

const solicitudFondo=document.getElementById('solicitudFondo');

const formSolicitud=document.getElementById('formSolicitud');

function abrirSolicitud(id,tipo,operacion){

  formSolicitud.reset();

  document.getElementById('solApartamento').value=id;

  document.getElementById('solTipo').value=tipo;

  document.getElementById('solOperacion').value=operacion;

  const visita=tipo==='visita';

  document.getElementById('solicitudTitulo').textContent=visita?'Agendar visita':(operacion==='Venta'?'Solicitar compra':'Solicitar alquiler');

  document.getElementById('camposVisita').hidden=!visita;

  document.getElementById('solFecha').required=visita;

  document.getElementById('solHora').required=visita;

  document.getElementById('solFecha').min=new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);

  document.getElementById('solError').hidden=true;

  solicitudFondo.classList.add('abierto');

  (visita ? document.getElementById('solFecha') : document.getElementById('solObservaciones')).focus();

}

function cerrarSolicitud(){solicitudFondo.classList.remove('abierto');}

document.getElementById('cerrarSolicitud').addEventListener('click',cerrarSolicitud);

solicitudFondo.addEventListener('click',e=>{if(e.target===solicitudFondo)cerrarSolicitud();});

document.addEventListener('keydown',e=>{if(e.key==='Escape')cerrarSolicitud();});

formSolicitud.addEventListener('submit', async e => {
    e.preventDefault();
    if (!formSolicitud.reportValidity()) return;

    const boton = document.getElementById('solGuardar');
    const error = document.getElementById('solError');
    boton.disabled = true;
    boton.textContent = 'Enviando...';
    error.hidden = true;

    try {
        const respuesta = await fetch(window.location.pathname, {
            method: 'POST',
            body: new FormData(formSolicitud),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const datos = await respuesta.json();
        if (!respuesta.ok || !datos.ok) {
            throw new Error(datos.error || 'No se pudo enviar la solicitud.');
        }

        cerrarSolicitud();
        cerrarModal();
        const mensaje = datos.tipo === 'visita'
            ? 'Tu solicitud de visita ha sido enviada correctamente. Próximamente nos estaremos comunicando contigo.'
            : datos.operacion === 'Venta'
                ? 'Tu solicitud de compra ha sido enviada correctamente. Próximamente nos estaremos comunicando contigo.'
                : 'Tu solicitud de alquiler ha sido enviada correctamente. Próximamente nos estaremos comunicando contigo.';

        if (typeof Swal !== 'undefined') {
            await Swal.fire({
                icon: 'success',
                title: '¡Solicitud enviada!',
                text: mensaje,
                confirmButtonText: 'Aceptar',
                confirmButtonColor: '#0F382B',
                allowOutsideClick: false
            });
        } else {
            alert('¡Solicitud enviada!\n\n' + mensaje);
        }
        formSolicitud.reset();
    } catch (err) {
        error.textContent = err.message || 'No se pudo enviar la solicitud.';
        error.hidden = false;
    } finally {
        boton.disabled = false;
        boton.textContent = 'Enviar solicitud';
    }
});

</script>

</body>

</html>
