<?php
// Conciliación de la integración existente. No acepta destinos HTTP suministrados por usuarios.
function paypal_transacciones(callable $request, array $cfg, string $token, string $id, int $desde, int $hasta): array
{
    $all=[]; $path='/v1/billing/subscriptions/'.rawurlencode($id).'/transactions'; $windows=0;
    while ($desde<$hasta) {
        if (++$windows>128) throw new RuntimeException('El histórico requiere una conciliación por lotes.');
        $end=min($hasta,$desde+31*86400);
        $route=$path.'?'.http_build_query(['start_time'=>gmdate('Y-m-d\TH:i:s\Z',$desde),'end_time'=>gmdate('Y-m-d\TH:i:s\Z',$end)]);
        $seen=[];
        while ($route!==null) {
            if (isset($seen[$route]) || count($seen)>=100) throw new RuntimeException('Paginación PayPal inválida.');
            $seen[$route]=true; $response=$request($cfg,$route,$token);
            // PayPal puede devolver {} mientras aún no publica movimientos: no equivale a un pago confirmado.
            if (isset($response['transactions']) && !is_array($response['transactions'])) throw new RuntimeException('Lista de pagos PayPal inválida.');
            foreach ($response['transactions']??[] as $p) if (!empty($p['id'])) $all[$p['id']]=$p;
            $route=null;
            foreach ($response['links']??[] as $link) if (($link['rel']??'')==='next') {
                $url=parse_url($link['href']??'');
                if (($url['scheme']??'')!=='https' || !in_array($url['host']??'', ['api-m.sandbox.paypal.com','api.sandbox.paypal.com'],true)
                    || ($url['path']??'')!==$path || isset($url['user']) || isset($url['port'])) throw new RuntimeException('Destino de paginación no permitido.');
                $route=$path.'?'.($url['query']??'');
            }
        }
        $desde=$end;
    }
    return array_values($all);
}

