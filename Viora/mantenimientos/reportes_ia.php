<?php
require_once __DIR__.'/reportes_servicio.php';
require_once __DIR__.'/reportes_documento.php';
function bi_declaracion_catalogo(): array {return ['name'=>'catalogo_reportes','description'=>'Devuelve el catálogo de 30 reportes BI, definiciones y opciones reales de zonas e inmuebles para construir filtros seguros.','parameters'=>['type'=>'object','properties'=>new stdClass()]];}
function bi_declaracion_generar(): array {
    $properties=[];
    foreach(['desde'=>'Fecha inicial YYYY-MM-DD','hasta'=>'Fecha final YYYY-MM-DD','zona'=>'Nombre exacto de ciudad; vacío elimina filtro','zona2'=>'Segunda ciudad para comparar; vacío elimina filtro','condominio'=>'Identificador real:N o demo:N del catálogo','edificio'=>'Identificador real:N o demo:N','propiedad'=>'Identificador de apartamento real:N o demo:N','fuente'=>'real, demo o todos; usar demo solo a petición explícita'] as $k=>$desc)$properties[$k]=['type'=>'string','description'=>$desc];
    $properties['meses']=['type'=>'integer','description'=>'Meses futuros de proyección, 1 a 12'];
    return ['name'=>'generar_reporte_bi','description'=>'Consulta datos reales y genera reporte con tabla, métricas, interpretación y PDF privado. Reutiliza el último reporte y filtros si se omiten. Para por qué/PDF de ese resultado usa reporte anterior.','parameters'=>['type'=>'object','properties'=>['reporte_id'=>['type'=>'integer','description'=>'ID 1 a 30 del catálogo. Omitir para continuar el reporte anterior.'],'filtros'=>['type'=>'object','properties'=>$properties]]]];
}
function bi_herramienta(mysqli $db,string $name,array $args): array {
    global $biToolResult;
    // Verify authority from DB even when the function is invoked outside the chatbot endpoint.
    $uid=(int)($_SESSION['id_usuario']??$_SESSION['id_usuarios']??0);
    $r=bi_query($db,'SELECT r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.id_usuarios=? AND u.activo=1',[$uid]);
    if(!$r || !in_array(strtolower($r[0]['nombre']),['admin','administrador'],true))return ['error'=>'Solo administradores pueden consultar reportes.'];
    if($name==='catalogo_reportes') {
        $source=bi_filtros($_SESSION['bi_filtros_pagina']??$_SESSION['bi_contexto']['filtros']??[])['fuente'];$d=bi_dataset($db,bi_filtros(['fuente'=>$source]));$opts=[];
        foreach($d['propiedades'] as $a)$opts[$a['id']]=['propiedad'=>$a['id'],'zona'=>$a['zona'],'condominio'=>$a['cid'],'nombre_condominio'=>$a['condominio'],'edificio'=>$a['eid'],'nombre_edificio'=>$a['edificio'],'numero'=>$a['numero']];
        return ['reportes'=>array_values(bi_catalogo()),'opciones'=>array_slice(array_values($opts),0,150),'opciones_total'=>count($opts),'nota'=>'Los filtros por fechas se aplican a cobros/gastos/solicitudes. Estados de facturas, precios y cancelaciones son actuales. Reporte 30 separa monedas y requiere pagos de suscripción conciliados.'];
    }
    $previous=$_SESSION['bi_contexto']??[];$id=filter_var($args['reporte_id']??$previous['reporte_id']??0,FILTER_VALIDATE_INT);
    if(!$id || !isset(bi_catalogo()[$id]))return ['error'=>'Seleccione un reporte del catálogo, del 1 al 30.'];
    if(isset($args['filtros']) && !is_array($args['filtros']))return ['error'=>'Filtros inválidos.'];
    $filters=array_replace($previous['filtros']??[],$args['filtros']??[]);
    // Visible filters initialize a new context; follow-ups keep the previously executed filters.
    if(!$previous)$filters=array_replace($_SESSION['bi_filtros_pagina']??[],$args['filtros']??[]);
    try {
        $r=bi_reporte($db,$id,$filters);$hid=bi_guardar($db,$r,$uid,'ia');
        $_SESSION['bi_contexto']=['reporte_id'=>$id,'filtros'=>$r['filtros'],'historial_id'=>$hid];$biToolResult=['reporte'=>$r,'historial_id'=>$hid];
        $result=$r;$result['filas']=array_slice($r['filas'],0,100);$result['total_filas']=count($r['filas']);
        $base=rtrim(dirname(dirname($_SERVER['SCRIPT_NAME']??'/mantenimientos/chatbot_api.php')),'/');
        return $result+['pdf'=>$base.'/mantenimientos/reportes_api.php?accion=pdf&id='.$hid,'descargar'=>$base.'/mantenimientos/reportes_api.php?accion=pdf&id='.$hid.'&descargar=1','nota'=>'El PDF contiene todas las filas. La tabla enviada al modelo se limita a 100 grupos; no asumas que es el total.'];
    }catch(InvalidArgumentException $e){return ['error'=>$e->getMessage()];}
}
