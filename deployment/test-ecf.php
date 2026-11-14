<?php
// Solo la copia fiscal local, nunca acepta parámetros de conexión.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', 'viora_ecf_qa', 13308);
$db->set_charset('utf8mb4');
require __DIR__ . '/../Viora/mantenimientos/ecf_service.php';
$checks = [];
function check($name, $ok) { global $checks; $checks[]=['test'=>$name,'passed'=>(bool)$ok]; echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL; if (!$ok) throw new RuntimeException($name); }
function rejects($name, $fn) { try { $fn(); } catch (InvalidArgumentException $e) { check($name,true); return; } check($name,false); }
try {
    check('Exact cents without binary rounding', ecf_centavos('123.45')===12345);
    foreach (['0','-1','1.001','1e3','INF'] as $bad) rejects('Invalid amount '.$bad,fn()=>ecf_centavos($bad));
    check('Existing per-line ITBIS calculation', ecf_calcular_totales([101,102],18)===['base'=>203,'itbis'=>36,'total'=>239]);
    check('RNC format normalized', ecf_rnc_normalizar('132-90740-1')==='132907401');
    check('URLs with embedded credentials rejected', !ecf_url_segura('https://secret@example.test'));
    check('HTTP provider rejected', !ecf_url_segura('http://example.test'));
    $cfg=ecf_configuracion();
    rejects('Unconfirmed issuer cannot send',fn()=>ecf_validar_configuracion($cfg,'E32'));
    $cfg['api_key']='qa-private-marker'; $cfg['ambiente']='pruebas';$cfg['contrato_validado']=true;$cfg['emisor_confirmado']=true;$cfg['tipos_autorizados']=['E31','E32','E34'];
    $cfg['emisor']=['RNCEmisor'=>'132907401','RazonSocialEmisor'=>'QA','DireccionEmisor'=>'QA','TelefonoEmisor'=>'8090000000','CorreoEmisor'=>'qa@example.invalid','NombreComercial'=>'QA'];
    ecf_validar_configuracion($cfg,'E32');check('Explicit test configuration validates',true);
    rejects('Unsupported type rejected',fn()=>ecf_validar_configuracion($cfg,'E33'));
    $note=['encf_modificado'=>'E310000000001','fecha_encf_modificado'=>'2026-01-01','codigo_modificacion'=>3];$lines=[['descripcion'=>'QA','centavos'=>10000]];
    check('E34 payload keeps original reference',ecf_payload_nota($cfg,$note,$lines,null)['ECF']['InformacionReferencia']['NCFModificado']===$note['encf_modificado']);
    rejects('Invalid original date rejected',fn()=>ecf_payload_nota($cfg,array_merge($note,['fecha_encf_modificado'=>'2026-02-30']),$lines,null));
    rejects('Fake reference format rejected',fn()=>ecf_payload_nota($cfg,array_merge($note,['encf_modificado'=>'FAC-1']),$lines,null));
    rejects('Negative credit line rejected',fn()=>ecf_payload_nota($cfg,$note,[['descripcion'=>'QA','centavos'=>-1]],null));
    foreach ([200,400,429,500] as $http) check('Unconfirmed HTTP '.$http.' blocks resend',ecf_normalizar_respuesta(['codigo'=>$http,'datos'=>[],'raw'=>'private'])['estado']==='PENDIENTE_CONCILIACION');
    $response=['codigo'=>200,'datos'=>['id'=>'qa-id','estado'=>'Aceptado','encf'=>'E320000000001','errorMessage'=>'qa-private-marker'],'raw'=>''];
    check('Provider secrets not returned to browser',!str_contains(json_encode(ecf_normalizar_respuesta($response)),'qa-private-marker'));
    check('Accepted without eNCF requires reconciliation',ecf_normalizar_respuesta(['codigo'=>200,'datos'=>['id'=>'qa-id','estado'=>'Aceptado'],'raw'=>''])['estado']==='PENDIENTE_CONCILIACION');
    check('Legacy API errors reserve credit balance',ecf_nota_activa('ERROR_API'));
    putenv('ECF_ENABLED=false');
    $before=$db->query('SELECT COUNT(*) FROM factura_ecf_nota')->fetch_row()[0];
    check('Disabled E34 creates no credit note',ecf_emitir_nota_credito($db,4,1,[],null)['id']===null && $db->query('SELECT COUNT(*) FROM factura_ecf_nota')->fetch_row()[0]===$before);
    check('Disabled send never needs transport',ecf_enviar_documento($cfg,[])['estado']==='PENDIENTE_CONFIGURACION');
    $old=$db->query('SELECT * FROM factura_ecf WHERE factura_id=4')->fetch_assoc();
    ecf_enviar_factura($db,4);check('Accepted document unchanged on resend',$old===$db->query('SELECT * FROM factura_ecf WHERE factura_id=4')->fetch_assoc());
    $db->begin_transaction();
    try {
        $id=ecf_auditar_inicio($db,4,null,['qa'=>'payload']);ecf_auditar_respuesta($db,$id,$response,$cfg['api_key']);
        $row=$db->query('SELECT * FROM ecf_envio WHERE id_envio='.$id)->fetch_assoc();
        check('Provider response and status persisted with invoice',$row['factura_id']==4 && $row['http_codigo']==200 && $row['estado']==='Aceptado');
        check('Audit strips private credentials',!str_contains($row['respuesta_json'],'qa-private-marker'));
        check('Payload fingerprint persisted',strlen($row['payload_sha256'])===64);
        ecf_registrar($db,4,'E32',$old['estado'],null,null,null,null,null);
        $new=$db->query('SELECT * FROM factura_ecf WHERE factura_id=4')->fetch_assoc();
        check('Invoice identifiers survive absent fields',$new['encf']===$old['encf'] && $new['ecf_api_id']===$old['ecf_api_id'] && $new['codigo_seguridad']===$old['codigo_seguridad']);
        $db->query('UPDATE factura SET total=24190.00 WHERE id_facturas=1');
        check('E32 preparation with consistent invoice',ecf_construir_payload($db,1,$cfg,'E32')['ECF']['Encabezado']['IdDoc']['TipoeCF']==='32');
        $db->query("UPDATE factura SET rnc_comprador='132907401',razon_social_comprador='QA' WHERE id_facturas=1");
        check('E31 preparation with buyer',ecf_construir_payload($db,1,$cfg,'E31')['ECF']['Encabezado']['Comprador']['RNCComprador']==='132907401');
        rejects('Unsupported invoice type rejected',fn()=>ecf_construir_payload($db,1,$cfg,'E34'));
    } finally { $db->rollback(); }
    foreach (['ECF_ENABLED'=>'true','ECF_AMBIENTE'=>'pruebas','ECF_CONTRATO_VALIDADO'=>'true','ECF_EMISOR_CONFIRMADO'=>'true','ECF_TIPOS_AUTORIZADOS'=>'E31,E32,E34','ECF_API_KEY'=>'qa-private-marker',
        'ECF_EMISOR_RNC'=>'132907401','ECF_EMISOR_RAZON_SOCIAL'=>'QA','ECF_EMISOR_DIRECCION'=>'QA','ECF_EMISOR_TELEFONO'=>'8090000000','ECF_EMISOR_CORREO'=>'qa@example.invalid'] as $k=>$v) putenv($k.'='.$v);
    $operation=bin2hex(random_bytes(32));$requestHash=hash('sha256',json_encode([4,3,[]],JSON_THROW_ON_ERROR));
    $db->query("INSERT INTO factura_ecf_nota (factura_id,codigo_modificacion,encf_modificado,fecha_encf_modificado,monto_centavos,lineas_json,estado) VALUES (4,3,'E310000000001','2026-01-01',11800,'[]','ENVIANDO')");
    $nid=(int)$db->insert_id;
    $q=$db->prepare('INSERT INTO ecf_nota_operacion (operacion,factura_id,nota_id,solicitud_sha256) VALUES (?,4,?,?)');$q->bind_param('sis',$operation,$nid,$requestHash);$q->execute();
    try {
        $count=$db->query('SELECT COUNT(*) FROM factura_ecf_nota')->fetch_row()[0];
        $result=ecf_emitir_nota_credito($db,4,3,[],null,$operation);
        check('Repeated E34 operation returns same note without transport',$result['id']===$nid && $result['estado']==='ENVIANDO');
        check('Repeated E34 operation creates no duplicate',$db->query('SELECT COUNT(*) FROM factura_ecf_nota')->fetch_row()[0]===$count);
        rejects('Changed proposal cannot reuse operation',fn()=>ecf_emitir_nota_credito($db,4,1,[],null,$operation));
        ecf_registrar_nota($db,$nid,'PENDIENTE_CONCILIACION','qa-reference','E340000000001','security','https://example.invalid',null);
        ecf_registrar_nota($db,$nid,'PENDIENTE_CONCILIACION',null,null,null,null,null);
        $saved=$db->query('SELECT encf,ecf_api_id,codigo_seguridad FROM factura_ecf_nota WHERE id_factura_ecf_nota='.$nid)->fetch_assoc();
        check('E34 identifiers preserved after incomplete update',$saved['encf']==='E340000000001' && $saved['ecf_api_id']==='qa-reference' && $saved['codigo_seguridad']==='security');
    } finally {
        $q=$db->prepare('DELETE FROM ecf_nota_operacion WHERE operacion=?');$q->bind_param('s',$operation);$q->execute();
        $db->query('DELETE FROM factura_ecf_nota WHERE id_factura_ecf_nota='.$nid);putenv('ECF_ENABLED=false');
    }
    putenv('IMECF_API_KEY=qa-imecf-fixture');
    check('IMECF secret environment is read without transport',ecf_configuracion()['api_key']==='qa-imecf-fixture');
    putenv('IMECF_API_KEY');
    $live=$cfg;$live['api_key']='ecf_live_qa_fixture_not_a_credential';
    rejects('Production-shaped key blocked in test configuration',fn()=>ecf_validar_configuracion($live,'E31'));
    $_SESSION=['ecf_csrf'=>'qa-token'];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_X_CSRF_TOKEN']='bad';
    rejects('Invalid fiscal CSRF denied',fn()=>ecf_validar_peticion());
    $_SERVER['HTTP_X_CSRF_TOKEN']='qa-token';ecf_validar_peticion();check('Valid fiscal CSRF accepted',true);
} finally {
    file_put_contents(__DIR__.'/ecf-test-results.json',json_encode(['provider_simulated'=>true,'database'=>'local fiscal QA','checks'=>$checks],JSON_PRETTY_PRINT));
}
