# HTTPS de VIORA: configurado y verificado

Fecha: 10 de noviembre de 2026, America/Santo_Domingo.
URL final: https://34.60.93.2/login/login.php
Estado: HTTPS activo y verificado. Esto resuelve el pendiente de cifrado; las integraciones y pruebas de escritura pendientes del informe general siguen pendientes.

## Solución elegida

Certificado público gratuito de Let's Encrypt para la dirección IP, sin dominio ni subdominio y sin otra VM, proxy o balanceador. Certbot 5.9.0 se instaló en /opt/viora-certbot, un entorno Python aislado. No se modificaron el diseño ni los flujos de la aplicación.

Se reservó la IP existente como viora-public-ip en us-central1. La dirección sigue siendo 34.60.93.2 y su estado es IN_USE en servidor-viora. Se conserva así al detener/iniciar la VM. Según las tarifas de Google Cloud, las IP estáticas y temporales en uso tienen la misma tarifa; el certificado es gratuito, pero los cargos de infraestructura existentes permanecen. Una IP estática puede seguir generando cargos aunque la VM esté detenida; revisar la facturación habitual.

No es necesario registrar un dominio ni realizar autorizaciones adicionales para usar esta URL.

## Certificado y Apache

- Emisor: Let's Encrypt, YE1.
- SAN: IP Address:34.60.93.2. Se verificó el nombre/IP y la cadena de confianza desde Windows, sin omitir la validación TLS.
- Certificado: /etc/letsencrypt/live/34.60.93.2/fullchain.pem.
- Clave privada: /etc/letsencrypt/live/34.60.93.2/privkey.pem, gestionada por Certbot. No se copió al repositorio ni se mostró.
- Vigencia inicial: hasta 16 de noviembre de 2026, 19:16:55 UTC (15:16:55 en Santo Domingo). La renovación automática sustituye estos archivos antes de caducar.
- Sitio HTTPS activo: /etc/apache2/sites-available/viora-https.conf, TLS 1.2/1.3.
- HTTP redirige con 308 a la misma IP y ruta por HTTPS, excepto el desafío ACME.
- /etc/apache2/conf-available/viora-acme.conf sirve exclusivamente /.well-known/acme-challenge/ desde /var/lib/letsencrypt/.well-known/acme-challenge/.
- APP_URL actualizado a https://34.60.93.2 en el archivo privado /etc/viora/environment.json.
- Cookies de sesión: Secure, HttpOnly y SameSite=Lax. Se conservan las restricciones de conf, SQL, archivos ocultos y uploads.
- No se cambiaron credenciales de usuarios, validaciones de suscripción, datos, diseño ni funcionalidades.

## Renovación automática y mantenimiento

Los certificados para IP tienen una duración corta, de aproximadamente seis días. Es indispensable mantener la automatización.

- Timer habilitado y activo: viora-certbot-renew.timer; comprobación cada hora con hasta diez minutos de variación y Persistent=true.
- Servicio: viora-certbot-renew.service.
- Comando: /opt/viora-certbot/bin/certbot renew --quiet.
- Hook posterior a una renovación: /etc/letsencrypt/renewal-hooks/deploy/viora-apache-reload. Ejecuta apache2ctl configtest antes de recargar Apache.
- Se ejecutó certbot renew --dry-run --run-deploy-hooks: la renovación simulada y la recarga de Apache pasaron.
- También se ejecutó el servicio real manualmente: Result=success, ExecMainStatus=0. No se forzó otra emisión real innecesaria.
- Revisar periódicamente: systemctl status viora-certbot-renew.timer; journalctl -u viora-certbot-renew.service; /var/log/letsencrypt/letsencrypt.log.
- Mantener el puerto 80 disponible para ACME y el 443 para HTTPS; no bloquear el desafío ni desactivar el timer. Certbot decide cuándo emitir; comprobar cada hora no solicita un certificado nuevo cada hora.
- Actualizar Certbot periódicamente dentro de /opt/viora-certbot y volver a probar la renovación. No hay actualización automática del paquete Python configurada.

## Pruebas realizadas

- Desde fuera de la VM: login HTTPS HTTP 200 con validación TLS correcta; CSS e imagen HTTP 200; configuración privada HTTP 403.
- HTTP del login devuelve 308 con Location: https://34.60.93.2/login/login.php.
- El navegador recibe cookie Secure; los recursos propios revisados no contienen enlaces HTTP explícitos.
- Se repitieron por HTTPS las pruebas de lectura con sesiones temporales de Administrador y Cliente: paneles, módulos, permisos de acceso y PDF de factura existente funcionan como antes.
- Se conservan 35 tablas, 264 registros y 49 relaciones sin referencias huérfanas.
- apache2ctl configtest devuelve Syntax OK; Apache, MySQL y el timer están activos.
- No se realizaron nuevas escrituras de negocio ni se modificaron contraseñas para estas pruebas. Las pruebas con sesiones temporales no sustituyen una prueba personal de inicio de sesión.

## Respaldos y archivos locales

Copias previas a HTTPS: /var/backups/viora/https-20261110/ y /var/backups/viora/https-enable-20261110T041536Z/. Incluyen la configuración HTTP anterior, PHP y entorno privado. No publicar esos respaldos.

Implementación local: deployment/enable-ip-https.py. Requiere un certificado ya emitido y no vuelve a sobrescribir un sitio HTTPS existente. deployment/verify-production.php ahora prueba HTTPS por defecto; VIORA_TEST_URL permite seleccionar otra URL para un diagnóstico explícito.

Se protegió deployment/configure-existing-vm.py para que se detenga si detecta el sitio HTTPS activo: no usar el antiguo script HTTP para reconfigurar este servidor. Las plantillas .disabled anteriores son históricas y no se deben habilitar encima del sitio HTTPS vigente.

## Si agregas un dominio más adelante

Apuntar su registro A a 34.60.93.2, emitir un certificado para ese nombre, actualizar ServerName, rutas de certificado y APP_URL, y validar antes de recargar Apache. Se puede mantener la IP HTTPS durante la transición. No es necesario comprar ni configurar un dominio ahora.

Referencias oficiales:
- https://letsencrypt.org/2026/03/11/shorter-certs-certbot
- https://cloud.google.com/vpc/pricing