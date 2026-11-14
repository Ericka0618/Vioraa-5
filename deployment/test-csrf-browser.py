"""Real login and form submission over HTTPS across two PHP replicas, isolated SQL only."""
import datetime, http.server, json, os, pathlib, re, ssl, tempfile, threading
import pymysql, requests
from playwright.sync_api import sync_playwright
from cryptography import x509
from cryptography.x509.oid import NameOID
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa

assert os.environ['DB_NAME']=='viora_csrf_qa'
assert os.environ['DB_HOST'] in ['127.0.0.1','invoice-csrf-db']
assert os.environ['ECF_ENABLED']=='false'
A=os.environ.get('CSRF_A','http://127.0.0.1:18091')
B=os.environ.get('CSRF_B','http://127.0.0.1:18092')
db=pymysql.connect(host=os.environ['DB_HOST'],port=int(os.environ.get('DB_PORT','3306')),user='root',database='viora_csrf_qa',autocommit=True)
checks=[];events=[];pdf_events=[];page_reads=0
def check(name,ok):
    checks.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name

class Proxy(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args):pass
    def do_GET(self):self.forward()
    def do_POST(self):self.forward()
    def forward(self):
        global page_reads
        if self.path.split('?')[0].endswith('/facturacion.php'):
            page_reads+=1;instance='A' if page_reads%2 else 'B'
        elif self.path.split('?')[0].endswith(('/guardar_factura.php','/buscar_clientes_factura.php')):instance='B'
        else:instance='A'
        body=self.rfile.read(int(self.headers.get('Content-Length','0')))
        headers={k:v for k,v in self.headers.items() if k.lower() not in ['host','connection','content-length']}
        headers['X-Forwarded-Proto']='https'
        with requests.Session() as s:
            s.trust_env=False;r=s.request(self.command,(A if instance=='A' else B)+self.path,data=body,headers=headers,allow_redirects=False,timeout=45)
        events.append((self.path.split('?')[0],instance))
        if self.path.split('?')[0].endswith('/factura_pdf.php'):
            pdf_events.append({'status':r.status_code,'pdf':r.content.startswith(b'%PDF')})
        self.send_response(r.status_code)
        for k in r.raw.headers:
            if k.lower() in ['transfer-encoding','connection','content-length','content-encoding']:continue
            for value in r.raw.headers.getlist(k):self.send_header(k,value)
        self.send_header('Content-Length',str(len(r.content)));self.send_header('X-QA-Instance',instance);self.end_headers()
        try:self.wfile.write(r.content)
        except (BrokenPipeError,ConnectionResetError,ConnectionAbortedError):pass

