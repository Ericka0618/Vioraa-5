"""Resend provider-generated QA events and verify signed receiver persistence. No simulator."""
import json
import time
import requests
from paypal_cloud import ROOT,API,paypal,database,private_dir

def main():
    fixture=json.loads((private_dir()/'sandbox-fixture.json').read_text());sid=fixture['subscription_id']
    registration=json.loads((ROOT/'deployment/paypal-webhook-registration.json').read_text())
    merchant=paypal();results=[]
    def check(name,ok):
        results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
        if not ok:raise RuntimeError(name)
    with database() as (db,_,__):
        def query(sql,params=()):
            with db.cursor() as c:c.execute(sql,params);return c.fetchall()
        sale=query('SELECT paypal_transaction_id FROM suscripcion_pago WHERE paypal_subscription_id=%s',(sid,))[0][0]
        response=merchant.get(API+'/v1/notifications/webhooks-events',params={'page_size':20},timeout=30);response.raise_for_status()
        events=[]
        for e in response.json().get('events',[]):
            p=e.get('resource',{})
            if p.get('id')==sid or p.get('billing_agreement_id')==sid or p.get('sale_id')==sale:events.append(e)
        found={e['event_type'] for e in events}
        expected={'BILLING.SUBSCRIPTION.ACTIVATED','BILLING.SUBSCRIPTION.CANCELLED','PAYMENT.SALE.COMPLETED','PAYMENT.SALE.REFUNDED'}
        try:
            check('Real provider emitted activation payment refund and cancellation events',expected.issubset(found))
            # Intentionally reverse provider order: current subscription and terminal refund must win.
            events.sort(key=lambda e: e['event_type']=='PAYMENT.SALE.COMPLETED')
            for event in events:
                r=merchant.post(API+'/v1/notifications/webhooks-events/'+event['id']+'/resend',json={'webhook_ids':[registration['id']]},timeout=30)
                check('Provider resend accepted '+event['event_type'],r.status_code==202)
                until=time.monotonic()+75
                while time.monotonic()<until:
                    if query('SELECT paypal_event_id FROM paypal_evento WHERE paypal_event_id=%s',(event['id'],)):break
                    time.sleep(2)
                check('Signed callback verified and committed '+event['event_type'],bool(query('SELECT paypal_event_id FROM paypal_evento WHERE paypal_event_id=%s',(event['id'],))))
            before=query('SELECT COUNT(*) FROM suscripcion_pago WHERE paypal_subscription_id=%s',(sid,))[0][0]
            event=next(e for e in events if e['event_type']=='PAYMENT.SALE.COMPLETED')
            r=merchant.post(API+'/v1/notifications/webhooks-events/'+event['id']+'/resend',json={'webhook_ids':[registration['id']]},timeout=30)
            check('Real duplicate delivery requested',r.status_code==202)
            time.sleep(3)
            check('Duplicate webhook leaves exactly one payment',before==1 and query('SELECT COUNT(*) FROM suscripcion_pago WHERE paypal_subscription_id=%s',(sid,))[0][0]==1)
            check('Out of order completed event cannot reactivate cancellation',query('SELECT estado FROM suscripcion WHERE paypal_subscription_id=%s',(sid,))[0][0]=='Cancelado')
            check('Out of order event cannot undo refund',query('SELECT estado FROM suscripcion_pago WHERE paypal_transaction_id=%s',(sale,))[0][0]=='REFUNDED')
            r=requests.post(registration['url'],json=event,timeout=30)
            check('Copied real event without signed headers is rejected',r.status_code==400)
        finally:
            (ROOT/'deployment/paypal-real-webhook-results.json').write_text(json.dumps({'environment':'sandbox','provider_generated_events':True,'checks':results},indent=2))

if __name__=='__main__':main()
