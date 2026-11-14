"""Publish only reviewed invoice files; never submits fiscal documents or payments."""
import contextlib, datetime, hashlib, json, pathlib, secrets, shutil, sys, time
from paypal_cloud import ROOT, cli, service, database
from playwright.sync_api import sync_playwright

RECEIPT = ROOT/'deployment/invoices-release.json'
BUCKET = 'viora-514300-production-documents'
FILES = ['conf/ecf.php', 'mantenimientos/ecf_validacion.php', 'mantenimientos/ecf_service.php',
    'mantenimientos/reenviar_ecf.php', 'mantenimientos/emitir_nota_credito.php',
    'mantenimientos/consulta_facturas.php', 'mantenimientos/mis_facturas.php',
    'mantenimientos/factura_acceso.php', 'mantenimientos/factura_pdf.php',
    'mantenimientos/factura_render.php', 'mantenimientos/factura_documento.php',
    'mantenimientos/guardar_factura.php', 'mantenimientos/menu_lateral.php',
    'mantenimientos/pdf_simple.php', 'mantenimientos/registrar_pago.php',
    'mantenimientos/facturacion.js']
PROTECTED = ['login/paypal_service.php', 'login/paypal_webhook.php', 'login/paypal_events.php',
    'login/paypal_reconciliation.php', 'login/suscripcion.php', 'conf/paypal_config.php',
    'conf/gemini.php', 'conf/subscription_access.php', 'mantenimientos/chatbot_api.php',
    'mantenimientos/chatbot.js', 'mantenimientos/chatbot_historial.php',
    'mantenimientos/contratos.php', 'mantenimientos/mantenimiento_mensual.php']

def config(s):
    spec=json.loads(json.dumps(s['spec']['template']['spec']))
    for container in spec['containers']:
        container.pop('image',None)
        container['env']=[e for e in container.get('env',[]) if e['name']!='INVOICE_PDF_BUCKET']
    return spec

def counts(db):
    with db.cursor() as c:
        out={}
        for t in ['factura','factura_detalle','factura_pago','factura_ecf','factura_ecf_nota','comprobante_fiscal']:
            c.execute('SELECT COUNT(*) FROM '+t);out[t]=c.fetchone()[0]
        return out

@contextlib.contextmanager
def runtime_accounts(db):
    # Temporary sessions for existing eligible users; no password/account changes.
    sessions=[];accounts={}
    try:
        with db.cursor() as c:
            c.execute("SELECT u.id_usuarios,r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND (LOWER(r.nombre) IN ('administrador','admin') OR (r.nombre='Cliente' AND u.estado_cuenta='Activo' AND EXISTS(SELECT 1 FROM suscripcion s WHERE s.usuario_id=u.id_usuarios AND s.estado='Activo' AND (s.fecha_proximo_pago IS NULL OR s.fecha_proximo_pago>UTC_TIMESTAMP())))) ORDER BY EXISTS(SELECT 1 FROM factura f WHERE f.persona_id=u.persona_id) DESC,u.id_usuarios")
            for uid,role in c.fetchall():
                role='Cliente' if role=='Cliente' else 'Administrador'
                if role in accounts:continue
                sid=secrets.token_hex(24)
                payload='logged_in|b:1;id_usuario|i:'+str(uid)+';rol|s:'+str(len(role))+':"'+role+'";'
                c.execute('INSERT INTO viora_runtime_sessions (id,payload,expires_at) VALUES (%s,%s,%s)',(sid,payload,int(time.time())+900))
                sessions.append(sid);accounts[role]=sid
        assert len(accounts)==2,'Existing eligible users required'
        yield accounts
    finally:
        with db.cursor() as c:
            for sid in sessions:c.execute('DELETE FROM viora_runtime_sessions WHERE id=%s',(sid,))

