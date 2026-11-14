"""Publish billing as one bundle after real HTTPS browser submission on two replicas."""
import datetime, hashlib, importlib.util, json, pathlib, re, shutil, subprocess, sys
import requests
from paypal_cloud import ROOT, cli, service, database
spec=importlib.util.spec_from_file_location('invoices',ROOT/'deployment/release-invoices.py')
invoices=importlib.util.module_from_spec(spec);spec.loader.exec_module(invoices)
RECEIPT=ROOT/'deployment/csrf-billing-release.json'
PUBLIC='https://viora-1055595302579.us-central1.run.app'
FILES=list(dict.fromkeys(invoices.FILES+['mantenimientos/facturacion_json.php','mantenimientos/buscar_clientes_factura.php','mantenimientos/obtener_propiedades_cliente.php']))
def config(s):
    out=json.loads(json.dumps(s['spec']['template']['spec']))
    for c in out['containers']:c.pop('image',None)
    return out

def form_check(base,db,accounts):
    session=requests.Session();session.trust_env=False;session.cookies.set('VIORASID',accounts['Administrador'],domain=base.split('//')[1],path='/')
    response=session.get(base+'/mantenimientos/facturacion.php',timeout=45)
    m=re.search(r'<meta name="ecf-csrf" content="([a-f0-9]{64})"',response.text)
    with db.cursor() as c:c.execute('SELECT payload FROM viora_runtime_sessions WHERE id=%s',(accounts['Administrador'],));payload=c.fetchone()[0]
    if isinstance(payload,bytes):payload=payload.decode()
    saved=re.search(r'ecf_csrf\|s:\d+:"([^";]+)";',payload)
    return session,response,m,saved

