"""Copy VM data to private staging resources; never changes the source VM."""
import subprocess,json,secrets,urllib.request,tarfile,io,tempfile,pathlib,re,hashlib
G='gcloud.cmd'; PROJECT='viora-514300'
def cli(*args,input=None):
 r=subprocess.run([G,*args,'--project='+PROJECT,'--quiet'],input=input,capture_output=True)
 if r.returncode: raise RuntimeError(r.stderr.decode(errors='replace'))
 return r.stdout
def ssh(command):
 return cli('compute','ssh','servidor-viora','--zone=us-central1-a','--command='+command)
def api(path,payload):
 token=cli('auth','print-access-token').decode().strip()
 req=urllib.request.Request('https://sqladmin.googleapis.com/sql/v1beta4/projects/'+PROJECT+'/'+path,data=json.dumps(payload).encode(),headers={'Authorization':'Bearer '+token,'Content-Type':'application/json'},method='POST')
 with urllib.request.urlopen(req) as r:return json.load(r)
def main():
 state=cli('sql','instances','describe','viora-staging-db','--format=value(state)').decode().strip()
 if state!='RUNNABLE': raise SystemExit('Cloud SQL not ready: '+state)
 existing=cli('sql','databases','list','--instance=viora-staging-db','--format=value(name)').decode().splitlines()
 if 'viora_production' in existing: raise SystemExit('Target already exists; refusing to overwrite data or rotate existing secrets')
 env=json.loads(ssh('sudo -n cat /etc/viora/environment.json'))
 db_password=secrets.token_urlsafe(40)
 values={'db-password':db_password,'paypal-client-id':env.get('PAYPAL_CLIENT_ID',''),'paypal-client-secret':env.get('PAYPAL_CLIENT_SECRET',''),'paypal-plan-id':env.get('PAYPAL_PLAN_ID',''),'gemini-api-key':env.get('GEMINI_API_KEY','')}
 missing=[]
 for suffix,value in values.items():
  name='viora-production-'+suffix
  cli('secrets','create',name,'--replication-policy=automatic')
  cli('secrets','versions','add',name,'--data-file=-',input=value.encode() if value else b'UNCONFIGURED')
  cli('secrets','add-iam-policy-binding',name,'--member=serviceAccount:viora-production@'+PROJECT+'.iam.gserviceaccount.com','--role=roles/secretmanager.secretAccessor')
  if not value:missing.append(suffix)
 print('Secrets stored without displaying values. Missing:',','.join(missing) or 'none',flush=True)
 cli('sql','databases','create','viora_production','--instance=viora-staging-db','--charset=utf8mb4','--collation=utf8mb4_unicode_ci')
 op=api('instances/viora-staging-db/users',{'name':'viora_prod','host':'%','password':db_password})
 cli('sql','operations','wait',op['name'],'--timeout=300')
 dump=ssh('sudo -n mysqldump --single-transaction --routines --triggers --hex-blob --set-gtid-purged=OFF --no-tablespaces viora')
 dump=re.sub(rb'DEFINER=`[^`]+`@`[^`]+`',b'',dump)
 pathlib.Path('deployment/cloudrun-production-migration.json').write_text(json.dumps({'source_instance':'servidor-viora','source_database':'viora','target_instance':'viora-staging-db','target_database':'viora_production','snapshot_sha256':hashlib.sha256(dump).hexdigest(),'source_modified':False},indent=2))
 dump+=b'\n'+pathlib.Path('deployment/staging-sessions.sql').read_bytes()
 cli('storage','cp','-','gs://'+PROJECT+'-build-source/migration/viora-production.sql',input=dump)
 service=json.loads(cli('sql','instances','describe','viora-staging-db','--format=json'))['serviceAccountEmailAddress']
 cli('storage','buckets','add-iam-policy-binding','gs://'+PROJECT+'-build-source','--member=serviceAccount:'+service,'--role=roles/storage.objectViewer')
 cli('sql','import','sql','viora-staging-db','gs://'+PROJECT+'-build-source/migration/viora-production.sql','--database=viora_production')
 cli('storage','rm','gs://'+PROJECT+'-build-source/migration/viora-production.sql')
 cli('storage','buckets','remove-iam-policy-binding','gs://'+PROJECT+'-build-source','--member=serviceAccount:'+service,'--role=roles/storage.objectViewer')
 print('Consistent SQL snapshot imported into viora_production; temporary export removed.',flush=True)
 for source,bucket in [('Viora/uploads','production-uploads'),('viora_documentos_privados','production-documents')]:
  archive=ssh('sudo -n tar -czf - -C /var/www/vioraa-5/'+source+' .')
  with tempfile.TemporaryDirectory(prefix='viora-migration-') as temp:
   with tarfile.open(fileobj=io.BytesIO(archive),mode='r:gz') as tar: tar.extractall(temp,filter='data')
   cli('storage','rsync',temp,'gs://'+PROJECT+'-'+bucket,'--recursive')
 print('Uploads and private documents copied; source unchanged.',flush=True)
if __name__=='__main__':main()
