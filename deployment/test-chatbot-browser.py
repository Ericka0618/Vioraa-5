"""Real browser/history/error tests on local QA. Does not simulate Gemini answers."""
import json
import os
import pathlib
import secrets
import socket
import subprocess
import time
from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = 'C:/xampp/php/php.exe'
BASE = 'http://127.0.0.1:18084'
env = os.environ.copy()
env.update(DB_HOST='127.0.0.1', DB_PORT='13308', DB_USER='root', DB_PASSWORD='', DB_SOCKET='',
           DB_NAME='viora_integrations_qa', GEMINI_API_KEY='', ECF_ENABLED='false')
password = secrets.token_urlsafe(24)
env['VIORA_QA_PASSWORD'] = password
setup = r'''
$db=new mysqli('127.0.0.1','root','','viora_integrations_qa',13308);
$db->set_charset('utf8mb4');
require 'Viora/mantenimientos/chatbot_historial.php';
$hash=password_hash(getenv('VIORA_QA_PASSWORD'),PASSWORD_DEFAULT);
$rows=$db->query("SELECT u.id_usuarios,u.usuario FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE r.nombre='Cliente' ORDER BY u.id_usuarios LIMIT 2")->fetch_all(MYSQLI_ASSOC);
foreach($rows as $i=>&$row){
 $uid=(int)$row['id_usuarios'];
 $q=$db->prepare("UPDATE usuario SET clave=?,activo=1,estado_cuenta='Activo' WHERE id_usuarios=?");$q->bind_param('si',$hash,$uid);$q->execute();
 // Access fixture only: no PayPal provider payment is claimed or created.
 $db->query("INSERT INTO suscripcion(usuario_id,estado,fecha_proximo_pago) VALUES($uid,'Activo',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))");
 $_SESSION=[];$id=chatbot_conversacion($db,$uid);
 $row['marker']='QA-HISTORY-'.bin2hex(random_bytes(6));
 chatbot_guardar_turno($db,$id,$row['marker'],'Mensaje guardado para verificar aislamiento.',$uid);
}
echo json_encode($rows);
'''
users = json.loads(subprocess.check_output([PHP, '-r', setup], cwd=ROOT, env=env, text=True))
results = []
def check(name, ok):
    results.append({'test':name,'passed':bool(ok)})
    print(('PASS ' if ok else 'FAIL ')+name, flush=True)
    assert ok, name

sessions = ROOT/'.cache/browser-chat-sessions'
sessions.mkdir(exist_ok=True, parents=True)
with (ROOT/'.cache/chatbot-browser-server.log').open('w') as log:
    proc = subprocess.Popen([PHP,'-d','session.save_path='+str(sessions),'-S','127.0.0.1:18084','-t',str(ROOT/'Viora')],
                            env=env, stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
    try:
        for _ in range(30):
            try:
                with socket.create_connection(('127.0.0.1',18084),timeout=1):break
            except OSError:time.sleep(.1)
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
            context=browser.new_context()
            page=context.new_page()
            errors=[]
            page.on('pageerror',lambda e:errors.append(str(e)))
            def login(user):
                page.goto(BASE+'/login/logout.php')
                page.goto(BASE+'/login/login.php')
                page.fill('#username',user['usuario']);page.fill('#password',password)
                with page.expect_navigation():page.locator('button[type=submit]').first.click()
                page.locator('#cb-enviar').wait_for(state='attached')
                page.wait_for_function("!document.querySelector('#cb-enviar').disabled")
                page.locator('#cb-abrir').click() if page.locator('#cb-ventana').is_hidden() else None
            login(users[0])
            check('Client browser loads existing own history',users[0]['marker'] in page.locator('#cb-mensajes').inner_text())
            check('Other client marker absent',users[1]['marker'] not in page.locator('#cb-mensajes').inner_text())
            page.fill('#cb-entrada','Que funciones tiene VIORA?')
            page.locator('#cb-enviar').click()
            page.wait_for_function("!document.querySelector('#cb-enviar').disabled")
            check('Missing key produces visible error in actual widget',page.locator('.cb__mensaje--error').count()==1)
            check('Error does not expose a key or stack trace','Stack trace' not in page.locator('#cb-mensajes').inner_text() and 'UNCONFIGURED' not in page.locator('#cb-mensajes').inner_text())
            page.reload();page.wait_for_function("document.querySelector('#cb-enviar') && !document.querySelector('#cb-enviar').disabled")
            check('History restored after real browser reload',users[0]['marker'] in page.locator('#cb-mensajes').inner_text())
            login(users[1])
            text=page.locator('#cb-mensajes').inner_text()
            check('Account switch in same browser isolates history',users[1]['marker'] in text and users[0]['marker'] not in text)
            page.locator('#cb-limpiar').click()
            page.wait_for_function("!document.querySelector('#cb-enviar').disabled")
            check('New conversation clears current widget',users[1]['marker'] not in page.locator('#cb-mensajes').inner_text())
            check('History no longer stored in shared sessionStorage',page.evaluate("sessionStorage.getItem('viora_chat_historial')") is None)
            check('Widget has no JavaScript exceptions',not errors)
            page.locator('#cb-ventana').screenshot(path=str(ROOT/'.cache/chatbot-browser.png'))
            browser.close()
    finally:
        proc.terminate();proc.wait(timeout=10)
        (ROOT/'deployment/chatbot-browser-results.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
