import subprocess,json
from pathlib import Path
G='gcloud.cmd'
def run(*args):
 r=subprocess.run([G,*args,'--project=viora-514300','--quiet'],capture_output=True,text=True)
 if r.returncode: raise RuntimeError(r.stderr)
 print('OK',args[0],args[1] if len(args)>1 else '')
project_roles={
'viora-staging':['roles/cloudsql.client'],
'viora-build':['roles/logging.logWriter','roles/run.developer','roles/serviceusage.serviceUsageConsumer'],
'viora-github':['roles/cloudbuild.builds.editor','roles/serviceusage.serviceUsageConsumer','roles/run.viewer']}
for sa,roles in project_roles.items():
 for role in roles:run('projects','add-iam-policy-binding','viora-514300','--member=serviceAccount:'+sa+'@viora-514300.iam.gserviceaccount.com','--role='+role)
run('artifacts','repositories','add-iam-policy-binding','viora','--location=us-central1','--member=serviceAccount:viora-build@viora-514300.iam.gserviceaccount.com','--role=roles/artifactregistry.writer')
for bucket in ['staging-uploads','staging-documents']:
 run('storage','buckets','add-iam-policy-binding','gs://viora-514300-'+bucket,'--member=serviceAccount:viora-staging@viora-514300.iam.gserviceaccount.com','--role=roles/storage.objectUser')
for sa in ['viora-build','viora-github']:
 run('storage','buckets','add-iam-policy-binding','gs://viora-514300-build-source','--member=serviceAccount:'+sa+'@viora-514300.iam.gserviceaccount.com','--role=roles/storage.objectAdmin')
for target,member in [('viora-staging','viora-build'),('viora-build','viora-github')]:
 run('iam','service-accounts','add-iam-policy-binding',target+'@viora-514300.iam.gserviceaccount.com','--member=serviceAccount:'+member+'@viora-514300.iam.gserviceaccount.com','--role=roles/iam.serviceAccountUser')
