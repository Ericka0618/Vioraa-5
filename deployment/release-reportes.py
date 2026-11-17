"""Additive BI migration and Cloud Run release, preserving the running service configuration."""
import argparse,contextlib,datetime,hashlib,json,os,pathlib,shutil,socket,subprocess,time
import pymysql,requests
ROOT=pathlib.Path(__file__).resolve().parents[1];PROJECT='viora-514300';REGION='us-central1';RECEIPT=ROOT/'deployment/reportes-release.json'
FILES=['estilos/reportes.css','mantenimientos/reportes.js','mantenimientos/reportes.php','mantenimientos/reportes_api.php','mantenimientos/reportes_catalogo.php','mantenimientos/reportes_documento.php','mantenimientos/reportes_ia.php','mantenimientos/reportes_servicio.php','mantenimientos/chatbot.js','mantenimientos/chatbot_api.php','mantenimientos/chatbot_herramientas.php','mantenimientos/chatbot_widget.php','mantenimientos/menu_lateral.php','mantenimientos/pdf_simple.php']
def cli(*args):return subprocess.check_output(['gcloud.cmd',*args,'--project='+PROJECT],text=True,stderr=subprocess.PIPE).strip()
def save(r):
 previous=json.loads(RECEIPT.read_text()) if RECEIPT.exists() else {}
 previous.update(r);RECEIPT.write_text(json.dumps(previous,indent=2),encoding='utf-8')
def storage():
 r=json.loads(RECEIPT.read_text())
 with cloud() as env:
  db=pymysql.connect(host=env['DB_HOST'],port=int(env['DB_PORT']),user=env['DB_USER'],password=env['DB_PASSWORD'],database=env['DB_NAME'])
  with db.cursor() as c:c.execute('SELECT archivo,sha256 FROM bi_reporte_historial ORDER BY id DESC LIMIT 30');artifacts=c.fetchall()
  db.close()
 objects=cli('storage','ls','gs://viora-514300-production-documents/reportes/**')
 assert artifacts and all('gs://viora-514300-production-documents/'+path in objects for path,_ in artifacts)
 path,sha=artifacts[0];pdf=subprocess.check_output(['gcloud.cmd','storage','cat','gs://viora-514300-production-documents/'+path,'--project='+PROJECT],stderr=subprocess.PIPE)
 assert hashlib.sha256(pdf).hexdigest()==sha
 assert requests.get('https://storage.googleapis.com/viora-514300-production-documents/'+path,timeout=30).status_code in [401,403],'Storage document is publicly accessible'
 r['private_storage_verified']=True;save(r);print('Verified 30 private Storage replicas and latest PDF integrity.')
@contextlib.contextmanager
def cloud():
 env=os.environ.copy();env['CSQL_PROXY_TOKEN']=cli('auth','print-access-token');password=cli('secrets','versions','access','latest','--secret=viora-production-db-password')
 path=ROOT/'.cache/reportes';path.mkdir(parents=True,exist_ok=True)
 with (path/'release-proxy.log').open('w') as log:
  proc=subprocess.Popen([str(pathlib.Path.home()/'.cache/viora-tools/cloud-sql-proxy.exe'),'--address=127.0.0.1','--port=13309',PROJECT+':'+REGION+':viora-staging-db'],env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
  try:
   for _ in range(80):
    try:
     with socket.create_connection(('127.0.0.1',13309),timeout=1):break
    except OSError:time.sleep(.25)
   env.update(DB_HOST='127.0.0.1',DB_PORT='13309',DB_USER='viora_prod',DB_PASSWORD=password,DB_NAME='viora_production',DB_SOCKET='',REPORT_PRIVATE_DIR=str(path/'private'))
   yield env
  finally:proc.terminate();proc.wait(timeout=15)
def migrate():
 backup=json.loads(cli('sql','backups','list','--instance=viora-staging-db','--format=json','--limit=3'))
 verified=next((b for b in backup if b.get('status')=='SUCCESSFUL' and 'Antes de modulo BI' in b.get('description','')),None)
 if not verified:raise RuntimeError('A successful pre-BI Cloud SQL backup is required.')
 with cloud() as env:
  subprocess.run(['C:/xampp/php/php.exe',str(ROOT/'deployment/migrate-reportes.php')],env=env,check=True)
  subprocess.run(['C:/xampp/php/php.exe',str(ROOT/'deployment/migrate-reportes.php')],env=env,check=True)
  result=subprocess.run(['C:/xampp/php/php.exe',str(ROOT/'deployment/test-reportes.php')],env=env,text=True,capture_output=True)
  (ROOT/'.cache/reportes/cloud-service-tests.log').write_text(result.stdout+result.stderr,encoding='utf-8');result.check_returncode()
  print('Cloud SQL migration repeatability and all 90 report/PDF source combinations passed.')
 r=json.loads(RECEIPT.read_text()) if RECEIPT.exists() else {};r['backup_id']=verified['id'];r['migration_verified']=True;save(r)
def build():
 service=json.loads(cli('run','services','describe','viora','--region='+REGION,'--format=json'))
 previous=next(t['revisionName'] for t in service['status']['traffic'] if t.get('percent')==100)
 revision=json.loads(cli('run','revisions','describe',previous,'--region='+REGION,'--format=json'))
 base=revision['status'].get('imageDigest') or revision['spec']['containers'][0]['image']
 if '@sha256:' not in base:raise RuntimeError('A pinned production image is required.')
 stamp='bi-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S');folder=ROOT/'.cache'/stamp;folder.mkdir();manifest=[]
 for name in FILES:
  src=ROOT/'Viora'/name;dst=folder/'overlay'/name;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst);manifest.append({'path':name,'sha256':hashlib.sha256(src.read_bytes()).hexdigest()})
 (folder/'Dockerfile').write_text('FROM '+base+'\nCOPY overlay/ /var/www/html/\nRUN '+' && '.join('php -l /var/www/html/'+f for f in FILES if f.endswith('.php'))+' && apache2ctl -t\n',encoding='utf-8')
 image='us-central1-docker.pkg.dev/'+PROJECT+'/viora/web:'+stamp
 protected=['/etc/viora/runtime.php','/etc/viora/mysql-session.php','/var/www/html/login/paypal_service.php','/var/www/html/login/paypal_events.php','/var/www/html/login/paypal_reconciliation.php','/var/www/html/mantenimientos/facturacion_json.php','/var/www/html/mantenimientos/factura_render.php','/var/www/html/mantenimientos/factura_documento.php','/var/www/html/conf/ecf.php']
 guard='set -eu\ndocker run --rm --entrypoint sha256sum '+base+' '+' '.join(protected)+' > /tmp/base.sha\ndocker run --rm --entrypoint sha256sum '+image+' '+' '.join(protected)+' > /tmp/new.sha\ndiff /tmp/base.sha /tmp/new.sha\n'
 config={'steps':[{'name':'gcr.io/cloud-builders/docker','args':['build','-t',image,'.']},{'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','args':['-c',guard]},{'name':'gcr.io/cloud-builders/docker','args':['push',image]}],'images':[image],'timeout':'1200s','options':{'logging':'CLOUD_LOGGING_ONLY'},'serviceAccount':'projects/'+PROJECT+'/serviceAccounts/viora-build@'+PROJECT+'.iam.gserviceaccount.com'}
 (folder/'cloudbuild.json').write_text(json.dumps(config),encoding='utf-8')
 build_id=cli('builds','submit',str(folder),'--config='+str(folder/'cloudbuild.json'),'--region='+REGION,'--gcs-source-staging-dir=gs://viora-514300-build-source/reportes','--async','--format=value(id)')
 r=json.loads(RECEIPT.read_text()) if RECEIPT.exists() else {};r.update(build_id=build_id,image=image,previous_revision=previous,manifest=manifest,base_image=base,candidate_verified=False,public_verified=False,promoted=False,private_storage_verified=False);save(r);print('Cloud Build: '+build_id)
