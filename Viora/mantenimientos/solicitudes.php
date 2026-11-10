<?php
session_start();
require_once '../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login/login.php'); exit;
}
// IMPORTANTE: proteger esta ruta con la verificación de rol administrativo de VIORA.
// La sesión logged_in por sí sola no distingue administradores de clientes.
$nombre = $_SESSION['nombre_usuario'] ?? 'Usuario';
$usuario = $_SESSION['usuario'] ?? '';
$menuActivo = 'solicitudes';
function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_solicitudes'])) $_SESSION['csrf_solicitudes'] = bin2hex(random_bytes(32));
$estados = ['Pendiente','En revision','Aprobada','Rechazada','Cancelada'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($_SESSION['csrf_solicitudes'], (string)($_POST['csrf'] ?? ''))) throw new Exception('Sesión de formulario no válida. Actualiza la página.');
        $accion = $_POST['action'] ?? '';
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new Exception('Solicitud inválida.');
        if ($accion === 'get') {
            $stmt = $conexion->prepare("SELECT s.*,p.nombres,p.apellidos,p.cedula,p.correo,p.telefono,p.direccion,a.numero AS apartamento,e.nombre AS edificio,c.nombre AS condominio FROM solicitud_propiedad s INNER JOIN persona p ON p.id_personas=s.persona_id INNER JOIN apartamento a ON a.id_apartamentos=s.apartamento_id INNER JOIN edificio e ON e.id_edificios=a.edificio_id INNER JOIN condominio c ON c.id_condominios=e.condominio_id WHERE s.id_solicitudes=? LIMIT 1");
            $stmt->bind_param('i',$id); $stmt->execute(); $fila=$stmt->get_result()->fetch_assoc();
            if (!$fila) throw new Exception('Solicitud no encontrada.');
            echo json_encode(['ok'=>true,'data'=>$fila],JSON_UNESCAPED_UNICODE); exit;
        }
        if ($accion === 'update') {
            $estado = (string)($_POST['estado'] ?? '');
            if (!in_array($estado,$estados,true)) throw new Exception('Estado no válido.');
            $stmt=$conexion->prepare('UPDATE solicitud_propiedad SET estado=? WHERE id_solicitudes=? AND activo=1');
            $stmt->bind_param('si',$estado,$id); $stmt->execute();
            if (!$stmt->affected_rows) {
                $check=$conexion->prepare('SELECT id_solicitudes FROM solicitud_propiedad WHERE id_solicitudes=? AND activo=1');
                $check->bind_param('i',$id);$check->execute();
                if (!$check->get_result()->fetch_assoc()) throw new Exception('Solicitud no encontrada o inactiva.');
            }
            echo json_encode(['ok'=>true,'msg'=>'Estado actualizado correctamente.','contrato_url'=>$estado==='Aprobada'?'contratos.php?solicitud='.$id:null],JSON_UNESCAPED_UNICODE);exit;
        }
        if ($accion === 'delete') {
            $stmt=$conexion->prepare('UPDATE solicitud_propiedad SET activo=0 WHERE id_solicitudes=? AND activo=1');
            $stmt->bind_param('i',$id);$stmt->execute();
            if (!$stmt->affected_rows) throw new Exception('Solicitud no encontrada o ya desactivada.');
            echo json_encode(['ok'=>true,'msg'=>'Solicitud desactivada.'],JSON_UNESCAPED_UNICODE);exit;
        }
        throw new Exception('Acción no permitida.');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e instanceof mysqli_sql_exception?'No se pudo completar la operación en la base de datos.':$e->getMessage()],JSON_UNESCAPED_UNICODE);
    }
    exit;
}
$sql="SELECT s.id_solicitudes,s.tipo_operacion,s.estado,s.observaciones,s.fecha_solicitud,s.activo,p.nombres,p.apellidos,p.cedula,p.correo,p.telefono,p.direccion,a.numero AS apartamento,e.nombre AS edificio,c.nombre AS condominio FROM solicitud_propiedad s INNER JOIN persona p ON p.id_personas=s.persona_id INNER JOIN apartamento a ON a.id_apartamentos=s.apartamento_id INNER JOIN edificio e ON e.id_edificios=a.edificio_id INNER JOIN condominio c ON c.id_condominios=e.condominio_id WHERE s.activo=1 ORDER BY s.fecha_solicitud DESC,s.id_solicitudes DESC";
$result=$conexion->query($sql);
$solicitudes=$result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mantenimiento de Solicitudes | VIORA</title>
<link rel="stylesheet" href="../estilos/styleP.css?v=6">
<style>
.solicitudes-resumen{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.solicitudes-resumen div{background:white;border:1px solid #e3ebe7;border-radius:12px;padding:14px 18px;flex:1;min-width:120px}.solicitudes-resumen strong{display:block;font-size:24px;color:#0c392b}.solicitudes-resumen span{font-size:12px;color:#64756f}.solicitudes-filtros{display:flex;gap:10px;flex-wrap:wrap;padding:14px}.solicitudes-filtros input,.solicitudes-filtros select{padding:10px;border:1px solid #dce6e1;border-radius:8px;min-width:160px;flex:1}.solicitudes-estado{display:inline-block;border-radius:30px;padding:5px 10px;font-size:12px;font-weight:700;background:#edf3f0;color:#315d4c}.solicitudes-estado[data-estado="Aprobada"]{background:#e0f5e9;color:#136b3e}.solicitudes-estado[data-estado="Rechazada"],.solicitudes-estado[data-estado="Cancelada"]{background:#ffe9e9;color:#a52d2d}.solicitudes-estado[data-estado="Pendiente"]{background:#fff4d8;color:#946500}.solicitudes-acciones{display:flex;gap:7px;white-space:nowrap}.solicitudes-acciones button{border:1px solid #dce6e1;background:#fff;color:#0c392b;border-radius:7px;padding:7px 10px;cursor:pointer}.solicitudes-acciones button:hover{background:#edf5f0}.solicitudes-modal{width:min(560px,95vw);max-height:90vh;overflow:auto}.solicitudes-datos{display:grid;grid-template-columns:1fr 1fr;gap:12px}.solicitudes-datos div{background:#f5f8f6;border-radius:8px;padding:10px;overflow-wrap:anywhere}.solicitudes-datos small{display:block;color:#71857b;margin-bottom:5px}.solicitudes-datos strong{color:#193e31;font-size:13px}.solicitudes-modal select{width:100%;padding:10px;border:1px solid #dce6e1;border-radius:8px}.solicitudes-error{color:#b42323;font-size:13px;margin:10px 0}@media(max-width:600px){.solicitudes-datos{grid-template-columns:1fr}}.solicitudes-tabla{overflow-x:auto}
</style>
</head>
<body>
<div class="diseño">
<?php include 'menu_lateral.php'; ?>
<main class="contenido">
<header class="contenido__superior"><h1 class="titulo">Mantenimiento de Solicitudes</h1><div class="filtros"><span class="filtro filtro--activo"><?=esc($nombre)?></span><span class="filtro"><?=esc($usuario)?></span><a href="../login/logout.php" class="filtro" style="text-decoration:none">Cerrar sesión</a></div></header>
<div class="solicitudes-resumen">
<div><strong><?=count($solicitudes)?></strong><span>Total activas</span></div>
<div><strong><?=count(array_filter($solicitudes,fn($s)=>$s['estado']==='Pendiente'))?></strong><span>Pendientes</span></div>
<div><strong><?=count(array_filter($solicitudes,fn($s)=>$s['estado']==='Aprobada'))?></strong><span>Aprobadas</span></div>
<div><strong><?=count(array_filter($solicitudes,fn($s)=>$s['tipo_operacion']==='Alquiler'))?></strong><span>Alquileres</span></div>
</div>
<section class="modulo"><div class="modulo__header"><h2 class="modulo__titulo">Solicitudes recibidas</h2></div>
<div class="solicitudes-filtros"><input id="buscar" type="search" placeholder="Buscar cliente, cédula, apartamento..." aria-label="Buscar solicitudes"><select id="filtroTipo"><option value="">Todas las operaciones</option><option value="Alquiler">Alquiler</option><option value="Venta">Compra</option></select><select id="filtroEstado"><option value="">Todos los estados</option><?php foreach($estados as $estado): ?><option value="<?=esc($estado)?>"><?=esc($estado==='En revision'?'En revisión':$estado)?></option><?php endforeach; ?></select></div>
<div class="modulo__cuerpo tabla-contenedor solicitudes-tabla"><table class="tabla"><thead><tr><th>ID</th><th>Cliente</th><th>Cédula</th><th>Propiedad</th><th>Operación</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="tablaSolicitudes">
<?php foreach($solicitudes as $s): ?><tr data-tipo="<?=esc($s['tipo_operacion'])?>" data-estado="<?=esc($s['estado'])?>" data-busqueda="<?=esc(mb_strtolower(implode(' ',[$s['nombres'],$s['apellidos'],$s['cedula'],$s['apartamento'],$s['edificio'],$s['condominio']]),'UTF-8'))?>"><td><?= (int)$s['id_solicitudes']?></td><td><?=esc($s['nombres'].' '.$s['apellidos'])?></td><td><?=esc($s['cedula']?:'—')?></td><td><?=esc($s['edificio'].' · Apt. '.$s['apartamento'])?><small style="display:block;color:#64756f"><?=esc($s['condominio'])?></small></td><td><?=esc($s['tipo_operacion']==='Venta'?'Compra':'Alquiler')?></td><td><?=esc($s['fecha_solicitud'])?></td><td><span class="solicitudes-estado" data-estado="<?=esc($s['estado'])?>"><?=esc($s['estado']==='En revision'?'En revisión':$s['estado'])?></span></td><td><div class="solicitudes-acciones"><button type="button" class="btn-ver" data-id="<?=(int)$s['id_solicitudes']?>">Ver / Editar</button><button type="button" class="btn-borrar" data-id="<?=(int)$s['id_solicitudes']?>" aria-label="Desactivar solicitud">Desactivar</button></div></td></tr><?php endforeach; ?>
<tr id="filaVacia" <?=count($solicitudes)?'hidden':''?>><td colspan="8" class="tabla__vacio">No hay solicitudes para mostrar.</td></tr>
</tbody></table></div></section>
</main></div>
<div class="modal-backdrop" id="dialog"><div class="modal solicitudes-modal" role="dialog" aria-modal="true" aria-labelledby="dlgTitle"><div class="modal__header"><h3 class="modal__title" id="dlgTitle">Detalle de solicitud</h3><button type="button" class="btn-icono" id="btnClose" aria-label="Cerrar">✖</button></div><form id="frmSolicitud"><div class="modal__body"><input type="hidden" name="id" id="fldId"><div class="solicitudes-datos" id="datosCliente"></div><div class="campo" style="margin-top:16px"><label for="fldEstado">Estado de la solicitud</label><select name="estado" id="fldEstado" required><?php foreach($estados as $estado): ?><option value="<?=esc($estado)?>"><?=esc($estado==='En revision'?'En revisión':$estado)?></option><?php endforeach; ?></select></div><p id="msgError" class="solicitudes-error" hidden></p></div><div class="modal__footer"><button type="button" class="btn-secundario" id="btnCancel">Cancelar</button><button type="submit" class="btn-primario" id="btnSave">Guardar cambios</button></div></form></div></div>
<script>
const csrf=<?=json_encode($_SESSION['csrf_solicitudes'])?>;
const dialog=document.getElementById('dialog'),form=document.getElementById('frmSolicitud'),error=document.getElementById('msgError');
const buscar=document.getElementById('buscar'),tipo=document.getElementById('filtroTipo'),estado=document.getElementById('filtroEstado');
function filtrar(){let n=0;document.querySelectorAll('#tablaSolicitudes tr[data-tipo]').forEach(tr=>{const ok=tr.dataset.busqueda.includes(buscar.value.trim().toLocaleLowerCase('es'))&&(!tipo.value||tr.dataset.tipo===tipo.value)&&(!estado.value||tr.dataset.estado===estado.value);tr.hidden=!ok;if(ok)n++;});document.getElementById('filaVacia').hidden=n>0;}
[buscar,tipo,estado].forEach(el=>el.addEventListener(el===buscar?'input':'change',filtrar));
async function enviar(action,id,extra={}){const fd=new FormData();fd.append('ajax','1');fd.append('csrf',csrf);fd.append('action',action);fd.append('id',id);Object.entries(extra).forEach(([k,v])=>fd.append(k,v));const r=await fetch(window.location.pathname,{method:'POST',body:fd});const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Error en la solicitud.');return data;}
function cerrar(){dialog.classList.remove('is-open');}
document.getElementById('btnClose').onclick=cerrar;document.getElementById('btnCancel').onclick=cerrar;dialog.addEventListener('click',e=>{if(e.target===dialog)cerrar();});document.addEventListener('keydown',e=>{if(e.key==='Escape')cerrar();});
function dato(t,v){const d=document.createElement('div'),sm=document.createElement('small'),st=document.createElement('strong');sm.textContent=t;st.textContent=v??'—';d.append(sm,st);return d;}
document.querySelectorAll('.btn-ver').forEach(btn=>btn.addEventListener('click',async()=>{try{const {data:s}=await enviar('get',btn.dataset.id);form.reset();error.hidden=true;document.getElementById('fldId').value=s.id_solicitudes;document.getElementById('fldEstado').value=s.estado;document.getElementById('dlgTitle').textContent='Solicitud #'+s.id_solicitudes;const cont=document.getElementById('datosCliente');cont.replaceChildren(dato('Cliente',s.nombres+' '+s.apellidos),dato('Cédula',s.cedula),dato('Correo',s.correo),dato('Teléfono',s.telefono),dato('Dirección',s.direccion),dato('Apartamento',s.edificio+' · '+s.apartamento),dato('Condominio',s.condominio),dato('Tipo',s.tipo_operacion==='Venta'?'Compra':'Alquiler'),dato('Fecha',s.fecha_solicitud),dato('Observaciones',s.observaciones));dialog.classList.add('is-open');document.getElementById('fldEstado').focus();}catch(e){alert(e.message);}}));
document.querySelectorAll('.btn-borrar').forEach(btn=>btn.addEventListener('click',async()=>{if(!confirm('¿Desactivar esta solicitud? No se eliminará de la base de datos.'))return;try{await enviar('delete',btn.dataset.id);location.reload();}catch(e){alert(e.message);}}));
form.addEventListener('submit',async e=>{e.preventDefault();if(!form.reportValidity())return;const btn=document.getElementById('btnSave');btn.disabled=true;error.hidden=true;try{const r=await enviar('update',document.getElementById('fldId').value,{estado:document.getElementById('fldEstado').value});if(r.contrato_url){window.location.href=r.contrato_url;}else{location.reload();}}catch(e){error.textContent=e.message;error.hidden=false;}finally{btn.disabled=false;}});
</script>
</body></html>
