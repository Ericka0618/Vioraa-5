<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','','viora_integrations_qa',13308);$db->set_charset('utf8mb4');
require __DIR__.'/../Viora/login/paypal_service.php';
require __DIR__.'/../Viora/login/paypal_events.php';
require __DIR__.'/../Viora/conf/subscription_access.php';
foreach (explode(';',file_get_contents(__DIR__.'/../Viora/conf/integraciones.sql')) as $sql) if(trim($sql)!=='')$db->query($sql);
$checks=[];
function check(string $name,bool $ok):void{global $checks;$checks[]=['test'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)throw new RuntimeException($name);}
function rejects(string $name,callable $action):void{try{$action();}catch(Throwable $e){check($name,true);return;}check($name,false);}
$suffix=strtoupper(bin2hex(random_bytes(5)));$id='I-QA'.$suffix;$tx='QA'.$suffix;$uid=5;
putenv('PAYPAL_PLAN_ID=P-QAEVENTS');
$db->query("UPDATE suscripcion SET estado='Pendiente' WHERE usuario_id=5"); // isolated fixture only
$plan=['billing_cycles'=>[['tenure_type'=>'REGULAR','frequency'=>['interval_unit'=>'MONTH','interval_count'=>1],'pricing_scheme'=>['fixed_price'=>['currency_code'=>'USD','value'=>'10.00']]]]];
$sub=['id'=>$id,'plan_id'=>'P-QAEVENTS','custom_id'=>'viora-user-5','status'=>'ACTIVE','start_time'=>gmdate('c',time()-60),
    'billing_info'=>['last_payment'=>['time'=>gmdate('c',time()-30)],'next_billing_time'=>gmdate('c',time()+25*86400)]];
$sale=['id'=>$tx,'billing_agreement_id'=>$id,'state'=>'completed','create_time'=>gmdate('c',time()-30),'amount'=>['total'=>'10.00','currency'=>'USD']];
$payment=['id'=>$tx,'status'=>'COMPLETED','time'=>$sale['create_time'],'amount_with_breakdown'=>['gross_amount'=>['value'=>'10.00','currency_code'=>'USD']]];
$empty=false;$calls=0;
$api=function($cfg,$path,$token=null,$body=null)use(&$sub,&$plan,&$sale,&$payment,&$empty,&$calls,$id,$tx){
    $calls++;
    if($path==='/v1/oauth2/token')return ['access_token'=>'fixture'];
    if(str_contains($path,'/billing/plans/'))return $plan;
    if(str_contains($path,'/payments/sale/'))return $sale;
    if(str_contains($path,'/payments/refund/'))return ['sale_id'=>$tx];
    if(str_contains($path,'/webhooks-events'))return ['events'=>[['resource'=>['id'=>$tx,'billing_agreement_id'=>$id]]]];
    if(str_contains($path,'/transactions?'))return $empty?[]:['transactions'=>[$payment]];
    return $sub;
};
$event=fn($type,$resource,$n)=>['id'=>'WH-'.$suffix.'-'.$n,'event_type'=>$type,'resource'=>$resource];
try{
    $empty=true;
    check('Empty Sandbox transaction list recovered only through verified sale',paypal_sincronizar($db,[],$id,$uid,$api)['activo']);
    check('Authenticated recovery records real sale identifier',(int)$db->query("SELECT COUNT(*) FROM suscripcion_pago WHERE paypal_transaction_id='$tx'")->fetch_row()[0]===1);
    $complete=$event('PAYMENT.SALE.COMPLETED',['id'=>$tx],1);
    check('Completion event processed',paypal_procesar_evento($db,[],$complete,$api)['success']);
    $before=$calls;
    check('Repeated event recognized without another financial write',paypal_procesar_evento($db,[],$complete,$api)['duplicate'] && $calls===$before);
    $refund=$event('PAYMENT.SALE.REFUNDED',['id'=>'QA-REFUND','sale_id'=>$tx,'amount'=>['total'=>'10.00']],2);
    check('Refund without subscription ID resolves original sale',paypal_procesar_evento($db,[],$refund,$api)['success']);
    check('Refund preserved even if sale API snapshot is stale',$db->query("SELECT estado FROM suscripcion_pago WHERE paypal_transaction_id='$tx'")->fetch_row()[0]==='REFUNDED');
    check('Refund denies catalog',!viora_subscription_access($db,$uid));
    check('Late COMPLETED event cannot undo refund',paypal_procesar_evento($db,[],$event('PAYMENT.SALE.COMPLETED',['id'=>$tx],3),$api)['success'] && !viora_subscription_access($db,$uid));
    $sub['status']='CANCELLED';
    check('Cancellation applied from current provider state',paypal_procesar_evento($db,[],$event('BILLING.SUBSCRIPTION.CANCELLED',['id'=>$id],4),$api)['estado']==='Cancelado');
    check('Late ACTIVATED event cannot undo cancellation',paypal_procesar_evento($db,[],$event('BILLING.SUBSCRIPTION.ACTIVATED',['id'=>$id],5),$api)['estado']==='Cancelado');
    $count=0;
    $window=function($c,$p,$t)use(&$count){$count++;return ['transactions'=>[]];};
    paypal_transacciones($window,[],'fixture',$id,time()-70*86400,time());
    check('Historical gap split into safe 31 day windows',$count===3);
    $count=0;
    $page=function($c,$p,$t)use(&$count,$id){$count++;return $count===1?['transactions'=>[],'links'=>[['rel'=>'next','href'=>'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/'.$id.'/transactions?page=2']]]:['transactions'=>[]];};
    paypal_transacciones($page,[],'fixture',$id,time()-60,time());
    check('Provider next page is consumed',$count===2);
    $bad=fn($c,$p,$t)=>['transactions'=>[],'links'=>[['rel'=>'next','href'=>'https://attacker.invalid/steal']]];
    rejects('Foreign paging URL cannot receive bearer token',fn()=>paypal_transacciones($bad,[],'fixture',$id,time()-60,time()));
    $sub['status']='ACTIVE';$sub['custom_id']='';
    check('Preexisting legacy ownership reconciles safely',paypal_sincronizar($db,[],$id,$uid,$api)['estado']==='Pendiente de pago');
    rejects('Legacy subscription cannot be adopted by another user',fn()=>paypal_sincronizar($db,[],$id,6,$api));
    check('Only one financial row after repeated out of order events',(int)$db->query("SELECT COUNT(*) FROM suscripcion_pago WHERE paypal_subscription_id='$id'")->fetch_row()[0]===1);
}finally{file_put_contents(__DIR__.'/paypal-event-test-results.json',json_encode($checks,JSON_PRETTY_PRINT));}