folder=pathlib.Path(tempfile.mkdtemp(prefix='viora-csrf-browser-'))
key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'isolated-viora-qa')])
now=datetime.datetime.now(datetime.timezone.utc)
cert=x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(minutes=1)).not_valid_after(now+datetime.timedelta(days=1)).sign(key,hashes.SHA256())
(folder/'key.pem').write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
(folder/'cert.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM))
server=http.server.ThreadingHTTPServer(('127.0.0.1',18443),Proxy)
tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(folder/'cert.pem',folder/'key.pem');server.socket=tls.wrap_socket(server.socket,server_side=True)
threading.Thread(target=server.serve_forever,daemon=True).start();base='https://127.0.0.1:18443'

def session_token(ctx):
    sid=next(c['value'] for c in ctx.cookies() if c['name']=='VIORASID')
    with db.cursor() as c:
        c.execute('SELECT payload,expires_at FROM viora_runtime_sessions WHERE id=%s',(sid,));row=c.fetchone()
    if not row:return None
    payload=row[0].decode() if isinstance(row[0],bytes) else row[0]
    m=re.search(r'ecf_csrf\|s:\d+:"([^";]+)";',payload)
    return m.group(1) if m else None

def login(browser,uid):
    ctx=browser.new_context(ignore_https_errors=True,accept_downloads=True);page=ctx.new_page()
    page.goto(base+'/login/login.php')
    old=next((c['value'] for c in ctx.cookies() if c['name']=='VIORASID'),None)
    page.fill('#username','qa-browser-'+str(uid));page.fill('#password',os.environ.get('QA_BROWSER_PASSWORD','isolated-browser-fixture'))
    with page.expect_navigation():page.locator('button[type=submit]').first.click()
    check('Real browser login '+str(uid),('/principal.php' if uid==1 else '/catalogo.php') in page.url)
    cookie=next(c for c in ctx.cookies() if c['name']=='VIORASID')
    check('HTTPS secure HttpOnly SameSite cookie '+str(uid),cookie['secure'] and cookie['httpOnly'] and cookie['sameSite']=='Lax')
    check('Login rotates session ID '+str(uid),old is None or old!=cookie['value'])
    return ctx,page

try:
    with sync_playwright() as pw:
        options={'headless':True,'channel':'chromium'}
        if os.environ.get('CHROME_PATH'):
            options.pop('channel');options['executable_path']=os.environ['CHROME_PATH']
        browser=pw.chromium.launch(**options)
        try:
            ctx,page=login(browser,1);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            r=page.goto(base+'/mantenimientos/facturacion.php')
            token=page.locator('meta[name=ecf-csrf]').get_attribute('content')
            check('Form publishes a strong CSRF token',bool(re.fullmatch('[a-f0-9]{64}',token)))
            check('Form cannot be cached','no-store' in r.headers.get('cache-control',''))
            check('Rendered token equals shared SQL session',token==session_token(ctx))
            page.wait_for_function("document.querySelector('#cliente').options.length>1")
            page.select_option('#cliente','2');page.locator('#grupoVariasPropiedades').wait_for(state='visible')
            page.select_option('#selectorPropiedades','2');page.select_option('#selectorPropiedades','1')
            page.check('#checkAlquiler');page.check('#checkMantenimiento');page.uncheck('#checkMantenimiento');page.check('#checkMantenimiento')
            check('Client property and concept changes preserve token',page.locator('meta[name=ecf-csrf]').get_attribute('content')==token and session_token(ctx)==token)
            statuses=page.evaluate("async () => await Promise.all(Array.from({length:4}, () => fetch('buscar_clientes_factura.php', {credentials:'same-origin'}).then(r => r.status)))")
            check('Concurrent AJAX preserves shared session',statuses==[200]*4 and session_token(ctx)==token)
            tab=ctx.new_page();r=tab.goto(base+'/mantenimientos/facturacion.php')
            check('Second replica and tab preserve same token',r.headers['x-qa-instance']=='B' and tab.locator('meta[name=ecf-csrf]').get_attribute('content')==token and session_token(ctx)==token);tab.close()
            page.on('dialog',lambda d:d.accept())
            with page.expect_response(lambda r:'/guardar_factura.php' in r.url and r.request.method=='POST') as response, page.expect_popup() as popup:
                page.locator('#btnGuardar').click()
            response=response.value;data=response.json();sent=response.request.headers.get('x-csrf-token','')
            check('Actual AJAX sends the rendered token',sent==token and len(sent)==64)
            check('Other replica accepts legitimate browser submission',response.headers['x-qa-instance']=='B' and response.status==200 and data.get('success') is True)
            check('Ordinary invoice and automatic PDF succeed',data['pdf_generado'] is True and data['ecf']['estado']=='PENDIENTE_CONFIGURACION')
            fid=data['id_facturas'];number=data['numero_documento']
            # Headless Chromium may download inline PDFs instead of navigating its viewer.
            # Require the actual popup's HTTP request to succeed before a separate byte check.
            for _ in range(100):
                if pdf_events:break
                page.wait_for_timeout(100)
            popup_succeeded=bool(pdf_events) and pdf_events[0]['status']==200 and pdf_events[0]['pdf']
            pdf=ctx.request.get(base+'/mantenimientos/factura_pdf.php?id='+str(fid))
            check('Real browser opened a working invoice PDF',popup_succeeded and pdf.status==200 and pdf.body().startswith(b'%PDF'))
            with db.cursor() as c:
                c.execute('SELECT subtotal,total,monto_pagado,saldo_pendiente FROM factura WHERE id_facturas=%s',(fid,));row=c.fetchone()
                c.execute('SELECT contenido,sha256 FROM factura_documento WHERE documento_id=%s AND tipo=%s',(fid,'factura'));doc=c.fetchone()
            import hashlib
            check('SQL totals and PDF persistence are correct',tuple(map(float,row))==(1100.0,1298.0,0.0,1298.0) and hashlib.sha256(doc[0]).hexdigest()==doc[1])
            body=response.request.post_data_json
            for name,headers in [('missing',{}),('wrong',{'X-CSRF-Token':'0'*64})]:
                bad=ctx.request.post(base+'/mantenimientos/guardar_factura.php',data=body,headers=headers)
                check('CSRF '+name+' remains rejected',bad.status==403)
            duplicate=ctx.request.post(base+'/mantenimientos/guardar_factura.php',data=body,headers={'X-CSRF-Token':token})
            check('Valid token retry rejects duplicate charge',duplicate.status==400 and duplicate.json()['success'] is False)
            with db.cursor() as c:c.execute('SELECT COUNT(*) FROM factura');count=c.fetchone()[0]
            check('Failed attempts leave exactly one invoice',count==1)
            page.goto(base+'/mantenimientos/consulta_facturas.php?q='+number)
            check('Generated invoice appears in admin history',page.locator('tbody').inner_text().find(number)>=0)
            check('No billing JavaScript exceptions',not errors)
            owner,client=login(browser,2);client.goto(base+'/mantenimientos/mis_facturas.php')
            check('Generated invoice automatically appears in client history',number in client.locator('tbody').inner_text())
            with client.expect_download() as download:client.locator('a[href*="descargar=1"]').first.click()
            check('Client downloads a real PDF through browser',pathlib.Path(download.value.path()).read_bytes().startswith(b'%PDF'))
            check('Client can view its PDF',owner.request.get(base+'/mantenimientos/factura_pdf.php?id='+str(fid)).status==200)
            page.goto(base+'/mantenimientos/facturacion_viora.php?contrato=1')
            for amount,expected in [('1.00','Parcial'),('1297.00','Pagada')]:
                form=page.locator('form.fv-pago[data-factura="'+str(fid)+'"]')
                form.locator('input[name=monto]').fill(amount);form.locator('select[name=metodo_pago]').select_option('Transferencia')
                with page.expect_navigation():
                    with page.expect_response(lambda r:'/registrar_pago.php' in r.url and r.request.method=='POST') as paid:
                        form.locator('button[type=submit]').click()
                    paid=paid.value;payment=paid.json()
                    check('Browser payment accepted with valid CSRF '+expected,paid.status==200 and payment.get('estado')==expected and paid.request.headers.get('x-csrf-token')==token)
            with db.cursor() as c:c.execute('SELECT monto_pagado,saldo_pendiente,estado FROM factura WHERE id_facturas=%s',(fid,));balance=c.fetchone()
            check('Two real browser payments update SQL balance',tuple(map(float,balance[:2]))==(1298.0,0.0) and balance[2]=='Pagada')
            client.goto(base+'/mantenimientos/mis_facturas.php?estado=Pagada')
            check('Fully paid invoice remains in client history',number in client.locator('tbody').inner_text())
            check('Paid invoice PDF remains accessible',owner.request.get(base+'/mantenimientos/factura_pdf.php?id='+str(fid)).status==200)
            stranger,other=login(browser,3)
            check('Another client cannot access PDF',stranger.request.get(base+'/mantenimientos/factura_pdf.php?id='+str(fid)).status==404)
            check('Another client cannot access invoice detail',stranger.request.get(base+'/mantenimientos/mis_facturas.php?id='+str(fid)).status==404)
            check('Login selection and submission crossed PHP replicas',('/login/procesar_login.php','A') in events and ('/mantenimientos/guardar_factura.php','B') in events and ('/mantenimientos/buscar_clientes_factura.php','B') in events)
            for context in [ctx,owner,stranger]:context.close()
        finally:browser.close()
finally:
    server.shutdown();db.close()
    result={'checks':checks,'real_browser_login_and_submit':True,'two_php_replicas':True,'shared_mysql_sessions':True,'isolated_database':'viora_csrf_qa','production_writes':False}
    if os.environ.get('CSRF_RESULT'):pathlib.Path(os.environ['CSRF_RESULT']).write_text(json.dumps(result,indent=2))
    print(json.dumps(result),flush=True)
