"""Registration/login/SDK smoke checks on the candidate or the public service; no buyer payment."""
import argparse
import hashlib
import json
import secrets
import requests
from playwright.sync_api import sync_playwright
from paypal_cloud import ROOT,private_dir,database

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--public',action='store_true');args=parser.parse_args()
    release=json.loads((ROOT/'deployment/paypal-release.json').read_text())
    base='https://viora-6ikx2ezlba-uc.a.run.app' if args.public else release['candidate_url']
    private=private_dir();results=[]
    def check(name,ok):
        results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
        if not ok:raise RuntimeError(name)
    try:
        check('Public HTTPS login healthy',requests.get(base+'/login/login.php',timeout=30).status_code==200)
        check('Unsigned webhook rejected',requests.post(base+'/login/paypal_webhook.php',json={'id':'WH-QA','event_type':'PAYMENT.SALE.COMPLETED'},timeout=30).status_code==400)
        gemini=json.loads((ROOT/'deployment/chatbot-release.json').read_text())
        expected=next(f['sha256'] for f in gemini['files'] if f['path']=='mantenimientos/chatbot.js')
        check('Deployed Gemini JavaScript unchanged',hashlib.sha256(requests.get(base+'/mantenimientos/chatbot.js?v=5',timeout=30).content).hexdigest()==expected)
        with database() as (db,_,__):
            with db.cursor() as c:
                c.execute("SELECT u.usuario,u.clave FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND LOWER(r.nombre) IN ('admin','administrador') ORDER BY u.id_usuarios")
                admin=next((r for r in c.fetchall() if not r[1].startswith(('$','DEMO_')) and len(r[1])<64),None)
        if not admin:raise RuntimeError('A private admin login is needed; no credentials will be reset.')
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
            context=browser.new_context();page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            username='QA_paypal_web_'+secrets.token_hex(4);password=secrets.token_urlsafe(24)
            page.goto(base+'/login/registro.php')
            fields={'nombres':username,'apellidos':'Sandbox','cedula':'997-'+str(secrets.randbelow(10000000)).zfill(7)+'-0',
                'correo':username+'@example.invalid','telefono':'809-000-0000','direccion':'QA SOLO SANDBOX',
                'usuario':username,'clave':password,'confirmar_clave':password}
            for name,value in fields.items():page.locator('[name="'+name+'"]').fill(value)
            page.locator('[name=terminos]').check()
            with page.expect_navigation():page.locator('button[type=submit]').first.click()
            check('Browser registration redirects to subscription',page.url.endswith('/login/suscripcion.php'))
            check('Existing US10 subscription design retained','US$10.00' in page.locator('body').inner_text())
            page.locator('#paypal-button-container iframe').first.wait_for(state='attached',timeout=45000)
            check('Real Sandbox SDK buttons render',page.locator('#paypal-button-container iframe').count()>0)
            state=context.storage_state()
            (private/('public-web-fixture.json' if args.public else 'candidate-web-fixture.json')).write_text(json.dumps({'username':username,'password':password,'state':state,'base':base}))
            response=page.request.post(base+'/login/verificar_suscripcion.php',data={'subscriptionID':'I-QA'})
            check('Missing payment CSRF rejected',response.status==403)
            page.goto(base+'/login/logout.php');page.goto(base+'/login/login.php');page.fill('#username',username);page.fill('#password',password)
            with page.expect_navigation():page.locator('button[type=submit]').first.click()
            check('Pending registration resumes after password verification',page.url.endswith('/login/suscripcion.php'))
            page.goto(base+'/mantenimientos/catalogo.php')
            check('Unpaid account cannot access catalog','/mantenimientos/catalogo.php' not in page.url)
            context.close()
            context=browser.new_context();page=context.new_page();page.goto(base+'/login/login.php');page.fill('#username',admin[0]);page.fill('#password',admin[1])
            with page.expect_navigation():page.locator('button[type=submit]').first.click()
            check('Existing Administrator login unaffected','/login/principal.php' in page.url)
            check('Existing Gemini widget remains present',page.locator('#cb-widget').count()==1)
            check('No PayPal application JavaScript exceptions',not errors)
            browser.close()
    finally:
        (ROOT/'deployment'/('paypal-public-smoke.json' if args.public else 'paypal-candidate-smoke.json')).write_text(json.dumps({'url':base,'revision':release['candidate_revision'],'checks':results},indent=2))

if __name__=='__main__':main()
