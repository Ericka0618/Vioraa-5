"""Prepare only the dedicated local fiscal fixture; never connects to Cloud SQL."""
import pathlib
import subprocess
import pymysql

ROOT=pathlib.Path(__file__).resolve().parents[1]
db=pymysql.connect(host='127.0.0.1',port=13308,user='root',autocommit=True)
with db.cursor() as c:
    c.execute('CREATE DATABASE IF NOT EXISTS viora_ecf_qa CHARACTER SET utf8mb4')
    c.execute("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='viora_ecf_qa'")
    empty=c.fetchone()[0]==0
db.close()
connection=['--host=127.0.0.1','--port=13308','-u','root']
mysql=['C:/xampp/mysql/bin/mysql.exe',*connection,'viora_ecf_qa']
if empty:
    data=subprocess.check_output(['C:/xampp/mysql/bin/mysqldump.exe',*connection,'viora_integrations_qa'])
    subprocess.run(mysql,input=data,check=True)
subprocess.run(mysql,input=(ROOT/'Viora/conf/ecf_auditoria.sql').read_bytes(),check=True)
print('Dedicated local fiscal QA ready; original and cloud databases unchanged.')