def prepare():
    s=service();active=next(t['revisionName'] for t in s['status']['traffic'] if t.get('percent')==100)
    assert next(e for e in s['spec']['template']['spec']['containers'][0]['env'] if e['name']=='ECF_ENABLED')['value']=='false'
    rev=json.loads(cli('run','revisions','describe',active,'--region=us-central1','--format=json'))
    base=rev['spec']['containers'][0]['image'];assert '@sha256:' in base
    stamp='invoices-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S')
    folder=ROOT/'.cache'/stamp;folder.mkdir();manifest=[]
    for f in FILES:
        src=ROOT/'Viora'/f;dst=folder/'overlay'/f;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
        manifest.append({'path':f,'sha256':hashlib.sha256(src.read_bytes()).hexdigest()})
    lint=' && '.join('php -l /var/www/html/'+f for f in FILES if f.endswith('.php'))
    runtime_check = "php -r 'require \"/var/www/html/mantenimientos/ecf_service.php\"; if (!function_exists(\"ecf_validar_peticion\")) exit(1);'"
    (folder/'Dockerfile').write_text('FROM '+base+'\nCOPY overlay/ /var/www/html/\nRUN '+lint+' && '+runtime_check+' && apache2ctl -t\n')
    image='us-central1-docker.pkg.dev/viora-514300/viora/web:'+stamp
    paths=' '.join('/var/www/html/'+p for p in PROTECTED)
    compare='set -eu\ndocker run --rm --entrypoint sha256sum "$$BASE" '+paths+' > /tmp/old.sha\ndocker run --rm --entrypoint sha256sum "$$IMAGE" '+paths+' > /tmp/new.sha\ndiff /tmp/old.sha /tmp/new.sha\n'
    build={'steps':[{'name':'gcr.io/cloud-builders/docker','args':['build','-t',image,'.']},
        {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['BASE='+base,'IMAGE='+image],'args':['-c',compare]},
        {'name':'gcr.io/cloud-builders/docker','args':['push',image]}],'images':[image],
        'serviceAccount':'projects/viora-514300/serviceAccounts/viora-build@viora-514300.iam.gserviceaccount.com','options':{'logging':'CLOUD_LOGGING_ONLY'}}
    (folder/'cloudbuild.json').write_text(json.dumps(build))
    r={'previous_revision':active,'config':config(s),'base_image':base,'image':image,'files':manifest,'protected_files':PROTECTED,'bucket':BUCKET,'ecf_enabled':False}
    b=json.loads(cli('builds','submit',str(folder),'--config='+str(folder/'cloudbuild.json'),'--region=us-central1','--gcs-source-staging-dir=gs://viora-514300-build-source/invoices','--async','--format=json','--quiet'))
    r['build_id']=b['id'];RECEIPT.write_text(json.dumps(r,indent=2));print('BUILD '+b['id'])

def smoke(base,accounts,invoice_ids,backfill=False):
    results=[]
    def check(name,ok):
        results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
        try:
            for role in ['Administrador','Cliente']:
                ctx=browser.new_context(viewport={'width':390 if role=='Cliente' else 1280,'height':844});page=ctx.new_page();errors=[];writes=[]
                page.on('pageerror',lambda e:errors.append(str(e)))
                def block(route):writes.append(True);route.abort()
                for endpoint in ['guardar_factura.php','registrar_pago.php','reenviar_ecf.php','emitir_nota_credito.php']:page.route('**/'+endpoint,block)
                ctx.add_cookies([{'name':'VIORASID','value':accounts[role],'url':base}])
                portal='consulta_facturas.php' if role=='Administrador' else 'mis_facturas.php'
                response=page.goto(base+'/mantenimientos/'+portal);check(role+' invoice portal renders',response.status==200 and page.locator('h1').count()==1)
                check(role+' no JavaScript exceptions',not errors)
                if role=='Administrador':
                    check('Admin date filters',page.locator('input[name=desde]').count()==1 and page.locator('input[name=hasta]').count()==1)
                    for fid in invoice_ids if backfill else invoice_ids[:1]:
                        r=page.request.get(base+'/mantenimientos/factura_pdf.php?id='+str(fid));check('Admin PDF '+str(fid),r.status==200 and r.body().startswith(b'%PDF'))
                    r=page.request.get(base+'/mantenimientos/factura_pdf.php?id='+str(invoice_ids[0])+'&descargar=1');check('PDF download disposition',r.status==200 and r.headers['content-disposition'].startswith('attachment'))
                else:
                    check('Client sidebar has Mis Facturas',page.locator('.barra-lateral a[href$="mis_facturas.php"]').count()==1)
                    check('Client cannot access admin invoices',page.request.get(base+'/mantenimientos/consulta_facturas.php').status==403)
                    check('Client cannot access missing PDF',page.request.get(base+'/mantenimientos/factura_pdf.php?id=9223372036854775807').status==404)
                check(role+' no financial POST',not writes);ctx.close()
            ctx=browser.new_context()
            r=ctx.request.get(base+'/mantenimientos/factura_pdf.php?id='+str(invoice_ids[0]),max_redirects=0)
            check('Anonymous PDF redirects to login',r.status==302)
            check('Unsigned PayPal event still rejected',ctx.request.post(base+'/login/paypal_webhook.php',data='{}',headers={'Content-Type':'application/json'}).status>=400)
            ctx.close()
        finally:browser.close()
    return results

