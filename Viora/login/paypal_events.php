<?php
function paypal_procesar_evento(mysqli $db, array $cfg, array $event, ?callable $request=null): array
{
    $request??='paypal_api';$eid=$event['id'];$type=$event['event_type'];$resource=$event['resource']??[];
    $allowed=['BILLING.SUBSCRIPTION.ACTIVATED','BILLING.SUBSCRIPTION.CANCELLED','BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.EXPIRED','BILLING.SUBSCRIPTION.PAYMENT.FAILED','PAYMENT.SALE.COMPLETED','PAYMENT.SALE.REFUNDED','PAYMENT.SALE.REVERSED'];
    if (!is_string($eid) || strlen($eid)>100 || !in_array($type,$allowed,true)) return ['ignored'=>true];
    $lock='viora_event_'.substr(hash('sha256',$eid),0,45);
    $q=$db->prepare('SELECT GET_LOCK(?,5)');$q->bind_param('s',$lock);$q->execute();
    if ((int)$q->get_result()->fetch_row()[0]!==1) throw new RuntimeException('Evento en curso.');
    try {
        $q=$db->prepare('SELECT paypal_event_id FROM paypal_evento WHERE paypal_event_id=?');
        $q->bind_param('s',$eid);$q->execute();
        if ($q->get_result()->fetch_assoc()) return ['success'=>true,'duplicate'=>true];
        $ajuste=null;
        if (str_starts_with($type,'BILLING.SUBSCRIPTION.')) $id=$resource['id']??'';
        else {
            $token=$request($cfg,'/v1/oauth2/token')['access_token']??'';
            if (!$token) throw new RuntimeException('Falta token PayPal.');
            $saleId=$resource['id']??'';
            if ($type==='PAYMENT.SALE.REFUNDED') {
                $saleId=$resource['sale_id']??'';
                if (!$saleId && preg_match('/^[A-Z0-9]+$/',$resource['id']??'')) {
                    $refund=$request($cfg,'/v1/payments/refund/'.rawurlencode($resource['id']),$token);
                    $saleId=$refund['sale_id']??'';
                }
            }
            if (!is_string($saleId) || !preg_match('/^[A-Z0-9]+$/',$saleId)) throw new RuntimeException('Evento sin transacción de origen.');
            $sale=$request($cfg,'/v1/payments/sale/'.rawurlencode($saleId),$token);
            if (($sale['id']??'')!==$saleId) throw new RuntimeException('Transacción PayPal no coincide.');
            $id=$sale['billing_agreement_id']??($resource['billing_agreement_id']??'');
            if (!$id) {
                $q=$db->prepare('SELECT paypal_subscription_id FROM suscripcion_pago WHERE paypal_transaction_id=?');
                $q->bind_param('s',$saleId);$q->execute();$id=$q->get_result()->fetch_assoc()['paypal_subscription_id']??'';
            }
            $state=strtoupper($sale['state']??'');
            if ($type==='PAYMENT.SALE.REVERSED') $state='REVERSED';
            if ($type==='PAYMENT.SALE.REFUNDED') {
                $refunded=$resource['amount']['total']??$sale['amount']['total']??null;
                $gross=$sale['amount']['total']??null;
                $state=is_numeric($refunded) && is_numeric($gross) && (float)$refunded<(float)$gross ? 'PARTIALLY_REFUNDED':'REFUNDED';
            }
            $ajuste=['id'=>$saleId,'status'=>$state,'time'=>$sale['create_time']??null,
                'amount_with_breakdown'=>['gross_amount'=>['value'=>$sale['amount']['total']??null,'currency_code'=>$sale['amount']['currency']??null]]];
        }
        if (!is_string($id) || !preg_match('/^I-[A-Z0-9]+$/',$id)) throw new RuntimeException('No se pudo asociar el evento a su suscripción.');
        $result=paypal_sincronizar($db,$cfg,$id,null,$request,$ajuste);
        // Se marca procesado después de confirmar el commit financiero. Un fallo aquí admite reintento idempotente.
        $q=$db->prepare('INSERT INTO paypal_evento(paypal_event_id,tipo,paypal_subscription_id) VALUES(?,?,?)');
        $q->bind_param('sss',$eid,$type,$id);$q->execute();
        return ['success'=>true,'estado'=>$result['estado']];
    } finally {$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->bind_param('s',$lock);$q->execute();}
}
