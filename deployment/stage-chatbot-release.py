"""Stage a verified chatbot image with no public traffic. Never promotes traffic."""
import hashlib
import importlib.util
import json
import pathlib
import subprocess
import requests

ROOT=pathlib.Path(__file__).resolve().parents[1]
PROJECT='viora-514300'
REGION='us-central1'
def cli(*args):
    return subprocess.check_output(['gcloud.cmd',*args,'--project='+PROJECT],text=True,stderr=subprocess.PIPE).strip()

def protected(service):
    spec=service['spec']['template']['spec']
    result={k:v for k,v in spec.items() if k!='containers'}
    result['containers']=[{k:([e for e in v if e['name'] not in ['GEMINI_API_KEY','GEMINI_MODEL']] if k=='env' else v)
                          for k,v in c.items() if k!='image'} for c in spec['containers']]
    return result

def main():
    receipt_path=ROOT/'deployment/chatbot-release.json'
    receipt=json.loads(receipt_path.read_text())
    validation_path=ROOT/'deployment/gemini-validation.json'
    if not validation_path.exists():raise RuntimeError('Primero ejecuta python deployment/set-gemini-secret.py en tu terminal.')
    validation=json.loads(validation_path.read_text())
    if not validation.get('real_text_response') or not validation.get('real_viora_function_call') or not validation.get('version'):
        raise RuntimeError('Falta validacion real y version del secreto. Ejecuta el instalador.')
    build=json.loads(cli('builds','describe',receipt['build_id'],'--region='+REGION,'--format=json'))
    if build['status']!='SUCCESS':raise RuntimeError('La compilacion no termino correctamente.')
    for file in receipt['files']:
        if hashlib.sha256((ROOT/'Viora'/file['path']).read_bytes()).hexdigest()!=file['sha256']:
            raise RuntimeError('El codigo cambio desde la compilacion; hay que reconstruir.')
    before=json.loads(cli('run','services','describe','viora','--region='+REGION,'--format=json'))
    snapshot=protected(before)
    if hashlib.sha256(json.dumps(snapshot,sort_keys=True).encode()).hexdigest()!=receipt['protected_config_sha256']:
        raise RuntimeError('La configuracion activa cambio; revisar antes de desplegar.')
    connection=before['spec']['template']['metadata'].get('annotations',{}).get('run.googleapis.com/cloudsql-instances')
    if before['status']['latestReadyRevisionName']!=receipt.get('prepared_latest_ready_revision',receipt['rollback_revision']):
        raise RuntimeError('La revision activa cambio; preparar imagen desde la nueva base.')
    if requests.get(before['status']['url']+'/login/login.php',timeout=30).status_code!=200:
        raise RuntimeError('La URL actual no responde correctamente; no se desplego.')
    # Repeat real provider validation using the exact secret version. No key appears in output.
    key=cli('secrets','versions','access',str(validation['version']),'--secret='+validation['secret'])
    module_spec=importlib.util.spec_from_file_location('gemini_setup',ROOT/'deployment/set-gemini-secret.py')
    module=importlib.util.module_from_spec(module_spec);module_spec.loader.exec_module(module)
    model=module.validate(key,validation['model'])
    digest=build['results']['images'][0]['digest']
    image=receipt['image'].rsplit(':',1)[0]+'@'+digest
    cli('run','deploy','viora','--region='+REGION,'--image='+image,'--no-traffic','--tag=chatbot-review',
        '--update-secrets=GEMINI_API_KEY='+validation['secret']+':'+str(validation['version']),
        '--update-env-vars=GEMINI_MODEL='+model,'--quiet')
    after=json.loads(cli('run','services','describe','viora','--region='+REGION,'--format=json'))
    if protected(after)!=snapshot or after['spec']['template']['metadata'].get('annotations',{}).get('run.googleapis.com/cloudsql-instances')!=connection:
        raise RuntimeError('La revision se creo sin trafico pero cambio configuracion protegida; no promover.')
    tag=next(t for t in after['status']['traffic'] if t.get('tag')=='chatbot-review')
    receipt.update(built=True,build_status='SUCCESS',image_digest=digest,staged=True,deployed=False,candidate_revision=tag['revisionName'],candidate_url=tag['url'],
                   gemini_model=model,gemini_secret_version=validation['version'])
    receipt_path.write_text(json.dumps(receipt,indent=2),encoding='utf-8')
    for base in [before['status']['url'],tag['url']]:
        if requests.get(base+'/login/login.php',timeout=30).status_code!=200:
            raise RuntimeError('Login no saludable. Revision sin trafico; no promover.')
    print('Revision candidata sin trafico: '+tag['revisionName'])
    print('URL de pruebas: '+tag['url'])
    print('PayPal, fiscal, Cloud SQL y configuracion restante conservados. Falta prueba autenticada y promocion.')

if __name__=='__main__':
    try:main()
    except (RuntimeError,subprocess.SubprocessError) as e:raise SystemExit(str(e)) from None