def stage():
 r=json.loads(RECEIPT.read_text());status=cli('builds','describe',r['build_id'],'--region='+REGION,'--format=value(status)')
 if status!='SUCCESS':raise RuntimeError('Build is not successful: '+status)
 for entry in r['manifest']:
  if hashlib.sha256((ROOT/'Viora'/entry['path']).read_bytes()).hexdigest()!=entry['sha256']:raise RuntimeError('Source changed after build: '+entry['path'])
 cli('run','deploy','viora','--region='+REGION,'--image='+r['image'],'--no-traffic','--tag=bi-review','--quiet')
 s=json.loads(cli('run','services','describe','viora','--region='+REGION,'--format=json'));r['candidate_revision']=s['status']['latestReadyRevisionName'];r['candidate_url']=next(t['url'] for t in s['status']['traffic'] if t.get('tag')=='bi-review');save(r);print('Candidate: '+r['candidate_url'])
def test(public=False):
 r=json.loads(RECEIPT.read_text());url=r.get('public_url','https://viora-6ikx2ezlba-uc.a.run.app') if public else r['candidate_url']
 with cloud() as env:
  subprocess.run(['python',str(ROOT/'deployment/test-reportes-browser.py'),'--url='+url,'--gemini'],env=env,check=True)
  db=pymysql.connect(host=env['DB_HOST'],port=int(env['DB_PORT']),user=env['DB_USER'],password=env['DB_PASSWORD'],database=env['DB_NAME'])
  with db.cursor() as c:c.execute('SELECT archivo,sha256 FROM bi_reporte_historial ORDER BY id DESC LIMIT 30');artifacts=c.fetchall()
  db.close()
 objects=cli('storage','ls','gs://viora-514300-production-documents/reportes/**')
 assert artifacts and all('gs://viora-514300-production-documents/'+path in objects for path,_ in artifacts),'Private Storage replicas are missing'
 path,sha=artifacts[0];pdf=subprocess.check_output(['gcloud.cmd','storage','cat','gs://viora-514300-production-documents/'+path,'--project='+PROJECT],stderr=subprocess.PIPE)
 assert hashlib.sha256(pdf).hexdigest()==sha,'Storage replica integrity mismatch'
 assert requests.get('https://storage.googleapis.com/viora-514300-production-documents/'+path,timeout=30).status_code in [401,403],'Storage document is publicly accessible'
 r['private_storage_verified']=True
 print('Private GCS replicas exist; latest PDF hash matches its Cloud SQL master.')
 r['public_verified' if public else 'candidate_verified']=True;save(r)
 if not public:shutil.copyfile(ROOT/'deployment/reportes-browser-results.json',ROOT/'deployment/reportes-candidate-results.json')
def promote():
 r=json.loads(RECEIPT.read_text());assert r.get('candidate_verified') and r.get('migration_verified')
 cli('run','services','update-traffic','viora','--region='+REGION,'--to-revisions='+r['candidate_revision']+'=100','--quiet')
 s=json.loads(cli('run','services','describe','viora','--region='+REGION,'--format=json'));r['public_url']=s['status']['url'];r['promoted']=True;save(r);print('Published '+r['candidate_revision']+' at '+r['public_url'])
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('phase',choices=['migrate','build','stage','test','storage','promote','public-test']);a=parser.parse_args()
 {'migrate':migrate,'build':build,'stage':stage,'test':test,'storage':storage,'promote':promote,'public-test':lambda:test(True)}[a.phase]()