def prepare():
    s=service();assert next(e['value'] for e in s['spec']['template']['spec']['containers'][0]['env'] if e['name']=='ECF_ENABLED')=='false'
    previous=next(t['revisionName'] for t in s['status']['traffic'] if t.get('percent')==100)
    rev=json.loads(cli('run','revisions','describe',previous,'--region=us-central1','--format=json'));base=rev['spec']['containers'][0]['image'];assert '@sha256:' in base
    stamp='csrf-billing-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S');folder=ROOT/'.cache'/stamp;folder.mkdir();manifest=[]
    for name in FILES:
        src=ROOT/'Viora'/name;dst=folder/'overlay'/name;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst);manifest.append({'path':name,'sha256':hashlib.sha256(src.read_bytes()).hexdigest()})
    schema=subprocess.check_output(['C:/xampp/mysql/bin/mysqldump.exe','--host=127.0.0.1','--port=13308','-u','root','--no-data','--skip-comments','viora_ecf_qa'])
    (folder/'qa-schema.sql').write_bytes(schema+b'\n'+(ROOT/'deployment/staging-sessions.sql').read_bytes())
    shutil.copyfile(ROOT/'deployment/seed-csrf-qa.php',folder/'seed.php');shutil.copyfile(ROOT/'deployment/test-csrf-browser.py',folder/'browser.py')
    (folder/'Dockerfile').write_text('FROM '+base+'\nCOPY overlay/ /var/www/html/\nRUN '+' && '.join('php -l /var/www/html/'+f for f in FILES if f.endswith('.php'))+' && apache2ctl -t\n')
    image='us-central1-docker.pkg.dev/viora-514300/viora/web:'+stamp
    paths=' '.join('/var/www/html/'+f for f in invoices.PROTECTED)+' /etc/viora/runtime.php /etc/viora/mysql-session.php'
    guard='set -eu\ndocker run --rm --entrypoint sha256sum "$$BASE" '+paths+' > /tmp/base.sha\ndocker run --rm --entrypoint sha256sum "$$IMAGE" '+paths+' > /tmp/new.sha\ndiff /tmp/base.sha /tmp/new.sha\n'
    start='''set -eu
docker run -d --name invoice-csrf-db --network cloudbuild -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_ROOT_HOST=% -e MYSQL_DATABASE=viora_csrf_qa mysql:8.0
ready=0
for i in $$(seq 1 90); do if docker exec invoice-csrf-db mysql --protocol=tcp -h127.0.0.1 -uroot -D viora_csrf_qa -e 'SELECT 1'; then ready=1; break; fi; sleep 2; done
test "$$ready" = 1
docker exec -i invoice-csrf-db mysql viora_csrf_qa < /workspace/qa-schema.sql
for replica in a b; do
 docker run -d --name invoice-csrf-$$replica --network cloudbuild -e DB_HOST=invoice-csrf-db -e DB_USER=root -e DB_PASSWORD= -e DB_NAME=viora_csrf_qa -e DB_SOCKET= -e DB_SESSION_ENABLED=true -e ECF_ENABLED=false -e K_SERVICE=csrf-qa "$$IMAGE"
done
docker cp /workspace/seed.php invoice-csrf-a:/tmp/seed.php
docker exec --user www-data invoice-csrf-a php /tmp/seed.php
for replica in a b; do
 for i in $$(seq 1 30); do if docker exec invoice-csrf-$$replica curl -fsS http://127.0.0.1:8080/healthz.php; then break; fi; sleep 1; done
done
'''
    build={'steps':[{'name':'gcr.io/cloud-builders/docker','id':'build','args':['build','-t',image,'.']},
        {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['BASE='+base,'IMAGE='+image],'args':['-c',guard]},
        {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['IMAGE='+image],'args':['-c',start]},
        {'name':'mcr.microsoft.com/playwright/python:v1.65.0-noble','id':'browser','entrypoint':'bash','env':['DB_HOST=invoice-csrf-db','DB_PORT=3306','DB_NAME=viora_csrf_qa','ECF_ENABLED=false','CSRF_A=http://invoice-csrf-a:8080','CSRF_B=http://invoice-csrf-b:8080','CSRF_RESULT=/workspace/csrf-browser-image-results.json'],'args':['-c','python -m pip install --quiet --disable-pip-version-check playwright==1.65.0 requests pymysql cryptography && python /workspace/browser.py']},
        {'name':'gcr.io/cloud-builders/docker','args':['rm','-f','invoice-csrf-a','invoice-csrf-b','invoice-csrf-db']},
        {'name':'gcr.io/cloud-builders/docker','args':['push',image]}], 'images':[image],'serviceAccount':'projects/viora-514300/serviceAccounts/viora-build@viora-514300.iam.gserviceaccount.com','options':{'logging':'CLOUD_LOGGING_ONLY'},'timeout':'1500s'}
    (folder/'cloudbuild.json').write_text(json.dumps(build))
    with database() as (db,_,__),invoices.runtime_accounts(db) as accounts:
        before=invoices.counts(db);session,response,token,saved=form_check(PUBLIC,db,accounts)
        baseline={'http_status':response.status_code,'csrf_meta_present':bool(token),'session_token_present':bool(saved),'tokens_match':bool(token and saved and token.group(1)==saved.group(1))}
        baseline['payment_page_csrf_header_present']='X-CSRF-Token' in session.get(PUBLIC+'/mantenimientos/facturacion_viora.php?contrato=1',timeout=45).text
        with db.cursor() as c:
            c.execute('SELECT MAX(fecha_registro) FROM factura');last=str(c.fetchone()[0])
            c.execute("SELECT COUNT(*) FROM (SELECT f.contrato_id,fd.periodo,fd.concepto FROM factura f JOIN factura_detalle fd ON fd.factura_id=f.id_facturas WHERE f.estado<>'Anulada' AND fd.activo=1 GROUP BY f.contrato_id,fd.periodo,fd.concepto HAVING COUNT(*)>1) d");duplicates=c.fetchone()[0]
    b=json.loads(cli('builds','submit',str(folder),'--config='+str(folder/'cloudbuild.json'),'--region=us-central1','--gcs-source-staging-dir=gs://viora-514300-build-source/csrf-billing','--async','--format=json','--quiet'))
    RECEIPT.write_text(json.dumps({'previous_revision':previous,'base_image':base,'config':config(s),'image':image,'files':manifest,'build_id':b['id'],'financial_counts_before':before,'last_invoice_before':last,'preexisting_duplicate_groups':duplicates,'public_url':PUBLIC,'production_before':baseline},indent=2));print('BUILD '+b['id'])

