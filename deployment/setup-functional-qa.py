import pathlib, subprocess, shutil, secrets, json, hashlib, os

base = pathlib.Path('/var/lib/viora-qa-20261110')
assert not base.exists(), 'QA environment already exists; do not overwrite it'
base.mkdir(mode=0o750)
shutil.chown(base, group='www-data')
dump = subprocess.check_output(['mysqldump', '--single-transaction', '--skip-comments', '--skip-add-locks', 'viora'])
(base/'production-before.sql').write_bytes(dump)
os.chmod(base/'production-before.sql', 0o600)
print('Production snapshot SHA256:', hashlib.sha256(dump).hexdigest())
password = secrets.token_hex(32)
subprocess.run(['mysql'], input="CREATE DATABASE viora_qa_20261110; CREATE USER 'viora_qa'@'localhost' IDENTIFIED BY '"+password+"'; GRANT ALL ON viora_qa_20261110.* TO 'viora_qa'@'localhost';", text=True, check=True, capture_output=True)
subprocess.run(['mysql', 'viora_qa_20261110'], input=dump, check=True)
app = base/'app'
app.mkdir()
shutil.copytree('/var/www/vioraa-5/Viora', app/'Viora', ignore=shutil.ignore_patterns('uploads'))
for folder in [app/'Viora/uploads', app/'viora_documentos_privados', base/'sessions']:
    folder.mkdir(mode=0o700)
    shutil.chown(folder, user='www-data', group='www-data')
settings = json.loads(pathlib.Path('/etc/viora/environment.json').read_text())
assert settings.get('PAYPAL_MODE') == 'sandbox'
settings.update(DB_NAME='viora_qa_20261110', DB_USER='viora_qa', DB_PASSWORD=password, APP_URL='https://34.60.93.2:8443', ECF_ENABLED='false', GEMINI_API_KEY='')
env = base/'environment.json'
env.write_text(json.dumps(settings)); env.chmod(0o640); shutil.chown(env, group='www-data')
bootstrap = base/'bootstrap.php'
bootstrap.write_text("<?php\nforeach (json_decode(file_get_contents('"+str(env)+"'), true) as $k=>$v) { putenv($k.'='.$v); }\n")
bootstrap.chmod(0o640); shutil.chown(bootstrap, group='www-data')
conn = app/'Viora/conf/conexion.php'
conn.write_text(conn.read_text().replace('/etc/viora/bootstrap.php', str(bootstrap)))
original = pathlib.Path('/etc/apache2/sites-available/viora-https.conf').read_text()
qa = original.replace('<VirtualHost *:443>', 'Listen 127.0.0.1:8443\n<VirtualHost 127.0.0.1:8443>')
qa = qa.replace('/var/www/vioraa-5/Viora', str(app/'Viora')).replace('${APACHE_LOG_DIR}/viora-', '${APACHE_LOG_DIR}/viora-qa-')
qa = qa.replace('</VirtualHost>', f'    php_admin_value auto_prepend_file {bootstrap}\n    php_admin_value session.save_path {base}/sessions\n</VirtualHost>')
pathlib.Path('/etc/apache2/sites-available/viora-qa.conf').write_text(qa)
subprocess.run(['a2ensite', 'viora-qa'], check=True)
subprocess.run(['apache2ctl', 'configtest'], check=True)
subprocess.run(['systemctl', 'reload', 'apache2'], check=True)
print('Private QA ready on 127.0.0.1:8443; separate database/user, sessions, uploads and documents.')
