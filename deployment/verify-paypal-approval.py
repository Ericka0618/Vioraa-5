import pathlib,json,socket,requests,subprocess
base=pathlib.Path('/var/lib/viora-qa-20261110')
fixture=json.loads((base/'paypal-fixture.json').read_text())
settings=json.loads((base/'environment.json').read_text());assert settings['DB_NAME']=='viora_qa_20261110'
old=socket.getaddrinfo
socket.getaddrinfo=lambda h,p,*a,**k:old('127.0.0.1' if h=='34.60.93.2' and int(p)==8443 else h,p,*a,**k)
session=requests.Session();session.cookies.update(fixture['cookies']);url='https://34.60.93.2:8443'
r=session.post(url+'/login/verificar_suscripcion.php',json={'subscriptionID':fixture['subscription_id']},timeout=45)
print('VIORA verification HTTP',r.status_code,r.json())
if not (r.status_code==200 and r.json().get('success')):
    api='https://api-m.sandbox.paypal.com'
    auth=requests.post(api+'/v1/oauth2/token',auth=(settings['PAYPAL_CLIENT_ID'],settings['PAYPAL_CLIENT_SECRET']),data={'grant_type':'client_credentials'},timeout=30);auth.raise_for_status()
    detail=requests.get(api+'/v1/billing/subscriptions/'+fixture['subscription_id'],headers={'Authorization':'Bearer '+auth.json()['access_token']},timeout=30);detail.raise_for_status()
    state=detail.json()
    print('PayPal authoritative status:',state.get('status'),'has_payment:',bool(state.get('billing_info',{}).get('last_payment')))
    raise SystemExit(2)
row=subprocess.check_output(['mysql','-N','viora_qa_20261110'],input='SELECT u.activo,u.estado_cuenta,s.estado,s.monto,s.moneda FROM usuario u JOIN suscripcion s ON s.usuario_id=u.id_usuarios WHERE u.id_usuarios='+str(fixture['user_id'])+" AND s.paypal_subscription_id='"+fixture['subscription_id']+"';",text=True).strip()
assert row=='1\tActivo\tActivo\t10.00\tUSD',row
print('PASS PayPal Sandbox payment persisted; account and subscription ACTIVE, USD 10.00')
login=requests.Session();r=login.post(url+'/login/procesar_login.php',data={'username':fixture['username'],'password':fixture['password']},allow_redirects=False)
assert r.status_code==302 and r.headers.get('Location')=='../mantenimientos/catalogo.php'
assert login.get(url+'/mantenimientos/catalogo.php').status_code==200
print('PASS Paid Sandbox client real login and catalog access')
(base/'paypal-result.json').write_text(json.dumps({'verification':True,'local_payment':True,'client_login':True,'catalog_access':True,'environment':'sandbox'}))
