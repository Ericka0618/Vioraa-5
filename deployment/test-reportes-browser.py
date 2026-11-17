"""BI browser, PDF, authorization and CSRF checks. No financial records are changed."""
import argparse,atexit,io,json,os,pathlib,re,subprocess,time,uuid
import pymysql,requests
from playwright.sync_api import sync_playwright
from pypdf import PdfReader
ROOT=pathlib.Path(__file__).resolve().parents[1]
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--url',default='http://127.0.0.1:8096');parser.add_argument('--gemini',action='store_true');args=parser.parse_args()
 db=pymysql.connect(host=os.getenv('DB_HOST','127.0.0.1'),port=int(os.getenv('DB_PORT','3306')),user=os.getenv('DB_USER','root'),password=os.getenv('DB_PASSWORD',''),database=os.getenv('DB_NAME','viora'),autocommit=True)
 def query(sql,params=()):
  with db.cursor() as c:c.execute(sql,params);return c.fetchall()
 accounts=query("SELECT u.id_usuarios,u.usuario,u.clave,r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND LOWER(r.nombre) IN ('admin','administrador') ORDER BY u.id_usuarios")
 account=next((r for r in accounts if not r[2].startswith(('$','DEMO_'))),None)
 if not account:raise RuntimeError('An existing administrator login is required for browser validation.')
 # A separate conversation protects the administrator's existing conversation from test prompts.
 with db.cursor() as c:c.execute('INSERT INTO conversacion_ia(usuario_id) VALUES(%s)',(account[0],));qa_cid=c.lastrowid
 def cleanup():
  conn=pymysql.connect(host=os.getenv('DB_HOST','127.0.0.1'),port=int(os.getenv('DB_PORT','3306')),user=os.getenv('DB_USER','root'),password=os.getenv('DB_PASSWORD',''),database=os.getenv('DB_NAME','viora'),autocommit=True)
  with conn.cursor() as c:c.execute('DELETE FROM mensaje_ia WHERE conversacion_id=%s',(qa_cid,));c.execute('DELETE FROM conversacion_ia WHERE id_conversaciones=%s AND usuario_id=%s',(qa_cid,account[0]))
  conn.close()
 atexit.register(cleanup)
 results=[]
 def check(label,ok):
  results.append({'test':label,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+label,flush=True)
  if not ok:raise AssertionError(label)
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto(args.url+'/login/login.php');page.fill('#username',account[1]);page.fill('#password',account[2]);page.locator('button[type=submit]').first.click();page.wait_for_url(re.compile(r'.*(principal|catalogo)\.php.*'))
  check('existing admin login',page.url.endswith('principal.php'))
  page.goto(args.url+'/mantenimientos/reportes.php');page.wait_for_function("document.querySelectorAll('.bi-kpi').length===12")
  check('dashboard has 12 linked indicators',page.locator('.bi-kpi').count()==12)
  check('admin navigation includes Reportes',page.locator('a.menu__enlace',has_text='Reportes').count()==1)
  token=page.locator('#bi').get_attribute('data-csrf');headers={'X-CSRF-Token':token};endpoint=args.url+'/mantenimientos/reportes_api.php?accion='
  check('CSRF missing rejected',page.request.post(endpoint+'generar',data={'reporte':1}).status==403)
  check('generation disallows GET',page.request.get(endpoint+'generar').status==405)
  reference='QA-BI-'+uuid.uuid4().hex
  record={'tipo_registro':'gasto','condominio':'demo:1','propiedad':'demo:1','fecha':'2026-01-03','monto':'123.45','concepto':'Registro aislado de prueba de API','referencia':reference}
  try:
   check('expense belongs to selected condominium',page.request.post(endpoint+'registrar',data={'registro':dict(record,propiedad='demo:2')},headers=headers).status==422)
   check('expense rejects negative amounts',page.request.post(endpoint+'registrar',data={'registro':dict(record,monto='-1')},headers=headers).status==422)
   check('demo expense can be registered',page.request.post(endpoint+'registrar',data={'registro':record},headers=headers).status==200)
   check('duplicate expense reference is rejected',page.request.post(endpoint+'registrar',data={'registro':record},headers=headers).status==422)
  finally:query('DELETE FROM bi_demo_gasto WHERE referencia=%s AND concepto=%s',(reference,record['concepto']))
  page.select_option('[name=fuente]','demo');page.wait_for_function("document.querySelector('#bi-source').textContent.includes('DEMOSTRACIÓN AISLADA')")
  check('five accordion reports per page',page.locator('#bi-list details').count()==5)
  page.fill('#bi-search','proyeccion');check('accent-insensitive search',page.locator('#bi-list details').count()==1)
  page.fill('#bi-search','');page.locator('#bi-categories button',has_text='Clientes').click();check('category filter',page.locator('#bi-list details').count()==5)
  page.locator('#bi-categories button').first.click();page.locator('#bi-pages button',has_text=re.compile('^2$')).click();check('pagination changes report list','Ingresos mensuales' in page.locator('#bi-list').inner_text())
  for report in range(1,31):
   response=page.request.post(endpoint+'generar',data={'reporte':report,'filtros':{'fuente':'demo'}},headers=headers,timeout=60000)
   check('HTTP generation report '+str(report),response.status==200)
   data=response.json();hid=data['historial_id'];pdf=page.request.get(endpoint+'pdf&id='+str(hid));check('PDF valid report '+str(report),pdf.status==200 and pdf.headers['content-type']=='application/pdf')
   reader=PdfReader(io.BytesIO(pdf.body()));text='\n'.join(pg.extract_text() for pg in reader.pages)
   check('PDF content and page numbering '+str(report),'VIORA' in text and 'Conclusiones' in text and 'Recomendaciones' in text and all('Página '+str(i+1) in pg.extract_text() for i,pg in enumerate(reader.pages)))
   saved=page.request.get(endpoint+'resultado&id='+str(hid)).json();check('history restores original result '+str(report),saved['filas']==data['reporte']['filas'])
   if report==1:
    download=page.request.get(endpoint+'pdf&id='+str(hid)+'&descargar=1');check('PDF attachment header','attachment' in download.headers.get('content-disposition',''))
  page.locator('#bi-list details').first.locator('summary').click();page.locator('#bi-list details').first.get_by_role('button',name='Generar reporte',exact=True).click();page.wait_for_function("document.querySelector('#bi-result').hidden===false")
  check('UI renders report table',page.locator('#bi-result-table tbody tr').count()>0)
  page.screenshot(path=str(ROOT/'.cache/reportes/desktop.png'),full_page=True)
  page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(ROOT/'.cache/reportes/mobile.png'),full_page=True)
  check('mobile no horizontal overflow',page.evaluate('document.documentElement.scrollWidth<=window.innerWidth'))
  if args.gemini:
   csrf=page.locator('#cb-widget').get_attribute('data-csrf');chat=args.url+'/mantenimientos/chatbot_api.php';ch={'X-CSRF-Token':csrf}
   for prompt in ['Genera el reporte de zonas con mayor rentabilidad, solo con datos de demostración de Santiago para este año.','¿Por qué? Explica los datos de ese reporte.','Genera un PDF de ese resultado.']:
    response=page.request.post(chat,data={'mensaje':prompt,'reportes_filtros':{'fuente':'demo','zona':'Santiago'}},headers=ch,timeout=180000)
    if response.status==502 and 'servicio de IA' in response.json().get('error',''):
     print('Gemini returned a transient upstream error; retrying this prompt once.',flush=True);time.sleep(1)
     response=page.request.post(chat,data={'mensaje':prompt,'reportes_filtros':{'fuente':'demo','zona':'Santiago'}},headers=ch,timeout=180000)
    check('live Gemini '+prompt[:35],response.status==200)
    data=response.json();check('Gemini has textual answer',bool(data.get('respuesta')))
    if prompt.startswith(('Genera el','Genera un')):check('Gemini returns authorized report and PDF',data.get('historial_id') is not None and data['reporte']['filtros']['zona']=='Santiago' and data['reporte']['filtros']['fuente']=='demo')
  check('no browser JavaScript errors',not errors)
  anonymous=browser.new_context();anon=anonymous.request.get(endpoint+'pdf&id='+str(hid),max_redirects=0);check('anonymous PDF is protected',anon.status in [302,401,403])
  # Use a temporary server-side session for a real existing client; no account is modified.
  client=query("SELECT u.id_usuarios,u.usuario,u.nombre,r.nombre FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND r.nombre='Cliente' LIMIT 1")
  if client and args.url.startswith('https:'):
   uid,username,name,role=client[0];sid=uuid.uuid4().hex
   def s(v):return 's:'+str(len(v.encode()))+':"'+v+'";'
   payload='logged_in|b:1;id_usuario|i:'+str(uid)+';usuario|'+s(username)+'nombre_usuario|'+s(name)+'rol|'+s('Administrador')
   try:
    query('INSERT INTO viora_runtime_sessions(id,payload,expires_at) VALUES(%s,%s,UNIX_TIMESTAMP()+300)',(sid,payload))
    resp=requests.get(endpoint+'pdf&id='+str(hid),cookies={'VIORASID':sid},allow_redirects=False,timeout=30);check('client cannot bypass authorization with forged session role',resp.status_code==403)
   finally:query('DELETE FROM viora_runtime_sessions WHERE id=%s',(sid,))
  browser.close()
 db.close();(ROOT/'deployment/reportes-browser-results.json').write_text(json.dumps({'url':args.url,'checks':results},indent=2),encoding='utf-8')
if __name__=='__main__':main()
