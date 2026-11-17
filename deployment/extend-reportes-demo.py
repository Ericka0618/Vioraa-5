"""Carga adicional aislada y verificación del reporte publicado, sin desplegar código web."""
import importlib.util,json,pathlib,re,subprocess,time
import pymysql,requests
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('bi_release',ROOT/'deployment/release-reportes.py')
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)

def main():
 backups=json.loads(release.cli('sql','backups','list','--instance=viora-staging-db','--limit=5','--format=json'))
 backup=next((b for b in backups if b.get('description')=='Antes de ampliar demostracion BI con propiedades disponibles'),None)
 if not backup or backup['status']!='SUCCESSFUL':raise RuntimeError('Debe finalizar el respaldo de Cloud SQL antes de esta carga.')
 with release.cloud() as env:
  db=pymysql.connect(host=env['DB_HOST'],port=int(env['DB_PORT']),user=env['DB_USER'],password=env['DB_PASSWORD'],database=env['DB_NAME'],autocommit=True)
  def query(sql,args=()):
   with db.cursor() as c:c.execute(sql,args);return c.fetchall()
  originals=['condominio','edificio','apartamento','persona','usuario','propiedad','contrato','factura','factura_pago','ocupacion','solicitud_propiedad','solicitud_mantenimiento','mantenimiento','suscripcion']
  before={t:query('SELECT COUNT(*) FROM '+t)[0][0] for t in originals}
  def seed():
   out=subprocess.check_output(['C:/xampp/php/php.exe',str(ROOT/'deployment/seed-reportes-disponibles.php')],env=env,text=True,encoding='utf-8')
   return json.loads(out)
  first=seed();second=seed()
  assert not second['agregados'] and first['totales']==second['totales'],'La carga no es idempotente.'
  assert all(n>=60 for n in first['totales'].values()) and first['propiedades_disponibles']>=40
  assert before=={t:query('SELECT COUNT(*) FROM '+t)[0][0] for t in originals},'Cambió el conteo de una tabla original durante la comprobación.'
  accounts=query("SELECT u.usuario,u.clave FROM usuario u JOIN role r ON r.id_roles=u.id_rol WHERE u.activo=1 AND LOWER(r.nombre) IN ('admin','administrador') ORDER BY u.id_usuarios")
  account=next((a for a in accounts if not a[1].startswith(('$','DEMO_'))),None)
  if not account:raise RuntimeError('No hay inicio de sesión verificable para comprobar el reporte publicado.')
  base='https://viora-6ikx2ezlba-uc.a.run.app';session=requests.Session()
  session.get(base+'/login/login.php',timeout=30)
  login=session.post(base+'/login/procesar_login.php',data={'username':account[0],'password':account[1]},timeout=30)
  assert login.status_code==200 and 'principal.php' in login.url
  page=session.get(base+'/mantenimientos/reportes.php',timeout=30)
  token=re.search(r'id="bi"\s+data-csrf="([^"]+)"',page.text)
  assert token,'No se encontró el formulario autorizado de reportes.'
  response=session.post(base+'/mantenimientos/reportes_api.php?accion=generar',json={'reporte':11,'filtros':{'fuente':'demo'}},headers={'X-CSRF-Token':token.group(1)},timeout=90)
  response.raise_for_status();report=response.json()
  assert sum(r['Disponibles'] for r in report['reporte']['filas'])==first['propiedades_disponibles']
  pdf=session.get(base+'/mantenimientos/reportes_api.php?accion=pdf&id='+str(report['historial_id']),timeout=30)
  assert pdf.status_code==200 and pdf.content.startswith(b'%PDF-')
  evidence=first|{'backup_id':backup['id'],'segunda_ejecucion_sin_duplicados':True,'tablas_originales_sin_cambios_de_conteo':True,'reporte_publicado_verificado':True,'pdf_verificado':True}
  (ROOT/'deployment/reportes-demo-ampliacion.json').write_text(json.dumps(evidence,ensure_ascii=False,indent=2),encoding='utf-8')
  print(json.dumps({'disponibles':first['propiedades_disponibles'],'registros_agregados':sum(first['agregados'].values()),'totales':first['totales'],'reporte_publicado_y_pdf':'verificados'},ensure_ascii=False,indent=2))
  db.close()
if __name__=='__main__':main()
