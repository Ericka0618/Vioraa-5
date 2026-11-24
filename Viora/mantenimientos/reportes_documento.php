<?php
require_once __DIR__.'/pdf_simple.php';
function bi_pdf(array $r): string {
    $pdf=new PdfSimple();$y=0;$page=0;
    $header=function()use($pdf,&$y,&$page,$r){$page++;$pdf->imagenJpeg(__DIR__.'/../imagenes/viora.jpg',34,22,56,44);$pdf->texto(102,42,'VIORA · Inteligencia de negocios',16,true);$pdf->texto(34,79,$r['nombre'],11,true);$pdf->texto(34,98,'Generado: '.$r['generado'].' · America/Santo_Domingo',8);$pdf->texto(34,112,'Período: '.$r['filtros']['desde'].' a '.$r['filtros']['hasta'].' · Fuente: '.$r['filtros']['fuente'],8);$pdf->linea(34,122,560,122);$pdf->texto(560,819,'Página '.$page,8,false,'D');$y=142;};
    $header();
    $write=function(string $text,bool $bold=false)use($pdf,&$y,$header){foreach($pdf->envolver($text,9,520,$bold) as $line){if($y>788){$pdf->nuevaPagina();$header();}$pdf->texto(34,$y,$line,9,$bold);$y+=13;}$y+=5;};
    $write('Filtros aplicados: '.json_encode($r['filtros'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $write('Resumen ejecutivo',true);$write($r['resumen']);$write($r['descripcion']);
    if($r['filas']) {
        $write('Indicadores principales',true);
        foreach(array_slice($r['filas'][0],0,8,true) as $label=>$value)$write($label.': '.($value===null?'N/D':(string)$value));
        // A vertical table preserves long labels and all values across multiple pages.
        $write('Tabla de resultados',true);
        foreach($r['filas'] as $n=>$row){$write('Registro '.($n+1),true);foreach($row as $key=>$value)$write($key.': '.($value===null?'N/D':(string)$value));}
        $numeric=null;foreach($r['filas'][0] as $key=>$v)if(is_numeric($v) && !in_array($key,['Año'],true)){$numeric=$key;break;}
        if($numeric){$write('Gráfico comparativo · '.$numeric.' (hasta 10 grupos)',true);$data=array_slice($r['filas'],0,10);$max=max(array_map(fn($x)=>abs((float)($x[$numeric]??0)),$data));foreach($data as $row){$write((string)reset($row).' · '.($row[$numeric]??'N/D'));if($y>760){$pdf->nuevaPagina();$header();}$pdf->relleno(34,$y, $max>0?max(1,450*abs((float)($row[$numeric]??0))/$max):0,7,.35);$y+=19;}}
    }
    $write('Interpretación y alcance',true);$write($r['interpretacion']??$r['descripcion']);foreach($r['advertencias'] as $w)$write($w);
    $write('Conclusiones',true);$write($r['conclusion']);$write('Recomendaciones',true);$write($r['recomendacion']);return $pdf->salida();
}
function bi_guardar(mysqli $db,array $r,int $uid,string $method='manual'): int {
    $bytes=bi_pdf($r);$hash=hash('sha256',$bytes);$file='reportes/'.bin2hex(random_bytes(16)).'.pdf';
    $filters=json_encode($r['filtros'],JSON_UNESCAPED_UNICODE);$json=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    $q=$db->prepare('INSERT INTO bi_reporte_historial (reporte_id,nombre,usuario_id,filtros,metodo,creado,resultado,pdf,sha256,archivo) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $q->bind_param('isisssssss',$r['id'],$r['nombre'],$uid,$filters,$method,$r['generado'],$json,$bytes,$hash,$file);$q->execute();$id=(int)$db->insert_id;
    // Cloud SQL is the durable private master; existing private GCS mount is a replica.
    $root=getenv('REPORT_PRIVATE_DIR')?:dirname(__DIR__,2).'/viora_documentos_privados';
    $replica = false;
    try {
        if (!is_dir($root.'/reportes') && !mkdir($root.'/reportes', 0770, true) && !is_dir($root.'/reportes')) throw new RuntimeException();
        if (file_put_contents($root.'/'.$file, $bytes) !== strlen($bytes)) throw new RuntimeException();
        $replica = true;
    } catch (Throwable $e) {
        error_log('VIORA reportes: réplica de almacenamiento pendiente; PDF conservado en Cloud SQL.');
    }
    if (function_exists('auditoria_evento')) {
        auditoria_evento('Documentos', 'Replicar PDF', 'Almacenamiento privado: reporte BI', $replica, [['bi_reporte_historial', (string)$id]]);
    }
    return $id;
}
