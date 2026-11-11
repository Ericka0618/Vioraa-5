"""Read-only HTTPS checks; token remains in memory and is never printed."""
import argparse, json, os, urllib.request, urllib.error
p=argparse.ArgumentParser(); p.add_argument('url'); p.add_argument('--token-env'); a=p.parse_args()
if not a.url.startswith('https://'): raise SystemExit('HTTPS required')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
opener=urllib.request.build_opener(NoRedirect)
headers={'Authorization':'Bearer '+os.environ[a.token_env]} if a.token_env else {}
checks=[('/healthz.php',200),('/login/login.php',200),('/login/registro.php',200),('/',302),('/conf/conexion.php',403),('/conf/paypal_config.php',403),('/conf/factura_ecf.sql',403),('/uploads/',403),('/mantenimientos/contratos.php',302)]
results=[]
for path, expected in checks:
    try:
        with opener.open(urllib.request.Request(a.url.rstrip('/')+path,headers=headers),timeout=40) as r: status=r.status; r.read()
    except urllib.error.HTTPError as e: status=e.code
    results.append({'path':path,'expected':expected,'actual':status,'pass':status==expected})
print(json.dumps(results,indent=2))
raise SystemExit(0 if all(r['pass'] for r in results) else 1)
