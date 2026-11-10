<?php
session_start();
require_once '../conf/conexion.php';
require_once __DIR__ . '/../conf/require_admin.php';
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../login/login.php'); exit; }
// IMPORTANTE: agregar aquí validación de rol administrador según tu tabla rol/usuario.
$nombre=$_SESSION['nombre_usuario']??'Usuario';
$usuario=$_SESSION['usuario']??'';
$menuActivo='contratos';
function h($v){return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}
if(empty($_SESSION['csrf_contratos'])) $_SESSION['csrf_contratos']=bin2hex(random_bytes(32));
$solicitudId=filter_input(INPUT_GET,'solicitud',FILTER_VALIDATE_INT) ?: 0;
$mensaje='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $guardado=null;
  try{
    if(!hash_equals($_SESSION['csrf_contratos'],(string)($_POST['csrf']??''))) throw new Exception('Formulario vencido. Actualiza la página.');
    $id=filter_var($_POST['solicitud_id']??null,FILTER_VALIDATE_INT);
    if(!$id || $id<1) throw new Exception('Solicitud inválida.');
    $numero=trim((string)($_POST['numero_contrato']??''));
    $inicio=(string)($_POST['fecha_inicio']??'');$fin=(string)($_POST['fecha_fin']??'');
    $monto=filter_var($_POST['monto_alquiler']??null,FILTER_VALIDATE_FLOAT);
    $deposito=filter_var($_POST['deposito']??'0',FILTER_VALIDATE_FLOAT);
    $mantenimiento=filter_var($_POST['monto_mantenimiento']??'0',FILTER_VALIDATE_FLOAT);
    $condiciones=trim((string)($_POST['condiciones']??''));
    $fechaValida=static function($v){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d && $d->format('Y-m-d')===$v;};
    if($numero==='' || mb_strlen($numero)>50) throw new Exception('Número de contrato obligatorio (máximo 50 caracteres).');
    if(!$fechaValida($inicio)||($fin!==''&&!$fechaValida($fin))) throw new Exception('Revisa las fechas.');
    if($fin!==''&&$fin<$inicio) throw new Exception('La fecha final debe ser posterior o igual a la inicial.');
    if($monto===false||!is_finite($monto)||$monto<=0||$deposito===false||!is_finite($deposito)||$deposito<0) throw new Exception('Indica un monto positivo y un depósito válido.');
    if($mantenimiento===false||!is_finite($mantenimiento)||$mantenimiento<0) throw new Exception('El mantenimiento debe ser un monto válido, igual o mayor que cero.');
    if(mb_strlen($condiciones)>10000) throw new Exception('Las condiciones son demasiado largas.');
    if(!isset($_FILES['archivo'])||$_FILES['archivo']['error']!==UPLOAD_ERR_OK) throw new Exception('Adjunta la copia del contrato en PDF.');
    $f=$_FILES['archivo'];
    if($f['size']<1||$f['size']>5*1024*1024||!is_uploaded_file($f['tmp_name'])) throw new Exception('El archivo debe ser un PDF de hasta 5 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $fh=fopen($f['tmp_name'],'rb');$cabecera=fread($fh,5);fclose($fh);
    if($mime!=='application/pdf'||$cabecera!=='%PDF-') throw new Exception('Solo se aceptan archivos PDF válidos.');
    $conexion->begin_transaction();
    $q=$conexion->prepare("SELECT s.id_solicitudes,s.persona_id,s.apartamento_id,s.tipo_operacion FROM solicitud_propiedad s WHERE s.id_solicitudes=? AND s.activo=1 AND s.estado='Aprobada' FOR UPDATE");
    $q->bind_param('i',$id);$q->execute();$sol=$q->get_result()->fetch_assoc();
    if(!$sol) throw new Exception('La solicitud debe estar activa y aprobada.');
    $q=$conexion->prepare('SELECT id_contratos FROM contrato WHERE solicitud_id=? LIMIT 1');$q->bind_param('i',$id);$q->execute();
    if($q->get_result()->fetch_assoc()) throw new Exception('Esta solicitud ya tiene un contrato.');
    // Carpeta privada fuera del directorio web: no servir archivos sin control de acceso.
    $directorio=dirname(__DIR__,2).'/viora_documentos_privados';
    if(!is_dir($directorio) && !mkdir($directorio,0700,true) && !is_dir($directorio)) throw new Exception('No se pudo crear la carpeta privada de contratos.');
    $archivo=bin2hex(random_bytes(20)).'.pdf';$ruta=$directorio.'/'.$archivo;
    if(!move_uploaded_file($f['tmp_name'],$ruta)) throw new Exception('No se pudo guardar el PDF.');
    $guardado=$ruta;@chmod($ruta,0600);
    $finSQL=$fin===''?null:$fin;

    $propiedadId=null; // Se deja NULL porque no hay relación propiedad-apartamento definida.
    $q=$conexion->prepare("INSERT INTO contrato (apartamento_id,propiedad_id,numero_contrato,fecha_inicio,fecha_fin,monto_alquiler,deposito,condiciones,estado,activo,solicitud_id,persona_id,tipo_operacion,archivo_contrato,monto_mantenimiento) VALUES (?,?,?,?,?,?,?,?,'Activo',1,?,?,?,?,?)");
    $q->bind_param('iisssddsiissd',$sol['apartamento_id'],$propiedadId,$numero,$inicio,$finSQL,$monto,$deposito,$condiciones,$id,$sol['persona_id'],$sol['tipo_operacion'],$archivo,$mantenimiento);
    $q->execute();
    $conexion->commit();$guardado=null;
    header('Location: contratos.php?creado=1');exit;
  }catch(Throwable $e){try{$conexion->rollback();}catch(Throwable $ignored){}if($guardado&&is_file($guardado))@unlink($guardado);$error=$e instanceof mysqli_sql_exception?'No se pudo guardar el contrato. Verifica el número único y la estructura SQL.':$e->getMessage();$solicitudId=(int)($_POST['solicitud_id']??0);}
}
$sql="SELECT s.id_solicitudes,s.tipo_operacion,s.fecha_solicitud,p.nombres,p.apellidos,p.cedula,p.correo,p.telefono,a.numero AS apartamento,a.precio_alquiler,a.precio_venta,e.nombre AS edificio FROM solicitud_propiedad s JOIN persona p ON p.id_personas=s.persona_id JOIN apartamento a ON a.id_apartamentos=s.apartamento_id JOIN edificio e ON e.id_edificios=a.edificio_id LEFT JOIN contrato ct ON ct.solicitud_id=s.id_solicitudes WHERE s.estado='Aprobada' AND s.activo=1 AND ct.id_contratos IS NULL ORDER BY s.id_solicitudes DESC";
$pendientes=$conexion->query($sql)->fetch_all(MYSQLI_ASSOC);
$contratos=$conexion->query("SELECT ct.id_contratos,ct.numero_contrato,ct.fecha_inicio,ct.fecha_fin,ct.monto_alquiler,ct.monto_mantenimiento,ct.estado,ct.tipo_operacion,ct.archivo_contrato,p.nombres,p.apellidos,a.numero AS apartamento FROM contrato ct LEFT JOIN persona p ON p.id_personas=ct.persona_id JOIN apartamento a ON a.id_apartamentos=ct.apartamento_id ORDER BY ct.id_contratos DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Contratos | VIORA</title><link rel="stylesheet" href="../estilos/styleP.css?v=6"><style>
.ct-panel{background:#fff;border:1px solid #e3ebe7;border-radius:14px;padding:22px;margin:18px 0}.ct-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ct-field{display:flex;flex-direction:column;gap:6px}.ct-field input,.ct-field select,.ct-field textarea{padding:11px;border:1px solid #dce6e1;border-radius:8px;font:inherit;width:100%;box-sizing:border-box}.ct-field.full{grid-column:1/-1}.ct-note{font-size:13px;color:#64756f}.ct-error{color:#ae2929}.ct-ok{color:#176a44}.ct-tabla{overflow-x:auto}.ct-tabla table{width:100%;border-collapse:collapse}.ct-tabla th,.ct-tabla td{padding:12px;border-bottom:1px solid #e3ebe7;text-align:left}@media(max-width:650px){.ct-grid{grid-template-columns:1fr}}
.ct-eye{display:inline-flex;align-items:center;gap:6px;color:#0c392b;font-weight:700;text-decoration:none;border:1px solid #c9ddd3;border-radius:8px;padding:7px 10px;white-space:nowrap}.ct-eye:hover{background:#eef8f2}</style></head><body><div class="diseño"><?php include 'menu_lateral.php'; ?><main class="contenido"><header class="contenido__superior"><h1 class="titulo">Mantenimiento de Contratos</h1><div class="filtros"><span class="filtro filtro--activo"><?=h($nombre)?></span><span class="filtro"><?=h($usuario)?></span><a href="../login/logout.php" class="filtro">Cerrar sesión</a></div></header>
<section class="ct-panel"><h2>Crear contrato desde solicitud aprobada</h2><p class="ct-note">Solo aparecen solicitudes aprobadas que todavía no tienen contrato. Adjunta la copia firmada en PDF (máximo 5 MB).</p>
<?php if(isset($_GET['creado'])): ?><p class="ct-ok">Contrato registrado correctamente.</p><?php endif; ?><?php if($error): ?><p class="ct-error"><?=h($error)?></p><?php endif; ?>
<?php if(!$pendientes): ?><p>No hay solicitudes aprobadas pendientes de contrato.</p><?php else: ?>
    
<form method="post" enctype="multipart/form-data" id="formContrato"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf_contratos'])?>"><div class="ct-grid"><label class="ct-field full">Solicitud aprobada<select name="solicitud_id" id="solicitud_id" required><option value="">Seleccionar solicitud</option><?php foreach($pendientes as $s): ?><option value="<?=(int)$s['id_solicitudes']?>" data-tipo="<?=h($s['tipo_operacion'])?>" data-monto="<?=h($s['tipo_operacion']==='Venta'?$s['precio_venta']:$s['precio_alquiler'])?>" <?=($solicitudId==(int)$s['id_solicitudes'])?'selected':''?>>#<?=(int)$s['id_solicitudes']?> · <?=h($s['nombres'].' '.$s['apellidos'])?> · <?=h($s['edificio'].' / '.$s['apartamento'])?> · <?=h($s['tipo_operacion']==='Venta'?'Compra':'Alquiler')?></option><?php endforeach; ?></select></label>
<label class="ct-field">Número de contrato<input name="numero_contrato" maxlength="50" required placeholder="Ej. CONT-2026-001"></label><label class="ct-field">Monto del contrato (RD$)<input name="monto_alquiler" id="monto" type="number" min="0.01" step="0.01" required></label><label class="ct-field">Fecha de inicio<input name="fecha_inicio" type="date" required></label><label class="ct-field">Fecha de finalización (opcional)<input name="fecha_fin" type="date"></label><label class="ct-field">Depósito (RD$)<input name="deposito" type="number" min="0" step="0.01" value="0" required></label><label class="ct-field">Monto de mantenimiento (RD$)<input name="monto_mantenimiento" type="number" min="0" step="0.01" value="0" required></label><label class="ct-field">Copia del contrato (PDF)<input name="archivo" type="file" accept="application/pdf,.pdf" required></label><label class="ct-field full">Condiciones<textarea name="condiciones" rows="4" maxlength="10000" placeholder="Condiciones acordadas"></textarea></label></div><p class="ct-note">El monto se propone según el precio del catálogo; puedes ajustarlo al monto acordado. Para compras, el campo monto_alquiler almacena el precio de venta por compatibilidad con tu tabla actual.</p><button class="btn-primario" type="submit">Guardar contrato y PDF</button></form><?php endif; ?></section>
<section class="ct-panel"><h2>Contratos registrados</h2><div class="ct-tabla"><table><thead><tr><th>Número</th><th>Cliente</th><th>Apartamento</th><th>Operación</th><th>Inicio</th><th>Fin</th><th>Monto RD$</th><th>Mantenimiento RD$</th><th>Estado</th><th>Copia</th></tr></thead><tbody><?php foreach($contratos as $c): ?><tr><td><?=h($c['numero_contrato'])?></td><td><?=h(trim($c['nombres'].' '.$c['apellidos']))?></td><td><?=h($c['apartamento'])?></td><td><?=h($c['tipo_operacion']==='Venta'?'Compra':'Alquiler')?></td><td><?=h($c['fecha_inicio'])?></td><td><?=h($c['fecha_fin']?:'—')?></td><td><?=number_format((float)$c['monto_alquiler'],2)?></td><td><?=number_format((float)$c['monto_mantenimiento'],2)?></td><td><?=h($c['estado'])?></td><td><?php if(!empty($c['archivo_contrato'])): ?><a class="ct-eye" href="ver_contrato.php?id=<?=(int)$c['id_contratos']?>" target="_blank" rel="noopener noreferrer" title="Visualizar PDF" aria-label="Visualizar contrato <?=h($c['numero_contrato'])?>">👁 Ver PDF</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?><?php if(!$contratos): ?><tr><td colspan="10">No hay contratos registrados.</td></tr><?php endif; ?></tbody></table></div><p class="ct-note">Los archivos se almacenan en una carpeta privada. Pulsa el icono del ojo para visualizar el PDF en una pestaña nueva. Solo los administradores autorizados pueden acceder.</p></section></main></div><script>
const sel=document.getElementById('solicitud_id');if(sel){function actualizar(){const op=sel.selectedOptions[0];document.getElementById('monto').value=op?.dataset.monto||'';}sel.addEventListener('change',actualizar);actualizar();}
</script></body></html>
