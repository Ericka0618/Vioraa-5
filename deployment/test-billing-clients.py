"""Read-only client/property checks. Never submits an invoice or mutates cloud rows."""
import argparse, contextlib, json, os, pathlib, secrets, socket, subprocess, time
import pymysql, requests
from playwright.sync_api import sync_playwright
from paypal_cloud import ROOT, database

PHP='C:/xampp/php/php.exe'
BASE='http://127.0.0.1:18089'
parser=argparse.ArgumentParser();parser.add_argument('--cloud-read-only',action='store_true');args=parser.parse_args()
results=[]
def check(name,ok):
    results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    assert ok,name

@contextlib.contextmanager
def local_db():
    db=pymysql.connect(host='127.0.0.1',port=13308,user='root',database='viora_ecf_qa',autocommit=True)
    try:yield db,'',None
    finally:db.close()

with (database() if args.cloud_read_only else local_db()) as (db,password,_):
    env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='13309' if args.cloud_read_only else '13308',DB_USER='viora_prod' if args.cloud_read_only else 'root',DB_PASSWORD=password,DB_NAME='viora_production' if args.cloud_read_only else 'viora_ecf_qa',DB_SOCKET='',DB_SESSION_ENABLED='false',ECF_ENABLED='false',GEMINI_API_KEY='')
    folder=ROOT/'.cache/billing-client-tests';folder.mkdir(parents=True,exist_ok=True)
    def session(uid):
        sid=secrets.token_hex(24);e=env.copy();e.update(QA_SESSION_ID=sid,QA_UID=str(uid))
        code="session_id(getenv('QA_SESSION_ID'));session_start();$_SESSION=['logged_in'=>true,'id_usuario'=>(int)getenv('QA_UID')];session_write_close();"
        subprocess.run([PHP,'-d','session.save_path='+str(folder),'-r',code],env=e,check=True,capture_output=True)
        s=requests.Session();s.trust_env=False;s.cookies.set('PHPSESSID',sid,domain='127.0.0.1',path='/');return s,sid
    def snapshot():
        with db.cursor() as c:
            values=[]
            for t in ['factura','factura_detalle','factura_pago','factura_ecf','factura_ecf_nota','comprobante_fiscal']:
                c.execute('SELECT COUNT(*) FROM '+t);values.append(c.fetchone()[0])
            return values
    with db.cursor() as c:
        c.execute("SELECT u.id_usuarios FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND LOWER(r.nombre) IN ('administrador','admin') LIMIT 1");admin=c.fetchone()[0]
        c.execute("SELECT u.id_usuarios FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND LOWER(r.nombre)='cliente' LIMIT 1");client=c.fetchone()[0]
    before=snapshot();s,sid=session(admin);client_session,_=session(client)
    @contextlib.contextmanager
    def server(override=None):
        e=env.copy();e.update(override or {})
        with (folder/'server.log').open('w') as log:
            p=subprocess.Popen([PHP,'-d','session.save_path='+str(folder),'-S','127.0.0.1:18089','-t',str(ROOT/'Viora')],env=e,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
            try:
                for _ in range(50):
                    try:
                        with socket.create_connection(('127.0.0.1',18089),timeout=1):break
                    except OSError:time.sleep(.1)
                yield
            finally:p.terminate();p.wait(timeout=10)
    def is_json(r,status):return r.status_code==status and r.headers.get('content-type','').startswith('application/json') and isinstance(r.json(),dict)
    try:
        with server():
            for endpoint in ['buscar_clientes_factura.php','obtener_propiedades_cliente.php?persona_id=1']:
                path=BASE+'/mantenimientos/'+endpoint
                anon=requests.Session();anon.trust_env=False
                check('Anonymous JSON 401 '+endpoint,is_json(anon.get(path),401))
                check('Client role JSON 403 '+endpoint,is_json(client_session.get(path),403))
                check('Method JSON 405 '+endpoint,is_json(s.post(path),405))
            r=s.get(BASE+'/mantenimientos/buscar_clientes_factura.php');check('Administrator gets JSON client array',is_json(r,200) and r.json()['success'] and isinstance(r.json()['clientes'],list))
            people=r.json()['clientes'];check('Available clients found',len(people)>0)
            r=s.get(BASE+'/mantenimientos/obtener_propiedades_cliente.php?persona_id=invalid');check('Invalid client JSON 400',is_json(r,400))
            r=s.get(BASE+'/mantenimientos/obtener_propiedades_cliente.php?persona_id=9223372036854775807');check('Absent client returns empty property array',is_json(r,200) and r.json()['propiedades']==[])
            choices=[]
            for person in people:
                r=s.get(BASE+'/mantenimientos/obtener_propiedades_cliente.php',params={'persona_id':person['id_personas']})
                assert is_json(r,200) and isinstance(r.json()['propiedades'],list)
                choices.append((person,r.json()['propiedades']))
            check('All client property responses are JSON',True)
            with sync_playwright() as p:
                browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
                context=browser.new_context();context.add_cookies([{'name':'PHPSESSID','value':sid,'domain':'127.0.0.1','path':'/'}]);page=context.new_page();errors=[];writes=[]
                page.on('pageerror',lambda e:errors.append(str(e)))
                def prevent_write(route):writes.append(True);route.abort()
                for path in ['guardar_factura.php','reenviar_ecf.php','emitir_nota_credito.php']:page.route('**/'+path,prevent_write)
                page.goto(BASE+'/mantenimientos/facturacion.php');page.wait_for_function("document.querySelector('#cliente').options.length > 1")
                check('Browser dropdown contains database clients',page.locator('#cliente option').count()==len(people)+1)
                single=next((x for x in choices if len(x[1])==1),None)
                if single:
                    person,properties=single;prop=properties[0]
                    page.select_option('#cliente',str(person['id_personas']));page.wait_for_function("document.querySelector('#propiedad').value.length > 0")
                    check('Single property fills automatically',page.input_value('#numero_contrato')==prop['numero_contrato'] and page.input_value('#apartamento')==str(prop['apartamento']))
                multiple=next((x for x in choices if len(x[1])>1),None)
                if multiple:
                    person,properties=multiple;page.select_option('#cliente',str(person['id_personas']));page.locator('#grupoVariasPropiedades').wait_for(state='visible');page.select_option('#selectorPropiedades',str(properties[0]['id_contratos']))
                    check('Multiple properties keep contract selector',page.input_value('#numero_contrato')==properties[0]['numero_contrato'])
                check('At least one property selectable',bool(single or multiple))
                page.fill('#periodo','2026-11');page.locator('#periodo').dispatch_event('change')
                check('Invoice can be prepared without submission',page.locator('#btnGuardar').is_enabled() and page.locator('#totalFactura').inner_text().strip()!='')
                check('Existing fiscal CSRF token retained',bool(page.locator('meta[name=ecf-csrf]').get_attribute('content')))
                check('No JavaScript exceptions',not errors);check('No financial write request',not writes)
                browser.close()
        with server({'DB_PORT':'1','DB_PASSWORD':'private-error-sentinel'}):
            r=s.get(BASE+'/mantenimientos/buscar_clientes_factura.php');check('Connection failure still returns JSON 503',is_json(r,503));check('Connection details not exposed','private-error-sentinel' not in r.text and 'mysqli' not in r.text)
        if not args.cloud_read_only:
            with server({'DB_NAME':'mysql'}):
                r=s.get(BASE+'/mantenimientos/buscar_clientes_factura.php');check('SQL failure still returns JSON 503',is_json(r,503) and 'SELECT' not in r.text)
            # Empty schema fixture, exclusively on the fixed local QA instance.
            with db.cursor() as c:
                c.execute('CREATE DATABASE IF NOT EXISTS viora_billing_empty_qa CHARACTER SET utf8mb4')
                for table in ['role','usuario','persona','contrato']:
                    c.execute(f'CREATE TABLE IF NOT EXISTS viora_billing_empty_qa.{table} LIKE viora_ecf_qa.{table}')
                c.execute('INSERT IGNORE INTO viora_billing_empty_qa.role SELECT * FROM viora_ecf_qa.role')
                c.execute('INSERT IGNORE INTO viora_billing_empty_qa.usuario SELECT * FROM viora_ecf_qa.usuario WHERE id_usuarios=%s',(admin,))
            with server({'DB_NAME':'viora_billing_empty_qa'}):
                r=s.get(BASE+'/mantenimientos/buscar_clientes_factura.php');check('No clients returns successful empty JSON array',is_json(r,200) and r.json()['success'] and r.json()['clientes']==[])
        check('Financial table counts unchanged',before==snapshot())
    finally:
        name='billing-clients-cloud-readonly-results.json' if args.cloud_read_only else 'billing-clients-local-results.json'
        (ROOT/'deployment'/name).write_text(json.dumps({'app':'local corrected PHP and Chrome','database':'Cloud SQL read-only queries' if args.cloud_read_only else 'isolated QA','cloud_run_updated':False,'financial_posts_blocked':True,'checks':results},indent=2))
