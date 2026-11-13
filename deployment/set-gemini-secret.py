"""Validate Gemini text and VIORA function calls before storing a key. Never deploys."""
import argparse
import datetime
import getpass
import json
import pathlib
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

PROJECT = 'viora-514300'
SECRET = 'viora-production-gemini-api-key'
BASE = 'https://generativelanguage.googleapis.com/v1beta/'

def request(key, path, payload=None):
    req = urllib.request.Request(BASE + path, data=None if payload is None else json.dumps(payload).encode(),
        headers={'x-goog-api-key': key, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=45) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        hint = {400:'Revisa que sea una clave Gemini API.',403:'Revisa restricciones de la clave y permisos de Generative Language API.',
                404:'El modelo no esta disponible para esta cuenta.',429:'Revisa cuota y facturacion de Gemini en AI Studio.'}.get(error.code,'Intenta de nuevo cuando el proveedor este disponible.')
        raise RuntimeError('Gemini HTTP ' + str(error.code) + '. ' + hint + ' Secreto sin cambios.') from None
    except Exception:
        raise RuntimeError('Error de conexion/respuesta Gemini; secreto sin cambios.') from None

def validate(key, requested=None):
    models = []
    path = 'models?pageSize=100'
    while path:
        result = request(key, path)
        models.extend(m['name'].removeprefix('models/') for m in result.get('models', [])
                      if 'generateContent' in m.get('supportedGenerationMethods', []))
        token = result.get('nextPageToken')
        path = 'models?pageSize=100&pageToken=' + urllib.parse.quote(token, safe='') if token else None
    preferred = ['gemini-flash-lite-latest', 'gemini-2.5-flash-lite', 'gemini-flash-latest']
    fallback = sorted(m for m in models if 'flash' in m and not any(x in m for x in ['image','tts','live','preview','experimental']))
    model = requested or next((m for m in preferred if m in models), next(iter(fallback), None))
    if not model or model not in models:
        raise RuntimeError('Modelo no disponible. Usa --model con un modelo generateContent de tu cuenta.')
    path = 'models/' + model + ':generateContent'
    result = request(key, path, {'contents': [{'role':'user', 'parts':[{'text':'Responde en espanol: que hace un asistente de administracion de condominios?'}]}],
                                'generationConfig': {'maxOutputTokens':1024}})
    parts = result.get('candidates', [{}])[0].get('content', {}).get('parts', [])
    if not any(p.get('text') and not p.get('thought') for p in parts):
        raise RuntimeError('Gemini no devolvio texto; secreto sin cambios.')
    root = pathlib.Path(__file__).resolve().parents[1]
    for declaration, function in [('chatbot_declaraciones_cliente','opciones_disponibles'),
                                  ('chatbot_declaraciones_herramientas','resumen_general')]:
        code = "require 'Viora/mantenimientos/chatbot_herramientas.php'; echo json_encode("+declaration+"());"
        tools = json.loads(subprocess.check_output(['C:/xampp/php/php.exe', '-r', code], cwd=root, text=True))
        result = request(key, path, {
            'contents': [{'role':'user','parts':[{'text':'Usa la herramienta '+function+'.'}]}],
            'tools': tools,
            'toolConfig': {'functionCallingConfig': {'mode':'ANY', 'allowedFunctionNames':[function]}},
            'generationConfig': {'maxOutputTokens':1024}})
        parts = result.get('candidates', [{}])[0].get('content', {}).get('parts', [])
        if not any(p.get('functionCall', {}).get('name') == function for p in parts):
            raise RuntimeError('Gemini no produjo una llamada compatible con VIORA; secreto sin cambios.')
    return model

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--model')
    parser.add_argument('--validate-existing', action='store_true', help='Validate the stored secret without adding a version')
    args = parser.parse_args()
    version = None
    if args.validate_existing:
        result = subprocess.run(['gcloud.cmd','secrets','versions','access','latest','--secret='+SECRET,'--project='+PROJECT], capture_output=True)
        if result.returncode: raise RuntimeError('No se pudo leer el secreto existente.')
        key = result.stdout.decode().strip()
        result = subprocess.run(['gcloud.cmd','secrets','versions','describe','latest','--secret='+SECRET,
                                 '--project='+PROJECT,'--format=value(name)'],capture_output=True,text=True)
        if result.returncode:raise RuntimeError('No se pudo identificar la version del secreto.')
        version=result.stdout.strip().rsplit('/',1)[-1]
    else:
        if not sys.stdin.isatty(): raise RuntimeError('Ejecuta en una terminal interactiva para ocultar la clave.')
        key = getpass.getpass('Clave API Gemini (entrada oculta): ').strip()
    if not key or key == 'UNCONFIGURED': raise RuntimeError('Falta una clave valida; no se realizaron cambios.')
    model = validate(key, args.model)
    if not args.validate_existing:
        result = subprocess.run(['gcloud.cmd','secrets','versions','add',SECRET,'--project='+PROJECT,
                                 '--data-file=-','--format=value(name)','--quiet'], input=key.encode(), capture_output=True)
        if result.returncode: raise RuntimeError('No se pudo guardar el secreto. Comprueba los permisos de tu cuenta.')
        version = result.stdout.decode().strip().rsplit('/', 1)[-1]
    receipt = {'project':PROJECT,'secret':SECRET,'version':version,'model':model,
               'validated_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
               'real_text_response':True,'real_viora_function_call':True,'deployed':False}
    (pathlib.Path(__file__).parent/'gemini-validation.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8')
    print('Gemini respondio y acepto herramientas VIORA. Modelo: ' + model)
    print('Validacion guardada sin claves. Falta desplegar y comprobar el flujo completo.')

if __name__ == '__main__':
    try: main()
    except (RuntimeError, subprocess.SubprocessError, ValueError) as error:
        raise SystemExit(str(error)) from None
