"""Create only the two cost-bearing resources explicitly approved by the user."""
import json
import subprocess
from paypal_cloud import ROOT,PROJECT,cli

def main():
    release=json.loads((ROOT/'deployment/paypal-release.json').read_text())
    if not release.get('scheduler_authorized'):raise RuntimeError('New resources require user cost approval.')
    image=release['image'].rsplit(':',1)[0]+'@'+release['image_digest']
    account='viora-production@'+PROJECT+'.iam.gserviceaccount.com'
    cli('services','enable','cloudscheduler.googleapis.com','--quiet')
    # Existing runtime account and existing secrets; no new key, bucket, VM or database instance.
    cli('run','jobs','deploy','viora-paypal-reconcile','--region=us-central1','--image='+image,
        '--service-account='+account,'--set-cloudsql-instances='+PROJECT+':us-central1:viora-staging-db',
        '--tasks=1','--max-retries=1','--task-timeout=300s','--memory=512Mi','--cpu=1','--command=php',
        '--args=-d,open_basedir=/var/www:/tmp:/etc/viora:/cloudsql:/opt/viora,/opt/viora/reconcile-paypal.php',
        '--set-env-vars=VIORA_APP_ROOT=/var/www/html,DB_HOST=localhost,DB_SOCKET=/cloudsql/'+PROJECT+':us-central1:viora-staging-db,DB_NAME=viora_production,DB_USER=viora_prod,DB_SESSION_ENABLED=false',
        '--set-secrets=DB_PASSWORD=viora-production-db-password:latest,PAYPAL_CLIENT_ID=viora-production-paypal-client-id:latest,PAYPAL_CLIENT_SECRET=viora-production-paypal-client-secret:latest,PAYPAL_PLAN_ID=viora-production-paypal-plan-id:latest','--quiet')
    cli('run','jobs','add-iam-policy-binding','viora-paypal-reconcile','--region=us-central1','--member=serviceAccount:'+account,'--role=roles/run.invoker','--quiet')
    uri='https://run.googleapis.com/v2/projects/'+PROJECT+'/locations/us-central1/jobs/viora-paypal-reconcile:run'
    common=['viora-paypal-reconcile-hourly','--location=us-central1','--schedule=0 * * * *','--time-zone=America/Santo_Domingo',
        '--uri='+uri,'--http-method=POST','--oauth-service-account-email='+account,'--oauth-token-scope=https://www.googleapis.com/auth/cloud-platform',
        '--headers=Content-Type=application/json','--message-body={}','--attempt-deadline=30s','--max-retry-attempts=2','--quiet']
    exists=subprocess.run(['gcloud.cmd','scheduler','jobs','describe','viora-paypal-reconcile-hourly','--location=us-central1','--project='+PROJECT],capture_output=True).returncode==0
    cli('scheduler','jobs','update' if exists else 'create','http',*common)
    result={'job':'viora-paypal-reconcile','scheduler':'viora-paypal-reconcile-hourly','schedule':'0 * * * *','timezone':'America/Santo_Domingo',
        'private':True,'http_endpoint_public':False,'user_cost_authorized':True,'configured':True,'first_execution_verified':False}
    (ROOT/'deployment/paypal-scheduler-result.json').write_text(json.dumps(result,indent=2));print(json.dumps(result,indent=2))

if __name__=='__main__':main()