function paypal_sincronizar_interno(mysqli $db, array $cfg, string $id, ?int $usuarioEsperado=null, ?callable $request=null, ?array $ajuste=null): array
{
    $request??='paypal_api';
    if (!preg_match('/^I-[A-Z0-9]+$/',$id)) throw new RuntimeException('Suscripción inválida.');
    $token=$request($cfg,'/v1/oauth2/token')['access_token']??'';
    if (!$token) throw new RuntimeException('Falta token PayPal.');
    $sub=$request($cfg,'/v1/billing/subscriptions/'.rawurlencode($id),$token);
    $plan=getenv('PAYPAL_PLAN_ID')?:'';
    if (!$plan || ($sub['id']??'')!==$id || ($sub['plan_id']??'')!==$plan) throw new RuntimeException('La suscripción no corresponde al plan VIORA.');
    if (preg_match('/^viora-user-([1-9][0-9]*)$/',$sub['custom_id']??'',$match)) $uid=(int)$match[1];
    elseif (empty($sub['custom_id'])) {
        // Las suscripciones antiguas solo se aceptan si ya tenían propietario local.
        $q=$db->prepare('SELECT usuario_id FROM suscripcion WHERE paypal_subscription_id=? AND paypal_plan_id=?');
        $q->bind_param('ss',$id,$plan);$q->execute();$uid=(int)($q->get_result()->fetch_assoc()['usuario_id']??0);
        if (!$uid) throw new RuntimeException('Suscripción sin propietario verificado.');
    } else throw new RuntimeException('Vinculación PayPal inválida.');
    if ($usuarioEsperado!==null && $uid!==$usuarioEsperado) throw new RuntimeException('Suscripción de otra cuenta.');
    paypal_plan_validar($request($cfg,'/v1/billing/plans/'.rawurlencode($plan),$token));
    $q=$db->prepare('SELECT MAX(fecha_pago) ultima FROM suscripcion_pago WHERE paypal_subscription_id=?');
    $q->bind_param('s',$id);$q->execute();$ultima=$q->get_result()->fetch_assoc()['ultima'];
    $desde=$ultima ? strtotime($ultima.' UTC')-31*86400 : (strtotime($sub['start_time']??'')?:time()-31*86400);
    $payments=in_array($sub['status']??'', ['APPROVAL_PENDING','APPROVED'],true) ? []
        : paypal_transacciones($request,$cfg,$token,$id,min($desde,time()-1),time());
    // Sandbox puede confirmar last_payment y emitir SALE.COMPLETED antes de publicar /transactions.
    // Recuperar el ID real desde eventos autenticados y verificar la venta directamente. Nunca fabricar un ID.
    if (!$payments && !empty($sub['billing_info']['last_payment']['time'])) {
        $eventPath='/v1/notifications/webhooks-events';
        $route=$eventPath.'?'.http_build_query(['event_type'=>'PAYMENT.SALE.COMPLETED','page_size'=>20]);
        $pages=0;
        while ($route!==null) {
            if (++$pages>50) throw new RuntimeException('Conciliación de eventos incompleta.');
            $events=$request($cfg,$route,$token);$route=null;
            foreach ($events['events']??[] as $event) {
                $saleId=$event['resource']['id']??'';
                if (($event['resource']['billing_agreement_id']??'')!==$id || !preg_match('/^[A-Z0-9]+$/',$saleId)) continue;
                $sale=$request($cfg,'/v1/payments/sale/'.$saleId,$token);
                if (($sale['id']??'')!==$saleId || ($sale['billing_agreement_id']??'')!==$id) throw new RuntimeException('Venta no vinculada a esta suscripción.');
                $payments[]=['id'=>$saleId,'status'=>strtoupper($sale['state']??''),'time'=>$sale['create_time']??null,
                    'amount_with_breakdown'=>['gross_amount'=>['value'=>$sale['amount']['total']??null,'currency_code'=>$sale['amount']['currency']??null]]];
            }
            foreach ($events['links']??[] as $link) if (($link['rel']??'')==='next') {
                $url=parse_url($link['href']??'');
                if (($url['scheme']??'')!=='https' || !in_array($url['host']??'', ['api-m.sandbox.paypal.com','api.sandbox.paypal.com'],true)
                    || ($url['path']??'')!==$eventPath || isset($url['user']) || isset($url['port'])) throw new RuntimeException('Destino de eventos no permitido.');
                $route=$eventPath.'?'.($url['query']??'');
            }
        }
    }
    if ($ajuste!==null) $payments[]=$ajuste;
    $next=paypal_fecha($sub['billing_info']['next_billing_time']??null);
    $start=paypal_fecha($sub['start_time']??null); $status=$sub['status']??'';
    $outstanding=$sub['billing_info']['outstanding_balance']['value']??'0';
    $db->begin_transaction();
    try {
        $q=$db->prepare('SELECT id_usuarios,activo,estado_cuenta FROM usuario WHERE id_usuarios=? FOR UPDATE');
        $q->bind_param('i',$uid);$q->execute();$account=$q->get_result()->fetch_assoc();
        if (!$account) throw new RuntimeException('Usuario inexistente.');
        $q=$db->prepare('SELECT usuario_id,estado FROM suscripcion WHERE paypal_subscription_id=? FOR UPDATE');
        $q->bind_param('s',$id);$q->execute();$owner=$q->get_result()->fetch_assoc();
        if ($owner && (int)$owner['usuario_id']!==$uid) throw new RuntimeException('Suscripción vinculada a otra cuenta.');
        foreach ($payments as $p) {
            $amount=$p['amount_with_breakdown']['gross_amount']??[]; $state=$p['status']??'';
            if (!in_array($state,['COMPLETED','REFUNDED','PARTIALLY_REFUNDED','REVERSED'],true)
                || ($amount['currency_code']??'')!=='USD' || !is_numeric($amount['value']??null) || (float)$amount['value']!==10.0
                || empty($p['id']) || empty($p['time'])) continue;
            $date=paypal_fecha($p['time']); $transaction=$p['id'];
            if ($date>gmdate('Y-m-d H:i:s')) throw new RuntimeException('PayPal devolvió un pago futuro.');
            $q=$db->prepare('SELECT paypal_subscription_id,usuario_id FROM suscripcion_pago WHERE paypal_transaction_id=? FOR UPDATE');
            $q->bind_param('s',$transaction);$q->execute();$previous=$q->get_result()->fetch_assoc();
            if ($previous && ($previous['paypal_subscription_id']!==$id || (int)$previous['usuario_id']!==$uid)) throw new RuntimeException('Transacción vinculada a otra suscripción.');
            // Un snapshot atrasado no puede convertir de nuevo un reembolso en COMPLETED.
            $q=$db->prepare("INSERT INTO suscripcion_pago(paypal_transaction_id,paypal_subscription_id,usuario_id,monto,moneda,fecha_pago,estado)
                VALUES(?,?,?,10.00,'USD',?,?) ON DUPLICATE KEY UPDATE estado=CASE
                WHEN estado='REVERSED' THEN estado WHEN VALUES(estado)='REVERSED' THEN VALUES(estado)
                WHEN estado='REFUNDED' THEN estado WHEN VALUES(estado)='REFUNDED' THEN VALUES(estado)
                WHEN estado='PARTIALLY_REFUNDED' THEN estado ELSE VALUES(estado) END");
            $q->bind_param('ssiss',$transaction,$id,$uid,$date,$state);$q->execute();
        }
        $q=$db->prepare("SELECT estado,fecha_pago FROM suscripcion_pago WHERE paypal_subscription_id=? AND usuario_id=? ORDER BY fecha_pago DESC,paypal_transaction_id DESC LIMIT 1");
        $q->bind_param('si',$id,$uid);$q->execute();$last=$q->get_result()->fetch_assoc();
        if (!$last && $owner && $owner['estado']==='Activo' && $status==='ACTIVE' && $next!==null && $next>gmdate('Y-m-d H:i:s')) {
            throw new RuntimeException('Suscripción legada activa sin ID de pago recuperable; requiere conciliación histórica.',409);
        }
        $paid=$last && $last['estado']==='COMPLETED' && strtotime($last['fecha_pago'].' UTC')>=time()-31*86400;
        // Un importe/moneda incorrectos en el movimiento más reciente no se compensan con uno antiguo.
        $latestTime=$last ? strtotime($last['fecha_pago'].' UTC') : 0;
        foreach ($payments as $p) if (!empty($p['time']) && strtotime($p['time'])>=$latestTime) {
            $a=$p['amount_with_breakdown']['gross_amount']??[];
            if (($a['currency_code']??'')!=='USD' || !is_numeric($a['value']??null) || (float)$a['value']!==10.0 || ($p['status']??'')!=='COMPLETED') $paid=false;
        }
        $active=$status==='ACTIVE' && $paid && is_numeric($outstanding) && (float)$outstanding===0.0 && $next!==null && $next>gmdate('Y-m-d H:i:s');
        $local=['CANCELLED'=>'Cancelado','SUSPENDED'=>'Suspendido','EXPIRED'=>'Vencido'][$status]??($active?'Activo':($next!==null && $next<=gmdate('Y-m-d H:i:s')?'Vencido':'Pendiente de pago'));
        $q=$db->prepare("INSERT INTO suscripcion(usuario_id,paypal_subscription_id,paypal_plan_id,monto,moneda,estado,fecha_inicio,fecha_proximo_pago)
            VALUES(?,?,?,10.00,'USD',?,?,?) ON DUPLICATE KEY UPDATE estado=VALUES(estado),fecha_proximo_pago=VALUES(fecha_proximo_pago)");
        $q->bind_param('isssss',$uid,$id,$plan,$local,$start,$next);$q->execute();
        if ($active && $account['estado_cuenta']==='Pendiente de pago') {
            $q=$db->prepare("UPDATE usuario SET activo=1,estado_cuenta='Activo' WHERE id_usuarios=? AND estado_cuenta='Pendiente de pago'");
            $q->bind_param('i',$uid);$q->execute();$account['activo']=1;$account['estado_cuenta']='Activo';
        }
        $db->commit();
    } catch (Throwable $e) {$db->rollback();throw $e;}
    return ['activo'=>$active && (int)$account['activo']===1 && $account['estado_cuenta']==='Activo','estado'=>$local];
}
