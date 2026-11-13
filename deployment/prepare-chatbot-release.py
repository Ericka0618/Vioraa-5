"""Prepare an allowlisted overlay on the live immutable image; never deploys."""
import datetime
import hashlib
import json
import pathlib
import re
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
PROJECT = 'viora-514300'
REGION = 'us-central1'
FILES = [
    'mantenimientos/chatbot_api.php',
    'mantenimientos/chatbot_historial.php',
    'mantenimientos/chatbot_herramientas.php',
    'mantenimientos/chatbot.js',
    'mantenimientos/chatbot_widget.php',
    'conf/gemini.php',
    'conf/subscription_access.php',
]

def cli(*args):
    return subprocess.check_output(['gcloud.cmd', *args, '--project='+PROJECT], text=True)

def main():
    service = json.loads(cli('run','services','describe','viora','--region='+REGION,'--format=json'))
    spec = service['spec']['template']['spec']
    active = max(service['status']['traffic'], key=lambda t:t.get('percent',0))['revisionName']
    revision = json.loads(cli('run','revisions','describe',active,'--region='+REGION,'--format=json'))
    image = revision['spec']['containers'][0]['image']
    # Bind to the actual image, never rebuild unrelated local modifications.
    if '@sha256:' in image:
        base = image
    else:
        digest = cli('artifacts','docker','images','describe',image,'--format=value(image_summary.digest)').strip()
        if not re.fullmatch(r'sha256:[a-f0-9]{64}', digest): raise RuntimeError('Invalid base image digest')
        base = image.rsplit(':',1)[0] + '@' + digest
    tag = 'chatbot-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S')
    folder = ROOT / '.cache' / tag
    folder.mkdir(parents=True, exist_ok=False)
    manifest = []
    for name in FILES:
        source = ROOT / 'Viora' / name
        target = folder / 'overlay' / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
        manifest.append({'path':name,'sha256':hashlib.sha256(source.read_bytes()).hexdigest()})
    protected = {k:v for k,v in spec.items() if k!='containers'}
    protected['containers'] = [{k:([e for e in v if e['name'] not in ['GEMINI_API_KEY','GEMINI_MODEL']] if k=='env' else v)
                                 for k,v in c.items() if k!='image'} for c in spec['containers']]
    receipt = {'service':'viora','region':REGION,'project':PROJECT,'base_image':base,
               'rollback_revision':active,
               'prepared_latest_ready_revision':service['status']['latestReadyRevisionName'],
               'protected_config_sha256':hashlib.sha256(json.dumps(protected,sort_keys=True).encode()).hexdigest(),
               'image':f'{REGION}-docker.pkg.dev/{PROJECT}/viora/web:{tag}',
               'files':manifest,'built':False,'deployed':False}
    (folder/'manifest.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8')
    (folder/'Dockerfile').write_text('FROM '+base+'\nCOPY overlay/ /var/www/html/\n',encoding='utf-8')
    script = "set -eu\ndocker run --rm --entrypoint sh \"$IMAGE\" -c 'find /var/www/html -name \"*.php\" -exec php -l {} \\; > /tmp/lint.log; if grep -E \"(Parse error|Errors parsing)\" /tmp/lint.log; then exit 1; fi; apache2ctl -t'\ndocker run -d --name chatbot-check \"$IMAGE\"\ntrap 'docker rm -f chatbot-check >/dev/null' EXIT\nfor attempt in $(seq 1 30); do\n if docker exec chatbot-check curl -fsS http://localhost:8080/healthz.php; then exit 0; fi\n sleep 1\ndone\nexit 1\n"
    build = {'steps':[
        {'name':'gcr.io/cloud-builders/docker','args':['build','-t',receipt['image'],'.']},
        {'name':'gcr.io/cloud-builders/docker','entrypoint':'bash','env':['IMAGE='+receipt['image']],
         'args':['-c',script.replace('$','$$')]},
        {'name':'gcr.io/cloud-builders/docker','args':['push',receipt['image']]}],
        'images':[receipt['image']],
        'serviceAccount':f'projects/{PROJECT}/serviceAccounts/viora-build@{PROJECT}.iam.gserviceaccount.com',
        'options':{'logging':'CLOUD_LOGGING_ONLY'},'timeout':'1200s'}
    (folder/'cloudbuild.json').write_text(json.dumps(build,indent=2),encoding='utf-8')
    (ROOT/'deployment/chatbot-release.json').write_text(json.dumps(dict(receipt,context=str(folder.relative_to(ROOT))),indent=2),encoding='utf-8')
    print('Prepared '+str(folder.relative_to(ROOT)))
    print('Only '+str(len(FILES))+' chatbot files; base '+base)

if __name__ == '__main__': main()
