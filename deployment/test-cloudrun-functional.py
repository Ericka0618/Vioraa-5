"""Run the existing business-flow tests against isolated Cloud Run staging only."""
import pathlib,subprocess,os,tempfile,time,socket,json,atexit
import pymysql,requests,hashlib,base64
ROOT=pathlib.Path(__file__).resolve().parents[1]
PROJECT='viora-514300'
def cli(*args):
 r=subprocess.run(['gcloud.cmd',*args,'--project='+PROJECT,'--quiet'],capture_output=True,check=True)
 return r.stdout.decode().strip()
def run():
 url=cli('run','services','describe','viora-staging','--region=us-central1','--format=value(status.url)')
 assert url.startswith('https://viora-staging-')
 password=cli('secrets','versions','access','latest','--secret=viora-staging-db-password')
 env=os.environ.copy();env['CSQL_PROXY_TOKEN']=cli('auth','print-access-token')
 proxy_path=pathlib.Path.home()/'.cache/viora-tools/cloud-sql-proxy.exe'
 with tempfile.TemporaryDirectory(prefix='viora-staging-qa-') as folder:
  base=pathlib.Path(folder)
  with (base/'proxy.log').open('w') as log:
   proxy=subprocess.Popen([str(proxy_path),'--address=127.0.0.1','--port=13307',PROJECT+':us-central1:viora-staging-db'],env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
   try:
    for _ in range(45):
     try:
      with socket.create_connection(('127.0.0.1',13307),timeout=1):break
     except OSError:time.sleep(1)
    db=pymysql.connect(host='127.0.0.1',port=13307,user='viora_app',password=password,database='viora_staging',charset='utf8mb4',autocommit=True,client_flag=pymysql.constants.CLIENT.MULTI_STATEMENTS)
    def sql(query):
     with db.cursor() as c:
      c.execute('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;'+query)
      rows=[]
      while True:
       if c.description:rows=c.fetchall()
       if not c.nextset():break
     return '\n'.join('\t'.join(str(v) if v is not None else 'NULL' for v in row) for row in rows).strip()
    # Compare every original table using sorted SHA-256 row fingerprints; no row values leave the processes.
    remote = """import subprocess,json,hashlib

def query(sql):
 return subprocess.check_output(['mysql','-N','-B','viora','-e',"SET time_zone='+00:00';"+sql],text=True).strip().splitlines()
result={}
for table in query('SHOW TABLES'):
 cols=query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='viora' AND table_name='"+table+"' ORDER BY ORDINAL_POSITION")
 expr=','.join('`'+c+'`' for c in cols)
 rows=query('SELECT SHA2(CAST(JSON_ARRAY('+expr+') AS CHAR),256) AS h FROM `'+table+'` ORDER BY h')
 result[table]={'rows':len(rows),'sha256':hashlib.sha256('|'.join(rows).encode()).hexdigest()}
print(json.dumps(result))
"""
    encoded=base64.b64encode(remote.encode()).decode()
    source=json.loads(cli('compute','ssh','servidor-viora','--zone=us-central1-a','--command=sudo -n python3 -c "import base64;exec(base64.b64decode(\''+encoded+'\'))"'))
    comparison=[]
    for table, expected in source.items():
     columns=sql("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='viora_staging' AND table_name='"+table+"' ORDER BY ORDINAL_POSITION").splitlines()
     expr=','.join('`'+c+'`' for c in columns)
     sql("SET time_zone='+00:00'")
     rows=sql('SELECT SHA2(CAST(JSON_ARRAY('+expr+') AS CHAR),256) AS h FROM `'+table+'` ORDER BY h').splitlines()
     actual={'rows':len(rows),'sha256':hashlib.sha256('|'.join(rows).encode()).hexdigest()}
     comparison.append({'table':table,'rows':len(rows),'passed':actual==expected})
    (ROOT/'deployment/cloudrun-data-integrity.json').write_text(json.dumps(comparison,indent=2))
    if not all(x['passed'] for x in comparison):raise RuntimeError('Source and staging differ; inspect integrity report before business tests')
    print('Data integrity verified:',len(comparison),'tables,',sum(x['rows'] for x in comparison),'rows',flush=True)
    token=cli('auth','print-identity-token')
    original=requests.Session
    class AuthenticatedSession(original):
     def __init__(self):super().__init__();self.headers['Authorization']='Bearer '+token
    requests.Session=AuthenticatedSession
    common={'__name__':'viora_cloudrun_qa','sql':sql,'q':sql}
    source=(ROOT/'deployment/functional-qa.py').read_text()
    source=source.replace("BASE=pathlib.Path('/var/lib/viora-qa-20261110')",'BASE=pathlib.Path('+repr(str(base))+')')
    start=source.index('original_dns=');end=source.index('results=[]',start)
    source=source[:start]+'URL='+repr(url)+'\n'+source[end:]
    start=source.index('def sql(');end=source.index('def hx(',start)
    source=source[:start]+source[end:]
    source=source.replace("['php',", "[r'C:\\xampp\\php\\php.exe',")
    source=source.replace('/var/www/vioraa-5/Viora/mantenimientos/pdf_simple.php',(ROOT/'Viora/mantenimientos/pdf_simple.php').as_posix())
    exec(compile(source,'cloudrun-functional-qa','exec'),common)
    functional=common['results']
    extra=(ROOT/'deployment/extra-functional-qa.py').read_text()
    extra=extra.replace("B=pathlib.Path('/var/lib/viora-qa-20261110')",'B=pathlib.Path('+repr(str(base))+')')
    start=extra.index('def q(');end=extra.index('def check(',start);extra=extra[:start]+extra[end:]
    start=extra.index('old=socket.getaddrinfo');end=extra.index('class Form',start);extra=extra[:start]+'url='+repr(url)+'\n'+extra[end:]
    scope={'__name__':'viora_cloudrun_extra_qa','q':sql}
    exec(compile(extra,'cloudrun-extra-qa','exec'),scope)
    # Force a new revision and reuse the same browser cookies, uploaded image and private document.
    import uuid
    cli('run','services','update','viora-staging','--region=us-central1','--update-env-vars=QA_REVISION='+uuid.uuid4().hex)
    scope['check']('Session survives new Cloud Run revision',scope['get'](scope['a'],'/login/principal.php').status_code==200)
    response=scope['get'](scope['a'],'/mantenimientos/ver_contrato.php?id='+scope['ctid'])
    scope['check']('Private PDF survives new revision',response.status_code==200 and response.content.startswith(b'%PDF-'))
    scope['check']('Uploaded image survives new revision',scope['get'](scope['a'],'/uploads/apartamentos/'+common['image']).status_code==200)
    combined={'service':url,'functional':functional,'extra':scope['results']}
    (ROOT/'deployment/cloudrun-functional-results.json').write_text(json.dumps(combined,indent=2),encoding='utf-8')
    # Persist no test passwords or real user records in the repository.
    print('RESULTS saved; fixtures remain in isolated staging database only.')
    # Original scripts register result writers; run them before the temporary directory is removed.
    atexit._run_exitfuncs()
    db.close()
   finally:proxy.terminate();proxy.wait(timeout=15)
if __name__=='__main__':run()
