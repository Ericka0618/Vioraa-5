<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../Viora/conf/conexion.php';require __DIR__.'/../Viora/mantenimientos/reportes_servicio.php';require __DIR__.'/../Viora/mantenimientos/reportes_documento.php';
function check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL; }
$today=(new DateTimeImmutable('now',new DateTimeZone('America/Santo_Domingo')))->format('Y-m-d');
$filters=['desde'=>substr($today,0,4).'-01-01','hasta'=>$today,'fuente'=>'demo'];
$db=$conexion;$d=bi_dataset($db,bi_filtros($filters));$uid=(int)bi_query($db,"SELECT u.id_usuarios FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE LOWER(r.nombre) IN ('administrador','admin') AND u.activo=1 LIMIT 1")[0]['id_usuarios'];
$files=getenv('BI_TEST_PDF_DIR')?:__DIR__.'/../.cache/reportes/pdf';if(!is_dir($files))mkdir($files,0770,true);
check(count(bi_catalogo())===30,'catalog has 30 distinct reports');
foreach(['real','demo','todos'] as $source) {
 $sourceFilters=bi_filtros(array_replace($filters,['fuente'=>$source]));$sourceData=bi_dataset($db,$sourceFilters);
 foreach(bi_catalogo() as $id=>$cat){
    $r=bi_reporte($db,$id,$sourceFilters,$sourceData);
    $pdf=bi_pdf($r);check(str_starts_with($pdf,'%PDF-1.4')&&str_ends_with($pdf,'%%EOF'),$source.' report '.$id.' generates PDF');
    if($source==='demo')file_put_contents($files.'/'.$id.'.pdf',$pdf);
}}
$raw=bi_query($db,"SELECT COALESCE(SUM(p.monto),0) total FROM bi_demo_factura_pago p JOIN bi_demo_factura f ON f.id_facturas=p.factura_id WHERE f.estado NOT IN ('Anulada','Borrador') AND p.fecha_pago>=? AND p.fecha_pago<DATE_ADD(?,INTERVAL 1 DAY)",[$filters['desde'],$filters['hasta']])[0]['total'];
check(abs(bi_sum($d['cobros'],'monto')-(float)$raw)<.01,'receipts equal SQL sum with split payments counted once');
$r=bi_reporte($db,1,$filters);foreach($r['filas'] as $row){if($row['Beneficio neto']!==null){check(abs($row['Beneficio neto']-($row['Cobrado']-$row['Gastos']))<.01,'net benefit formula '.$row['Grupo']);if($row['Cobrado']>0)check(abs($row['Margen %']-round(100*$row['Beneficio neto']/$row['Cobrado'],2))<.01,'margin formula '.$row['Grupo']);if($row['Inversión']>0)check(abs($row['ROI %']-round(100*$row['Beneficio neto']/$row['Inversión'],2))<.01,'ROI formula '.$row['Grupo']);}}
$real=bi_dataset($db,bi_filtros(array_replace($filters,['fuente'=>'real'])));$all=bi_dataset($db,bi_filtros(array_replace($filters,['fuente'=>'todos'])));
check(abs(bi_sum($all['cobros'],'monto')-bi_sum($real['cobros'],'monto')-bi_sum($d['cobros'],'monto'))<.01,'demo isolation and combined totals');
foreach(['Santiago','Santo Domingo','Punta Cana','La Vega','Puerto Plata','Moca'] as $zone){$filtered=bi_dataset($db,bi_filtros($filters+['zona'=>$zone]));check(count(array_filter($filtered['propiedades'],fn($r)=>$r['zona']!==$zone))===0,'zone filter '.$zone);}
$empty=bi_reporte($db,1,array_replace($filters,['zona'=>"' OR 1=1 --"]));check(count($empty['filas'])===0,'SQL injection string treated as zone literal');
foreach([['desde'=>'2026-02-30'],['desde'=>'2027-01-01','hasta'=>'2026-01-01'],['fuente'=>'invalid'],['propiedad'=>'1 OR 1=1'],['meses'=>0]] as $invalid){$thrown=false;try{bi_filtros(array_replace($filters,$invalid));}catch(InvalidArgumentException $e){$thrown=true;}check($thrown,'invalid filters rejected '.json_encode($invalid));}
$snapshot=bi_reporte($db,8,$filters);$hid=bi_guardar($db,$snapshot,$uid);$saved=bi_query($db,'SELECT pdf,sha256,resultado FROM bi_reporte_historial WHERE id=?',[$hid])[0];check(hash_equals($saved['sha256'],hash('sha256',$saved['pdf'])),'stored PDF integrity');check(json_decode($saved['resultado'],true)['filas']==$snapshot['filas'],'history is immutable result snapshot');
$_SESSION=['id_usuario'=>$uid,'rol'=>'Administrador'];require_once __DIR__.'/../Viora/mantenimientos/reportes_ia.php';
$tool=bi_herramienta($db,'generar_reporte_bi',['reporte_id'=>1,'filtros'=>$filters+['zona'=>'Santiago']]);check(isset($tool['pdf'])&&$tool['filtros']['zona']==='Santiago','authorized AI tool produces real report and PDF');
$follow=bi_herramienta($db,'generar_reporte_bi',[]);check($follow['id']===1&&$follow['filtros']['zona']==='Santiago','follow-up preserves report and filters');
$_SESSION=['id_usuario'=>0,'rol'=>'Administrador'];check(isset(bi_herramienta($db,'generar_reporte_bi',['reporte_id'=>1])['error']),'AI cannot trust forged session role');
echo "All BI service checks passed.\n";
// Shared PDF engine regression: repeated logos remain one image object.
$engine=new PdfSimple();$logo=__DIR__.'/../Viora/imagenes/viora.jpg';$engine->imagenJpeg($logo,20,20,50,40);$engine->nuevaPagina();$engine->imagenJpeg($logo,20,20,50,40);
check(substr_count($engine->salida(),'/Subtype /Image')===1,'shared PDF engine reuses image across pages');
require_once __DIR__.'/../Viora/mantenimientos/factura_render.php';
$invoice=array_fill_keys(['encf','ecf_estado','codigo_seguridad','dgii_url','fecha_emision_ecf','cedula','telefono','edificio','apto','numero_contrato'],'');
$invoice+=['tipo_ecf'=>'E32','numero_documento'=>'DEMO-REGRESION-PDF','fecha'=>$today,'periodo'=>substr($today,0,7),'fecha_registro'=>$today.' 12:00:00','subtotal'=>100,'total'=>118,'monto_pagado'=>0,'saldo_pendiente'=>118,'nombres'=>'DEMO','apellidos'=>'PDF'];
$invoiceBytes=factura_render($invoice,[['concepto'=>'Prueba','descripcion'=>'Factura de prueba en memoria, no registrada','periodo'=>substr($today,0,7),'monto'=>100]]);
check(str_starts_with($invoiceBytes,'%PDF-'),'existing invoice renderer works without registering or issuing an invoice');
