"""Fiscal HTTP integration, fixed isolated QA destination; ECF always disabled."""
import json, os, pathlib, re, secrets, socket, subprocess, time
import requests
import pymysql

ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP='C:/xampp/php/php.exe'
BASE='http://127.0.0.1:18087'
env=os.environ.copy();password=secrets.token_urlsafe(24)
env.update(DB_HOST='127.0.0.1',DB_PORT='13308',DB_USER='root',DB_PASSWORD='',DB_NAME='viora_ecf_qa',DB_SOCKET='',ECF_ENABLED='false',DB_SESSION_ENABLED='false',VIORA_QA_PASSWORD=password)
db=pymysql.connect(host='127.0.0.1',port=13308,user='root',database='viora_ecf_qa',autocommit=True)
hash_code="echo password_hash(getenv('VIORA_QA_PASSWORD'),PASSWORD_DEFAULT);"
hashed=subprocess.check_output([PHP,'-r',hash_code],env=env,text=True)
with db.cursor() as c:
    c.execute("SELECT u.id_usuarios,u.usuario FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE LOWER(r.nombre) IN ('administrador','admin') LIMIT 1");uid,user=c.fetchone()
    c.execute("UPDATE usuario SET clave=%s,activo=1,estado_cuenta='Activo' WHERE id_usuarios=%s",(hashed,uid))
    c.execute("SELECT id_contratos FROM contrato WHERE activo=1 AND estado='Activo' AND tipo_operacion='Alquiler' LIMIT 1");contract=c.fetchone()[0]
    c.execute("UPDATE contrato SET fecha_inicio='2000-01-01',fecha_fin='2099-12-31' WHERE id_contratos=%s",(contract,))
    c.execute("SELECT periodo FROM factura WHERE contrato_id=%s AND estado<>'Anulada'",(contract,));used={r[0] for r in c.fetchall()}
    period=next(f'{y}-01' for y in range(2030,2099) if f'{y}-01' not in used)
results=[]
def check(name,ok):
    results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    assert ok,name
sessions=ROOT/'.cache/ecf-http-sessions';sessions.mkdir(parents=True,exist_ok=True)
with (ROOT/'.cache/ecf-http.log').open('w') as log:
    proc=subprocess.Popen([PHP,'-d','session.save_path='+str(sessions),'-S','127.0.0.1:18087','-t',str(ROOT/'Viora')],env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',18087),timeout=1):break
            except OSError:time.sleep(.1)
        s=requests.Session();s.trust_env=False
        check('Anonymous local fiscal query denied',s.get(BASE+'/mantenimientos/consultar_ecf.php?factura_id=4').status_code==401)
        r=s.post(BASE+'/login/procesar_login.php',data={'username':user,'password':password});check('QA administrator login succeeds','principal.php' in r.url)
        r=s.get(BASE+'/mantenimientos/facturacion.php');token=re.search(r'name="ecf-csrf" content="([a-f0-9]+)"',r.text).group(1)
        headers={'X-CSRF-Token':token};url=BASE+'/mantenimientos/guardar_factura.php'
        body={'contrato_id':contract,'periodo':period,'fecha':'2026-11-12','conceptos':['Alquiler','Mantenimiento']}
        check('Invoice without CSRF denied',s.post(url,json=body).status_code==403)
        check('Invoice GET denied',s.get(url,headers=headers).status_code==405)
        r=s.post(url,json=body,headers=headers);data=r.json();check('Actual invoice generation commits in isolated QA',r.status_code==200 and data.get('success'))
        fid=data['id_facturas'];check('Disabled fiscal state after invoice generation',data['ecf']['estado']=='PENDIENTE_CONFIGURACION')
        with db.cursor() as c:
            c.execute('SELECT subtotal,total,saldo_pendiente FROM factura WHERE id_facturas=%s',(fid,));sub,total,balance=c.fetchone()
            c.execute('SELECT SUM(monto),COUNT(*) FROM factura_detalle WHERE factura_id=%s',(fid,));base,count=c.fetchone()
        check('Invoice detail and total match existing ITBIS rule',base==sub and count==2 and round(float(sub)*1.18,2)==float(total) and balance==total)
        check('Duplicate invoice concepts denied',not s.post(url,json=body,headers=headers).json()['success'])
        r=s.get(BASE+'/mantenimientos/factura_pdf.php',params={'id':fid});check('Existing PDF still renders',r.status_code==200 and r.content.startswith(b'%PDF'))
        r=s.get(BASE+'/mantenimientos/consultar_ecf.php',params={'factura_id':fid});check('Query identifies local source and no remote claim',r.status_code==200 and r.json()['fuente']=='registro_local' and r.json()['consulta_proveedor_disponible'] is False)
        r=s.post(BASE+'/mantenimientos/emitir_nota_credito.php',json={'factura_id':4,'codigo_modificacion':1},headers=headers);check('Disabled E34 returns no issued note',r.status_code==200 and r.json()['nota']['id'] is None)
        check('Credit note without CSRF denied',s.post(BASE+'/mantenimientos/emitir_nota_credito.php',json={'factura_id':4}).status_code==403)
        check('Fiscal retry without CSRF denied',s.post(BASE+'/mantenimientos/reenviar_ecf.php',json={'factura_id':fid}).status_code==403)
    finally:
        proc.terminate();proc.wait(timeout=10);db.close()
        (ROOT/'deployment/ecf-http-results.json').write_text(json.dumps({'destination':'127.0.0.1:13308/viora_ecf_qa','real_provider_called':False,'checks':results},indent=2))
