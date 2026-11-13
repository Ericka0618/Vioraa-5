"""Authorized clients/properties patch, candidate and public browser checks, rollback."""
import datetime, hashlib, json, shutil, sys
from paypal_cloud import ROOT, cli, service, database
from playwright.sync_api import sync_playwright

RECEIPT=ROOT/'deployment/billing-clients-release.json'
def config(s):
    template=s['spec']['template'];spec=json.loads(json.dumps(template['spec']))
    for c in spec['containers']:c.pop('image',None)
    return {'spec':spec,'cloud_sql':template['metadata'].get('annotations',{}).get('run.googleapis.com/cloudsql-instances')}

def prepare():
    r=json.loads(RECEIPT.read_text());s=service()
    active=next(t['revisionName'] for t in s['status']['traffic'] if t.get('percent')==100)
    assert active==r['prepared_on_revision'],'Active revision changed'
    rev=json.loads(cli('run','revisions','describe',active,'--region=us-central1','--format=json'));base=rev['spec']['containers'][0]['image'];assert '@sha256:' in base
    stamp='billing-clients-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S');folder=ROOT/'.cache'/stamp;folder.mkdir()
    for f in r['files']:
        src=ROOT/'Viora'/f['path'];assert hashlib.sha256(src.read_bytes()).hexdigest()==f['sha256']
        dest=folder/'overlay'/f['path'];dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dest)
    (folder/'Dockerfile').write_text('FROM '+base+'\nCOPY overlay/ /var/www/html/\nRUN php -l /var/www/html/mantenimientos/buscar_clientes_factura.php && php -l /var/www/html/mantenimientos/obtener_propiedades_cliente.php && php -l /var/www/html/mantenimientos/facturacion_json.php && apache2ctl -t\n')
    image='us-central1-docker.pkg.dev/viora-514300/viora/web:'+stamp
    protected=['mantenimientos/chatbot_api.php','mantenimientos/chatbot_historial.php','mantenimientos/chatbot_herramientas.php','mantenimientos/chatbot.js','mantenimientos/chatbot_widget.php','conf/gemini.php','conf/subscription_access.php',
        'login/paypal_service.php','login/paypal_events.php','login/paypal_reconciliation.php','login/paypal_webhook.php','login/suscripcion.php','login/verificar_suscripcion.php',
        'conf/ecf.php','mantenimientos/ecf_service.php','mantenimientos/guardar_factura.php','mantenimientos/emitir_nota_credito.php','mantenimientos/reenviar_ecf.php','mantenimientos/facturacion.php','mantenimientos/factura_pdf.php']
    paths=' '.join('/var/www/html/'+p for p in protected)
    compare='set -eu\ndocker run --rm --entrypoint sha256sum "$$BASE" '+paths+' > /tmp/old.sha\ndocker run --rm --entrypoint sha256sum "$$IMAGE" '+paths+' > /tmp/new.sha\ndiff /tmp/old.sha /tmp/new.sha\n'
    build={'steps':[{'name':'gcr.io/cloud-builders/docker','args':['build','-t',image,'.']},
        {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['BASE='+base,'IMAGE='+image],'args':['-c',compare]},
        {'name':'gcr.io/cloud-builders/docker','args':['push',image]}],'images':[image],'serviceAccount':'projects/viora-514300/serviceAccounts/viora-build@viora-514300.iam.gserviceaccount.com','options':{'logging':'CLOUD_LOGGING_ONLY'}}
    (folder/'cloudbuild.json').write_text(json.dumps(build))
    r.update(deployment_authorized=True,build_authorized=True,base_image=base,image=image,config=config(s),context=str(folder),protected_files=protected)
    RECEIPT.write_text(json.dumps(r,indent=2))
    b=json.loads(cli('builds','submit',str(folder),'--config='+str(folder/'cloudbuild.json'),'--region=us-central1','--gcs-source-staging-dir=gs://viora-514300-build-source/billing-clients','--async','--format=json','--quiet'))
    r['build_id']=b['id'];RECEIPT.write_text(json.dumps(r,indent=2));print('BUILD '+b['id'])

