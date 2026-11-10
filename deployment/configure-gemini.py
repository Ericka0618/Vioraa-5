"""Run with sudo on servidor-viora. The key is entered privately, never as an argument."""
import getpass,json,pathlib,urllib.request,urllib.error,datetime,shutil,os,tempfile,argparse
path=pathlib.Path('/etc/viora/environment.json')
settings=json.loads(path.read_text())
parser=argparse.ArgumentParser();parser.add_argument('--key-file');args=parser.parse_args()
key_file=pathlib.Path(args.key_file) if args.key_file else None
if key_file:
    try:key=key_file.read_text().strip()
    finally:key_file.unlink(missing_ok=True)
else:key=getpass.getpass('GEMINI_API_KEY (entrada oculta): ').strip()
if not key:raise SystemExit('No se guardo ninguna clave.')
model=settings.get('GEMINI_MODEL','gemini-flash-lite-latest')
request=urllib.request.Request('https://generativelanguage.googleapis.com/v1beta/models/'+model,headers={'x-goog-api-key':key})
try:
    with urllib.request.urlopen(request,timeout=30) as response: metadata=json.load(response)
except urllib.error.HTTPError as error:
    raise SystemExit('La validacion de Gemini fallo (HTTP '+str(error.code)+'). No se cambio la configuracion.')
if 'generateContent' not in metadata.get('supportedGenerationMethods',[]):raise SystemExit('El modelo no admite generateContent; configuracion sin cambios.')
test=urllib.request.Request('https://generativelanguage.googleapis.com/v1beta/models/'+model+':generateContent',data=json.dumps({'contents':[{'parts':[{'text':'Responde solamente VIORA_OK'}]}]}).encode(),headers={'x-goog-api-key':key,'Content-Type':'application/json'})
try:
    with urllib.request.urlopen(test,timeout=40) as response:answer=json.load(response)
    if not answer.get('candidates'):raise SystemExit('Gemini no devolvio respuesta. No se cambio la configuracion.')
except urllib.error.HTTPError as error:raise SystemExit('La pregunta de prueba fallo (HTTP '+str(error.code)+'). No se cambio la configuracion.')
backup=pathlib.Path('/var/backups/viora')/('gemini-env-'+datetime.datetime.now().strftime('%Y%m%d%H%M%S')+'.json')
shutil.copy2(path,backup);backup.chmod(0o600)
settings.update(GEMINI_API_KEY=key,GEMINI_MODEL=model)
fd,tmp=tempfile.mkstemp(prefix='environment-',suffix='.json',dir=path.parent)
try:
    with os.fdopen(fd,'w') as out:json.dump(settings,out)
    shutil.chown(tmp,user='root',group='www-data');os.chmod(tmp,0o640);os.replace(tmp,path)
finally:
    if os.path.exists(tmp):os.unlink(tmp)
print('Gemini configurado; modelo validado. La clave no se muestra ni se guarda en Git.')
