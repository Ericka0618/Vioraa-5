import subprocess,json
from pathlib import Path
G='gcloud.cmd'
def run(*args):
 r=subprocess.run([G,*args,'--project=viora-514300','--quiet'],capture_output=True,text=True)
 if r.returncode: raise RuntimeError(r.stderr)
 print('OK',args[0],args[1] if len(args)>1 else '')
run('projects','add-iam-policy-binding','viora-514300','--member=serviceAccount:viora-production@viora-514300.iam.gserviceaccount.com','--role=roles/cloudsql.client')
for bucket in ['production-uploads','production-documents']:
 run('storage','buckets','add-iam-policy-binding','gs://viora-514300-'+bucket,'--member=serviceAccount:viora-production@viora-514300.iam.gserviceaccount.com','--role=roles/storage.objectUser')
run('iam','service-accounts','add-iam-policy-binding','viora-production@viora-514300.iam.gserviceaccount.com','--member=serviceAccount:viora-build@viora-514300.iam.gserviceaccount.com','--role=roles/iam.serviceAccountUser')
