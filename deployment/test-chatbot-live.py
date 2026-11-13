"""Real Gemini + browser + Cloud SQL QA; never changes existing accounts or financial records."""
import argparse
import datetime
import json
import os
import pathlib
import re
import socket
import subprocess
import time
import uuid
import pymysql
from playwright.sync_api import sync_playwright

ROOT=pathlib.Path(__file__).resolve().parents[1]
PROJECT='viora-514300'
def cli(*args):
    return subprocess.check_output(['gcloud.cmd',*args,'--project='+PROJECT],text=True,stderr=subprocess.PIPE).strip()

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--public',action='store_true');args=parser.parse_args()
    release=json.loads((ROOT/'deployment/chatbot-release.json').read_text())
    base='https://viora-6ikx2ezlba-uc.a.run.app' if args.public else release['candidate_url']
    resultfile=ROOT/'deployment'/('chatbot-public-results.json' if args.public else 'chatbot-live-results.json')
    results=[];created=[]
    def check(name,ok):
        results.append({'test':name,'passed':bool(ok)})
        print(('PASS ' if ok else 'FAIL ')+name,flush=True)
        if not ok:raise RuntimeError(name)
    env=os.environ.copy();env['CSQL_PROXY_TOKEN']=cli('auth','print-access-token')
    password=cli('secrets','versions','access','latest','--secret=viora-production-db-password')
    with (ROOT/'.cache/chatbot-live-sql.log').open('w') as log:
        proc=subprocess.Popen([str(pathlib.Path.home()/'.cache/viora-tools/cloud-sql-proxy.exe'),
            '--address=127.0.0.1','--port=13309',PROJECT+':us-central1:viora-staging-db'],
            env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
        db=None
        try:
            for _ in range(40):
                try:
                    with socket.create_connection(('127.0.0.1',13309),timeout=1):break
                except OSError:time.sleep(.2)
            db=pymysql.connect(host='127.0.0.1',port=13309,user='viora_prod',password=password,
                database='viora_production',charset='utf8mb4',autocommit=True,read_timeout=20)
            def query(sql,values=()):
                with db.cursor() as c:c.execute(sql,values);return c.fetchall() if c.description else c.lastrowid
            # Use only existing active accounts whose legacy credentials can be verified without resetting them.
            # No account name/password/hash is written to results, arguments, screenshots or logs.
            available=query("SELECT u.id_usuarios,u.usuario,u.clave,r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND (LOWER(r.nombre) IN ('admin','administrador') OR (r.nombre='Cliente' AND u.estado_cuenta='Activo' AND EXISTS (SELECT 1 FROM suscripcion s WHERE s.usuario_id=u.id_usuarios AND s.estado='Activo' AND (s.fecha_proximo_pago IS NULL OR s.fecha_proximo_pago>UTC_TIMESTAMP())))) ORDER BY u.id_usuarios")
            accounts={}
            for row in available:
                role='Cliente' if row[3]=='Cliente' else 'Administrador'
                if role not in accounts and not row[2].startswith(('$','DEMO_')) and len(row[2])<64:
                    accounts[role]=row
            if len(accounts)!=2:raise RuntimeError('No hay cuentas verificables de ambos roles sin restablecer credenciales. Se requiere login privado del titular.')
            with sync_playwright() as p:
                browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
                errors=[];markers={}
                for role,account in accounts.items():
                    uid,username,credential,_=account
                    cid=query('INSERT INTO conversacion_ia(usuario_id) VALUES(%s)',(uid,));created.append((cid,uid))
                    context=browser.new_context();page=context.new_page()
                    page.on('pageerror',lambda e:errors.append(str(e)))
                    page.goto(base+'/login/login.php');page.fill('#username',username);page.fill('#password',credential)
                    with page.expect_navigation():page.locator('button[type=submit]').first.click()
                    page.locator('#cb-widget').wait_for(state='attached')
                    page.wait_for_function("!document.querySelector('#cb-enviar').disabled")
                    page.locator('#cb-abrir').click()
                    check(role+' actual browser login',page.locator('#cb-ventana').is_visible())
                    check(role+' opens separate QA conversation',len(query('SELECT id_mensajes FROM mensaje_ia WHERE conversacion_id=%s',(cid,)))==0)
                    endpoint=base+'/mantenimientos/chatbot_api.php'
                    check(role+' authenticated request without CSRF rejected',page.request.post(endpoint,data={'accion':'historial'}).status==403)
                    token=page.locator('#cb-widget').get_attribute('data-csrf')
                    if len(created)>1:
                        history=page.request.post(endpoint,data={'accion':'historial','conversacion_id':created[0][0]},headers={'X-CSRF-Token':token})
                        check(role+' cannot request other user conversation by ID',history.status==200 and history.json()['historial']==[])
                    for othermarker in markers.values():check(role+' does not see other account history',othermarker not in page.locator('#cb-mensajes').inner_text())
                    marker='QA-CHATBOT-'+uuid.uuid4().hex[:12];markers[role]=marker
                    prompt='Explica brevemente las funciones de VIORA para mi rol. Identifica esta prueba como '+marker+'. No registres ninguna visita ni solicitud.'
                    page.fill('#cb-entrada',prompt)
                    with page.expect_response(lambda r:'/mantenimientos/chatbot_api.php' in r.url and r.request.method=='POST',timeout=180000) as response:
                        page.locator('#cb-enviar').click()
                    r=response.value;data=r.json()
                    check(role+' Gemini real reply over HTTPS',r.status==200 and bool(data.get('respuesta')) and 'viora' in data['respuesta'].lower())
                    page.wait_for_function("!document.querySelector('#cb-enviar').disabled")
                    saved=query('SELECT tipo_emisor,contenido FROM mensaje_ia WHERE conversacion_id=%s ORDER BY id_mensajes',(cid,))
                    check(role+' both Gemini messages persisted in Cloud SQL',len(saved)==2 and saved[0][0]=='USUARIO' and marker in saved[0][1] and saved[1][1]==data['respuesta'])
                    page.reload();page.wait_for_function("document.querySelector('#cb-enviar') && !document.querySelector('#cb-enviar').disabled")
                    check(role+' remote history survives reload',marker in page.locator('#cb-mensajes').inner_text())
                    # This request must consult real data; check returned property identifiers against the actual authorized catalog.
                    prompt='Consulta las propiedades disponibles usando tus herramientas y muestra hasta tres codigos APT con sus precios. No registres visitas ni solicitudes.'
                    page.fill('#cb-entrada',prompt)
                    with page.expect_response(lambda r:'/mantenimientos/chatbot_api.php' in r.url and r.request.method=='POST',timeout=180000) as response:
                        page.locator('#cb-enviar').click()
                    r=response.value;data=r.json();text=data.get('respuesta','')
                    codes=set(re.findall(r'APT-\d+',text))
                    valid={f'APT-{row[0]:03d}' for row in query("SELECT a.id_apartamentos FROM apartamento a JOIN edificio e ON e.id_edificios=a.edificio_id JOIN condominio c ON c.id_condominios=e.condominio_id WHERE a.activo=1 AND a.disponible=1 AND a.estado='Disponible' AND NOT EXISTS(SELECT 1 FROM solicitud_propiedad sp WHERE sp.apartamento_id=a.id_apartamentos AND sp.estado='Aprobada')")}
                    if role=='Administrador':valid={f'APT-{row[0]:03d}' for row in query("SELECT id_apartamentos FROM apartamento WHERE activo=1 AND estado='Disponible'")}
                    check(role+' real property reply contains valid catalog codes',r.status==200 and (bool(codes) if valid else bool(text)) and codes.issubset(valid))
                    if role=='Cliente' and valid:
                        code=sorted(valid)[0]
                        before=query('SELECT COUNT(*) FROM visita_propiedad WHERE persona_id=(SELECT persona_id FROM usuario WHERE id_usuarios=%s)',(uid,))[0][0]
                        date=(datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=-4))).date()+datetime.timedelta(days=7)).isoformat()
                        page.fill('#cb-entrada',f'Prepara una visita a {code} para el {date} a las 10:00. Muestrame la propuesta para decidir; todavia no la confirmo.')
                        with page.expect_response(lambda r:'/mantenimientos/chatbot_api.php' in r.url and r.request.method=='POST',timeout=180000) as response:
                            page.locator('#cb-enviar').click()
                        r=response.value;data=r.json()
                        check('Client receives explicit server confirmation before action',r.status==200 and 'Confirma estos datos' in data.get('respuesta',''))
                        check('Unconfirmed visit creates no business record',query('SELECT COUNT(*) FROM visita_propiedad WHERE persona_id=(SELECT persona_id FROM usuario WHERE id_usuarios=%s)',(uid,))[0][0]==before)
                        page.fill('#cb-entrada','No, cancela esa propuesta. No registres ninguna visita.')
                        with page.expect_response(lambda r:'/mantenimientos/chatbot_api.php' in r.url and r.request.method=='POST',timeout=180000) as response:
                            page.locator('#cb-enviar').click()
                        check('Declined proposal creates no business record',response.value.status==200 and query('SELECT COUNT(*) FROM visita_propiedad WHERE persona_id=(SELECT persona_id FROM usuario WHERE id_usuarios=%s)',(uid,))[0][0]==before)
                    context.close()
                check('Browser has no JavaScript exceptions',not errors)
                browser.close()
        finally:
            if db:
                # Retain audit messages; close only the newly created test conversations.
                for cid,uid in created:
                    with db.cursor() as c:c.execute("UPDATE conversacion_ia SET estado='Cerrada',activo=0 WHERE id_conversaciones=%s AND usuario_id=%s",(cid,uid))
                db.close()
            proc.terminate();proc.wait(timeout=15)
            resultfile.write_text(json.dumps({'url':base,'revision':release['candidate_revision'],
                'real_gemini':any('Gemini real reply' in r['test'] and r['passed'] for r in results),
                'checks':results,'new_qa_conversations_closed':len(created)},indent=2),encoding='utf-8')

if __name__=='__main__':main()
