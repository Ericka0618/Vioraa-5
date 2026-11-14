"""Read-only portal requests; temporary sessions are removed on exit."""
import importlib.util, json, re, os
import requests
from paypal_cloud import ROOT, database, service

spec=importlib.util.spec_from_file_location('release',ROOT/'deployment/release-invoices.py')
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)
checks=[]
def check(name,ok):
    checks.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name);assert ok,name

with database() as (db,_,__), release.runtime_accounts(db) as accounts:
    with db.cursor() as c:
        c.execute('SELECT payload FROM viora_runtime_sessions WHERE id=%s',(accounts['Cliente'],))
        payload=c.fetchone()[0]
        if isinstance(payload,bytes):payload=payload.decode()
        uid=int(re.search(r'id_usuario\|i:(\d+);',payload).group(1))
        c.execute('SELECT persona_id FROM usuario WHERE id_usuarios=%s',(uid,));person=c.fetchone()[0]
        c.execute('SELECT id_facturas FROM factura WHERE persona_id=%s LIMIT 1',(person,));own=c.fetchone()
        c.execute('SELECT id_facturas FROM factura WHERE persona_id<>%s LIMIT 1',(person,));other=c.fetchone()
    base=os.environ.get('INVOICE_VERIFY_URL') or service()['status']['url'];session=requests.Session();session.trust_env=False
    session.cookies.set('VIORASID',accounts['Cliente'],domain=base.split('//')[1],path='/')
    if own:
        r=session.get(base+'/mantenimientos/factura_pdf.php',params={'id':own[0]},timeout=45)
        check('Public client can view its existing PDF',r.status_code==200 and r.content.startswith(b'%PDF'))
        r=session.get(base+'/mantenimientos/factura_pdf.php',params={'id':own[0],'descargar':1},timeout=45)
        check('Public client can download its existing PDF',r.status_code==200 and r.headers['Content-Disposition'].startswith('attachment'))
    check('Eligible client with historical invoice tested',own is not None)
    check('Foreign invoice fixture available',other is not None)
    r=session.get(base+'/mantenimientos/factura_pdf.php',params={'id':other[0]},timeout=45)
    check('Public foreign invoice PDF denied',r.status_code==404)
    r=session.get(base+'/mantenimientos/mis_facturas.php',params={'id':other[0]},timeout=45)
    check('Public foreign invoice detail denied',r.status_code==404)
    with db.cursor() as c:
        c.execute('SELECT contenido FROM factura_documento WHERE tipo=%s AND documento_id=%s',('factura',own[0]))
        doc=c.fetchone()[0]
    # Validate PDF cross-reference offsets independently of the renderer.
    start=int(re.search(rb'startxref\s+(\d+)\s+%%EOF',doc).group(1))
    lines=doc[start:].splitlines();first,count=map(int,lines[1].split())
    valid=lines[0]==b'xref' and first==0
    for number,line in enumerate(lines[3:3+count-1],1):
        offset=int(line[:10]);valid=valid and doc[offset:].startswith(str(number).encode()+b' 0 obj')
    check('Stored PDF has valid xref and invoice totals',valid and b'Saldo pendiente' in doc and b'VIORA' in doc)
    check('Stored PDF embeds existing VIORA logo',b'/Subtype /Image' in doc and (ROOT/'Viora/imagenes/viora.jpg').read_bytes() in doc)
    r=service();check('Fiscal issuance remains disabled',next(e for e in r['spec']['template']['spec']['containers'][0]['env'] if e['name']=='ECF_ENABLED')['value']=='false')

(ROOT/'deployment'/os.environ.get('INVOICE_VERIFY_RESULT', 'invoice-ownership-public-results.json')).write_text(json.dumps({'url':base,'checks':checks,'sessions_removed':True,'financial_posts':False},indent=2))
