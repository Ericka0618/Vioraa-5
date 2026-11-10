# VIORA: estado del despliegue en la VM existente

Revisión: 10 de noviembre de 2026. Estado: HTTPS activo y verificado; integraciones y pruebas completas pendientes según este informe.
URL: https://34.60.93.2/login/login.php
Proyecto: viora-514300. VM: servidor-viora, us-central1-a. No se crearon recursos nuevos ni se compró un dominio.

## Actualización funcional

El 10 de noviembre se completaron 89 comprobaciones funcionales, de seguridad y de Chrome, con datos aislados de producción. Se corrigieron contratos, PDF, rutas Linux, CSRF de condominios, fotos faltantes y validación de fecha de suscripción. Consultar [FUNCTIONAL_REPORT.md](FUNCTIONAL_REPORT.md) para resultados, limitaciones y pendientes de PayPal/Gemini.

## Configuración realizada

- El DocumentRoot ya estaba guardado como /var/www/vioraa-5/Viora. Se validó Apache antes de recargarlo; desapareció el 404 del login.
- La raíz / redirige al formulario de inicio de sesión.
- Apache está activo; PHP 8.3.6 dispone de mysqli, pdo_mysql, mbstring, curl, XML, zip, GD y fileinfo. No fue necesario instalar extensiones.
- MySQL 8.0.46 está activo y escucha en 127.0.0.1:3306; no se abrió MySQL a Internet.
- Credenciales de aplicación guardadas en /etc/viora/environment.json (root:www-data, 0640), cargadas por /etc/viora/bootstrap.php, fuera de DocumentRoot y Git.
- Se estableció un secreto nuevo para viora_user mediante ALTER USER ... RETAIN CURRENT PASSWORD. MySQL conserva la contraseña anterior como secundaria. No se cambiaron contraseñas de cuentas de VIORA ni se eliminaron usuarios MySQL.
- conexion.php usa utf8mb4 y entrega un error genérico si la conexión falla; los detalles no se muestran al navegador.
- PHP oculta errores públicos, conserva logs y configura sesiones HttpOnly, SameSite=Lax y modo estricto.
- Apache impide índices de carpetas, acceso HTTP a conf, archivos ocultos, SQL, copias y credenciales. Se probó respuesta 403.
- Se impide ejecutar PHP y otros scripts en uploads. Una prueba temporal devolvió 403 y el archivo se retiró.
- uploads y la carpeta privada de contratos tienen permisos para www-data. El código no se hizo escribible globalmente.
- Se corrigieron dos referencias CSS a tu-imagen.jpg utilizando la imagen existente imagenes/viora.jpg.
- Se añadió require_admin.php con comprobación del rol real en MySQL a 12 rutas administrativas: usuarios, roles, condominios, edificios, apartamentos, clientes, solicitudes, visitas, facturación y consultas auxiliares. Antes algunas solo comprobaban logged_in.

## Respaldos

