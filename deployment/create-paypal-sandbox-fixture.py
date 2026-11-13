"""Register a new isolated QA customer and create one real Sandbox subscription for manual buyer approval."""
import json
import re
import secrets
from urllib.parse import urlparse
import requests
import os
import socket
import subprocess
import time
from paypal_cloud import ROOT,API,database,paypal,secret

BASE='https://viora-6ikx2ezlba-uc.a.run.app'
EVENTS=['BILLING.SUBSCRIPTION.ACTIVATED','BILLING.SUBSCRIPTION.CANCELLED','BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.EXPIRED','BILLING.SUBSCRIPTION.PAYMENT.FAILED','PAYMENT.SALE.COMPLETED',
        'PAYMENT.SALE.REFUNDED','PAYMENT.SALE.REVERSED']
def main():
    merchant=paypal()
    url=BASE+'/login/paypal_webhook.php'
    r=merchant.get(API+'/v1/notifications/webhooks',timeout=30);r.raise_for_status()
    existing=next((w for w in r.json().get('webhooks',[]) if w['url']==url),None)
    if existing:
        webhook=existing
        missing=set(EVENTS)-{e['name'] for e in webhook['event_types']}
        if missing:raise RuntimeError('Existing webhook needs event update; no replacement created.')
    else:
        r=merchant.post(API+'/v1/notifications/webhooks',json={'url':url,'event_types':[{'name':e} for e in EVENTS]},timeout=30)
        if r.status_code!=201:raise RuntimeError('Webhook creation HTTP '+str(r.status_code))
        webhook=r.json()
    (ROOT/'deployment/paypal-webhook-registration.json').write_text(json.dumps({'id':webhook['id'],'url':url,'events':EVENTS,'environment':'sandbox','receiver_deployment_pending':True},indent=2))
    with database() as (db,password_db,private):
        fixture=private/'sandbox-fixture.json'
        if fixture.exists():
            data=json.loads(fixture.read_text());print('Existing fixture retained. Approval URL: '+data['approval_url']);return
        # The public registration currently has leading output. Test its corrected local copy
        # against Cloud SQL without deploying before the user's approval.
        local='http://127.0.0.1:18085'
        env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='13309',DB_USER='viora_prod',DB_NAME='viora_production',DB_PASSWORD=password_db,DB_SOCKET='')
        sessions=private/'sessions';sessions.mkdir(exist_ok=True)
        log=(private/'registration.log').open('w')
        server=subprocess.Popen(['C:/xampp/php/php.exe','-d','session.save_path='+str(sessions),'-S','127.0.0.1:18085','-t',str(ROOT/'Viora')],env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
        for _ in range(30):
            try:
                with socket.create_connection(('127.0.0.1',18085),timeout=1):break
            except OSError:time.sleep(.1)
        s=requests.Session();r=s.get(local+'/login/registro.php',timeout=30)
        token=re.search(r'name="csrf"\s+value="([a-f0-9]+)"',r.text).group(1)
        username='QA_paypal_'+secrets.token_hex(4);password=secrets.token_urlsafe(24)
        fields={'csrf':token,'nombres':username,'apellidos':'Sandbox','cedula':'997-'+str(secrets.randbelow(10000000)).zfill(7)+'-0',
                'correo':username+'@example.invalid','telefono':'809-000-0000','direccion':'QA SOLO SANDBOX',
                'usuario':username,'clave':password,'confirmar_clave':password,'terminos':'1'}
        try:r=s.post(local+'/login/guardar_registro.php',data=fields,allow_redirects=False,timeout=30)
        finally:server.terminate();server.wait(timeout=10);log.close()
        if urlparse(r.headers.get('Location','')).path.rsplit('/',1)[-1]!='suscripcion.php':
            diagnostic=r.text[:400]
            for value in fields.values():diagnostic=diagnostic.replace(value,'[QA]')
            print('Registration response diagnostic:',repr(diagnostic))
            safe='Check the private registration log'
            raise RuntimeError('Registration HTTP '+str(r.status_code)+' redirect path '+urlparse(r.headers.get('Location','')).path+': '+safe[:250]+'. No buyer subscription created.')
        with db.cursor() as c:
            c.execute('SELECT id_usuarios FROM usuario WHERE usuario=%s',(username,));uid=c.fetchone()[0]
        r=merchant.post(API+'/v1/billing/subscriptions',headers={'PayPal-Request-Id':secrets.token_hex(16)},json={
            'plan_id':secret('viora-production-paypal-plan-id'),'custom_id':'viora-user-'+str(uid),
            'application_context':{'brand_name':'VIORA Sandbox QA','user_action':'SUBSCRIBE_NOW',
                'return_url':BASE+'/login/suscripcion.php','cancel_url':BASE+'/login/suscripcion.php'}},timeout=40)
        if r.status_code!=201:raise RuntimeError('Sandbox create subscription HTTP '+str(r.status_code))
        sub=r.json();approval=next(l['href'] for l in sub['links'] if l['rel']=='approve')
        if not approval.startswith('https://www.sandbox.paypal.com/'):raise RuntimeError('Unexpected approval host')
        data={'user_id':uid,'username':username,'password':password,'cookies':s.cookies.get_dict(),
              'subscription_id':sub['id'],'approval_url':approval,'environment':'sandbox','registration_test':'corrected local copy against Cloud SQL'}
        fixture.write_text(json.dumps(data),encoding='utf-8')
        print('Sandbox created; status: '+sub['status'])
        print('Approval URL: '+approval)

if __name__=='__main__':main()
