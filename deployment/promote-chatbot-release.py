"""Promote only the tested candidate; verify publicly and restore old traffic on failure."""
import json
import pathlib
import subprocess
import sys
import requests

ROOT=pathlib.Path(__file__).resolve().parents[1]
def cli(*args):
    return subprocess.check_output(['gcloud.cmd',*args,'--project=viora-514300'],text=True,stderr=subprocess.PIPE).strip()

def main():
    path=ROOT/'deployment/chatbot-release.json';release=json.loads(path.read_text())
    tests=json.loads((ROOT/'deployment/chatbot-live-results.json').read_text())
    required=['Administrador both Gemini messages persisted in Cloud SQL','Cliente both Gemini messages persisted in Cloud SQL',
              'Cliente cannot request other user conversation by ID','Unconfirmed visit creates no business record']
    passed={t['test'] for t in tests['checks'] if t['passed']}
    if tests['revision']!=release['candidate_revision'] or not tests['real_gemini'] or any(not t['passed'] for t in tests['checks']) or any(t not in passed for t in required):
        raise RuntimeError('Faltan pruebas reales aprobadas para esta revision. No se cambio trafico.')
    service=json.loads(cli('run','services','describe','viora','--region=us-central1','--format=json'))
    tag=next(t for t in service['status']['traffic'] if t.get('tag')=='chatbot-review')
    if tag['revisionName']!=release['candidate_revision']:raise RuntimeError('La revision etiquetada cambio; no promover.')
    if requests.get(service['status']['url']+'/login/login.php',timeout=30).status_code!=200:
        raise RuntimeError('Login actual no saludable; no promover.')
    build=json.loads(cli('builds','describe',release['build_id'],'--region=us-central1','--format=json'))
    if build['status']!='SUCCESS':raise RuntimeError('Compilacion no valida.')
    release.update(built=True,build_status='SUCCESS',image_digest=build['results']['images'][0]['digest'])
    cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+release['candidate_revision']+'=100','--quiet')
    try:
        result=subprocess.run([sys.executable,str(ROOT/'deployment/test-chatbot-live.py'),'--public'],cwd=ROOT)
        if result.returncode:raise RuntimeError('Fallo una prueba publica del chatbot.')
    except BaseException:
        cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+release['rollback_revision']+'=100','--quiet')
        release.update(deployed=False,rolled_back=True)
        path.write_text(json.dumps(release,indent=2),encoding='utf-8')
        raise
    release.update(deployed=True,public_verified=True,rolled_back=False)
    path.write_text(json.dumps(release,indent=2),encoding='utf-8')
    print('PUBLIC VERIFIED: '+release['candidate_revision']+' serves 100% of traffic.')

if __name__=='__main__':main()
