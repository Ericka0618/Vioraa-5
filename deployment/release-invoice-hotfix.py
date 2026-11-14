"""Atomic fiscal dependencies and isolated functional tests of the exact Linux image."""
import hashlib, importlib.util, json, pathlib, shutil, subprocess, sys, datetime
from paypal_cloud import ROOT, cli, service, database

spec=importlib.util.spec_from_file_location('invoices',ROOT/'deployment/release-invoices.py')
invoices=importlib.util.module_from_spec(spec);spec.loader.exec_module(invoices)
RECEIPT=ROOT/'deployment/invoice-hotfix-release.json'
PUBLIC='https://viora-1055595302579.us-central1.run.app'
FILES=['mantenimientos/guardar_factura.php','mantenimientos/reenviar_ecf.php','mantenimientos/emitir_nota_credito.php','mantenimientos/ecf_service.php','mantenimientos/ecf_validacion.php','conf/ecf.php']

def config(s):
    copy=json.loads(json.dumps(s['spec']['template']['spec']))
    for c in copy['containers']:c.pop('image',None)
    return copy

def prepare():
    s=service();assert next(e['value'] for e in s['spec']['template']['spec']['containers'][0]['env'] if e['name']=='ECF_ENABLED')=='false'
    old=next(t['revisionName'] for t in s['status']['traffic'] if t.get('percent')==100)
    rev=json.loads(cli('run','revisions','describe',old,'--region=us-central1','--format=json'));base=rev['spec']['containers'][0]['image'];assert '@sha256:' in base
    stamp='invoice-hotfix-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S');folder=ROOT/'.cache'/stamp;folder.mkdir()
    manifest=[]
    for f in FILES:
        src=ROOT/'Viora'/f;dst=folder/'overlay'/f;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst);manifest.append({'path':f,'sha256':hashlib.sha256(src.read_bytes()).hexdigest()})
    # Schema only: no customer data, passwords or production rows enter the build.
    schema=subprocess.check_output(['C:/xampp/mysql/bin/mysqldump.exe','--host=127.0.0.1','--port=13308','-u','root','--no-data','--skip-comments','viora_ecf_qa'])
    (folder/'qa-schema.sql').write_bytes(schema);shutil.copyfile(ROOT/'deployment/test-invoice-image.php',folder/'qa-test.php')
    runtime="php -r 'require \"/var/www/html/mantenimientos/ecf_service.php\"; if (!function_exists(\"ecf_validar_peticion\")) exit(1); if (!is_array(ecf_configuracion()) || !is_array(ecf_configuracion())) exit(2);'"
    (folder/'Dockerfile').write_text('FROM '+base+'\nCOPY overlay/ /var/www/html/\nRUN '+ ' && '.join('php -l /var/www/html/'+f for f in FILES)+' && '+runtime+' && apache2ctl -t\n')
    image='us-central1-docker.pkg.dev/viora-514300/viora/web:'+stamp
    paths=' '.join('/var/www/html/'+f for f in invoices.PROTECTED+['mantenimientos/factura_pdf.php','mantenimientos/factura_render.php','mantenimientos/factura_documento.php','mantenimientos/consulta_facturas.php','mantenimientos/mis_facturas.php'])
    guard='set -eu\ndocker run --rm --entrypoint sha256sum "$$BASE" '+paths+' > /tmp/base.sha\ndocker run --rm --entrypoint sha256sum "$$IMAGE" '+paths+' > /tmp/patch.sha\ndiff /tmp/base.sha /tmp/patch.sha\n'
    diagnostic='docker run --rm --entrypoint php "$$BASE" -r \'require "/var/www/html/mantenimientos/ecf_service.php"; echo json_encode(["base_validator_loaded"=>function_exists("ecf_validar_peticion"),"base_service_sha256"=>hash_file("sha256","/var/www/html/mantenimientos/ecf_service.php")]),PHP_EOL;\''
    functional='''set -eu
docker run -d --name invoice-hotfix-db --network cloudbuild -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_ROOT_HOST=% -e MYSQL_DATABASE=viora_invoice_hotfix_qa mysql:8.0
ready=0
for i in $$(seq 1 90); do if docker exec invoice-hotfix-db mysql --protocol=tcp -h127.0.0.1 -uroot -D viora_invoice_hotfix_qa -e 'SELECT 1'; then ready=1; break; fi; sleep 2; done
test "$$ready" = 1
docker exec -i invoice-hotfix-db mysql viora_invoice_hotfix_qa < /workspace/qa-schema.sql
docker run -d --name invoice-hotfix-app --network cloudbuild -e DB_HOST=invoice-hotfix-db -e DB_USER=root -e DB_PASSWORD= -e DB_NAME=viora_invoice_hotfix_qa -e DB_SOCKET= -e DB_SESSION_ENABLED=false -e ECF_ENABLED=false "$$IMAGE"
docker cp /workspace/qa-test.php invoice-hotfix-app:/tmp/qa-test.php
for i in $$(seq 1 30); do if docker exec invoice-hotfix-app curl -fsS http://127.0.0.1:8080/healthz.php; then break; fi; sleep 1; done
docker exec --user www-data invoice-hotfix-app php /tmp/qa-test.php
docker rm -f invoice-hotfix-app invoice-hotfix-db
'''
    build={'steps':[{'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['BASE='+base],'args':['-c',diagnostic]}, {'name':'gcr.io/cloud-builders/docker','args':['build','-t',image,'.']}, {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['BASE='+base,'IMAGE='+image],'args':['-c',guard]}, {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['IMAGE='+image],'args':['-c',functional]}, {'name':'gcr.io/cloud-builders/docker','args':['push',image]}], 'images':[image], 'serviceAccount':'projects/viora-514300/serviceAccounts/viora-build@viora-514300.iam.gserviceaccount.com','options':{'logging':'CLOUD_LOGGING_ONLY'},'timeout':'1200s'}
    (folder/'cloudbuild.json').write_text(json.dumps(build))
    with database() as (db,_,__):
        before=invoices.counts(db)
        with db.cursor() as c:
            c.execute('SELECT MAX(fecha_registro) FROM factura');last=str(c.fetchone()[0])
            c.execute("SELECT COUNT(*) FROM (SELECT f.contrato_id,fd.periodo,fd.concepto FROM factura f JOIN factura_detalle fd ON fd.factura_id=f.id_facturas WHERE f.estado<>'Anulada' AND fd.activo=1 GROUP BY f.contrato_id,fd.periodo,fd.concepto HAVING COUNT(*)>1) duplicates");duplicates=c.fetchone()[0]
    b=json.loads(cli('builds','submit',str(folder),'--config='+str(folder/'cloudbuild.json'),'--region=us-central1','--gcs-source-staging-dir=gs://viora-514300-build-source/invoice-hotfix','--async','--format=json','--quiet'))
    RECEIPT.write_text(json.dumps({'previous_revision':old,'base_image':base,'config':config(s),'image':image,'files':manifest,'build_id':b['id'],'financial_counts_before':before,'last_invoice_before':last,'preexisting_duplicate_groups':duplicates,'public_url':PUBLIC},indent=2));print('BUILD '+b['id'])

def finish():
    r=json.loads(RECEIPT.read_text());b=json.loads(cli('builds','describe',r['build_id'],'--region=us-central1','--format=json'));assert b['status']=='SUCCESS',b['status']
    logs=cli('logging','read','resource.type="build" AND resource.labels.build_id="'+r['build_id']+'"','--limit=500','--format=value(textPayload)')
    line=next(line for line in logs.splitlines() if '{"checks":' in line)
    r['image_checks']=json.loads(line[line.index('{"checks":'):])['checks']
    assert len(r['image_checks'])==14 and all(c['passed'] for c in r['image_checks']), 'Missing image functional evidence'
    r['base_validator_loaded']=False
    assert '"base_validator_loaded":false' in logs, 'Base dependency diagnosis not recorded'
    s=service();assert config(s)==r['config'];assert any(t.get('percent')==100 and t['revisionName']==r['previous_revision'] for t in s['status']['traffic'])
    image=r['image'].rsplit(':',1)[0]+'@'+b['results']['images'][0]['digest']
    cli('run','deploy','viora','--region=us-central1','--image='+image,'--no-traffic','--tag=invoice-hotfix-review','--quiet')
    s=service();assert config(s)==r['config'];tag=next(t for t in s['status']['traffic'] if t.get('tag')=='invoice-hotfix-review')
    r.update(candidate_revision=tag['revisionName'],candidate_url=tag['url'],image_digest=b['results']['images'][0]['digest'],build_status='SUCCESS');RECEIPT.write_text(json.dumps(r,indent=2))
    with database() as (db,_,__),invoices.runtime_accounts(db) as accounts:
        with db.cursor() as c:c.execute('SELECT id_facturas FROM factura ORDER BY id_facturas');ids=[row[0] for row in c.fetchall()]
        r['candidate_checks']=invoices.smoke(tag['url'],accounts,ids)
        import requests
        def validator(base):
            session=requests.Session();session.trust_env=False;session.cookies.set('VIORASID',accounts['Administrador'],domain=base.split('//')[1],path='/')
            response=session.post(base+'/mantenimientos/guardar_factura.php',json={},timeout=40)
            assert response.status_code==403 and response.json().get('success') is False and 'undefined function' not in response.text
            print('PASS fiscal request validator executes on '+base,flush=True)
        validator(tag['url']);assert invoices.counts(db)==r['financial_counts_before']
        cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+tag['revisionName']+'=100','--quiet')
        try:
            r['public_checks']=invoices.smoke(PUBLIC,accounts,ids);validator(PUBLIC)
            assert invoices.counts(db)==r['financial_counts_before'];assert config(service())==r['config']
        except Exception:
            cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+r['previous_revision']+'=100','--quiet');raise
    r.update(deployed=True,fiscal_enabled=False,financial_counts_unchanged=True,validator_executed_candidate_and_public=True)
    RECEIPT.write_text(json.dumps(r,indent=2));print('PUBLIC '+r['candidate_revision'])

if __name__=='__main__':
    if sys.argv[1:]==['finish']:finish()
    else:prepare()
