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
        # Portal, ownership, persistence and payment lifecycle in this fixed QA database.
        with db.cursor() as c:
            c.execute('SELECT persona_id FROM factura WHERE id_facturas=%s',(fid,));person=c.fetchone()[0]
            c.execute("SELECT u.id_usuarios FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE LOWER(r.nombre)='cliente' AND u.persona_id=%s AND u.activo=1 LIMIT 1",(person,));row=c.fetchone()
            assert row, 'QA invoice must belong to an existing active client'
            owner=row[0]
            c.execute("SELECT u.id_usuarios FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE LOWER(r.nombre)='cliente' AND u.persona_id<>%s AND u.activo=1 LIMIT 1",(person,));other=c.fetchone()[0]
            c.execute('SELECT contenido,sha256 FROM factura_documento WHERE tipo=%s AND documento_id=%s',('factura',fid));stored=c.fetchone()
        import hashlib
        check('PDF created and persisted automatically',data.get('pdf_generado') is True and stored[0].startswith(b'%PDF') and hashlib.sha256(stored[0]).hexdigest()==stored[1])
        def client_session(uid):
            sid=secrets.token_hex(24);e=env.copy();e.update(QA_SID=sid,QA_UID=str(uid))
            code="session_id(getenv('QA_SID'));session_start();$_SESSION=['logged_in'=>true,'id_usuario'=>(int)getenv('QA_UID'),'rol'=>'Cliente'];session_write_close();"
            subprocess.run([PHP,'-d','session.save_path='+str(sessions),'-r',code],env=e,capture_output=True,check=True)
            session=requests.Session();session.trust_env=False;session.cookies.set('PHPSESSID',sid,domain='127.0.0.1',path='/');return session,sid
        client,client_sid=client_session(owner);stranger,_=client_session(other)
        portal=BASE+'/mantenimientos/mis_facturas.php'
        r=client.get(portal,params={'q':data['numero_documento']})
        check('Owner automatically sees invoice and sidebar',r.status_code==200 and data['numero_documento'] in r.text and 'Mis Facturas' in r.text)
        check('Client cannot access admin consultation',client.get(BASE+'/mantenimientos/consulta_facturas.php').status_code==403)
        check('Other client cannot see invoice detail',stranger.get(portal,params={'id':fid}).status_code==404)
        r=stranger.get(portal,params={'q':data['numero_documento'],'persona_id':person})
        check('Client cannot override owner with query parameters',r.status_code==200 and '<strong>'+data['numero_documento']+'</strong>' not in r.text)
        pdf_url=BASE+'/mantenimientos/factura_pdf.php'
        check('Other client cannot fetch invoice PDF',stranger.get(pdf_url,params={'id':fid}).status_code==404)
        check('Anonymous cannot fetch invoice PDF',requests.get(pdf_url,params={'id':fid},allow_redirects=False).status_code==302)
        r=client.get(pdf_url,params={'id':fid});check('Owner can view PDF',r.status_code==200 and r.content.startswith(b'%PDF') and r.headers['Content-Disposition'].startswith('inline'))
        r=client.get(pdf_url,params={'id':fid,'descargar':1});check('Owner can download PDF',r.status_code==200 and r.headers['Content-Disposition'].startswith('attachment'))
        check('Admin consultation contains invoice',data['numero_documento'] in s.get(BASE+'/mantenimientos/consulta_facturas.php',params={'q':data['numero_documento']}).text)
        with db.cursor() as c:c.execute('UPDATE factura SET fecha_vencimiento=DATE_SUB(CURRENT_DATE,INTERVAL 1 DAY) WHERE id_facturas=%s',(fid,))
        check('Overdue filter finds outstanding invoice',data['numero_documento'] in client.get(portal,params={'estado':'Vencida','q':data['numero_documento']}).text)
        check('Date filter excludes invoice outside range','<strong>'+data['numero_documento']+'</strong>' not in client.get(portal,params={'desde':'2001-01-01','hasta':'2001-12-31'}).text)
        pay_url=BASE+'/mantenimientos/registrar_pago.php'
        payment={'factura_id':fid,'monto':'1.00','metodo_pago':'Transferencia','referencia':'QA-ONLY'}
        check('Payment without CSRF denied',s.post(pay_url,json=payment).status_code==403)
        check('Client cannot mark invoice paid',client.post(pay_url,json=payment).status_code==403)
        r=s.post(pay_url,json=payment,headers=headers);check('Partial payment updates balance',r.status_code==200 and r.json()['estado']=='Parcial' and r.json()['saldo_pendiente']==round(float(total)-1,2))
        check('Partial payment visible to client',data['numero_documento'] in client.get(portal,params={'estado':'Vencida','q':data['numero_documento']}).text)
        payment['monto']=str(total)
        check('Overpayment rejected',s.post(pay_url,json=payment,headers=headers).status_code==400)
        payment['monto']=format(float(total)-1,'.2f')
        r=s.post(pay_url,json=payment,headers=headers);check('Full payment clears balance',r.status_code==200 and r.json()['estado']=='Pagada' and r.json()['saldo_pendiente']==0)
        r=client.get(portal,params={'estado':'Pagada','q':data['numero_documento']});check('Paid invoice retained in client history',r.status_code==200 and data['numero_documento'] in r.text)
        from playwright.sync_api import sync_playwright
        with sync_playwright() as pw:
            browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
            ctx=browser.new_context(viewport={'width':390,'height':844});ctx.add_cookies([{'name':'PHPSESSID','value':client_sid,'domain':'127.0.0.1','path':'/'}]);page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.goto(portal+'?q='+data['numero_documento']);check('Mobile portal renders paid invoice',page.locator('h1').inner_text()=='Mis Facturas' and page.locator('table').count()>0)
            check('Client portal has no fiscal emission form',page.locator('#form-nota-credito').count()==0)
            check('Mobile portal has no JavaScript exceptions',not errors)
            browser.close()
    finally:
        proc.terminate();proc.wait(timeout=10);db.close()
        (ROOT/'deployment/ecf-http-results.json').write_text(json.dumps({'destination':'127.0.0.1:13308/viora_ecf_qa','real_provider_called':False,'checks':results},indent=2))
