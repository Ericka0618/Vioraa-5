<?php
session_start();
require_once '../conf/conexion.php';
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login/login.php');
    exit;
}
$nombre = $_SESSION['nombre_usuario'] ?? 'Usuario';
$usuario = $_SESSION['usuario'] ?? '';
$menuActivo = 'apartamentos';
function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function responder($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if (!in_array($action, ['get', 'create', 'update', 'delete'], true)) throw new Exception('Acción no válida.');
        if (in_array($action, ['get', 'update', 'delete'], true) && $id <= 0) throw new Exception('ID inválido.');
        $anterior = null;
        if ($id > 0) {
            $q = $conexion->prepare('SELECT * FROM apartamento WHERE id_apartamentos = ? LIMIT 1');
            $q->bind_param('i', $id);
            $q->execute();
            $anterior = $q->get_result()->fetch_assoc();
            if (!$anterior && $action !== 'create') throw new Exception('Apartamento no encontrado.');
        }
        if ($action === 'get') responder(['ok' => true, 'data' => $anterior]);
        if ($action === 'delete') {
            // Se conserva el registro y sus relaciones: se desactiva en lugar de borrarlo.
            $q = $conexion->prepare('UPDATE apartamento SET activo=0, disponible=0 WHERE id_apartamentos=?');
            $q->bind_param('i', $id);
            $q->execute();
            responder(['ok'=>true, 'msg'=>'Apartamento desactivado correctamente.']);
        }
        $edificioId = filter_var($_POST['edificio_id'] ?? null, FILTER_VALIDATE_INT);
        $numero = trim((string)($_POST['numero'] ?? ''));
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $estado = trim((string)($_POST['estado'] ?? 'Disponible'));
        $activo = filter_var($_POST['activo'] ?? null, FILTER_VALIDATE_INT);
        $disponible = filter_var($_POST['disponible'] ?? null, FILTER_VALIDATE_INT);
        if (!$edificioId || $edificioId <= 0) throw new Exception('Selecciona un edificio válido.');
        if ($numero === '' || mb_strlen($numero) > 20) throw new Exception('El número es obligatorio (máximo 20 caracteres).');
        if (!in_array($estado, ['Disponible','Ocupado','Reservado','En mantenimiento','Inactivo','Vendido'], true)) throw new Exception('Estado inválido.');
        if (!in_array($activo, [0,1], true) || !in_array($disponible, [0,1], true)) throw new Exception('Selecciona valores válidos para activo y disponible.');
        $q = $conexion->prepare('SELECT id_edificios FROM edificio WHERE id_edificios=? LIMIT 1');
        $q->bind_param('i', $edificioId); $q->execute();
        if (!$q->get_result()->fetch_assoc()) throw new Exception('El edificio no existe.');
        $ints = [];
        foreach (['piso','habitaciones','banos'] as $campo) {
            $raw = trim((string)($_POST[$campo] ?? ''));
            $v = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT);
            if ($v === false || ($v !== null && $v < 0)) throw new Exception("$campo debe ser un entero no negativo.");
            $ints[$campo] = $v;
        }
        $piso=$ints['piso']; $habitaciones=$ints['habitaciones']; $banos=$ints['banos'];
        $metros = trim((string)($_POST['metros_cuadrados'] ?? ''));
        if ($metros !== '' && (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $metros) || (float)$metros <= 0)) throw new Exception('Metros cuadrados inválidos.');
        $metros = $metros === '' ? null : $metros;
        $precio = trim((string)($_POST['precio_alquiler'] ?? ''));
        if ($precio !== '' && (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $precio) || (float)$precio < 0)) throw new Exception('Precio de alquiler inválido.');
        $precio = $precio === '' ? null : $precio;
        $tipoOperacion = trim((string)($_POST['tipo_operacion'] ?? 'Alquiler'));
        if (!in_array($tipoOperacion, ['Alquiler','Venta','Ambos'], true)) throw new Exception('Tipo de operación inválido.');
        $precioVenta = trim((string)($_POST['precio_venta'] ?? ''));
        if ($precioVenta !== '' && (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $precioVenta) || (float)$precioVenta < 0)) throw new Exception('Precio de venta inválido.');
        $precioVenta = $precioVenta === '' ? null : $precioVenta;
        if ($tipoOperacion === 'Venta') $precio = null;
        if ($tipoOperacion === 'Alquiler') $precioVenta = null;
        if (in_array($tipoOperacion, ['Alquiler','Ambos'], true) && ($precio === null || (float)$precio <= 0)) throw new Exception('Indica un precio de alquiler mayor que cero.');
        if (in_array($tipoOperacion, ['Venta','Ambos'], true) && ($precioVenta === null || (float)$precioVenta <= 0)) throw new Exception('Indica un precio de venta mayor que cero.');
        $imagen = $anterior['imagen'] ?? null;
        $nuevaImagen = null;
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['imagen'];
            if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('No se pudo subir la imagen.');
            if ($f['size'] > 5 * 1024 * 1024) throw new Exception('La imagen debe pesar como máximo 5 MB.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
            if (!$ext || !getimagesize($f['tmp_name'])) throw new Exception('Solo se aceptan imágenes JPG, PNG o WEBP.');
            $dir = __DIR__ . '/../uploads/apartamentos/';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new Exception('No se pudo crear la carpeta de imágenes.');
            $nuevaImagen = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . $nuevaImagen)) throw new Exception('No se pudo guardar la imagen.');
            $imagen = $nuevaImagen;
        }
        try {
            if ($action === 'create') {
                $q = $conexion->prepare('INSERT INTO apartamento (edificio_id,numero,piso,habitaciones,banos,metros_cuadrados,descripcion,estado,activo,disponible,precio_alquiler,imagen,tipo_operacion,precio_venta) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->bind_param('isiiisssiissss', $edificioId,$numero,$piso,$habitaciones,$banos,$metros,$descripcion,$estado,$activo,$disponible,$precio,$imagen,$tipoOperacion,$precioVenta);
            } else {
                $q = $conexion->prepare('UPDATE apartamento SET edificio_id=?,numero=?,piso=?,habitaciones=?,banos=?,metros_cuadrados=?,descripcion=?,estado=?,activo=?,disponible=?,precio_alquiler=?,imagen=?,tipo_operacion=?,precio_venta=? WHERE id_apartamentos=?');
                $q->bind_param('isiiisssiissssi', $edificioId,$numero,$piso,$habitaciones,$banos,$metros,$descripcion,$estado,$activo,$disponible,$precio,$imagen,$tipoOperacion,$precioVenta,$id);
            }
            $q->execute();
        } catch (Throwable $e) {
            if ($nuevaImagen) @unlink(__DIR__ . '/../uploads/apartamentos/' . $nuevaImagen);
            throw $e;
        }
        if ($nuevaImagen && !empty($anterior['imagen']) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $anterior['imagen'])) {
            @unlink(__DIR__ . '/../uploads/apartamentos/' . $anterior['imagen']);
        }
        responder(['ok'=>true, 'msg'=>$action === 'create' ? 'Apartamento registrado.' : 'Apartamento actualizado.']);
    } catch (Throwable $e) {
        responder(['ok'=>false, 'error'=>$e instanceof mysqli_sql_exception ? 'Error de base de datos. Verifica que agregaste las columnas nuevas y que las relaciones sean válidas.' : $e->getMessage()]);
    }
}
$edificios = $conexion->query('SELECT e.id_edificios,e.nombre,c.nombre AS condominio FROM edificio e JOIN condominio c ON c.id_condominios=e.condominio_id ORDER BY c.nombre,e.nombre');
$result = $conexion->query('SELECT a.*,e.nombre AS nombre_edificio,c.nombre AS nombre_condominio FROM apartamento a JOIN edificio e ON e.id_edificios=a.edificio_id JOIN condominio c ON c.id_condominios=e.condominio_id ORDER BY a.id_apartamentos DESC');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mantenimiento de Apartamentos - VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=6">
<style>
.tabla__miniatura{width:58px;height:44px;object-fit:cover;border-radius:7px;background:#edf2ef}
.imagen-preview{width:100%;max-height:155px;object-fit:contain;border:1px dashed #cbd5d1;border-radius:10px;margin-top:8px;display:none}
.campo input[type=file]{width:100%;max-width:100%;padding:9px;border:1px solid #d7e1dc;border-radius:8px}
.catalogo-aviso{font-size:12px;color:#547266;margin-top:8px}
</style>
</head>
<body>
<div class="diseño">
<?php include 'menu_lateral.php'; ?>
<main class="contenido">
<header class="contenido__superior"><h1 class="titulo">Mantenimiento de Apartamentos</h1><div class="filtros" role="group" aria-label="Usuario actual"><span class="filtro filtro--activo"><?= esc($nombre) ?></span><span class="filtro"><?= esc($usuario) ?></span><a href="../login/logout.php" class="filtro" style="text-decoration:none">Cerrar sesión</a></div></header>
<section class="modulo">
<div class="modulo__header"><h2 class="modulo__titulo">Lista de Apartamentos</h2><button type="button" id="btnNuevo" class="btn-primario">+ Añadir Apartamento</button></div>
<div class="modulo__cuerpo tabla-contenedor"><table class="tabla"><thead><tr><th>ID</th><th>Foto</th><th>Número</th><th>Edificio</th><th>Condominio</th><th>Piso</th><th>Habitaciones</th><th>Baños</th><th>m²</th><th>Operación</th><th>Alquiler</th><th>Venta</th><th>Estado</th><th>Activo</th><th>Disponible</th><th>Catálogo</th><th>Acciones</th></tr></thead><tbody>
<?php if ($result && $result->num_rows): while ($row=$result->fetch_assoc()):
$publicado = (int)$row['activo']===1 && (int)$row['disponible']===1 && $row['estado']==='Disponible';
$foto = !empty($row['imagen']) ? '../uploads/apartamentos/'.rawurlencode($row['imagen']) : '';
?>
<tr><td><?= (int)$row['id_apartamentos'] ?></td><td><?php if($foto): ?><img class="tabla__miniatura" src="<?= esc($foto) ?>" alt="Foto del apartamento" loading="lazy"><?php else: ?>—<?php endif; ?></td><td><?= esc($row['numero']) ?></td><td><?= esc($row['nombre_edificio']) ?></td><td><?= esc($row['nombre_condominio']) ?></td><td><?= esc($row['piso'] ?? '—') ?></td><td><?= esc($row['habitaciones'] ?? '—') ?></td><td><?= esc($row['banos'] ?? '—') ?></td><td><?= esc($row['metros_cuadrados'] ?? '—') ?></td><td><?= esc($row['tipo_operacion']) ?></td><td><?= $row['precio_alquiler'] === null ? '—' : 'RD$ '.number_format((float)$row['precio_alquiler'],2) ?></td><td><?= $row['precio_venta'] === null ? '—' : 'RD$ '.number_format((float)$row['precio_venta'],2) ?></td><td><?= esc($row['estado']) ?></td><td><span class="badge <?= $row['activo'] ? 'badge--activo':'badge--inactivo' ?>"><?= $row['activo'] ? 'Activo':'Inactivo' ?></span></td><td><?= $row['disponible'] ? 'Sí':'No' ?></td><td><?= $publicado ? 'Visible':'Oculto' ?></td><td class="tabla__acciones"><button type="button" class="btn-icono btn-icono--editar btn-edit" data-id="<?= (int)$row['id_apartamentos'] ?>" title="Editar">✎</button><button type="button" class="btn-icono btn-icono--eliminar btn-delete" data-id="<?= (int)$row['id_apartamentos'] ?>" title="Desactivar">🗑️</button></td></tr>
<?php endwhile; else: ?><tr><td colspan="17" class="tabla__vacio">No hay apartamentos registrados.</td></tr><?php endif; ?>
</tbody></table></div></section>
</main></div>
<div class="modal-backdrop" id="dialog"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="dlgTitle">
<div class="modal__header"><h3 class="modal__title" id="dlgTitle">Nuevo apartamento</h3><button type="button" class="btn-icono" id="btnClose" aria-label="Cerrar">✖</button></div>
<form id="frmApartamento" enctype="multipart/form-data"><div class="modal__body"><input type="hidden" name="id" id="fldId"><div class="formulario-modal">
<div class="campo"><label for="fldEdificio">Edificio *</label><select id="fldEdificio" name="edificio_id" required><option value="">Selecciona un edificio</option><?php while($e=$edificios->fetch_assoc()): ?><option value="<?= (int)$e['id_edificios'] ?>"><?= esc($e['condominio'].' — '.$e['nombre']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label for="fldNumero">Número de apartamento *</label><input type="text" id="fldNumero" name="numero" maxlength="20" required placeholder="Ej. A-101"></div>
<div class="campo"><label for="fldPiso">Piso</label><input type="number" id="fldPiso" name="piso" min="0" step="1"></div>
<div class="campo"><label for="fldHabitaciones">Habitaciones</label><input type="number" id="fldHabitaciones" name="habitaciones" min="0" step="1"></div>
<div class="campo"><label for="fldBanos">Baños</label><input type="number" id="fldBanos" name="banos" min="0" step="1"></div>
<div class="campo"><label for="fldMetros">Metros cuadrados (m²)</label><input type="number" id="fldMetros" name="metros_cuadrados" min="0.01" step="0.01"></div>
<div class="campo"><label for="fldTipoOperacion">Tipo de operación *</label><select id="fldTipoOperacion" name="tipo_operacion" required><option value="Alquiler">Alquiler</option><option value="Venta">Venta</option><option value="Ambos">Alquiler y venta</option></select></div>
<div class="campo" id="grupoAlquiler"><label for="fldPrecio">Precio mensual (RD$)</label><input type="number" id="fldPrecio" name="precio_alquiler" min="0" step="0.01" placeholder="Ej. 28000"></div>
<div class="campo" id="grupoVenta"><label for="fldPrecioVenta">Precio de venta (RD$)</label><input type="number" id="fldPrecioVenta" name="precio_venta" min="0.01" step="0.01" placeholder="Ej. 4500000"></div>
<div class="campo"><label for="fldEstado">Estado</label><select id="fldEstado" name="estado"><option value="Disponible">Disponible</option><option value="Ocupado">Ocupado</option><option value="Reservado">Reservado</option><option value="En mantenimiento">En mantenimiento</option><option value="Inactivo">Inactivo</option><option value="Vendido">Vendido</option></select></div>
<div class="campo"><label for="fldActivo">Activo en el sistema</label><select id="fldActivo" name="activo"><option value="1">Sí, activo</option><option value="0">No, inactivo</option></select></div>
<div class="campo"><label for="fldDisponible">Disponible para publicar</label><select id="fldDisponible" name="disponible"><option value="1">Sí</option><option value="0">No</option></select></div>
<div class="campo campo-completo"><label for="fldImagen">Fotografía principal (JPG, PNG o WEBP, máximo 5 MB)</label><input type="file" id="fldImagen" name="imagen" accept="image/jpeg,image/png,image/webp"><img id="previewImagen" class="imagen-preview" alt="Vista previa de la fotografía"></div>
<div class="campo campo-completo"><label for="fldDescripcion">Descripción</label><textarea id="fldDescripcion" name="descripcion" placeholder="Detalles del apartamento (opcional)"></textarea></div>
</div><p class="catalogo-aviso">Solo aparecerá en el catálogo cuando esté activo, disponible y su estado sea «Disponible».</p><p id="msgError" class="mensaje-error" style="display:none"></p></div>
<div class="modal__footer"><button type="button" class="btn-secundario" id="btnCancel">Cancelar</button><button type="submit" class="btn-primario" id="btnSave">Guardar</button></div>
</form></div></div>
<script>
const dlg=document.getElementById('dialog'),frm=document.getElementById('frmApartamento'),btnSave=document.getElementById('btnSave'),msgError=document.getElementById('msgError');
const fldId=document.getElementById('fldId'),fldEdificio=document.getElementById('fldEdificio'),preview=document.getElementById('previewImagen'),fileInput=document.getElementById('fldImagen');
let previewUrl=null;
function mostrarError(m){msgError.textContent=m;msgError.style.display='block';}
function abrirModal(modo,data={}){
 frm.reset();msgError.style.display='none';msgError.textContent='';
 if(previewUrl){URL.revokeObjectURL(previewUrl);previewUrl=null;}
 preview.style.display='none';preview.removeAttribute('src');
 fldId.value=modo==='create'?'':data.id_apartamentos;
 document.getElementById('dlgTitle').textContent=modo==='create'?'Nuevo apartamento':'Editar apartamento';
 const campos={fldEdificio:'edificio_id',fldNumero:'numero',fldPiso:'piso',fldHabitaciones:'habitaciones',fldBanos:'banos',fldMetros:'metros_cuadrados',fldPrecio:'precio_alquiler',fldPrecioVenta:'precio_venta',fldTipoOperacion:'tipo_operacion',fldDescripcion:'descripcion',fldEstado:'estado',fldActivo:'activo',fldDisponible:'disponible'};
 for(const [id,key] of Object.entries(campos)) document.getElementById(id).value=String(data[key]??(key==='estado'?'Disponible':key==='tipo_operacion'?'Alquiler':['activo','disponible'].includes(key)?'1':''));
 if(data.imagen && /^[a-f0-9]{32}\.(jpg|png|webp)$/.test(data.imagen)){preview.src='../uploads/apartamentos/'+encodeURIComponent(data.imagen);preview.style.display='block';}
 actualizarOperacion();dlg.classList.add('is-open');fldEdificio.focus();
}
function actualizarOperacion(){
 const t=document.getElementById('fldTipoOperacion').value;
 const al=document.getElementById('fldPrecio'), ve=document.getElementById('fldPrecioVenta');
 document.getElementById('grupoAlquiler').hidden=t==='Venta';
 document.getElementById('grupoVenta').hidden=t==='Alquiler';
 al.required=t!=='Venta';ve.required=t!=='Alquiler';
}
document.getElementById('fldTipoOperacion').addEventListener('change',actualizarOperacion);
function cerrarModal(){dlg.classList.remove('is-open');}
fileInput.addEventListener('change',()=>{
 if(previewUrl){URL.revokeObjectURL(previewUrl);previewUrl=null;}
 const f=fileInput.files[0];if(!f){preview.style.display='none';return;}
 if(!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>5*1024*1024){fileInput.value='';mostrarError('Selecciona JPG, PNG o WEBP de máximo 5 MB.');return;}
 previewUrl=URL.createObjectURL(f);preview.src=previewUrl;preview.style.display='block';
});
async function enviar(fd){const r=await fetch(window.location.pathname,{method:'POST',body:fd});if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}
document.getElementById('btnNuevo').addEventListener('click',()=>abrirModal('create'));
document.getElementById('btnClose').addEventListener('click',cerrarModal);
document.getElementById('btnCancel').addEventListener('click',cerrarModal);
dlg.addEventListener('click',e=>{if(e.target===dlg)cerrarModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')cerrarModal();});
document.querySelectorAll('.btn-edit').forEach(btn=>btn.addEventListener('click',async()=>{
 const fd=new FormData();fd.append('ajax','1');fd.append('action','get');fd.append('id',btn.dataset.id);
 try{const d=await enviar(fd);if(d.ok)abrirModal('edit',d.data);else alert(d.error||'Error al cargar.');}catch(e){alert('No se pudieron cargar los datos.');}
}));
document.querySelectorAll('.btn-delete').forEach(btn=>btn.addEventListener('click',async()=>{
 if(!confirm('¿Desactivar este apartamento? Dejará de mostrarse en el catálogo.'))return;
 const fd=new FormData();fd.append('ajax','1');fd.append('action','delete');fd.append('id',btn.dataset.id);
 try{const d=await enviar(fd);if(d.ok)location.reload();else alert(d.error||'No se pudo desactivar.');}catch(e){alert('Error de conexión.');}
}));
frm.addEventListener('submit',async e=>{
 e.preventDefault();if(!frm.reportValidity())return;
 const fd=new FormData(frm);fd.append('ajax','1');fd.append('action',fldId.value?'update':'create');
 btnSave.disabled=true;btnSave.textContent='Guardando...';msgError.style.display='none';
 try{const d=await enviar(fd);if(d.ok)location.reload();else mostrarError(d.error||'Error al guardar.');}catch(e){mostrarError('No se pudo completar la operación. Verifica la conexión.');}
 finally{btnSave.disabled=false;btnSave.textContent='Guardar';}
});
</script>
</body></html>
