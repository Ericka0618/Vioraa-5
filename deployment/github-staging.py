"""GitHub API helper using the existing Git credential manager, without printing tokens."""
import subprocess,urllib.request,json
REPO='Ericka0618/Vioraa-5'
def request(method,path,body=None):
 r=subprocess.run(['git','-c','credential.interactive=never','credential','fill'],input='protocol=https\nhost=github.com\n\n',text=True,capture_output=True,check=True)
 creds=dict(l.split('=',1) for l in r.stdout.splitlines() if '=' in l)
 req=urllib.request.Request('https://api.github.com/repos/'+REPO+path,method=method,data=json.dumps(body).encode() if body is not None else None,headers={'Authorization':'Bearer '+creds['password'],'Accept':'application/vnd.github+json','Content-Type':'application/json','X-GitHub-Api-Version':'2022-11-28'})
 with urllib.request.urlopen(req) as response:
  raw=response.read();return json.loads(raw) if raw else {}
def variable(name,value):
 try:request('PATCH','/actions/variables/'+name,{'name':name,'value':value})
 except urllib.error.HTTPError as e:
  if e.code!=404:raise
  request('POST','/actions/variables',{'name':name,'value':value})
if __name__=='__main__':
 request('PUT','/environments/staging',{})
 variable('WIF_PROVIDER','projects/1055595302579/locations/global/workloadIdentityPools/viora-github/providers/github')
 variable('STAGING_DEPLOY_ENABLED','false')
 print('GitHub staging environment and WIF variable configured; deploy remains disabled until validation.')
