"""Stage the explicitly authorized PayPal build; leaves public traffic unchanged."""
import hashlib
import json
import pathlib
import requests
from paypal_cloud import ROOT,cli,service
from importlib.util import spec_from_file_location,module_from_spec

def main():
    path=ROOT/'deployment/paypal-release.json';r=json.loads(path.read_text())
    if not r.get('deployment_authorized') or not r.get('build_authorized'):raise RuntimeError('Deployment requires user authorization.')
    spec=spec_from_file_location('prepare',ROOT/'deployment/prepare-paypal-release.py');m=module_from_spec(spec);spec.loader.exec_module(m)
    s=service()
    if hashlib.sha256(json.dumps(m.protected(s),sort_keys=True).encode()).hexdigest()!=r['protected_config_sha256']:raise RuntimeError('Protected configuration changed; do not deploy.')
    if s['status']['latestReadyRevisionName']!=r['prepared_latest_ready_revision']:raise RuntimeError('Revision changed; review the package.')
    for f in r['files']:
        if hashlib.sha256((ROOT/'Viora'/f['path']).read_bytes()).hexdigest()!=f['sha256']:raise RuntimeError('Source changed since preparation.')
    if hashlib.sha256((ROOT/'deployment/reconcile-paypal.php').read_bytes()).hexdigest()!=r['cli_sha256']:raise RuntimeError('CLI source changed.')
    b=json.loads(cli('builds','describe',r['build_id'],'--region=us-central1','--format=json'))
    if b['status']!='SUCCESS':raise RuntimeError('Build is not successful.')
    if requests.get(s['status']['url']+'/login/login.php',timeout=30).status_code!=200:raise RuntimeError('Public login not healthy.')
    digest=b['results']['images'][0]['digest'];image=r['image'].rsplit(':',1)[0]+'@'+digest
    cli('run','deploy','viora','--region=us-central1','--image='+image,'--no-traffic','--tag=paypal-review',
        '--update-env-vars=PAYPAL_WEBHOOK_ID='+r['webhook_id'],'--quiet')
    after=service()
    if m.protected(after)!=m.protected(s):raise RuntimeError('Protected configuration differs; leave candidate without traffic.')
    tag=next(t for t in after['status']['traffic'] if t.get('tag')=='paypal-review')
    for base in [s['status']['url'],tag['url']]:
        if requests.get(base+'/login/login.php',timeout=30).status_code!=200:raise RuntimeError('Login not healthy; leave candidate without traffic.')
    bad=requests.post(tag['url']+'/login/paypal_webhook.php',json={'id':'WH-QA','event_type':'PAYMENT.SALE.COMPLETED'},timeout=30)
    if bad.status_code!=400:raise RuntimeError('Unsigned webhook not rejected.')
    r.update(built=True,build_status='SUCCESS',image_digest=digest,staged=True,deployed=False,
        candidate_revision=tag['revisionName'],candidate_url=tag['url'],protected_configuration_preserved=True,unsigned_webhook_rejected=True)
    path.write_text(json.dumps(r,indent=2));print('CANDIDATE '+tag['revisionName']+' '+tag['url'])

if __name__=='__main__':main()
