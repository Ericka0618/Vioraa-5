"""Promote the explicitly authorized and tested PayPal candidate, preserving rollback."""
import json
import subprocess
import sys
from paypal_cloud import ROOT,cli,service

def main():
    path=ROOT/'deployment/paypal-release.json';r=json.loads(path.read_text())
    tests=json.loads((ROOT/'deployment/paypal-candidate-smoke.json').read_text())
    if not r['deployment_authorized'] or tests['revision']!=r['candidate_revision'] or len(tests['checks'])<12 or any(not t['passed'] for t in tests['checks']):raise RuntimeError('Candidate not authorized or not verified.')
    s=service();tag=next(t for t in s['status']['traffic'] if t.get('tag')=='paypal-review')
    if tag['revisionName']!=r['candidate_revision']:raise RuntimeError('Candidate changed.')
    cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+r['candidate_revision']+'=100','--quiet')
    result=subprocess.run([sys.executable,str(ROOT/'deployment/test-paypal-deployment.py'),'--public'],cwd=ROOT)
    if result.returncode:
        cli('run','services','update-traffic','viora','--region=us-central1','--to-revisions='+r['rollback_revision']+'=100','--quiet')
        raise RuntimeError('Public smoke failed; previous traffic restored.')
    r.update(deployed=True,public_smoke_passed=True);path.write_text(json.dumps(r,indent=2))
    print('PUBLIC PAYPAL REVISION '+r['candidate_revision'])

if __name__=='__main__':main()
