import os, pathlib, datetime, shutil, subprocess, secrets, json
root=pathlib.Path('/var/www/vioraa-5')
if pathlib.Path('/etc/apache2/sites-enabled/viora-https.conf').exists():
    raise SystemExit('HTTPS is already configured. This initial HTTP setup script must not overwrite the active TLS deployment.')
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup=pathlib.Path('/var/backups/viora')/stamp
backup.mkdir(parents=True,mode=0o700)
os.chmod(backup,0o700)
files=['/etc/apache2/sites-available/000-default.conf',str(root/'Viora/conf/conexion.php')]
for f in files:
    shutil.copy2(f,backup/pathlib.Path(f).name)
with open(backup/'viora.sql','wb') as out:
    subprocess.run(['mysqldump','--single-transaction','--routines','--triggers','viora'],stdout=out,check=True)
os.chmod(backup/'viora.sql',0o600)
private=pathlib.Path('/etc/viora')
private.mkdir(mode=0o750,exist_ok=True)
shutil.chown(private,user='root',group='www-data')
config=private/'environment.json'
if not config.exists():
    password=secrets.token_hex(32)
    values={'DB_HOST':'localhost','DB_USER':'viora_user','DB_PASSWORD':password,'DB_NAME':'viora','APP_ENV':'production','APP_URL':'http://34.60.93.2','ECF_ENABLED':'false','PAYPAL_MODE':'sandbox'}
    config.write_text(json.dumps(values),encoding='utf-8')
    os.chmod(config,0o640)
    shutil.chown(config,user='root',group='www-data')
    subprocess.run(['mysql'],input="ALTER USER 'viora_user'@'localhost' IDENTIFIED BY '"+password+"' RETAIN CURRENT PASSWORD;",text=True,check=True,capture_output=True)
loader=private/'bootstrap.php'
loader.write_text('''<?php
$settings = json_decode(file_get_contents('/etc/viora/environment.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($settings as $name => $value) { putenv($name . '=' . $value); }
unset($settings, $name, $value);
''')
os.chmod(loader,0o640)
shutil.chown(loader,user='root',group='www-data')
conn=root/'Viora/conf/conexion.php'
conn.write_text('''<?php
if (is_file('/etc/viora/bootstrap.php')) { require_once '/etc/viora/bootstrap.php'; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conexion = new mysqli(getenv('DB_HOST') ?: 'localhost', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', getenv('DB_NAME') ?: 'viora');
    $conexion->set_charset('utf8mb4');
} catch (mysqli_sql_exception $error) {
    error_log('VIORA database connection failed: ' . $error->getCode());
    http_response_code(503);
    exit('Servicio temporalmente no disponible.');
}
''')
site=pathlib.Path('/etc/apache2/sites-available/000-default.conf')
site.write_text('''<VirtualHost *:80>
    ServerName 34.60.93.2
    DocumentRoot /var/www/vioraa-5/Viora
    RedirectMatch 302 ^/$ /login/login.php
    <Directory /var/www/vioraa-5/Viora>
        Options -Indexes -ExecCGI +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    <Directory /var/www/vioraa-5/Viora/conf>
        Require all denied
    </Directory>
    <Directory /var/www/vioraa-5/Viora/uploads>
        Options -Indexes -ExecCGI
        AllowOverride None
        <FilesMatch "(?i)\\.(php[0-9]?|phtml|pht|phar|cgi|pl|py|sh)(\\.|$)">
            Require all denied
        </FilesMatch>
    </Directory>
    <FilesMatch "(?i)(^\\.|\\.(sql|bak|backup|env|ini|log|conf|pem|key|old|swp)$|~$)">
        Require all denied
    </FilesMatch>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    ErrorLog ${APACHE_LOG_DIR}/viora-error.log
    CustomLog ${APACHE_LOG_DIR}/viora-access.log combined
</VirtualHost>
''')
ini=pathlib.Path('/etc/php/8.3/apache2/conf.d/99-viora-production.ini')
if ini.exists(): shutil.copy2(ini,backup/ini.name)
ini.write_text('''display_errors=Off
display_startup_errors=Off
log_errors=On
expose_php=Off
session.cookie_httponly=1
session.cookie_samesite=Lax
session.use_strict_mode=1
auto_prepend_file=/etc/viora/bootstrap.php
''')
for folder in [root/'Viora/uploads',root/'viora_documentos_privados']:
    folder.mkdir(exist_ok=True)
    shutil.chown(folder,user='www-data',group='www-data')
    os.chmod(folder,0o750)
subprocess.run(['a2enmod','headers'],check=True,stdout=subprocess.DEVNULL)
subprocess.run(['apache2ctl','configtest'],check=True)
subprocess.run(['systemctl','reload','apache2'],check=True)
print('Backup:',backup)
print('Production configuration applied; secrets withheld; previous database password retained by MySQL.')