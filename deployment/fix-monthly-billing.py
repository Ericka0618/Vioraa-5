"""Authorized one-file fix over the active immutable image; no fiscal activation."""
import datetime,hashlib,json,subprocess,sys
from paypal_cloud import ROOT,cli,service,database
from playwright.sync_api import sync_playwright

RECEIPT=ROOT/'deployment/monthly-billing-release.json'
def config(s):
    spec=json.loads(json.dumps(s['spec']['template']['spec']))
    for c in spec['containers']:c.pop('image',None)
    return spec

def prepare():
    s=service();active=next(t['revisionName'] for t in s['status']['traffic'] if t.get('percent')==100)
    rev=json.loads(cli('run','revisions','describe',active,'--region=us-central1','--format=json'));base=rev['spec']['containers'][0]['image']
    assert '@sha256:' in base
    original=subprocess.check_output(['git','show','HEAD:Viora/mantenimientos/facturacion.php'],cwd=ROOT)
    fixed=original.lstrip(b'\r\n\t ');assert fixed!=original and fixed.startswith(b'<?php')
    stamp='monthly-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S');folder=ROOT/'.cache'/stamp;folder.mkdir()
    (folder/'facturacion.php').write_bytes(fixed)
    # Assert exact old bytes before replacing; undeployed fiscal changes stay local.
    oldhash=hashlib.sha256(original).hexdigest()
    (folder/'Dockerfile').write_text('FROM '+base+'\nRUN php -r \'if (hash_file("sha256", "/var/www/html/mantenimientos/facturacion.php") !== "'+oldhash+'") exit(1);\'\nCOPY facturacion.php /var/www/html/mantenimientos/facturacion.php\nRUN php -l /var/www/html/mantenimientos/facturacion.php && apache2ctl -t\n')
    image='us-central1-docker.pkg.dev/viora-514300/viora/web:'+stamp
    build={'steps':[{'name':'gcr.io/cloud-builders/docker','args':['build','-t',image,'.']},{'name':'gcr.io/cloud-builders/docker','args':['push',image]}],'images':[image],'serviceAccount':'projects/viora-514300/serviceAccounts/viora-build@viora-514300.iam.gserviceaccount.com','options':{'logging':'CLOUD_LOGGING_ONLY'}}
    (folder/'cloudbuild.json').write_text(json.dumps(build))
    r={'base_revision':active,'base_image':base,'image':image,'config':config(s),'context':str(folder),'sha256':hashlib.sha256(fixed).hexdigest(),'only_file':'mantenimientos/facturacion.php','authorized_by_user':True}
    RECEIPT.write_text(json.dumps(r,indent=2))
    result=cli('builds','submit',str(folder),'--config='+str(folder/'cloudbuild.json'),'--region=us-central1','--gcs-source-staging-dir=gs://viora-514300-build-source/monthly','--async','--format=json','--quiet')
    r['build_id']=json.loads(result)['id'];RECEIPT.write_text(json.dumps(r,indent=2));print('Build submitted:',r['build_id'])

def smoke(base,admin):
    checks=[]
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
        context=browser.new_context();page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/login/login.php');page.fill('#username',admin[0]);page.fill('#password',admin[1])
        with page.expect_navigation():page.locator('button[type=submit]').first.click()
        assert '/principal.php' in page.url;checks.append('Admin login')
        response=page.goto(base+'/mantenimientos/facturacion.php');assert response.status==200 and page.locator('form').count()>0;checks.append('Monthly billing form renders')
        assert page.locator('body').inner_text().strip() and page.locator('#cb-widget').count()==1;checks.append('Page and existing Gemini widget present')
        assert not errors;checks.append('No JavaScript exceptions')
        assert page.request.post(base+'/login/paypal_webhook.php',data={'id':'QA'}).status==400;checks.append('Unsigned PayPal event rejected')
        context.close();context=browser.new_context();page=context.new_page();page.goto(base+'/mantenimientos/facturacion.php');assert '/login/login.php' in page.url;checks.append('Anonymous session redirected')
        browser.close()
    return checks

def finish():
    r=json.loads(RECEIPT.read_text());b=json.loads(cli('builds','describe',r['build_id'],'--region=us-central1','--format=json'))
    assert b['status']=='SUCCESS',b['status'];s=service();assert config(s)==r['config']
    image=r['image'].rsplit(':',1)[0]+'@'+b['results']['images'][0]['digest']
    cli('run','deploy','viora','--region=us-central1','--image='+image,'--no-traffic','--tag=monthly-review','--quiet')
    s=service();assert config(s)==r['config'];t=next(t for t in s['status']['traffic'] if t.get('tag')=='monthly-review')
    r.update(candidate_revision=t['revisionName'],image_digest=b['results']['images'][0]['digest']);RECEIPT.write_text(json.dumps(r,indent=2))
    with database() as (db,_,__):
        with db.cursor() as c:
            c.execute("SELECT u.usuario,u.clave FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND LOWER(r.nombre) IN ('admin','administrador')")
            admin=next(x for x in c.fetchall() if not x[1].startswith(('$','DEMO_')) and len(x[1])<64)
    r['candidate_checks']=smoke(t['url'],admin)
    cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+t['revisionName']+'=100','--quiet')
    try:r['public_checks']=smoke(s['status']['url'],admin)
    except Exception:
        cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+r['base_revision']+'=100','--quiet');raise
    assert config(service())==r['config'];r['deployed']=True;RECEIPT.write_text(json.dumps(r,indent=2));print('Public revision:',t['revisionName'],'Checks:',len(r['candidate_checks'])+len(r['public_checks']))

if __name__=='__main__':
    if sys.argv[1:] == ['finish']:finish()
    else:prepare()
