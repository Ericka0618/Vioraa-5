"""Enable HTTPS on the existing VIORA VM after Certbot issues its IP certificate."""
import pathlib, shutil, datetime, subprocess, json, os
ip='34.60.93.2'
cert=pathlib.Path('/etc/letsencrypt/live')/ip
assert (cert/'fullchain.pem').exists(), 'Issue the IP certificate first'
backup=pathlib.Path('/var/backups/viora')/('https-enable-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
backup.mkdir(mode=0o700)
site=pathlib.Path('/etc/apache2/sites-available/000-default.conf')
ini=pathlib.Path('/etc/php/8.3/apache2/conf.d/99-viora-production.ini')
env=pathlib.Path('/etc/viora/environment.json')
for p in [site,ini,env]:
 shutil.copy2(p,backup/p.name)
 os.chmod(backup/p.name,0o600)
original=site.read_text()
sslsite=pathlib.Path('/etc/apache2/sites-available/viora-https.conf')
assert not sslsite.exists(), 'HTTPS configuration already exists; inspect before re-running'
https=original.replace('<VirtualHost *:80>','<VirtualHost *:443>',1)
https=https.replace('    DocumentRoot',f'    SSLEngine on\n    SSLCertificateFile {cert}/fullchain.pem\n    SSLCertificateKeyFile {cert}/privkey.pem\n    SSLProtocol -all +TLSv1.2 +TLSv1.3\n    DocumentRoot',1)
sslsite.write_text(https)
http=original.replace('    RedirectMatch 302 ^/$ /login/login.php',f'    RewriteEngine On\n    RewriteCond %{{REQUEST_URI}} !^/\\.well-known/acme-challenge/\n    RewriteRule ^ https://{ip}%{{REQUEST_URI}} [R=308,L,NE]')
site.write_text(http)
subprocess.run(['a2enmod','ssl','rewrite'],check=True)
subprocess.run(['a2ensite','viora-https'],check=True)
check=subprocess.run(['apache2ctl','configtest'])
if check.returncode:
 shutil.copy2(backup/site.name,site)
 subprocess.run(['a2dissite','viora-https'],check=True)
 raise SystemExit('HTTPS validation failed; original HTTP configuration restored')
settings=json.loads(env.read_text());settings['APP_URL']='https://'+ip;env.write_text(json.dumps(settings));os.chmod(env,0o640)
ini.write_text(ini.read_text()+'\n; HTTPS is active\nsession.cookie_secure=1\n')
hooks=pathlib.Path('/etc/letsencrypt/renewal-hooks/deploy');hooks.mkdir(parents=True,exist_ok=True)
hook=hooks/'viora-apache-reload'
hook.write_text('#!/bin/sh\nset -eu\n/usr/sbin/apache2ctl configtest\n/bin/systemctl reload apache2\n')
hook.chmod(0o755)
service=pathlib.Path('/etc/systemd/system/viora-certbot-renew.service')
service.write_text('[Unit]\nDescription=Renew VIORA IP TLS certificate\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nExecStart=/opt/viora-certbot/bin/certbot renew --quiet\n')
timer=pathlib.Path('/etc/systemd/system/viora-certbot-renew.timer')
timer.write_text('[Unit]\nDescription=Check VIORA TLS renewal hourly\n\n[Timer]\nOnCalendar=hourly\nRandomizedDelaySec=600\nPersistent=true\n\n[Install]\nWantedBy=timers.target\n')
subprocess.run(['systemctl','daemon-reload'],check=True)
subprocess.run(['systemctl','enable','--now','viora-certbot-renew.timer'],check=True)
subprocess.run(['systemctl','reload','apache2'],check=True)
print('HTTPS enabled; HTTP redirects with ACME exception. Backup:',backup)