def finish():
    r=json.loads(RECEIPT.read_text());b=json.loads(cli('builds','describe',r['build_id'],'--region=us-central1','--format=json'));assert b['status']=='SUCCESS',b['status']
    s=service();assert config(s)==r['config'];assert any(t.get('percent')==100 and t['revisionName']==r['previous_revision'] for t in s['status']['traffic'])
    with database() as (db,_,__), runtime_accounts(db) as accounts:
        before=counts(db)
        # This migration creates one new table only. Existing financial rows are untouched.
        with db.cursor() as c:
            c.execute((ROOT/'Viora/conf/factura_documento.sql').read_text(encoding='utf-8'))
            c.execute('SHOW CREATE TABLE factura_documento');r['document_schema_sha256']=hashlib.sha256(c.fetchone()[1].encode()).hexdigest()
            c.execute('SELECT id_facturas FROM factura ORDER BY id_facturas');ids=[row[0] for row in c.fetchall()];assert ids
        image=r['image'].rsplit(':',1)[0]+'@'+b['results']['images'][0]['digest']
        cli('run','deploy','viora','--region=us-central1','--image='+image,'--no-traffic','--tag=invoices-review','--update-env-vars=INVOICE_PDF_BUCKET='+BUCKET,'--quiet')
        after=service();assert config(after)==r['config'];tag=next(t for t in after['status']['traffic'] if t.get('tag')=='invoices-review')
        r.update(candidate_revision=tag['revisionName'],candidate_url=tag['url'],image_digest=b['results']['images'][0]['digest'],build_status='SUCCESS')
        RECEIPT.write_text(json.dumps(r,indent=2))
        r['candidate_checks']=smoke(tag['url'],accounts,ids,backfill=True);assert counts(db)==before
        with db.cursor() as c:c.execute('SELECT COUNT(*) FROM factura_documento WHERE tipo=\'factura\'');r['persisted_pdfs']=c.fetchone()[0]
        assert r['persisted_pdfs']>=len(ids)
        # Verify private Storage actually contains mirrored PDFs before traffic promotion.
        objects=cli('storage','ls','gs://'+BUCKET+'/factura/**')
        with db.cursor() as c:
            c.execute("SELECT documento_id,sha256 FROM factura_documento WHERE tipo='factura'")
            assert all('gs://'+BUCKET+'/factura/'+str(i)+'/'+h+'.pdf' in objects for i,h in c.fetchall())
        r['all_current_storage_hashes_verified']=True
        r['storage_mirror_verified']=True
        cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+tag['revisionName']+'=100','--quiet')
        try:
            r['public_checks']=smoke(s['status']['url'],accounts,ids);assert counts(db)==before;assert config(service())==r['config']
        except Exception:
            cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+r['previous_revision']+'=100','--quiet');raise
        r.update(deployed=True,financial_counts_unchanged=True,financial_counts=before,protected_configuration_preserved=True)
    RECEIPT.write_text(json.dumps(r,indent=2));print('PUBLIC '+r['candidate_revision'])

if __name__=='__main__':
    if sys.argv[1:]==['finish']:finish()
    else:prepare()
