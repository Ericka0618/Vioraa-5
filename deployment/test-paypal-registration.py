"""Browser tests of corrected registration/resumption in the fixed local QA database."""
import json
import os
import pathlib
import secrets
import socket
import subprocess
import time
from playwright.sync_api import sync_playwright

ROOT=pathlib.Path(__file__).resolve().parents[1]
env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='13308',DB_USER='root',DB_PASSWORD='',DB_SOCKET='',DB_NAME='viora_integrations_qa')
results=[]
def check(name,ok):
    results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok:raise RuntimeError(name)
folder=ROOT/'.cache/paypal-local-sessions';folder.mkdir(parents=True,exist_ok=True)
with (ROOT/'.cache/paypal-registration-test.log').open('w') as log:
    server=subprocess.Popen(['C:/xampp/php/php.exe','-d','session.save_path='+str(folder),'-S','127.0.0.1:18086','-t',str(ROOT/'Viora')],env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
    try:
        for _ in range(30):
            try:
                with socket.create_connection(('127.0.0.1',18086),timeout=1):break
            except OSError:time.sleep(.1)
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
            page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            base='http://127.0.0.1:18086';username='QA_paypal_'+secrets.token_hex(4);password=secrets.token_urlsafe(24)
            page.goto(base+'/login/registro.php')
            fields={'nombres':username,'apellidos':'Sandbox','cedula':'997-'+str(secrets.randbelow(10000000)).zfill(7)+'-0',
                'correo':username+'@example.invalid','telefono':'809-000-0000','direccion':'QA local',
                'usuario':username,'clave':password,'confirmar_clave':password}
            for name,value in fields.items():page.locator('[name="'+name+'"]').fill(value)
            page.locator('[name=terminos]').check()
            with page.expect_navigation():page.locator('button[type=submit]').first.click()
            check('Actual registration redirects without prior header output',page.url.endswith('/login/suscripcion.php'))
            check('Subscription form keeps existing design and price','US$10.00' in page.locator('body').inner_text())
            response=page.request.post(base+'/login/verificar_suscripcion.php',data={'subscriptionID':'I-QA'})
            check('Pending verification rejects absent CSRF',response.status==403)
            page.goto(base+'/login/logout.php');page.goto(base+'/login/login.php')
            page.fill('#username',username);page.fill('#password',password)
            with page.expect_navigation():page.locator('button[type=submit]').first.click()
            check('Correct pending credentials resume subscription without a new account',page.url.endswith('/login/suscripcion.php'))
            page.goto(base+'/mantenimientos/catalogo.php')
            check('Pending account cannot access catalog','/mantenimientos/catalogo.php' not in page.url)
            check('PayPal page has no JavaScript syntax errors',not errors)
            browser.close()
    finally:
        server.terminate();server.wait(timeout=10)
        (ROOT/'deployment/paypal-registration-results.json').write_text(json.dumps(results,indent=2))
