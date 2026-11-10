"""Create only a Sandbox subscription; buyer approval is intentionally manual."""
import pathlib,json,requests,secrets,socket,subprocess,re
from html.parser import HTMLParser
base=pathlib.Path('/var/lib/viora-qa-20261110')
env=json.loads((base/'environment.json').read_text())
assert env['PAYPAL_MODE']=='sandbox' and env['DB_NAME']=='viora_qa_20261110'
api='https://api-m.sandbox.paypal.com'
old=socket.getaddrinfo
socket.getaddrinfo=lambda host,port,*a,**k:old('127.0.0.1' if host=='34.60.93.2' and int(port)==8443 else host,port,*a,**k)
session=requests.Session();url='https://34.60.93.2:8443'
class Fields(HTMLParser):
 def __init__(self,html):super().__init__();self.fields={};self.feed(html)
 def handle_starttag(self,tag,attrs):
  a=dict(attrs)
  if tag=='input' and a.get('name'):self.fields[a['name']]=a.get('value','')
token=Fields(session.get(url+'/login/registro.php').text).fields['csrf']
name='QA_paypal_'+secrets.token_hex(4);password=secrets.token_urlsafe(20)
data={'csrf':token,'nombres':name,'apellidos':'Sandbox','cedula':'997-'+str(secrets.randbelow(10000000)).zfill(7)+'-0','correo':name+'@example.invalid','telefono':'809-000-0000','direccion':'QA SOLO SANDBOX','usuario':name,'clave':password,'confirmar_clave':password,'terminos':'1'}
r=session.post(url+'/login/guardar_registro.php',data=data,allow_redirects=False);assert r.headers.get('Location')=='suscripcion.php'
uid=int(subprocess.check_output(['mysql','-N','viora_qa_20261110'],input="SELECT id_usuarios FROM usuario WHERE usuario='"+name+"';",text=True))
token=requests.post(api+'/v1/oauth2/token',auth=(env['PAYPAL_CLIENT_ID'],env['PAYPAL_CLIENT_SECRET']),data={'grant_type':'client_credentials'},timeout=30);token.raise_for_status()
headers={'Authorization':'Bearer '+token.json()['access_token'],'PayPal-Request-Id':secrets.token_hex(16)}
r=requests.post(api+'/v1/billing/subscriptions',headers=headers,json={'plan_id':env['PAYPAL_PLAN_ID'],'custom_id':'viora-user-'+str(uid),'application_context':{'brand_name':'VIORA Sandbox QA','user_action':'SUBSCRIBE_NOW','return_url':'https://34.60.93.2/login/login.php?sandbox_qa=approved','cancel_url':'https://34.60.93.2/login/login.php?sandbox_qa=cancelled'}},timeout=30)
assert r.status_code==201, 'Sandbox subscription creation failed HTTP '+str(r.status_code)
subscription=r.json();approve=next(x['href'] for x in subscription['links'] if x['rel']=='approve')
assert approve.startswith('https://www.sandbox.paypal.com/')
fixture={'user_id':uid,'username':name,'password':password,'subscription_id':subscription['id'],'approval_url':approve,'cookies':session.cookies.get_dict()}
file=base/'paypal-fixture.json';file.write_text(json.dumps(fixture));file.chmod(0o600)
print('Sandbox subscription created. State:',subscription['status'])
print('Approval URL:',approve)
# Unapproved subscriptions must never activate a VIORA account.
response=session.post(url+'/login/verificar_suscripcion.php',json={'subscriptionID':subscription['id']},timeout=45)
assert response.status_code==409, 'Unapproved subscription was not rejected'
print('Unapproved Sandbox subscription correctly rejected: HTTP 409')
