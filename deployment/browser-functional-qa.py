import pathlib,subprocess,json
from playwright.sync_api import sync_playwright
gcloud=r'C:\Program Files (x86)\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd'
fixture=json.loads(subprocess.check_output([gcloud,'compute','ssh','servidor-viora','--project=viora-514300','--zone=us-central1-a','--quiet','--command=sudo cat /var/lib/viora-qa-20261110/fixture.json'],text=True))
results=[]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=r'C:\Program Files\Google\Chrome\Application\chrome.exe',headless=True)
    public=browser.new_context()
    page=public.new_page();r=page.goto('https://34.60.93.2/login/login.php')
    results.append({'test':'Public login valid HTTPS in Chrome','passed':r.status==200 and page.locator('#loginForm').is_visible()})
    public.close()
    # Only the SSH loopback test endpoint uses a hostname different from the IP certificate.
    context=browser.new_context(ignore_https_errors=True)
    page=context.new_page();errors=[];failed=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('response',lambda r:failed.append({'url':r.url,'status':r.status}) if r.status>=400 and '127.0.0.1:18443' in r.url else None)
    base='https://127.0.0.1:18443'
    page.goto(base+'/login/login.php');page.fill('#username',fixture['admin_name']);page.fill('#password',fixture['password'])
    with page.expect_navigation():page.locator('#btn-login,button[type=submit]').first.click()
    results.append({'test':'Administrator browser login','passed':'principal.php' in page.url})
    for name in ['condominio','edificio','apartamento','catalogo','solicitudes','contratos','mantenimiento_mensual','reparaciones','facturacion','consulta_facturas','cxc_clientes']:
        r=page.goto(base+'/mantenimientos/'+name+'.php');page.wait_for_timeout(400)
        results.append({'test':'Browser module '+name,'passed':r.status==200 and len(page.locator('body').inner_text())>100})
    page.goto(base+'/mantenimientos/condominio.php')
    row=page.locator('tr').filter(has_text=fixture['tag']).first
    row.locator('.btn-edit').click();page.wait_for_timeout(800)
    results.append({'test':'Condominium edit button AJAX on Linux','passed':page.locator('#fldNombre').input_value()==fixture['tag']})
    page.locator('#fldDescripcion').fill('QA edit through browser')
    with page.expect_navigation():
        page.locator('#frmCondominio button[type=submit]').click()
    page.locator('tr').filter(has_text=fixture['tag']).first.locator('.btn-edit').click()
    page.wait_for_timeout(800)
    results.append({'test':'Condominium save form with CSRF','passed':page.locator('#fldDescripcion').input_value()=='QA edit through browser'})
    context.close()
    context=browser.new_context(ignore_https_errors=True);page=context.new_page()
    page.goto(base+'/login/login.php');page.fill('#username',fixture['client_name']);page.fill('#password',fixture['password'])
    with page.expect_navigation():page.locator('button[type=submit]').first.click()
    results.append({'test':'Client browser login','passed':'catalogo.php' in page.url})
    results.append({'test':'Client menu excludes admin configuration','passed':page.locator('a[href*="configuracion/usuarios.php"]').count()==0})
    results.append({'test':'No browser JavaScript exceptions','passed':not errors,'details':errors})
    results.append({'test':'No missing local browser resources','passed':not failed,'details':failed})
    browser.close()
pathlib.Path('deployment/browser-qa-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
for result in results:print(('PASS ' if result['passed'] else 'FAIL ')+result['test'])
