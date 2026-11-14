"""Disposable local fixture with shared SQL sessions and two PHP servers."""
import os, pathlib, subprocess, sys, time, socket
import pymysql
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP='C:/xampp/php/php.exe'
db=pymysql.connect(host='127.0.0.1',port=13308,user='root',autocommit=True)
with db.cursor() as c:
    # Only this fixed, local, disposable test database may be reset.
    c.execute('DROP DATABASE IF EXISTS viora_csrf_qa');c.execute('CREATE DATABASE viora_csrf_qa CHARACTER SET utf8mb4')
db.close()
args=['--host=127.0.0.1','--port=13308','-u','root']
schema=subprocess.check_output(['C:/xampp/mysql/bin/mysqldump.exe',*args,'--no-data','--skip-comments','viora_ecf_qa'])
schema+=b'\n'+(ROOT/'deployment/staging-sessions.sql').read_bytes()
subprocess.run(['C:/xampp/mysql/bin/mysql.exe',*args,'viora_csrf_qa'],input=schema,check=True)
env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='13308',DB_USER='root',DB_PASSWORD='',DB_NAME='viora_csrf_qa',DB_SOCKET='',DB_SESSION_ENABLED='true',ECF_ENABLED='false',INVOICE_PDF_BUCKET='',CHROME_PATH='C:/Program Files/Google/Chrome/Application/chrome.exe',CSRF_RESULT=str(ROOT/'deployment/csrf-browser-local-results.json'))
subprocess.run([PHP,str(ROOT/'deployment/seed-csrf-qa.php')],env=env,check=True)
folder=ROOT/'.cache/csrf-browser';folder.mkdir(parents=True,exist_ok=True)
bootstrap=folder/'bootstrap.php';bootstrap.write_text("<?php\n$_SERVER['HTTPS']='on';\nrequire_once '"+(ROOT/'deployment/mysql-session.php').as_posix()+"';\n")
processes=[];logs=[]
try:
    for port in [18091,18092]:
        log=(folder/('server-'+str(port)+'.log')).open('w');logs.append(log)
        processes.append(subprocess.Popen([PHP,'-d','auto_prepend_file='+str(bootstrap),'-d','session.name=VIORASID','-d','session.use_strict_mode=1','-d','session.cookie_secure=1','-d','session.cookie_httponly=1','-d','session.cookie_samesite=Lax','-S','127.0.0.1:'+str(port),'-t',str(ROOT/'Viora')],env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW))
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=1):break
            except OSError:time.sleep(.1)
    subprocess.run([sys.executable,str(ROOT/'deployment/test-csrf-browser.py')],env=env,check=True)
finally:
    for p in processes:p.terminate();p.wait(timeout=10)
    for log in logs:log.close()
