"""Verify only the newly created QA Sandbox fixture, using real PayPal and Cloud SQL."""
import argparse
import datetime
import json
import os
import subprocess
import time
import requests
from paypal_cloud import ROOT,API,database,paypal,private_dir,secret

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--refund-and-cancel',action='store_true');parser.add_argument('--finish-refund-and-cancel',action='store_true');args=parser.parse_args()
    fixture=json.loads((private_dir()/'sandbox-fixture.json').read_text());sid=fixture['subscription_id'];uid=fixture['user_id']
    merchant=paypal();r=merchant.get(API+'/v1/billing/subscriptions/'+sid,timeout=30);r.raise_for_status();sub=r.json()
    assert sub['custom_id']=='viora-user-'+str(uid)
    previous=ROOT/'deployment/paypal-real-flow-results.json'
    results=[r for r in json.loads(previous.read_text())['checks'] if r['passed']] if args.finish_refund_and_cancel else []
    def check(name,ok):
        results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
        if not ok:raise RuntimeError(name)
    with database() as (db,password,private):
        env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='13309',DB_USER='viora_prod',DB_NAME='viora_production',DB_PASSWORD=password,DB_SOCKET='',
            PAYPAL_CLIENT_ID=secret('viora-production-paypal-client-id'),PAYPAL_CLIENT_SECRET=secret('viora-production-paypal-client-secret'),PAYPAL_PLAN_ID=secret('viora-production-paypal-plan-id'),QA_SUBSCRIPTION=sid,QA_USER=str(uid))
        def sync():
            code="set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);}); require 'Viora/conf/conexion.php'; require 'Viora/login/paypal_service.php'; $cfg=require 'Viora/conf/paypal_config.php'; echo json_encode(paypal_sincronizar($conexion,$cfg,getenv('QA_SUBSCRIPTION'),(int)getenv('QA_USER')));"
            r=subprocess.run(['C:/xampp/php/php.exe','-r',code],cwd=ROOT,env=env,capture_output=True,text=True)
            if r.returncode:raise RuntimeError('PHP reconciliation failed: '+r.stderr[:500])
            return json.loads(r.stdout)
        def count():
            with db.cursor() as c:
                c.execute('SELECT COUNT(*) FROM suscripcion_pago WHERE paypal_subscription_id=%s AND usuario_id=%s',(sid,uid));return c.fetchone()[0]
        try:
            check('Real Sandbox buyer approval ACTIVE',sub['status']=='ACTIVE')
            pay=sub.get('billing_info',{}).get('last_payment',{});check('Real first payment USD10',pay.get('amount',{}).get('currency_code')=='USD' and float(pay.get('amount',{}).get('value',0))==10.0)
            browser=requests.Session();response=browser.post('https://viora-6ikx2ezlba-uc.a.run.app/login/procesar_login.php',data={'username':fixture['username'],'password':fixture['password']},timeout=40)
            if not args.finish_refund_and_cancel:
                check('Real verified payment activates new QA account',sync()['activo'])
                check('Payment persisted once in Cloud SQL',count()==1)
                sync();check('Repeated real reconciliation creates no duplicate',count()==1)
                response=browser.post('https://viora-6ikx2ezlba-uc.a.run.app/login/procesar_login.php',data={'username':fixture['username'],'password':fixture['password']},timeout=40)
                check('Confirmed QA subscriber accesses public catalog',response.status_code==200 and '/mantenimientos/catalogo.php' in response.url)
            if args.refund_and_cancel or args.finish_refund_and_cancel:
                with db.cursor() as c:
                    c.execute('SELECT paypal_transaction_id FROM suscripcion_pago WHERE paypal_subscription_id=%s AND usuario_id=%s',(sid,uid));row=c.fetchone()
                if not row:raise RuntimeError('No fixture-owned completed transaction to refund')
                sale=row[0]
                if not args.finish_refund_and_cancel:
                    response=merchant.post(API+'/v1/payments/sale/'+sale+'/refund',headers={'PayPal-Request-Id':'viora-qa-refund-'+sale},json={'amount':{'total':'10.00','currency':'USD'}},timeout=40)
                    check('Real Sandbox refund accepted',response.status_code in [200,201])
                    refund=response.json();check('Refund completed at provider',refund.get('state')=='completed')
                active=True
                for _ in range(3):
                    active=sync()['activo']
                    if not active:break
                    time.sleep(2)
                check('Refund reconciliation revokes subscription access',not active)
                with db.cursor() as c:
                    c.execute('SELECT estado FROM suscripcion_pago WHERE paypal_transaction_id=%s',(sale,));state=c.fetchone()[0]
                check('Refund status persisted on original payment',state in ['REFUNDED','PARTIALLY_REFUNDED','REVERSED'])
                response=merchant.post(API+'/v1/billing/subscriptions/'+sid+'/cancel',json={'reason':'VIORA QA Sandbox completed; avoid further test renewals'},timeout=30)
                check('Real Sandbox cancellation accepted',response.status_code==204)
                check('Cancellation persisted and access denied',sync()['estado']=='Cancelado')
                response=requests.Session().post('https://viora-6ikx2ezlba-uc.a.run.app/login/procesar_login.php',data={'username':fixture['username'],'password':fixture['password']},timeout=40)
                check('Cancelled subscriber cannot log into catalog','/mantenimientos/catalogo.php' not in response.url)
        finally:
            (ROOT/'deployment/paypal-real-flow-results.json').write_text(json.dumps({'environment':'sandbox','subscription_id':sid,'checks':results,'renewal_real_tested':False,'webhook_delivery_tested':False},indent=2),encoding='utf-8')

if __name__=='__main__':main()