def validate(base,db,accounts):
    session,response,token,saved=form_check(base,db,accounts)
    assert response.status_code==200 and token and saved and token.group(1)==saved.group(1)
    assert 'no-store' in response.headers.get('Cache-Control','')
    payment=session.get(base+'/mantenimientos/facturacion_viora.php?contrato=1',timeout=45)
    assert payment.status_code==200 and "'X-CSRF-Token': "+json.dumps(token.group(1)) in payment.text
    assert 'no-store' in payment.headers.get('Cache-Control','')
    # Valid CSRF reaches domain validation without creating financial test data.
    r=session.post(base+'/mantenimientos/guardar_factura.php',json={'contrato_id':0},headers={'X-CSRF-Token':token.group(1)},timeout=45)
    assert r.status_code==400 and r.json()['message']=='Contrato inválido', 'Valid CSRF did not reach domain validation'
    print('PASS published form and shared session token match; valid AJAX reaches domain validation: '+base,flush=True)
    return {'rendered_token_matches_sql':True,'payment_form_token_matches':True,'valid_csrf_reaches_domain_validation':True,'form_cache_disabled':True}

def finish():
    r=json.loads(RECEIPT.read_text());b=json.loads(cli('builds','describe',r['build_id'],'--region=us-central1','--format=json'));assert b['status']=='SUCCESS',b['status']
    logs=cli('logging','read','resource.type="build" AND resource.labels.build_id="'+r['build_id']+'"','--limit=1000','--format=value(textPayload)')
    line=next(line for line in logs.splitlines() if '"real_browser_login_and_submit": true' in line)
    result=json.loads(line[line.index('{"checks":'):]);assert len(result['checks'])==37 and all(c['passed'] for c in result['checks'])
    (ROOT/'deployment/csrf-browser-image-results.json').write_text(json.dumps(result,indent=2))
    s=service();assert config(s)==r['config'];assert any(t.get('percent')==100 and t['revisionName']==r['previous_revision'] for t in s['status']['traffic'])
    image=r['image'].rsplit(':',1)[0]+'@'+b['results']['images'][0]['digest']
    cli('run','deploy','viora','--region=us-central1','--image='+image,'--no-traffic','--tag=csrf-billing-review','--quiet')
    s=service();assert config(s)==r['config'];tag=next(t for t in s['status']['traffic'] if t.get('tag')=='csrf-billing-review')
    r.update(candidate_revision=tag['revisionName'],candidate_url=tag['url'],image_digest=b['results']['images'][0]['digest'],build_status='SUCCESS',image_browser_checks=len(result['checks']));RECEIPT.write_text(json.dumps(r,indent=2))
    with database() as (db,_,__),invoices.runtime_accounts(db) as accounts:
        with db.cursor() as c:c.execute('SELECT id_facturas FROM factura ORDER BY id_facturas');ids=[row[0] for row in c.fetchall()]
        r['candidate_csrf']=validate(tag['url'],db,accounts);r['candidate_checks']=invoices.smoke(tag['url'],accounts,ids)
        assert invoices.counts(db)==r['financial_counts_before']
        cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+tag['revisionName']+'=100','--quiet')
        try:
            r['public_csrf']=validate(PUBLIC,db,accounts);r['public_checks']=invoices.smoke(PUBLIC,accounts,ids)
            assert invoices.counts(db)==r['financial_counts_before'];assert config(service())==r['config']
        except Exception:
            cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+r['previous_revision']+'=100','--quiet');raise
    r.update(deployed=True,fiscal_enabled=False,financial_counts_unchanged=True,session_runtime_unchanged=True)
    RECEIPT.write_text(json.dumps(r,indent=2));print('PUBLIC '+r['candidate_revision'])

if __name__=='__main__':
    if sys.argv[1:]==['finish']:finish()
    else:prepare()
