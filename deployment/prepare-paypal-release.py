"""Prepare a PayPal-only overlay. No build, deployment, traffic change or new cloud resource."""
import datetime
import hashlib
import json
import shutil
from paypal_cloud import ROOT,PROJECT,cli,service

FILES=['login/paypal_service.php','login/paypal_reconciliation.php','login/paypal_events.php','login/paypal_webhook.php',
       'login/verificar_suscripcion.php','login/suscripcion.php','login/guardar_registro.php','login/procesar_login.php','mantenimientos/catalogo.php']
def protected(s):
    spec=s['spec']['template']['spec'];result={k:v for k,v in spec.items() if k!='containers'}
    result['containers']=[{k:([e for e in v if e['name']!='PAYPAL_WEBHOOK_ID'] if k=='env' else v) for k,v in c.items() if k!='image'} for c in spec['containers']]
    result['cloud_sql']=s['spec']['template']['metadata'].get('annotations',{}).get('run.googleapis.com/cloudsql-instances')
    return result
def main():
    s=service();active=max(s['status']['traffic'],key=lambda t:t.get('percent',0))['revisionName']
    rev=json.loads(cli('run','revisions','describe',active,'--region=us-central1','--format=json'))
    image=rev['spec']['containers'][0]['image']
    if '@sha256:' not in image:
        digest=cli('artifacts','docker','images','describe',image,'--format=value(image_summary.digest)')
        image=image.rsplit(':',1)[0]+'@'+digest
    stamp='paypal-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S')
    folder=ROOT/'.cache'/stamp;folder.mkdir(parents=True,exist_ok=False)
    manifest=[]
    for name in FILES:
        src=ROOT/'Viora'/name;dst=folder/'overlay'/name;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
        manifest.append({'path':name,'sha256':hashlib.sha256(src.read_bytes()).hexdigest()})
    shutil.copyfile(ROOT/'deployment/reconcile-paypal.php',folder/'reconcile-paypal.php')
    (folder/'Dockerfile').write_text('FROM '+image+'\nCOPY overlay/ /var/www/html/\nCOPY reconcile-paypal.php /opt/viora/reconcile-paypal.php\n',encoding='utf-8')
    target='us-central1-docker.pkg.dev/'+PROJECT+'/viora/web:'+stamp
    script="set -eu\ndocker run --rm --entrypoint sh \"$IMAGE\" -c 'find /var/www/html /opt/viora -name \"*.php\" -exec php -l {} \\; > /tmp/lint.log; if grep -E \"(Parse error|Errors parsing)\" /tmp/lint.log; then exit 1; fi; apache2ctl -t'\ndocker run -d --name paypal-check \"$IMAGE\"\ntrap 'docker rm -f paypal-check >/dev/null' EXIT\nfor n in $(seq 1 30); do if docker exec paypal-check curl -fsS http://localhost:8080/healthz.php; then exit 0; fi; sleep 1; done\nexit 1\n"
    build={'steps':[{'name':'gcr.io/cloud-builders/docker','args':['build','-t',target,'.']},
        {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['IMAGE='+target],'args':['-c',script.replace('$','$$')]},
        {'name':'gcr.io/cloud-builders/docker','args':['push',target]}],'images':[target],
        'serviceAccount':'projects/'+PROJECT+'/serviceAccounts/viora-build@'+PROJECT+'.iam.gserviceaccount.com','options':{'logging':'CLOUD_LOGGING_ONLY'},'timeout':'1200s'}
    protected_files=['mantenimientos/chatbot_api.php','mantenimientos/chatbot_historial.php','mantenimientos/chatbot_herramientas.php',
        'mantenimientos/chatbot.js','mantenimientos/chatbot_widget.php','conf/gemini.php','conf/ecf.php',
        'mantenimientos/ecf_service.php','mantenimientos/pdf_simple.php']
    paths=' '.join('/var/www/html/'+p for p in protected_files)
    compare='set -eu\ndocker run --rm --entrypoint sha256sum "$$BASE" '+paths+' > /tmp/before.sha\ndocker run --rm --entrypoint sha256sum "$$IMAGE" '+paths+' > /tmp/after.sha\ndiff /tmp/before.sha /tmp/after.sha\n'
    build['steps'].insert(2,{'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['IMAGE='+target,'BASE='+image],'args':['-c',compare]})
    (folder/'cloudbuild.json').write_text(json.dumps(build,indent=2))
    gemini=json.loads((ROOT/'deployment/chatbot-release.json').read_text())
    for f in gemini['files']:
        if hashlib.sha256((ROOT/'Viora'/f['path']).read_bytes()).hexdigest()!=f['sha256']:raise RuntimeError('Gemini source changed; do not deploy.')
    receipt={'project':PROJECT,'service':'viora','region':'us-central1','base_image':image,'rollback_revision':active,
        'prepared_latest_ready_revision':s['status']['latestReadyRevisionName'],'image':target,'context':str(folder.relative_to(ROOT)),
        'files':manifest,'protected_config_sha256':hashlib.sha256(json.dumps(protected(s),sort_keys=True).encode()).hexdigest(),
        'webhook_id':json.loads((ROOT/'deployment/paypal-webhook-registration.json').read_text())['id'],
        'gemini_sources_unchanged':True,'build_authorized':False,'deployment_authorized':False,'built':False,'deployed':False,
        'cli_sha256':hashlib.sha256((folder/'reconcile-paypal.php').read_bytes()).hexdigest()}
    (ROOT/'deployment/paypal-release.json').write_text(json.dumps(receipt,indent=2))
    print('Prepared '+str(folder.relative_to(ROOT))+' on live revision '+active)
    print('Gemini preserved. No build or deployment executed.')

if __name__=='__main__':main()
