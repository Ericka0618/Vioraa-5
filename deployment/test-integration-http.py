"""HTTP tests against the isolated local fixture, never Cloud SQL or production."""
import json
import os
import pathlib
import re
import secrets
import socket
import subprocess
import time
import requests

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = 'C:/xampp/php/php.exe'
BASE = 'http://127.0.0.1:18083'
env = os.environ.copy()
env.update(DB_HOST='127.0.0.1', DB_PORT='13308', DB_USER='root', DB_PASSWORD='',
           DB_NAME='viora_integrations_qa', DB_SOCKET='', GEMINI_API_KEY='', ECF_ENABLED='false')
password = secrets.token_urlsafe(24)
env['VIORA_QA_PASSWORD'] = password
# Only the isolated database is named here. Password never enters command arguments or output.
setup = r'''
$db=new mysqli('127.0.0.1','root','','viora_integrations_qa',13308);
$hash=password_hash(getenv('VIORA_QA_PASSWORD'),PASSWORD_DEFAULT);
$q=$db->prepare("UPDATE usuario u JOIN role r ON r.id_roles=u.id_rol SET u.clave=?,u.activo=1,u.estado_cuenta='Activo' WHERE LOWER(r.nombre) IN ('admin','administrador')");
$q->bind_param('s',$hash);$q->execute();
$row=$db->query("SELECT u.usuario FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE LOWER(r.nombre) IN ('admin','administrador') LIMIT 1")->fetch_assoc();
echo json_encode($row);
'''
user = json.loads(subprocess.check_output([PHP, '-r', setup], env=env, text=True))['usuario']
session_dir = ROOT / '.cache/http-sessions'
session_dir.mkdir(parents=True, exist_ok=True)
results = []

def check(name, ok):
    results.append({'test': name, 'passed': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    assert ok, name

with (ROOT / '.cache/http-server.log').open('w') as log:
    proc = subprocess.Popen([PHP, '-d', 'session.save_path=' + str(session_dir),
                             '-S', '127.0.0.1:18083', '-t', str(ROOT / 'Viora')],
                            env=env, stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
    try:
        for _ in range(30):
            try:
                with socket.create_connection(('127.0.0.1', 18083), timeout=1):
                    break
            except OSError:
                time.sleep(.1)
        anon = requests.Session()
        anon.trust_env = False
        check('Anonymous chatbot denied', anon.post(BASE+'/mantenimientos/chatbot_api.php', json={'mensaje':'QA'}).status_code == 401)
        check('Anonymous fiscal query denied', anon.get(BASE+'/mantenimientos/consultar_ecf.php?factura_id=4').status_code == 401)
        check('Webhook GET denied', anon.get(BASE+'/login/paypal_webhook.php').status_code == 405)
        check('Unconfigured webhook fails closed', anon.post(BASE+'/login/paypal_webhook.php', json={}).status_code == 503)
        s = requests.Session()
        s.trust_env = False
        r = s.post(BASE+'/login/procesar_login.php', data={'username':user,'password':password})
        check('Admin login with hashed fixture password', '/principal.php' in r.url and r.status_code == 200)
        csrf = re.search(r'data-csrf="([a-f0-9]+)"', r.text).group(1)
        endpoint = BASE+'/mantenimientos/chatbot_api.php'
        check('Chat rejects missing CSRF', s.post(endpoint, json={'accion':'historial'}).status_code == 403)
        headers = {'X-CSRF-Token':csrf}
        r = s.post(endpoint, json={'accion':'historial'}, headers=headers)
        check('Chat history available without Gemini key', r.status_code == 200 and isinstance(r.json()['historial'], list))
        r = s.post(endpoint, json={'accion':'nueva'}, headers=headers)
        check('New conversation preserves records and clears current history', r.status_code == 200 and r.json()['historial'] == [])
        r = s.post(endpoint, json={'mensaje':'Prueba VIORA'}, headers=headers)
        check('Missing Gemini key returns safe JSON 503', r.status_code == 503 and 'error' in r.json() and 'api_key' not in r.text)
        r = s.get(BASE+'/mantenimientos/consultar_ecf.php?factura_id=4')
        check('Fiscal status query explicitly identifies local source', r.status_code == 200 and r.json()['fuente']=='registro_local' and r.json()['ecf']['encf'])
        r = s.post(BASE+'/login/verificar_suscripcion.php', json={'subscriptionID':'I-QA'})
        check('No pending registration cannot activate subscription', r.status_code == 401)
    finally:
        proc.terminate()
        proc.wait(timeout=10)
        (ROOT/'deployment/integration-http-results.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
