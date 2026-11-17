<?php
session_start();
ini_set('display_errors','0');
require_once __DIR__.'/../conf/conexion.php';
require_once __DIR__.'/../conf/require_admin.php';
require_once __DIR__.'/reportes_servicio.php';
require_once __DIR__.'/reportes_documento.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$action=(string)($_GET['accion']??'');
try {
    if(in_array($action,['pdf','resultado'],true)) {
        $id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT);if(!$id)throw new InvalidArgumentException('Identificador inválido.');
        $rows=bi_query($conexion,'SELECT resultado,pdf,sha256 FROM bi_reporte_historial WHERE id=?',[$id]);
        if(!$rows){http_response_code(404);exit('Documento no encontrado.');}$r=$rows[0];
        if($action==='pdf') {if(!hash_equals($r['sha256'],hash('sha256',$r['pdf'])))throw new RuntimeException('Integridad del PDF inválida.');header('Content-Type: application/pdf');header('Content-Disposition: '.(isset($_GET['descargar'])?'attachment':'inline').'; filename="VIORA-reporte-'.$id.'.pdf"');echo $r['pdf'];exit;}
        header('Content-Type: application/json; charset=utf-8');echo $r['resultado'];exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Use POST.']);exit;}
    if(empty($_SESSION['bi_csrf'])||!hash_equals($_SESSION['bi_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))){http_response_code(403);echo json_encode(['error'=>'Formulario vencido. Recargue la página.']);exit;}
    $input=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Solicitud inválida.');
    $f=bi_filtros($input['filtros']??[]);
    if($action==='registrar') {
        $data=$input['registro']??[];if(!is_array($data))throw new InvalidArgumentException('Registro inválido.');
        $kind=$data['tipo_registro']??'';if(!in_array($kind,['gasto','inversion','propiedad'],true))throw new InvalidArgumentException('Tipo inválido.');
        $cid=(string)($data['condominio']??'');$aid=(string)($data['propiedad']??'');
        if(!preg_match('/^(real|demo):([1-9][0-9]*)$/',$cid,$cm))throw new InvalidArgumentException('Seleccione un condominio.');
        $pre=$cm[1]==='demo'?'bi_demo_':'';$condId=(int)$cm[2];
        if(!bi_query($conexion,"SELECT id_condominios FROM {$pre}condominio WHERE id_condominios=?",[$condId]))throw new InvalidArgumentException('Condominio no encontrado.');
        $aptId=null;if($aid!=='') {
            if(!preg_match('/^(real|demo):([1-9][0-9]*)$/',$aid,$am)||$am[1]!==$cm[1])throw new InvalidArgumentException('La propiedad debe pertenecer al mismo origen.');
            $aptId=(int)$am[2];if(!bi_query($conexion,"SELECT a.id_apartamentos FROM {$pre}apartamento a JOIN {$pre}edificio e ON e.id_edificios=a.edificio_id WHERE a.id_apartamentos=? AND e.condominio_id=?",[$aptId,$condId]))throw new InvalidArgumentException('La propiedad no pertenece al condominio.');
        }
        $date=(string)($data['fecha']??'');bi_filtros(['desde'=>$date,'hasta'=>$date]);
        $prefix=$cm[1]==='demo'?'bi_demo_':'bi_';
        if($kind==='propiedad') {
            $type=trim((string)($data['tipo_inmueble']??''));if(!$aptId||$type===''||mb_strlen($type)>60)throw new InvalidArgumentException('Seleccione propiedad e indique su tipo (máximo 60 caracteres).');
            $q=$conexion->prepare("INSERT INTO {$prefix}propiedad_info (apartamento_id,tipo,disponible_desde) VALUES (?,?,?) ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),disponible_desde=VALUES(disponible_desde)");$q->bind_param('iss',$aptId,$type,$date);$q->execute();
        } else {
            $amount=(string)($data['monto']??'');$concept=trim((string)($data['concepto']??''));$ref=trim((string)($data['referencia']??''));
            if(!preg_match('/^[0-9]{1,12}(\.[0-9]{1,2})?$/',$amount)||(float)$amount<=0||$concept===''||mb_strlen($concept)>200||!preg_match('/^[A-Za-z0-9_-]{8,100}$/',$ref))throw new InvalidArgumentException('Indique monto positivo (hasta dos decimales), concepto y referencia única (8–100 letras, números o guiones).');
            $table=$prefix.$kind;
            if(bi_query($conexion,"SELECT id FROM {$table} WHERE referencia=?",[$ref]))throw new InvalidArgumentException('Esta referencia ya fue registrada; no se duplicó el movimiento.');
            $q=$conexion->prepare("INSERT INTO {$table} (condominio_id,apartamento_id,fecha,monto,concepto,referencia) VALUES (?,?,?,?,?,?)");$q->bind_param('iissss',$condId,$aptId,$date,$amount,$concept,$ref);$q->execute();
        }
        $out=['mensaje'=>'Registro guardado. Actualice los indicadores para consultar el resultado.'];
    }
    elseif($action==='dashboard')$out=bi_dashboard($conexion,$f);
    elseif($action==='generar'){$r=bi_reporte($conexion,(int)($input['reporte']??0),$f);$hid=bi_guardar($conexion,$r,(int)($_SESSION['id_usuario']??$_SESSION['id_usuarios']??0));$out=['reporte'=>$r,'historial_id'=>$hid];}
    elseif($action==='historial'){
        $page=max(1,(int)($input['pagina']??1));$offset=($page-1)*15;
        $out=['filas'=>bi_query($conexion,'SELECT h.id,h.nombre,h.creado,h.filtros,h.metodo,u.nombre usuario FROM bi_reporte_historial h JOIN usuario u ON u.id_usuarios=h.usuario_id ORDER BY h.id DESC LIMIT 15 OFFSET '.(int)$offset),'total'=>(int)bi_query($conexion,'SELECT COUNT(*) n FROM bi_reporte_historial')[0]['n'],'pagina'=>$page];
    } elseif($action==='opciones') {
        $all=bi_dataset($conexion,bi_filtros(['fuente'=>$f['fuente']]));$opts=['zonas'=>[],'condominios'=>[],'edificios'=>[],'propiedades'=>[]];
        foreach($all['propiedades'] as $a){$opts['zonas'][$a['zona']]=$a['zona'];$opts['condominios'][$a['cid']]=$a['condominio'].' · '.$a['cid'];$opts['edificios'][$a['eid']]=$a['edificio'].' · '.$a['eid'];$opts['propiedades'][$a['id']]=$a['edificio'].' / '.$a['numero'].' · '.$a['id'];}$out=$opts;
    } else throw new InvalidArgumentException('Acción inválida.');
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('VIORA reportes: '.get_class($e).' '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'No fue posible generar el reporte. Revise la conexión y la migración de reportes.']);}
