<?php
/** Amplía únicamente las tablas aisladas de demostración. Ejecutar tras un respaldo. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../Viora/conf/conexion.php';
require __DIR__.'/../Viora/mantenimientos/reportes_servicio.php';
$db=$conexion;
$lote='reportes-disponibles-v2';
$tablas=[
    'condominio'=>'id_condominios','edificio'=>'id_edificios',
    'apartamento'=>'id_apartamentos','persona'=>'id_personas',
    'usuario'=>'id_usuarios','propiedad'=>'id_propiedades',
    'contrato'=>'id_contratos','factura'=>'id_facturas',
    'factura_pago'=>'id_pagos','ocupacion'=>'id_ocupaciones',
    'solicitud_propiedad'=>'id_solicitudes','solicitud_mantenimiento'=>'id_solicitudes',
    'mantenimiento'=>'id_mantenimientos','suscripcion'=>'id_suscripcion',
    'gasto'=>'id','inversion'=>'id','suscripcion_pago'=>'id',
    'propiedad_info'=>'apartamento_id',
];
$relaciones=[
    'condominio_id'=>'condominio','edificio_id'=>'edificio',
    'apartamento_id'=>'apartamento','persona_id'=>'persona',
    'usuario_id'=>'usuario','contrato_id'=>'contrato',
    'propiedad_id'=>'propiedad','factura_id'=>'factura','suscripcion_id'=>'suscripcion',
];
$lock=$db->query("SELECT GET_LOCK('bi_seed_disponibles_v2',30)")->fetch_row()[0];
if((int)$lock!==1)throw new RuntimeException('Otra carga está en ejecución.');
$conteos=[];$mapas=[];
$ayer=(new DateTimeImmutable('now',new DateTimeZone('America/Santo_Domingo')))->modify('-1 day')->format('Y-m-d');
$db->begin_transaction();
try {
    $existe=bi_query($db,'SELECT clave FROM bi_demo_lote WHERE clave=?',[$lote]);
    if(!$existe) {
        foreach($tablas as $tabla=>$pk) {
            // El lote original tiene IDs 1–30, y 32 abonos que deben conservarse juntos.
            $campoOrigen=$tabla==='factura_pago'?'factura_id':$pk;
            $filas=bi_query($db,'SELECT * FROM bi_demo_'.$tabla.' WHERE `'.$campoOrigen.'`<=? ORDER BY `'.$pk.'`',[30]);
            if(count($filas)<30)throw new RuntimeException('Falta el lote original en '.$tabla.'. Ejecute migrate-reportes.php primero.');
            $columnas=bi_query($db,'SHOW COLUMNS FROM bi_demo_'.$tabla);
            $generadas=[];foreach($columnas as $c)if(str_contains(strtoupper($c['Extra']),'GENERATED'))$generadas[]=$c['Field'];
            foreach($filas as $fila) {
                $anterior=(int)$fila[$pk];unset($fila[$pk]);
                foreach($generadas as $col)unset($fila[$col]);
                foreach($relaciones as $col=>$destino) {
                    if(!isset($fila[$col]))continue;
                    if(!isset($mapas[$destino][(int)$fila[$col]]))throw new RuntimeException('Relación no resuelta: '.$tabla.'.'.$col);
                    $fila[$col]=$mapas[$destino][(int)$fila[$col]];
                }
                // Nunca se crean cuentas operativas, cobros ni publicaciones reales.
                if($tabla==='condominio')$fila['nombre']='DEMO Disponibles '.$anterior;
                if($tabla==='edificio')$fila['nombre']='DEMO Edificio disponible '.$anterior;
                if($tabla==='apartamento') {
                    $fila['numero']='DISP-'.$anterior;$fila['estado']='Disponible';
                    $fila['disponible']=1;$fila['activo']=1;
                }
                if($tabla==='persona') {
                    $fila['nombres']='Cliente DEMO adicional '.$anterior;
                    $fila['correo']='demo-disponible-'.$anterior.'@example.invalid';$fila['cedula']=null;
                }
                if($tabla==='usuario') {
                    $fila['usuario']='bi-demo-disponible-'.$anterior;
                    $fila['nombre']='Cuenta DEMO adicional '.$anterior;
                    $fila['clave']='!INICIO_SESION_DESHABILITADO';$fila['activo']=0;$fila['estado_cuenta']='Suspendido';
                }
                if($tabla==='propiedad')$fila['estado']='Disponible';
                if($tabla==='contrato') {
                    $fila['numero_contrato']='DEMO-DISP-C-'.$anterior;
                    $fila['fecha_fin']=$ayer;$fila['estado']='Finalizado';$fila['activo']=0;
                    if($fila['fecha_inicio']>$ayer)$fila['fecha_inicio']=$ayer;
                }
                if($tabla==='factura')$fila['numero_documento']='DEMO-DISP-F-'.$anterior;
                if(array_key_exists('referencia',$fila))$fila['referencia']='DEMO-DISP-'.$tabla.'-'.$anterior;
                if($tabla==='ocupacion') {
                    $fila['fecha_fin']=$ayer;$fila['estado']='Finalizada';
                    if($fila['fecha_inicio']>$ayer)$fila['fecha_inicio']=$ayer;
                }
                if($tabla==='solicitud_propiedad')$fila['estado']='Cancelada';
                if($tabla==='propiedad_info') {
                    $fila['apartamento_id']=$mapas['apartamento'][$anterior];$fila['disponible_desde']=$ayer;
                }
                $cols=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($fila)));
                $q=$db->prepare('INSERT INTO bi_demo_'.$tabla.' ('.$cols.') VALUES ('.implode(',',array_fill(0,count($fila),'?')).')');
                $values=array_values($fila);$q->bind_param(str_repeat('s',count($values)),...$values);$q->execute();
                $mapas[$tabla][$anterior]=$tabla==='propiedad_info'?$fila['apartamento_id']:(int)$db->insert_id;
                $conteos[$tabla]=($conteos[$tabla]??0)+1;
            }
        }
        $q=$db->prepare('INSERT INTO bi_demo_lote(clave,creado,descripcion) VALUES (?,NOW(),?)');
        $descripcion='Ampliación aislada: 30 registros por tabla, 32 abonos y 30 propiedades actualmente disponibles.';
        $q->bind_param('ss',$lote,$descripcion);$q->execute();
    }
    $db->commit();
}catch(Throwable $e){$db->rollback();throw $e;}
finally{$db->query("SELECT RELEASE_LOCK('bi_seed_disponibles_v2')");}
$totales=[];
foreach($tablas as $t=>$pk)$totales[$t]=(int)bi_query($db,'SELECT COUNT(*) n FROM bi_demo_'.$t)[0]['n'];
$disponibles=bi_reporte($db,11,['fuente'=>'demo']);
$totalDisponibles=array_sum(array_column($disponibles['filas'],'Disponibles'));
if($totalDisponibles<30)throw new RuntimeException('La verificación no encontró 30 propiedades disponibles.');
echo json_encode(['lote'=>$lote,'agregados'=>$conteos,'totales'=>$totales,'propiedades_disponibles'=>$totalDisponibles,'por_zona'=>$disponibles['filas']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