Antes de modificar configuraciones se creó /var/backups/viora/20261110T035541Z, incluyendo un mysqldump consistente de viora y archivos originales. Hay respaldos adicionales code-*, css-* y roles-* bajo /var/backups/viora. Los directorios de respaldo tienen permisos 0700; el dump tiene 0600. Los archivos locales modificados también tienen copias bajo deployment/*_backup.

## Verificaciones realizadas

- 35 tablas, 264 registros en conjunto; todas las tablas pudieron consultarse.
- 49 relaciones por claves foráneas; cero registros huérfanos según sus relaciones declaradas.
- Sintaxis de los PHP locales y del servidor comprobada. Apache responde Syntax OK.
- Login, registro, CSS e imagen accesibles; login público HTTP 200.
- Inicio de sesión real de Administrador: redirección a principal.php.
- Inicio de sesión real de Cliente con suscripción activa: redirección a catalogo.php; cierre de sesión HTTP 302.
- Las pruebas usaron credenciales legadas ya disponibles en la base de datos solo en memoria dentro de la VM. No se imprimieron, copiaron al repositorio ni modificaron.
- Una cuenta sin suscripción fue rechazada con subscription_required. Se conservó esta validación. Hay 7 cuentas de Cliente activas, 6 cumplen los requisitos de suscripción.
- Lecturas con sesiones de prueba: panel, roles, usuarios, condominios, edificios, apartamentos, catálogo, solicitudes administrativas, visitas, contratos, facturación, consulta de facturas, cuentas por cobrar, mantenimiento y reparaciones respondieron HTTP 200 para Administrador.
- Catálogo, seguimiento de solicitudes y administración de propiedades respondieron HTTP 200 para Cliente.
- Cliente recibió HTTP 403 al intentar usuarios, roles, condominios y aprobación administrativa de solicitudes. El panel administrativo redirige al Cliente.
- Administrador recibió HTTP 403 en las dos pantallas reservadas a Clientes, conforme a su diseño actual.
- Una factura existente generó HTTP 200 y contenido %PDF-. El generador PDF también pasó una prueba mínima independiente.
- Después de los cambios no se observaron errores fatales de PHP en el log del sitio durante las pruebas. Las entradas de acceso denegado corresponden a pruebas esperadas de protección.
- Los registros siguen siendo 264 tras las pruebas. No se crearon, editaron o eliminaron registros de negocio para probar escrituras.

## Integraciones

PayPal Sandbox: se encontró la configuración privada existente en C:/xampp/paypal_private/paypal_config.php, se transfirió mediante SSH con archivos temporales privados y se retiraron esos temporales. Las claves están exclusivamente en /etc/viora/environment.json. Se verificó OAuth contra PayPal Sandbox y el plan P-2GW66466MT116720LNLIDS2A está ACTIVE. La ruta exclusiva de Windows se reemplazó por configuración portátil de entorno. No se creó ningún cobro ni suscripción durante las pruebas; falta una prueba completa del flujo de pago Sandbox.

Gemini: falta GEMINI_API_KEY. No se inventó una clave ni se verificó la API sin credenciales.

e-CF: falta ECF_API_KEY y validación de datos reales del emisor/proveedor. ECF_ENABLED=false y ecf_post bloquea cualquier envío mientras no sea exactamente true. Se comprobó que el bloqueo se ejecuta antes de llamar a la API. No habilitar emisión real sin autorización y validación fiscal.

## HTTPS configurado y verificado

Actualización del 10 de noviembre de 2026: se emitió un certificado gratuito de Let's Encrypt para la IP 34.60.93.2 y se habilitó https://34.60.93.2/login/login.php. HTTP redirige a HTTPS. No hace falta un dominio ni subdominio. La IP existente se reservó como viora-public-ip y permanece asociada a la misma VM; no se creó otro servidor.

La renovación automática está habilitada mediante viora-certbot-renew.timer, con comprobaciones cada hora. La simulación de renovación y el hook de validación/recarga de Apache pasaron. Las cookies de sesión tienen Secure, HttpOnly y SameSite=Lax. Las protecciones de archivos privados y subidas se conservan.

El certificado se validó desde fuera de la VM sin omitir comprobaciones TLS. Login, CSS e imagen responden HTTP 200 por HTTPS. Se repitieron las pruebas de lectura de Administrador, Cliente y PDF por HTTPS. Los datos permanecen en 35 tablas y 264 registros.

Consultar deployment/HTTPS_REPORT.md para rutas de configuración, renovación, costos, respaldos y eventual incorporación de un dominio. Las plantillas .disabled previas son históricas: el sitio activo es viora-https.conf. El certificado es gratuito; los cargos de Google Cloud existentes permanecen.

## Límites y acciones pendientes

- El usuario debe probar sus propias cuentas en la URL indicada. No hace falta enviar contraseñas por chat.
- HTTPS está resuelto. Persisten las validaciones de integraciones y flujos completos que se detallan abajo; no se certifica todo el sistema para producción únicamente por tener HTTPS.
- No se probaron altas, cambios, aprobación/rechazo, contratos nuevos, cobros ni registro completo con datos reales, para conservarlos. Preparar datos de prueba autorizados antes de verificar esos flujos de extremo a extremo.
- No se realizó una prueba visual en navegador gráfico; se comprobaron HTTP, contenido, recursos y rutas desde fuera de la VM y desde el servidor.
- Completar Gemini y las validaciones fiscales; realizar una suscripción Sandbox de prueba antes de considerar validado todo el flujo de PayPal.
- La red tenía reglas públicas preexistentes para SSH y RDP. No se abrieron puertos administrativos nuevos. Conviene restringir esas reglas al acceso administrativo necesario sin bloquear el acceso legítimo.
- Hay contraseñas legadas de usuarios de aplicación almacenadas sin hash. Planificar su migración con password_hash; no se alteraron para estas pruebas.
- Después de verificar todos los consumidores de MySQL, retirar de forma planificada la contraseña secundaria de viora_user. No se retiró ahora para conservar compatibilidad.
- No ejecutar los scripts antiguos deploy-production.sh ni deploy-viora-gcp.ps1: contienen eliminación del despliegue y recreación de usuarios. No se ejecutaron. El repositorio tenía cambios previos que se conservaron; no se hizo commit ni push.

Código y verificaciones de esta intervención: deployment/configure-existing-vm.py y deployment/verify-production.php. El verificador crea sesiones temporales para probar vistas; no sustituye las pruebas de autenticación reales. El script de configuración modifica el servidor: revisar antes de volver a ejecutarlo; no es necesario ejecutarlo para acceder ahora.