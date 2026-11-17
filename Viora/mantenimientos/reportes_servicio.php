<?php
require_once __DIR__.'/reportes_catalogo.php';

function bi_query(mysqli $db, string $sql, array $params=[]): array {
    $q=$db->prepare($sql);
    if ($params) $q->bind_param(str_repeat('s',count($params)), ...$params);
    $q->execute(); $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close(); return $rows;
}
function bi_filtros(array $input): array {
    $now=new DateTimeImmutable('now',new DateTimeZone('America/Santo_Domingo'));
    $f=['desde'=>$input['desde']??$now->format('Y-01-01'),'hasta'=>$input['hasta']??$now->format('Y-m-d'),'fuente'=>$input['fuente']??'real'];
    foreach (['desde','hasta'] as $k) {
        if (!is_string($f[$k])) throw new InvalidArgumentException('Fecha inválida.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$f[$k]);
        if (!$d || $d->format('Y-m-d')!==$f[$k]) throw new InvalidArgumentException('Use fechas YYYY-MM-DD válidas.');
    }
    if ($f['desde']>$f['hasta'] || (strtotime($f['hasta'])-strtotime($f['desde']))>3660*86400) throw new InvalidArgumentException('El período debe ser de hasta diez años y estar ordenado.');
    if (!in_array($f['fuente'],['real','demo','todos'],true)) throw new InvalidArgumentException('Fuente inválida.');
    $f['zona']=trim((string)($input['zona']??''));
    if (mb_strlen($f['zona'])>100) throw new InvalidArgumentException('Zona demasiado larga.');
    // Dos zonas permiten comparaciones sin una consulta SQL libre.
    $f['zona2']=trim((string)($input['zona2']??''));
    if (mb_strlen($f['zona2'])>100) throw new InvalidArgumentException('Zona demasiado larga.');
    if ($f['zona2']!=='' && $f['zona']==='') throw new InvalidArgumentException('Seleccione la primera zona antes de comparar.');
    foreach (['condominio','edificio','propiedad'] as $k) {
        $f[$k]=(string)($input[$k]??'');
        if ($f[$k]!=='' && !preg_match('/^(real|demo):[1-9][0-9]*$/',$f[$k])) throw new InvalidArgumentException('Identificador de filtro inválido.');
    }
    $f['meses']=filter_var($input['meses']??3,FILTER_VALIDATE_INT);
    if ($f['meses']===false || $f['meses']<1 || $f['meses']>12) throw new InvalidArgumentException('La proyección permite entre uno y doce meses.');
    return $f;
}
function bi_dataset(mysqli $db, array $f): array {
    $d=['propiedades'=>[],'cobros'=>[],'facturas'=>[],'gastos'=>[],'inversiones'=>[],'solicitudes'=>[],'reparaciones'=>[],'contratos'=>[],'clientes'=>[],'suscripciones'=>[],'suscripcion_pagos'=>[]];
    $sources=$f['fuente']==='todos'?['real','demo']:[$f['fuente']];
    foreach ($sources as $source) {
        $pre=$source==='demo'?'bi_demo_':'';
        $key=fn($id)=>$source.':'.$id;
        $cond=[]; foreach(bi_query($db,"SELECT id_condominios,nombre,ciudad FROM {$pre}condominio") as $r) $cond[$r['id_condominios']]=$r;
        $ed=[]; foreach(bi_query($db,"SELECT id_edificios,condominio_id,nombre FROM {$pre}edificio") as $r) $ed[$r['id_edificios']]=$r;
        $pub=[]; foreach(bi_query($db,"SELECT apartamento_id,MIN(fecha_publicacion) fecha FROM {$pre}propiedad WHERE activo=1 GROUP BY apartamento_id") as $r) $pub[$r['apartamento_id']]=$r['fecha'];
        $info=[];$infoTable=$source==='demo'?'bi_demo_propiedad_info':'bi_propiedad_info';foreach(bi_query($db,"SELECT apartamento_id,tipo,disponible_desde FROM {$infoTable}") as $r)$info[$r['apartamento_id']]=$r;
        $occ=[]; $last=[];
        foreach (bi_query($db,"SELECT apartamento_id,tipo_ocupacion,fecha_inicio,fecha_fin,activo FROM {$pre}ocupacion") as $r) {
            if ($r['fecha_fin'] && $r['fecha_fin']<=$f['hasta']) $last[$r['apartamento_id']]=max($last[$r['apartamento_id']]??'', $r['fecha_fin']);
            if ($r['activo'] && $r['fecha_inicio']<=$f['hasta'] && (!$r['fecha_fin'] || $r['fecha_fin']>$f['hasta'])) $occ[$r['apartamento_id']]=$r['tipo_ocupacion'];
        }
        $ap=[];
        foreach (bi_query($db,"SELECT id_apartamentos,edificio_id,numero,estado,activo,tipo_operacion,precio_alquiler,precio_venta FROM {$pre}apartamento") as $r) {
            $e=$ed[$r['edificio_id']]??null; $c=$cond[$e['condominio_id']??0]??null; if (!$c) continue;
            $r+=['id'=>$key($r['id_apartamentos']),'condominio'=>$c['nombre'],'cid'=>$key($c['id_condominios']),'edificio'=>$e['nombre'],'eid'=>$key($e['id_edificios']),'zona'=>$c['ciudad']?:'Sin zona','fuente'=>$source,'ocupada'=>isset($occ[$r['id_apartamentos']]),'publicacion'=>$last[$r['id_apartamentos']]??$pub[$r['id_apartamentos']]??null];
            $r['vendida']=($occ[$r['id_apartamentos']]??'')==='Propietario' || in_array(strtolower($r['estado']),['vendido','vendida']);
            $r['tipo_inmueble']=$info[$r['id_apartamentos']]['tipo']??'Apartamento';
            if(!empty($info[$r['id_apartamentos']]['disponible_desde']))$r['publicacion']=max($r['publicacion']??'',$info[$r['id_apartamentos']]['disponible_desde']);
            if ($f['zona']!=='' && $r['zona']!==$f['zona'] && ($f['zona2']==='' || $r['zona']!==$f['zona2'])) continue;
            if ($f['condominio']!=='' && $r['cid']!==$f['condominio']) continue;
            if ($f['edificio']!=='' && $r['eid']!==$f['edificio']) continue;
            if ($f['propiedad']!=='' && $r['id']!==$f['propiedad']) continue;
            $ap[$r['id_apartamentos']]=$r; $d['propiedades'][]=$r;
        }
        $people=[]; foreach(bi_query($db,"SELECT id_personas,nombres,apellidos,activo FROM {$pre}persona") as $r) $people[$r['id_personas']]=$r;
        $clients=[]; $ct=[];
        foreach(bi_query($db,"SELECT id_contratos,apartamento_id,persona_id,tipo_operacion,fecha_inicio,fecha_fin,estado,activo FROM {$pre}contrato") as $r) {
            if (!isset($ap[$r['apartamento_id']])) continue;
            $r+=['a'=>$ap[$r['apartamento_id']],'cliente'=>trim(($people[$r['persona_id']]['nombres']??'').' '.($people[$r['persona_id']]['apellidos']??'')),'pid'=>$key($r['persona_id'])];
            $ct[$r['id_contratos']]=$r; $d['contratos'][]=$r; if ($r['persona_id']) $clients[$r['persona_id']]=true;
        }
        $invoices=[];
        foreach (bi_query($db,"SELECT id_facturas,contrato_id,persona_id,fecha,fecha_vencimiento,total,saldo_pendiente,estado FROM {$pre}factura WHERE estado NOT IN ('Borrador','Anulada')") as $r) {
            if (!isset($ct[$r['contrato_id']])) continue;
            $r+=['a'=>$ct[$r['contrato_id']]['a'],'operacion'=>$ct[$r['contrato_id']]['tipo_operacion'],'cliente'=>trim(($people[$r['persona_id']]['nombres']??'').' '.($people[$r['persona_id']]['apellidos']??'')),'pid'=>$key($r['persona_id'])];
            $invoices[$r['id_facturas']]=$r; if(bi_en_periodo($r['fecha'],$f)) $d['facturas'][]=$r;
        }
        foreach (bi_query($db,"SELECT id_pagos,factura_id,fecha_pago,monto,metodo_pago FROM {$pre}factura_pago WHERE fecha_pago>=? AND fecha_pago<DATE_ADD(?,INTERVAL 1 DAY)",[$f['desde'],$f['hasta']]) as $r) {
            if (!isset($invoices[$r['factura_id']])) continue;
            $inv=$invoices[$r['factura_id']]; $r+=['a'=>$inv['a'],'operacion'=>$inv['operacion'],'cliente'=>$inv['cliente'],'pid'=>$inv['pid']]; $d['cobros'][]=$r;
        }
        foreach (['solicitud_propiedad'=>'solicitudes','solicitud_mantenimiento'=>'reparaciones'] as $table=>$target) {
            foreach (bi_query($db,"SELECT apartamento_id,persona_id,fecha_solicitud,estado,activo FROM {$pre}{$table} WHERE activo=1") as $r) {
                if(!isset($ap[$r['apartamento_id']])) continue;
                $clients[$r['persona_id']]=true;
                if (bi_en_periodo($r['fecha_solicitud'],$f)) { $r['a']=$ap[$r['apartamento_id']]; $d[$target][]=$r; }
            }
        }
        foreach (array_keys($clients) as $id) if(isset($people[$id])) $d['clientes'][]=['id'=>$key($id),'activo'=>$people[$id]['activo']];
        $expenseTable=$source==='demo'?'bi_demo_gasto':'bi_gasto'; $investTable=$source==='demo'?'bi_demo_inversion':'bi_inversion';
        foreach ([$expenseTable=>'gastos',$investTable=>'inversiones'] as $table=>$target) {
            foreach(bi_query($db,"SELECT condominio_id,apartamento_id,fecha,monto FROM {$table} WHERE fecha<=?",[$f['hasta']]) as $r) {
                if ($target==='gastos' && !bi_en_periodo($r['fecha'],$f)) continue;
                $a=bi_ubicacion_gasto($r,$ap,$key,$f,$cond,$source); if (!$a) continue;
                $r['a']=$a; $r['tipo']='Operativo'; $d[$target][]=$r;
            }
        }
        foreach (bi_query($db,"SELECT condominio_id,fecha_realizacion,costo FROM {$pre}mantenimiento WHERE activo=1 AND estado='Realizado' AND fecha_realizacion>=? AND fecha_realizacion<=?",[$f['desde'],$f['hasta']]) as $r) {
            $a=bi_ubicacion_gasto($r,$ap,$key,$f,$cond,$source); if(!$a) continue;
            $d['gastos'][]=['a'=>$a,'monto'=>$r['costo'],'fecha'=>$r['fecha_realizacion'],'tipo'=>'Mantenimiento'];
        }
        // Suscripciones son globales: nunca se atribuyen artificialmente a un inmueble.
        if ($f['zona']==='' && $f['condominio']==='' && $f['edificio']==='' && $f['propiedad']==='') {
            foreach (bi_query($db,"SELECT id_suscripcion,estado,moneda,fecha_inicio,fecha_proximo_pago,monto FROM {$pre}suscripcion") as $r) $d['suscripciones'][]=$r+['sid'=>$key($r['id_suscripcion'])];
            $payment=$source==='demo'?'bi_demo_suscripcion_pago':'bi_suscripcion_pago';
            foreach (bi_query($db,"SELECT suscripcion_id,fecha,monto,moneda,referencia FROM {$payment} WHERE fecha>=? AND fecha<DATE_ADD(?,INTERVAL 1 DAY)",[$f['desde'],$f['hasta']]) as $r) $d['suscripcion_pagos'][]=$r+['sid'=>$key($r['suscripcion_id'])];
            if($source==='real' && bi_query($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='suscripcion_pago'")) {
                $payments=bi_query($db,"SELECT p.paypal_transaction_id,p.fecha_pago fecha,p.monto,p.moneda,s.id_suscripcion suscripcion_id FROM suscripcion_pago p JOIN suscripcion s ON CAST(s.paypal_subscription_id AS BINARY)=CAST(p.paypal_subscription_id AS BINARY) WHERE UPPER(p.estado) IN ('COMPLETED','COMPLETADA','PAGADO','PAGADA') AND p.fecha_pago>=? AND p.fecha_pago<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM bi_suscripcion_pago b WHERE CAST(b.referencia AS BINARY)=CAST(p.paypal_transaction_id AS BINARY))",[$f['desde'],$f['hasta']]);
                foreach($payments as $r)$d['suscripcion_pagos'][]=$r+['sid'=>$key($r['suscripcion_id'])];
            }
        }
    }
    return $d;
}
function bi_ubicacion_gasto(array $r,array $ap,callable $key,array $f,array $cond=[],string $source='real'): ?array {
    if (!empty($r['apartamento_id'])) return $ap[$r['apartamento_id']]??null;
    // Los gastos comunes no se reparten con un criterio inventado entre edificios o apartamentos.
    if ($f['edificio']!=='' || $f['propiedad']!=='') return null;
    foreach($ap as $a) if($a['cid']===$key($r['condominio_id'])) { $a['id']=''; return $a; }
    $c=$cond[$r['condominio_id']]??null;
    if($c && ($f['condominio']==='' || $f['condominio']===$key($c['id_condominios'])) && ($f['zona']==='' || $f['zona']===($c['ciudad']?:'Sin zona') || $f['zona2']===($c['ciudad']?:'Sin zona')))return ['id'=>'','cid'=>$key($c['id_condominios']),'condominio'=>$c['nombre'],'zona'=>$c['ciudad']?:'Sin zona','fuente'=>$source];
    return null;
}
function bi_en_periodo(?string $date,array $f): bool { $date=substr($date??'',0,10); return $date!=='' && $date>=$f['desde'] && $date<=$f['hasta']; }
function bi_group(array $rows,callable $key,callable $value): array {
    $out=[]; foreach($rows as $r) { $k=$key($r); if(!isset($out[$k])) $out[$k]=[]; $out[$k][]=$r; }
    $result=[]; foreach($out as $k=>$group) $result[]=$value($k,$group); return $result;
}
function bi_sum(array $rows,string $key): float { return array_sum(array_map(fn($r)=>(float)($r[$key]??0),$rows)); }
function bi_finanzas(array $d,string $group): array {
    $out=[];
    $ensure=function(array $a) use (&$out,$group) {
        $k=match($group) {'condominio'=>$a['cid'],'propiedad'=>$a['id'],'tipo'=>$a['id']!==''?$a['tipo_inmueble']:'',default=>$a['zona']};
        if($k==='') return null;
        if(!isset($out[$k])) $out[$k]=['Grupo'=>match($group){'condominio'=>$a['condominio'].' · '.$a['cid'],'propiedad'=>$a['edificio'].' / '.$a['numero'].' · '.$a['id'],'tipo'=>$a['tipo_inmueble'],default=>$a['zona']},'Cobrado'=>0.0,'Gastos'=>0.0,'Inversión'=>0.0,'_costos'=>0]; return $k;
    };
    foreach($d['propiedades'] as $a) $ensure($a);
    foreach(['cobros'=>'Cobrado','gastos'=>'Gastos','inversiones'=>'Inversión'] as $target=>$label) foreach($d[$target] as $r) { $k=$ensure($r['a']); if($k===null)continue; $out[$k][$label]+=(float)$r['monto']; if($target==='gastos')$out[$k]['_costos']++; }
    foreach($out as &$r) {
        $r['Beneficio neto']=$r['_costos']?$r['Cobrado']-$r['Gastos']:null;
        $r['Margen %']=$r['_costos'] && $r['Cobrado']>0?round($r['Beneficio neto']/$r['Cobrado']*100,2):null;
        $r['ROI %']=$r['_costos'] && $r['Inversión']>0?round($r['Beneficio neto']/$r['Inversión']*100,2):null; unset($r['_costos']);
    } unset($r); return array_values($out);
}
function bi_meses(array $f): array {
    $d=new DateTimeImmutable(substr($f['desde'],0,7).'-01'); $end=substr($f['hasta'],0,7); $out=[];
    while($d->format('Y-m')<=$end) {$out[$d->format('Y-m')]=0.0; $d=$d->modify('+1 month');} return $out;
}
function bi_reporte(mysqli $db,int $id,array $input,?array $dataset=null): array {
    $cat=bi_catalogo(); if(!isset($cat[$id])) throw new InvalidArgumentException('Reporte inexistente.');
    $f=bi_filtros($input); $d=$dataset??bi_dataset($db,$f); $rows=[];
    $warnings=['Moneda inmobiliaria: DOP. Fuente de cobros: factura_pago, excluyendo facturas anuladas y borradores. No se suman pago ni viora_pago para evitar duplicaciones. Los cobros incluyen los conceptos e impuestos de la factura; no equivalen a ingresos contables sin impuestos.', 'Gastos e inversión: solo importes registrados; un margen calculable es provisional y depende de la integridad de los gastos. La inversión es acumulada hasta la fecha final.', 'Los saldos, precios, estados de solicitudes y disponibilidad son actuales; ocupación se reconstruye con las fechas de ocupacion. No se presenta un saldo histórico reconstruido.'];
    $active=array_values(array_filter($d['propiedades'],fn($a)=>$a['activo']));
    if(in_array($id,[1,2,3,4,5],true)) {
        $rows=bi_finanzas($d,in_array($id,[3,5],true)?'condominio':($id===4?'propiedad':'zona'));
        $sort=$id===4?'Cobrado':'Margen %';
        usort($rows,function($a,$b)use($sort,$id){ if($a[$sort]===null)return $b[$sort]===null?0:1;if($b[$sort]===null)return -1;return $id===2?$a[$sort]<=>$b[$sort]:$b[$sort]<=>$a[$sort]; });
    } elseif(in_array($id,[6,7,8,27],true)) {
        $months=bi_meses($f); foreach($d['cobros'] as $r) {if($id===6 && $r['operacion']!=='Alquiler')continue; if($id===7 && $r['operacion']!=='Venta')continue; $months[substr($r['fecha_pago'],0,7)]+=(float)$r['monto'];}
        foreach($months as $m=>$v) $rows[]=['Mes'=>$m,'Año'=>(int)substr($m,0,4),'Cobrado'=>$v];
    } elseif($id===9) {
        $end=new DateTimeImmutable($f['hasta']); $months=bi_meses($f); foreach($d['cobros'] as $r)$months[substr($r['fecha_pago'],0,7)]+=(float)$r['monto'];
        $base=[]; foreach($months as $m=>$v) if(($m<substr($f['hasta'],0,7) || ($m===substr($f['hasta'],0,7) && $end->format('d')===$end->format('t'))) && $m.'-01'>=$f['desde'])$base[$m]=$v;
        $base=array_slice($base,-3,true);
        if(count($base)<3) $warnings[]='Se requieren tres meses completos en el período para proyectar.';
        else for($n=1;$n<=$f['meses'];$n++)$rows[]=['Mes'=>$end->modify('first day of this month')->modify('+'.$n.' months')->format('Y-m'),'Estimación'=>round(array_sum($base)/3,2),'Base'=>implode(', ',array_keys($base))];
        $warnings[]='Estimación por promedio móvil de tres meses completos, incluidos meses sin cobros. No es ingreso garantizado ni un modelo de estacionalidad.';
    } elseif($id===10) {
        $rows=bi_finanzas($d,'tipo');
        $warnings[]='Tipología física de bi_propiedad_info; por defecto Apartamento para el inventario existente. Los gastos comunes no se distribuyen: los márgenes por tipo reflejan solo gastos directos atribuidos al inmueble. Consulte el condominio para rentabilidad completa.';
    } elseif(in_array($id,[11,12,13,14,16,17,23],true)) {
        $groups=bi_group($active,fn($a)=>$id===13?$a['cid']:$a['zona'],fn($k,$g)=>['key'=>$k,'items'=>$g]);
        foreach($groups as $gr) { $g=$gr['items']; $a=$g[0];$label=$id===13?$a['condominio'].' · '.$a['cid']:$a['zona'];
            if($id===11)$rows[]=['Zona'=>$label,'Disponibles'=>count(array_filter($g,fn($a)=>!$a['vendida'] && !$a['ocupada'] && $a['estado']==='Disponible'))];
            if($id===12)$rows[]=['Zona'=>$label,'Ocupadas'=>count(array_filter($g,fn($a)=>$a['ocupada'] && !$a['vendida']))];
            if($id===13){$base=array_filter($g,fn($a)=>!$a['vendida'] && $a['tipo_operacion']!=='Venta');$occupied=count(array_filter($base,fn($a)=>$a['ocupada']));$rows[]=['Condominio'=>$label,'Total ocupable'=>count($base),'Ocupadas'=>$occupied,'Ocupación %'=>count($base)?round(100*$occupied/count($base),2):null];}
            if(in_array($id,[14,23],true)) { $s=array_filter($d['solicitudes'],fn($r)=>$r['a']['zona']===$a['zona']);$rows[]=['Zona'=>$label,'Solicitudes'=>count($s),'Propiedades'=>count($g),'Solicitudes / propiedad'=>count($g)?round(count($s)/count($g),2):null]; }
            if(in_array($id,[16,17],true)){$col=$id===16?'precio_alquiler':'precio_venta';$prices=array_filter($g,fn($a)=>$a[$col]>0 && ($id===16?$a['tipo_operacion']!=='Venta':$a['tipo_operacion']!=='Alquiler'));$rows[]=['Zona'=>$label,'Precio promedio'=>count($prices)?round(bi_sum($prices,$col)/count($prices),2):null,'Muestra'=>count($prices)];}
        }
        if(in_array($id,[14,23],true))usort($rows,fn($a,$b)=>$b['Solicitudes']<=>$a['Solicitudes']);
    } elseif($id===15) {
        foreach($active as $a) if(!$a['ocupada'] && !$a['vendida'] && $a['estado']==='Disponible')$rows[]=['Propiedad'=>$a['edificio'].' / '.$a['numero'].' · '.$a['id'],'Zona'=>$a['zona'],'Desde'=>$a['publicacion'],'Días sin ocupar'=>$a['publicacion']?max(0,(int)((strtotime($f['hasta'])-strtotime($a['publicacion']))/86400)):null];
        usort($rows,fn($a,$b)=>($b['Días sin ocupar']??-1)<=>($a['Días sin ocupar']??-1));
    } elseif($id===18) {
        foreach(['Venta','Alquiler'] as $type)$rows[]=['Operación'=>$type,'Contratos'=>count(array_filter($d['contratos'],fn($r)=>$r['activo'] && $r['tipo_operacion']===$type && bi_en_periodo($r['fecha_inicio'],$f)))];
    } elseif($id===19) {
        $rows=bi_group($d['cobros'],fn($r)=>$r['pid'],fn($k,$g)=>['Cliente'=>$g[0]['cliente'].' · '.$k,'Pagos'=>count($g),'Cobrado'=>bi_sum($g,'monto')]);usort($rows,fn($a,$b)=>$b['Cobrado']<=>$a['Cobrado']);
    } elseif(in_array($id,[20,25],true)) {
        $pending=array_filter($d['facturas'],fn($r)=>$r['saldo_pendiente']>0);
        $rows=bi_group($pending,fn($r)=>$r['pid'].($id===25?'|'.$r['a']['cid']:''),function($k,$g)use($id){$r=['Cliente'=>$g[0]['cliente'].' · '.$g[0]['pid']];if($id===25)$r['Condominio']=$g[0]['a']['condominio'].' · '.$g[0]['a']['cid'];$r['Facturas']=count($g);$r['Pendiente']=bi_sum($g,'saldo_pendiente');$r['Vencido']=bi_sum(array_filter($g,fn($x)=>$x['fecha_vencimiento'] && $x['fecha_vencimiento']<(new DateTimeImmutable('now',new DateTimeZone('America/Santo_Domingo')))->format('Y-m-d')),'saldo_pendiente');return $r;});
        usort($rows,fn($a,$b)=>$b['Pendiente']<=>$a['Pendiente']);
    } elseif($id===21) { foreach([1=>'Activos',0=>'Inactivos'] as $state=>$label)$rows[]=['Estado'=>$label,'Clientes'=>count(array_filter($d['clientes'],fn($r)=>(int)$r['activo']===$state))];
    } elseif($id===22) { $rows=bi_group($d['solicitudes'],fn($r)=>$r['estado'],fn($k,$g)=>['Estado'=>$k,'Solicitudes'=>count($g)]);
    } elseif($id===24) { $rows=bi_group($d['facturas'],fn($r)=>$r['estado'],fn($k,$g)=>['Estado'=>$k,'Facturas'=>count($g),'Facturado'=>bi_sum($g,'total'),'Saldo actual'=>bi_sum($g,'saldo_pendiente')]);
    } elseif($id===26) { $rows=bi_group($d['cobros'],fn($r)=>$r['metodo_pago'],fn($k,$g)=>['Método'=>$k,'Pagos'=>count($g),'Cobrado'=>bi_sum($g,'monto')]);
    } elseif($id===28) { $rows=bi_group(array_filter($d['gastos'],fn($r)=>$r['tipo']==='Mantenimiento'),fn($r)=>$r['a']['cid'],fn($k,$g)=>['Condominio'=>$g[0]['a']['condominio'].' · '.$k,'Trabajos'=>count($g),'Gastos'=>bi_sum($g,'monto')]);usort($rows,fn($a,$b)=>$b['Gastos']<=>$a['Gastos']);
    } elseif($id===29) { $rows=bi_group($d['reparaciones'],fn($r)=>substr($r['fecha_solicitud'],0,7).'|'.$r['a']['id'].'|'.$r['estado'],fn($k,$g)=>['Mes'=>substr($g[0]['fecha_solicitud'],0,7),'Propiedad'=>$g[0]['a']['edificio'].' / '.$g[0]['a']['numero'].' · '.$g[0]['a']['id'],'Estado'=>$g[0]['estado'],'Solicitudes'=>count($g)]);
    } elseif($id===30) {
        $currencies=array_unique(array_merge(array_column($d['suscripciones'],'moneda'),array_column($d['suscripcion_pagos'],'moneda')));
        foreach($currencies as $currency) { $ss=array_filter($d['suscripciones'],fn($r)=>$r['moneda']===$currency);$pp=array_filter($d['suscripcion_pagos'],fn($r)=>$r['moneda']===$currency);$ren=0;foreach($pp as $p){$s=array_values(array_filter($ss,fn($s)=>$s['sid']===$p['sid']))[0]??null;if($s && substr($p['fecha'],0,7)>substr($s['fecha_inicio']??'',0,7))$ren++;}
            $rows[]=['Moneda'=>$currency,'Cobrado'=>bi_sum($pp,'monto'),'Pagos de renovación'=>$ren,'Vencimientos en período'=>count(array_filter($ss,fn($s)=>bi_en_periodo($s['fecha_proximo_pago'],$f))),'Canceladas actuales'=>count(array_filter($ss,fn($s)=>in_array(strtolower($s['estado']),['cancelado','cancelada','cancelled']))),'Activas actuales'=>count(array_filter($ss,fn($s)=>in_array(strtolower($s['estado']),['activo','activa','active'])))];
        }
        $warnings[]='Las fechas históricas de cancelación no están registradas: se muestra el estado actual. Los pagos de suscripción requieren conciliación explícita en bi_suscripcion_pago, no se deducen del importe del plan.';
        if($f['zona']!==''||$f['condominio']!==''||$f['edificio']!==''||$f['propiedad']!=='')$warnings[]='Las suscripciones no tienen vínculo geográfico: quite los filtros de inmuebles para consultarlas.';
    }
    $missing=false;foreach($rows as $r)if(in_array(null,$r,true))$missing=true;
    if($missing)$warnings[]='N/D indica que falta fecha, gasto atribuido, inversión o un denominador válido. No se sustituye por cero.';
    if($id===4 || $f['edificio']!=='' || $f['propiedad']!=='')$warnings[]='Los gastos comunes del condominio no se distribuyen a edificios o propiedades. El margen de ese nivel puede estar incompleto.';
    $summary=$rows?count($rows).' grupos con información de la base de datos.':'No hay datos para los filtros seleccionados.';
    $conclusion=$missing?'Complete los datos marcados N/D antes de comparar rentabilidades.':'Compare el período, la fuente y la cobertura de gastos antes de decidir.';
    $interpretation='No hay evidencia suficiente para extraer conclusiones con estos filtros.';
    $recommendation='Revise saldos vencidos, valide gastos e inversiones y contraste varios períodos. Las proyecciones son estimaciones.';
    if($rows) {
        $first=$rows[0];$parts=[];foreach($first as $k=>$v)$parts[]=$k.': '.($v===null?'N/D':(is_numeric($v)?number_format((float)$v,2,'.',','):$v));
        $summary.=' Primer grupo: '.implode('; ',$parts).'.';
        $interpretation='Los resultados corresponden a '.$f['desde'].'–'.$f['hasta'].' y la fuente '.$f['fuente'].'. '.$cat[$id]['descripcion'];
        if(in_array($id,[1,2,3,5,10],true)) {
            $negatives=count(array_filter($rows,fn($x)=>($x['Beneficio neto']??0)<0));
            $known=count(array_filter($rows,fn($x)=>($x['Margen %']??null)!==null));
            $interpretation.=' Hay '.$known.' grupos con margen calculable y '.$negatives.' con beneficio negativo registrado.';
            $conclusion=$known?($first['Margen %']!==null?$first['Grupo'].' presenta un margen de '.$first['Margen %'].'% en esta selección.':'Existen grupos sin margen calculable.').' Las diferencias dependen de cobros y costos registrados.':'Faltan gastos o cobros para comparar márgenes de forma válida.';
            $recommendation=$negatives?'Priorice la revisión de los grupos con pérdidas y los gastos pendientes de registrar.':'Valide costos completos y ocupación antes de ampliar la inversión en los grupos con mayor margen.';
        }
        if(in_array($id,[20,25],true)){$pending=bi_sum($rows,'Pendiente');$overdue=bi_sum($rows,'Vencido');$interpretation.=' Saldo pendiente actual: RD$ '.number_format($pending,2).'; vencido: RD$ '.number_format($overdue,2).'.';$conclusion=$overdue>0?'Existen saldos vencidos que requieren seguimiento.':'No aparecen saldos vencidos con vencimiento registrado en esta selección.';$recommendation='Priorice los saldos vencidos de mayor importe y revise las facturas sin fecha de vencimiento.';}
        if($id===9){$conclusion='La estimación mensual es RD$ '.number_format((float)$first['Estimación'],2).' durante '.$f['meses'].' meses; no es ingreso garantizado.';$recommendation='Contraste la estimación con vencimientos de contratos, estacionalidad y cobros pendientes antes de presupuestar.';}
        if(in_array($id,[6,7,8,27],true)){$total=bi_sum($rows,'Cobrado');$interpretation.=' Los cobros del período suman RD$ '.number_format($total,2).'.';$conclusion='Los cobros registrados muestran '.count(array_filter($rows,fn($x)=>$x['Cobrado']>0)).' meses con actividad de '.count($rows).' meses del período.';}
    }
    return $cat[$id]+['filtros'=>$f,'generado'=>(new DateTimeImmutable('now',new DateTimeZone('America/Santo_Domingo')))->format('Y-m-d H:i:s'),'filas'=>$rows,'advertencias'=>$warnings,'resumen'=>$summary,'interpretacion'=>$interpretation,'conclusion'=>$conclusion,'recomendacion'=>$recommendation];
}
function bi_dashboard(mysqli $db,array $input): array {
    $f=bi_filtros($input);$d=bi_dataset($db,$f);$income=bi_sum($d['cobros'],'monto');$cost=bi_sum($d['gastos'],'monto');$profit=$d['gastos']?$income-$cost:null;
    $zones=bi_reporte($db,1,$f,$d);$condos=bi_reporte($db,3,$f,$d);$occupancy=bi_reporte($db,13,$f,$d);
    return ['indicadores'=>['Ingresos cobrados'=>$income,'Gastos registrados'=>$cost,'Beneficio neto'=>$profit,'Margen %'=>$profit!==null&&$income>0?round($profit/$income*100,2):null,'Zona más rentable'=>($zones['filas'][0]['Margen %']??null)!==null?$zones['filas'][0]['Grupo']:null,'Condominio más rentable'=>($condos['filas'][0]['Margen %']??null)!==null?$condos['filas'][0]['Grupo']:null,'Propiedades disponibles'=>array_sum(array_column(bi_reporte($db,11,$f,$d)['filas'],'Disponibles')),'Propiedades ocupadas'=>array_sum(array_column(bi_reporte($db,12,$f,$d)['filas'],'Ocupadas')),'Cuentas por cobrar'=>bi_sum($d['facturas'],'saldo_pendiente'),'Facturas pendientes'=>count(array_filter($d['facturas'],fn($r)=>$r['saldo_pendiente']>0)),'Clientes activos'=>count(array_filter($d['clientes'],fn($r)=>$r['activo'])),'Ocupación %'=>array_sum(array_column($occupancy['filas'],'Total ocupable'))?round(100*array_sum(array_column($occupancy['filas'],'Ocupadas'))/array_sum(array_column($occupancy['filas'],'Total ocupable')),2):null], 'zonas'=>$zones['filas'],'tendencia'=>bi_reporte($db,8,$f,$d)['filas'],'facturas'=>bi_reporte($db,24,$f,$d)['filas'],'advertencias'=>$zones['advertencias']];
}
