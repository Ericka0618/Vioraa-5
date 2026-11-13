"""Visible, human-approved Sandbox checkout via the actual public VIORA PayPal button."""
import json
import time
from playwright.sync_api import sync_playwright
from paypal_cloud import ROOT,private_dir

def main():
    private=private_dir();fixture=json.loads((private/'public-web-fixture.json').read_text())
    base='https://viora-6ikx2ezlba-uc.a.run.app';results=[];verified=[];created=[]
    def check(name,ok):
        results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
        if not ok:raise RuntimeError(name)
    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=False)
            context=browser.new_context();page=context.new_page()
            def response(r):
                try:
                    if r.url.endswith('/login/verificar_suscripcion.php') and r.request.method=='POST':
                        data=r.json()
                        if r.status==200 and data.get('success'):verified.append(True)
                    if '/v1/billing/subscriptions' in r.url and r.request.method=='POST' and r.status==201:
                        data=r.json()
                        if data.get('id','').startswith('I-'):created.append(data['id'])
                except Exception:pass
            page.on('response',response)
            page.goto(base+'/login/login.php');page.fill('#username',fixture['username']);page.fill('#password',fixture['password'])
            with page.expect_navigation():page.locator('button[type=submit]').first.click()
            check('Existing pending account resumes actual public subscription',page.url.endswith('/login/suscripcion.php'))
            frame=page.frame_locator('#paypal-button-container iframe').first
            frame.locator('[data-funding-source="paypal"]').first.wait_for(timeout=45000)
            with page.expect_popup(timeout=60000) as popup:
                frame.locator('[data-funding-source="paypal"]').first.click()
            checkout=popup.value
            print('BUYER_WINDOW_READY: approve only Sandbox in the visible Chrome window.',flush=True)
            until=time.monotonic()+600
            while time.monotonic()<until and not verified:
                page.wait_for_timeout(500)
                if page.is_closed():break
            check('Actual SDK onApprove verified payment at public backend',bool(verified))
            page.wait_for_url('**/login/login.php',timeout=45000)
            page.fill('#username',fixture['username']);page.fill('#password',fixture['password'])
            with page.expect_navigation():page.locator('button[type=submit]').first.click()
            check('New buyer can access actual public catalog after checkout','/mantenimientos/catalogo.php' in page.url)
            check('Gemini widget still present for subscribed client',page.locator('#cb-widget').count()==1)
            (private/'public-active-cookies.json').write_text(json.dumps(context.storage_state()))
            browser.close()
    finally:
        (ROOT/'deployment/paypal-buyer-browser-results.json').write_text(json.dumps({'public_url':base,'payment_callback_verified':bool(verified),'checks':results},indent=2))

if __name__=='__main__':
    try:main()
    except Exception as e:raise SystemExit('Buyer UI test stopped: '+type(e).__name__+'. No private checkout data printed.')
