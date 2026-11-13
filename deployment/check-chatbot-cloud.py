"""Read-only Cloud Run, IAM and chatbot schema inspection. Never prints credentials."""
import json
import os
import pathlib
import socket
import subprocess
import time
import pymysql
import requests

ROOT = pathlib.Path(__file__).resolve().parents[1]
PROJECT = 'viora-514300'
def cli(*args):
    return subprocess.check_output(['gcloud.cmd',*args,'--project='+PROJECT],text=True,stderr=subprocess.PIPE).strip()

def main():
    service=json.loads(cli('run','services','describe','viora','--region=us-central1','--format=json'))
    spec=service['spec']['template']['spec']
    variables={e['name']:e for e in spec['containers'][0].get('env',[])}
    secret=variables['GEMINI_API_KEY']['valueFrom']['secretKeyRef']
    key=cli('secrets','versions','access',secret['key'],'--secret='+secret['name'])
    iam=json.loads(cli('secrets','get-iam-policy',secret['name'],'--format=json'))
    accessor=any(b['role']=='roles/secretmanager.secretAccessor' and
        'serviceAccount:'+spec['serviceAccountName'] in b.get('members',[]) for b in iam.get('bindings',[]))
    result={'url':service['status']['url'],'revision':service['status']['latestReadyRevisionName'],
            'gemini_key_configured':bool(key and key!='UNCONFIGURED'),'secret_accessor_granted':accessor,
            'database':variables['DB_NAME']['value'],'cloud_sql_read_only':True}
    result['public_login_http']=requests.get(result['url']+'/login/login.php',timeout=30).status_code
    password=cli('secrets','versions','access','latest','--secret=viora-production-db-password')
    env=os.environ.copy();env['CSQL_PROXY_TOKEN']=cli('auth','print-access-token')
    proxy=pathlib.Path.home()/'.cache/viora-tools/cloud-sql-proxy.exe'
    (ROOT/'.cache').mkdir(exist_ok=True)
    with (ROOT/'.cache/chatbot-cloudsql.log').open('w') as log:
        proc=subprocess.Popen([str(proxy),'--address=127.0.0.1','--port=13309',PROJECT+':us-central1:viora-staging-db'],
                              env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
        try:
            for _ in range(40):
                try:
                    with socket.create_connection(('127.0.0.1',13309),timeout=1):break
                except OSError:time.sleep(.2)
            db=pymysql.connect(host='127.0.0.1',port=13309,user='viora_prod',password=password,
                               database=result['database'],connect_timeout=15,read_timeout=15)
            try:
                with db.cursor() as c:
                    c.execute('SET TRANSACTION READ ONLY');c.execute('START TRANSACTION')
                    c.execute('SELECT VERSION()');result['mysql_version']=c.fetchone()[0]
                    result['tables']={}
                    for table in ['conversacion_ia','mensaje_ia']:
                        c.execute('SHOW COLUMNS FROM '+table)
                        columns=[{'name':r[0],'type':r[1],'null':r[2],'key':r[3]} for r in c.fetchall()]
                        c.execute('SELECT COUNT(*) FROM '+table)
                        result['tables'][table]={'columns':columns,'row_count':c.fetchone()[0]}
                    c.execute("SELECT COUNT(*) FROM mensaje_ia m LEFT JOIN conversacion_ia c ON c.id_conversaciones=m.conversacion_id WHERE c.id_conversaciones IS NULL")
                    result['orphan_messages']=c.fetchone()[0]
                    db.rollback()
            finally:db.close()
        finally:proc.terminate();proc.wait(timeout=15)
    (ROOT/'deployment/chatbot-cloud-check.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
    print(json.dumps({k:v for k,v in result.items() if k!='tables'},indent=2))
    print('Chatbot table metadata checked; no records changed.')

if __name__=='__main__':main()
