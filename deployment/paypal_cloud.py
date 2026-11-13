"""Shared private tooling for this PayPal intervention. No credentials in output/files."""
import contextlib
import json
import os
import pathlib
import socket
import subprocess
import time
import pymysql
import requests

ROOT=pathlib.Path(__file__).resolve().parents[1]
PROJECT='viora-514300'
API='https://api-m.sandbox.paypal.com'
def cli(*args):
    return subprocess.check_output(['gcloud.cmd',*args,'--project='+PROJECT],text=True,stderr=subprocess.PIPE).strip()
def service():return json.loads(cli('run','services','describe','viora','--region=us-central1','--format=json'))
def secret(name):return cli('secrets','versions','access','latest','--secret='+name)
def paypal():
    token=requests.post(API+'/v1/oauth2/token',auth=(secret('viora-production-paypal-client-id'),secret('viora-production-paypal-client-secret')),data={'grant_type':'client_credentials'},timeout=40)
    if token.status_code!=200:raise RuntimeError('Sandbox OAuth HTTP '+str(token.status_code))
    session=requests.Session();session.headers['Authorization']='Bearer '+token.json()['access_token'];return session
def private_dir():
    path=ROOT/'.cache/paypal-private';path.mkdir(parents=True,exist_ok=True)
    user=subprocess.check_output(['whoami'],text=True).strip()
    subprocess.run(['icacls',str(path),'/inheritance:r','/grant:r',user+':(OI)(CI)F'],capture_output=True,check=True)
    return path
@contextlib.contextmanager
def database():
    env=os.environ.copy();env['CSQL_PROXY_TOKEN']=cli('auth','print-access-token')
    password=secret('viora-production-db-password')
    path=private_dir()
    with (path/'proxy.log').open('w') as log:
        proc=subprocess.Popen([str(pathlib.Path.home()/'.cache/viora-tools/cloud-sql-proxy.exe'),'--address=127.0.0.1','--port=13309',PROJECT+':us-central1:viora-staging-db'],env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
        try:
            for _ in range(40):
                try:
                    with socket.create_connection(('127.0.0.1',13309),timeout=1):break
                except OSError:time.sleep(.2)
            db=pymysql.connect(host='127.0.0.1',port=13309,user='viora_prod',password=password,database='viora_production',charset='utf8mb4',autocommit=True,read_timeout=45)
            try:yield db,password,path
            finally:db.close()
        finally:proc.terminate();proc.wait(timeout=15)
