"""Enter Gemini key locally; validate it and send it directly to Secret Manager."""
import getpass,json,subprocess,urllib.request
key=getpass.getpass('Gemini API key (hidden): ').strip()
if not key:raise SystemExit('Empty key; nothing changed')
req=urllib.request.Request('https://generativelanguage.googleapis.com/v1beta/models',headers={'x-goog-api-key':key})
try:
 with urllib.request.urlopen(req,timeout=30) as response:json.load(response)
except Exception:raise SystemExit('Gemini did not validate the key; nothing changed')
r=subprocess.run(['gcloud.cmd','secrets','versions','add','viora-production-gemini-api-key','--project=viora-514300','--data-file=-','--quiet'],input=key.encode(),capture_output=True)
if r.returncode:raise SystemExit('Secret Manager update failed; check account access')
print('Validated key saved privately. Redeploy production to load the new secret version.')