def smoke(base,accounts):
    checks=[]
    def check(name,ok):
        checks.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
            context=browser.new_context();page=context.new_page();errors=[];writes=[]
            page.on('pageerror',lambda e:errors.append(str(e)))
            def deny(route):writes.append(True);route.abort()
            for path in ['guardar_factura.php','reenviar_ecf.php','emitir_nota_credito.php']:page.route('**/'+path,deny)
            for role in ['Administrador','Cliente']:
                if role=='Cliente':context.close();context=browser.new_context();page=context.new_page()
                a=accounts[role];page.goto(base+'/login/login.php');page.fill('#username',a[0]);page.fill('#password',a[1])
                with page.expect_navigation():page.locator('button[type=submit]').first.click()
                check(role+' real login succeeds',('/principal.php' if role=='Administrador' else '/catalogo.php') in page.url)
                if role=='Cliente':
                    for path in ['buscar_clientes_factura.php','obtener_propiedades_cliente.php?persona_id=1']:
                        response=page.request.get(base+'/mantenimientos/'+path)
                        check('Client denied JSON '+path,response.status==403 and response.headers.get('content-type','').startswith('application/json') and response.json()['success'] is False)
                    continue
                r=page.goto(base+'/mantenimientos/facturacion.php');check('Monthly page renders',r.status==200 and page.locator('form').count()>0)
                page.wait_for_function("document.querySelector('#cliente').options.length>1")
                r=page.request.get(base+'/mantenimientos/buscar_clientes_factura.php');data=r.json()
                check('Client list is valid JSON',r.status==200 and r.headers.get('content-type','').startswith('application/json') and data['success'])
                people=data['clientes'];check('Browser dropdown matches SQL endpoint',page.locator('#cliente option').count()==len(people)+1)
                single=None;multiple=None
                for person in people:
                    r=page.request.get(base+'/mantenimientos/obtener_propiedades_cliente.php?persona_id='+str(person['id_personas']))
                    assert r.status==200;props=r.json()['propiedades']
                    if len(props)==1 and single is None:single=(person,props)
                    if len(props)>1 and multiple is None:multiple=(person,props)
                check('All client properties return JSON',True)
                check('Single and multiple property cases available',single is not None and multiple is not None)
                person,props=single;page.select_option('#cliente',str(person['id_personas']));page.wait_for_function("document.querySelector('#propiedad').value.length>0")
                check('Single property loaded automatically',page.input_value('#numero_contrato')==props[0]['numero_contrato'] and page.input_value('#apartamento')==str(props[0]['apartamento']))
                person,props=multiple;page.select_option('#cliente',str(person['id_personas']));page.locator('#grupoVariasPropiedades').wait_for(state='visible');page.select_option('#selectorPropiedades',str(props[0]['id_contratos']))
                check('Multiple properties selectable',page.input_value('#numero_contrato')==props[0]['numero_contrato'])
                page.fill('#periodo','2026-11');page.locator('#periodo').dispatch_event('change')
                check('Invoice prepared without submission',page.locator('#btnGuardar').is_enabled() and bool(page.locator('#totalFactura').inner_text().strip()))
                check('No JavaScript exceptions',not errors);check('No financial POST attempted',not writes)
                check('Existing Gemini widget visible',page.locator('#cb-widget').count()==1)
                expected=next(f['sha256'] for f in json.loads((ROOT/'deployment/chatbot-release.json').read_text())['files'] if f['path']=='mantenimientos/chatbot.js')
                check('Gemini JavaScript unchanged',hashlib.sha256(page.request.get(base+'/mantenimientos/chatbot.js').body()).hexdigest()==expected)
                r=page.request.get(base+'/mantenimientos/obtener_propiedades_cliente.php?persona_id=invalid');check('Invalid client JSON 400',r.status==400 and r.json()['success'] is False)
            context.close();context=browser.new_context()
            for path in ['buscar_clientes_factura.php','obtener_propiedades_cliente.php?persona_id=1']:
                r=context.request.get(base+'/mantenimientos/'+path);check('Anonymous JSON 401 '+path,r.status==401 and r.json()['success'] is False)
            browser.close()
    finally:
        filename='billing-clients-public-results.json' if base=='https://viora-6ikx2ezlba-uc.a.run.app' else 'billing-clients-candidate-results.json'
        (ROOT/'deployment'/filename).write_text(json.dumps({'url':base,'real_browser_and_cloud_sql':True,'financial_posts_blocked':True,'checks':checks},indent=2))
    return checks

def finish():
    r=json.loads(RECEIPT.read_text());assert r.get('deployment_authorized')
    b=json.loads(cli('builds','describe',r['build_id'],'--region=us-central1','--format=json'));assert b['status']=='SUCCESS',b['status']
    s=service();assert config(s)==r['config']
    assert any(t.get('percent')==100 and t['revisionName']==r['prepared_on_revision'] for t in s['status']['traffic'])
    image=r['image'].rsplit(':',1)[0]+'@'+b['results']['images'][0]['digest']
    cli('run','deploy','viora','--region=us-central1','--image='+image,'--no-traffic','--tag=billing-clients-review','--quiet')
    after=service();assert config(after)==r['config'];tag=next(t for t in after['status']['traffic'] if t.get('tag')=='billing-clients-review')
    r.update(candidate_revision=tag['revisionName'],candidate_url=tag['url'],image_digest=b['results']['images'][0]['digest'],build_status='SUCCESS');RECEIPT.write_text(json.dumps(r,indent=2))
    with database() as (db,_,__):
        def snapshot():
            with db.cursor() as c:
                out=[]
                for t in ['factura','factura_detalle','factura_pago','factura_ecf','factura_ecf_nota','comprobante_fiscal']:
                    c.execute('SELECT COUNT(*) FROM '+t);out.append(c.fetchone()[0])
                return out
        with db.cursor() as c:
            c.execute("SELECT u.usuario,u.clave,r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND (LOWER(r.nombre) IN ('administrador','admin') OR (r.nombre='Cliente' AND u.estado_cuenta='Activo' AND EXISTS(SELECT 1 FROM suscripcion s WHERE s.usuario_id=u.id_usuarios AND s.estado='Activo' AND (s.fecha_proximo_pago IS NULL OR s.fecha_proximo_pago>UTC_TIMESTAMP())))) ORDER BY u.id_usuarios")
            accounts={}
            for user,password,role in c.fetchall():
                role='Cliente' if role=='Cliente' else 'Administrador'
                if role not in accounts and not password.startswith(('$','DEMO_')) and len(password)<64:accounts[role]=(user,password)
        assert len(accounts)==2,'Private login required; no account will be reset'
        before=snapshot();r['candidate_checks_passed']=len(smoke(tag['url'],accounts));assert before==snapshot()
        cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+tag['revisionName']+'=100','--quiet')
        try:
            r['public_checks_passed']=len(smoke(s['status']['url'],accounts));assert before==snapshot();assert config(service())==r['config']
        except Exception:
            cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+r['prepared_on_revision']+'=100','--quiet');raise
    r.update(deployed=True,financial_counts_unchanged=True,protected_configuration_preserved=True);RECEIPT.write_text(json.dumps(r,indent=2));print('PUBLIC '+tag['revisionName'])

if __name__=='__main__':
    if sys.argv[1:]==['finish']:finish()
    else:prepare()
