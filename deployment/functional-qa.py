import pathlib, subprocess, json, secrets, socket, re, datetime, base64, atexit
from html.parser import HTMLParser
import requests

BASE=pathlib.Path('/var/lib/viora-qa-20261110')
DB='viora_qa_20261110'
assert DB != 'viora'
original_dns=socket.getaddrinfo
def local_dns(host,port,*args,**kwargs):
    return original_dns('127.0.0.1' if host=='34.60.93.2' and int(port)==8443 else host,port,*args,**kwargs)
socket.getaddrinfo=local_dns
URL='https://34.60.93.2:8443'
results=[]
atexit.register(lambda: (BASE/'results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2)))
def sql(query):
    return subprocess.check_output(['mysql','-N','-B',DB],input="SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;"+query,text=True).strip()
def hx(value): return '(CONVERT(0x'+str(value).encode().hex()+' USING utf8mb4) COLLATE utf8mb4_unicode_ci)'
def check(name,ok,detail=''):
    results.append({'test':name,'passed':bool(ok),'detail':str(detail)[:200]})
    print(('PASS ' if ok else 'FAIL ')+name+(' '+str(detail)[:150] if detail else ''),flush=True)
class Inputs(HTMLParser):
    def __init__(self,html):super().__init__();self.values={};self.feed(html)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and 'name' in a:self.values[a['name']]=a.get('value','')
def call(session,path,**kw):return session.request(kw.pop('method','GET'),URL+path,timeout=40,allow_redirects=False,**kw)
def post(session,path,data):return call(session,path,method='POST',data=data)
def jpost(session,path,data):return call(session,path,method='POST',json=data)
def good(name,response,key='ok'):
    try: data=response.json()
    except Exception: data={}
    check(name,response.status_code<400 and data.get(key) is True,json.dumps(data,ensure_ascii=False)[:150]);return data

tag='QA'+secrets.token_hex(5)
password=secrets.token_urlsafe(20)
hashed=subprocess.check_output(['php','-r','echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],input=password,text=True)
admin_person=sql("INSERT INTO persona(nombres,apellidos,cedula,correo,telefono,direccion,activo) VALUES ("+','.join(map(hx,[tag,'Admin','998-'+str(secrets.randbelow(10000000)).zfill(7)+'-0',tag+'admin@example.invalid','809-000-0000','QA']))+",1); SELECT LAST_INSERT_ID()")
sql("INSERT INTO usuario(nombre,usuario,clave,id_rol,persona_id,activo,estado_cuenta) SELECT "+','.join(map(hx,[tag,tag+'_admin',hashed]))+",id_roles,"+admin_person+",1,'Activo' FROM role WHERE nombre='Administrador' LIMIT 1")
admin=requests.Session();client=requests.Session();anon=requests.Session()
r=post(admin,'/login/procesar_login.php',{'username':tag+'_admin','password':password})
check('Administrator real login',r.status_code==302 and r.headers.get('Location')=='principal.php')
reg=call(client,'/login/registro.php');fields=Inputs(reg.text).values
signup={'csrf':fields.get('csrf',''),'nombres':tag,'apellidos':'Prueba','cedula':'999-'+str(secrets.randbelow(10000000)).zfill(7)+'-0','correo':tag.lower()+'@example.invalid','telefono':'809-000-0000','direccion':'DATOS DE PRUEBA QA','usuario':tag+'_client','clave':password,'confirmar_clave':password,'terminos':'1'}
r=post(client,'/login/guardar_registro.php',signup)
check('Registration redirects to subscription',r.status_code==302 and r.headers.get('Location')=='suscripcion.php',str(r.status_code)+' '+r.headers.get('Location',''))
if r.headers.get('Location')=='registro.php':
    error=re.search(r'class="error-message">(.*?)</div>',call(client,'/login/registro.php').text,re.S)
    print('Registration diagnostic:',error[1].strip() if error else 'No error shown',flush=True)
uid=sql('SELECT id_usuarios FROM usuario WHERE usuario='+hx(tag+'_client'))
assert uid,'Registration failed; dependent tests cannot run'
(BASE/'fixture.json').write_text(json.dumps({'tag':tag,'user_id':int(uid),'admin_name':tag+'_admin','client_name':tag+'_client','password':password}));(BASE/'fixture.json').chmod(0o600)
check('New registration inactive and hashed',sql('SELECT activo=0 AND estado_cuenta=\'Pendiente de pago\' AND clave LIKE \'$2%\' FROM usuario WHERE id_usuarios='+uid)=='1')
pending=requests.Session()
r=post(pending,'/login/procesar_login.php',{'username':tag+'_client','password':password});check('Unpaid registration cannot login','error=inactive' in r.headers.get('Location',''))
r=jpost(client,'/login/verificar_suscripcion.php',{'subscriptionID':'invalid'});check('Invalid PayPal subscription rejected',r.status_code==400)
r=jpost(anon,'/login/verificar_suscripcion.php',{'subscriptionID':'I-INVALID12345'});check('Anonymous PayPal callback rejected',r.status_code==401)
# Explicit fixture activation ONLY in the isolated copy, to exercise downstream modules.
sql("UPDATE usuario SET activo=1,estado_cuenta='Activo' WHERE id_usuarios="+uid)
r=post(pending,'/login/procesar_login.php',{'username':tag+'_client','password':password});check('Active user without subscription cannot login','subscription_required' in r.headers.get('Location',''))
sql("INSERT INTO suscripcion(usuario_id,paypal_subscription_id,estado,fecha_inicio,fecha_proximo_pago) VALUES ("+uid+','+hx('QA-'+tag)+",'Activo',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY))")
r=post(client,'/login/procesar_login.php',{'username':tag+'_client','password':password});check('Subscribed Client real login',r.headers.get('Location')=='../mantenimientos/catalogo.php')
for name in ['roles','usuarios']:
    check('Client denied '+name,call(client,'/configuracion/'+name+'.php').status_code==403)
for name in ['condominio','edificio','apartamento','clientes','solicitudes','contratos','mantenimiento_mensual','reparaciones','facturacion','cxc_clientes','consulta_facturas','consulta_cobros']:
    r=call(client,'/mantenimientos/'+name+'.php');check('Client denied '+name,r.status_code in (401,403),r.status_code)
for name in ['catalogo','administrar_solicitudes','administrar_propiedad','solicitar_reparacion']:
    r=call(client,'/mantenimientos/'+name+'.php');check('Client own module '+name,r.status_code==200)
for name in ['guardar_factura','registrar_pago','reenviar_ecf','emitir_nota_credito']:
    r=jpost(client,'/mantenimientos/'+name+'.php',{});check('Client denied write '+name,r.status_code in (401,403))
condtoken=Inputs(call(admin,'/mantenimientos/condominio.php').text).values.get('csrf','')
cond={'csrf':condtoken,'ajax':'1','action':'create','nombre':tag,'descripcion':'QA','direccion':'QA','ciudad':'QA','provincia':'QA','estado':'Activo','activo':'1'}
good('Create condominium',post(admin,'/mantenimientos/condominio.php',cond))
cid=sql('SELECT id_condominios FROM condominio WHERE nombre='+hx(tag))
cond.update(action='update',id=cid,descripcion='QA EDITADO');good('Edit condominium',post(admin,'/mantenimientos/condominio.php',cond))
building={'ajax':'1','action':'create','condominio_id':cid,'nombre':tag,'cantidad_pisos':'3','estado':'Activo','activo':'1'}
good('Create building',post(admin,'/mantenimientos/edificio.php',building))
eid=sql('SELECT id_edificios FROM edificio WHERE nombre='+hx(tag))
building.update(action='update',id=eid,cantidad_pisos='4');good('Edit building',post(admin,'/mantenimientos/edificio.php',building))
apt={'ajax':'1','action':'create','edificio_id':eid,'numero':tag,'piso':'1','habitaciones':'2','banos':'1','metros_cuadrados':'80','descripcion':'QA','estado':'Disponible','activo':'1','disponible':'1','precio_alquiler':'1000','tipo_operacion':'Alquiler'}
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
r=call(admin,'/mantenimientos/apartamento.php',method='POST',data=apt,files={'imagen':('qa.png',png,'image/png')});good('Create apartment with image',r)
aid=sql('SELECT id_apartamentos FROM apartamento WHERE numero='+hx(tag));assert aid
image=sql('SELECT imagen FROM apartamento WHERE id_apartamentos='+aid)
check('Uploaded apartment image HTTPS',call(client,'/uploads/apartamentos/'+image).status_code==200)
catalog=call(client,'/mantenimientos/catalogo.php');token=Inputs(catalog.text).values.get('csrf_token','')
check('Catalog includes test apartment',tag in catalog.text)
request={'ajax':'solicitud','csrf_token':token,'tipo_solicitud':'operacion','apartamento_id':aid,'operacion':'Alquiler','observaciones':tag}
bad=dict(request,csrf_token='invalid');check('Catalog CSRF rejected',post(client,'/mantenimientos/catalogo.php',bad).status_code==400)
good('Client rental request',post(client,'/mantenimientos/catalogo.php',request))
sid=sql('SELECT id_solicitudes FROM solicitud_propiedad WHERE observaciones='+hx(tag))
requests_page=call(admin,'/mantenimientos/solicitudes.php');match=re.search(r'const\s+\w*[Cc][Ss][Rr][Ff]\w*\s*=\s*[\"\x27]([a-f0-9]+)',requests_page.text)
# The existing page exposes the synchronizer token as a JSON string in JS.
if not match:match=re.search(r'[\"\x27]([a-f0-9]{64})[\"\x27]',requests_page.text)
assert match,'Request CSRF token missing'
good('Approve rental request',post(admin,'/mantenimientos/solicitudes.php',{'ajax':'1','action':'update','csrf':match[1],'id':sid,'estado':'Aprobada'}))
contract_page=call(admin,'/mantenimientos/contratos.php');ctoken=Inputs(contract_page.text).values.get('csrf','')
pdf=subprocess.check_output(['php','-r',"require '/var/www/vioraa-5/Viora/mantenimientos/pdf_simple.php'; $p=new PdfSimple();$p->texto(20,20,'QA CONTRACT ONLY');echo $p->salida();"])
contract={'csrf':ctoken,'solicitud_id':sid,'numero_contrato':tag,'fecha_inicio':datetime.date.today().replace(day=1).isoformat(),'fecha_fin':str(datetime.date.today().year+1)+'-12-31','monto_alquiler':'1000','deposito':'1000','monto_mantenimiento':'100','condiciones':'QA'}
r=call(admin,'/mantenimientos/contratos.php',method='POST',data=contract,files={'archivo':('qa.pdf',pdf,'application/pdf')})
check('Create contract with private PDF',r.status_code==302 and 'creado=1' in r.headers.get('Location',''))
ctid=sql('SELECT id_contratos FROM contrato WHERE numero_contrato='+hx(tag));assert ctid
r=call(admin,'/mantenimientos/ver_contrato.php?id='+ctid);check('Contract PDF download',r.status_code==200 and r.content.startswith(b'%PDF-'))
check('Client denied contract PDF',call(client,'/mantenimientos/ver_contrato.php?id='+ctid).status_code==403)
period=datetime.date.today().strftime('%Y-%m')
r=jpost(admin,'/mantenimientos/guardar_factura.php',{'contrato_id':ctid,'periodo':period,'fecha':datetime.date.today().isoformat(),'conceptos':['Alquiler','Mantenimiento'],'observaciones':tag})
invoice=good('Create invoice',r,'success');fid=str(invoice.get('id_facturas',''));assert fid
check('Invoice amount with ITBIS',float(invoice['total'])==1298)
duplicate=jpost(admin,'/mantenimientos/guardar_factura.php',{'contrato_id':ctid,'periodo':period,'fecha':datetime.date.today().isoformat(),'conceptos':['Alquiler']});check('Duplicate billing rejected',not duplicate.json().get('success'))
r=call(admin,'/mantenimientos/factura_pdf.php?id='+fid);check('Invoice PDF',r.status_code==200 and r.content.startswith(b'%PDF-'))
pay={'factura_id':fid,'monto':'100','metodo_pago':'Efectivo','referencia':tag}
good('Partial invoice payment',jpost(admin,'/mantenimientos/registrar_pago.php',pay),'success')
check('Receivable balance updated',sql('SELECT estado=\'Parcial\' AND saldo_pendiente=1198 FROM factura WHERE id_facturas='+fid)=='1')
good('Final invoice payment',jpost(admin,'/mantenimientos/registrar_pago.php',dict(pay,monto='1198')),'success')
check('Invoice settled zero balance',sql('SELECT estado=\'Pagada\' AND saldo_pendiente=0 FROM factura WHERE id_facturas='+fid)=='1')
check('Overpayment rejected',not jpost(admin,'/mantenimientos/registrar_pago.php',pay).json().get('success'))
monthly=call(admin,'/mantenimientos/mantenimiento_mensual.php');mtoken=Inputs(monthly.text).values.get('csrf','')
post(admin,'/mantenimientos/mantenimiento_mensual.php',{'csrf':mtoken,'accion':'generar','mes':period})
check('Generate monthly maintenance',int(sql("SELECT COUNT(*) FROM mantenimiento WHERE condominio_id="+cid+" AND tipo='Mensual' AND periodo="+hx(period)))==1)
post(admin,'/mantenimientos/mantenimiento_mensual.php',{'csrf':mtoken,'accion':'generar','mes':period})
check('Monthly maintenance idempotent',int(sql("SELECT COUNT(*) FROM mantenimiento WHERE condominio_id="+cid+" AND tipo='Mensual' AND periodo="+hx(period)))==1)
r=jpost(client,'/mantenimientos/chatbot_api.php',{'mensaje':'Prueba QA'});check('Missing Gemini key fails safely',r.status_code==503 and 'GEMINI_API_KEY' not in r.text)
r=call(client,'/login/logout.php');check('Logout clears access',r.status_code==302 and call(client,'/mantenimientos/catalogo.php').status_code==302)
(BASE/'fixture.json').write_text(json.dumps({'tag':tag,'user_id':int(uid),'admin_name':tag+'_admin','client_name':tag+'_client','password':password}));(BASE/'fixture.json').chmod(0o600)
(BASE/'results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
print('TOTAL',len(results),'FAILED',sum(not r['passed'] for r in results),flush=True)
