# VIORA: pruebas funcionales y correcciones

Fecha: 10 de noviembre de 2026. URL: https://34.60.93.2/login/login.php
Estado: módulos revisados y correcciones desplegadas; cierre completo pendiente de confirmación efectiva de PayPal Sandbox y de instalar la clave privada de Gemini.

## Protección de datos reales

Se creó una copia privada de la aplicación y de las 35 tablas en la misma VM. La base de pruebas viora_qa_20261110 usa un usuario MySQL exclusivo, sesiones independientes y directorios separados de subidas y contratos. Apache sirve esta copia solo en 127.0.0.1:8443. No se abrió ese puerto en el firewall ni se creó otra VM.

Todas las altas, cambios, facturas, pagos y reparaciones de prueba se ejecutaron en esa copia. Los registros nuevos se identifican con prefijos QA. La activación manual de una cuenta para probar los módulos posteriores ocurrió exclusivamente en esta base de pruebas; no sustituye una aprobación de PayPal ni se cambió la validación en el código de la aplicación.

Se comparó un mysqldump consistente de producción antes y después: los SHA-256 coinciden (c41fb8fa3ab22329df20016bb6978184d3424facf827599ed9bc0144d8acdb59). La base real conserva 35 tablas y 264 registros, con 49 relaciones sin referencias huérfanas. No se cambiaron contraseñas de usuarios reales.

## Resultado de las pruebas

89 comprobaciones pasaron: 56 funcionales, 14 adicionales de validación y seguridad, y 19 en Chrome. Esto cubre los casos descritos; no equivale a verificar cada combinación posible de datos ni sustituye una auditoría independiente de seguridad.

| Área | Comprobaciones que pasaron |
|---|---|
| Autenticación | Inicio de sesión real con cuentas QA de Administrador y Cliente; contraseña incorrecta, intento SQL injection y cuenta desactivada rechazados; cierre de sesión |
| Roles | Cliente bloqueado en usuarios, roles, condominios, edificios, apartamentos, clientes, aprobación de solicitudes, contratos, mantenimiento administrativo, reparaciones, facturación y cuentas por cobrar; APIs de factura, pago y e-CF también denegadas |
| Registro | Formulario con CSRF, creación de persona/usuario, contraseña almacenada con hash, cuenta inicialmente inactiva y redirección a suscripción |
| Suscripciones locales | Acceso bloqueado sin suscripción, con suscripción cancelada y con fecha de próximo pago vencida; acceso permitido a la cuenta QA con estado y fecha válidos |
| Condominios y edificios | Alta y edición; botón de edición y guardado desde Chrome; eliminación por GET bloqueada y POST sin CSRF rechazado |
| Apartamentos y catálogo | Alta de apartamento con imagen, imagen servida por HTTPS, propiedad visible, solicitud de alquiler y rechazo de token CSRF incorrecto |
| Solicitudes | Aprobación administrativa de una solicitud real de prueba y vínculo al formulario de contrato |
| Contratos | Creación desde solicitud aprobada, subida de PDF a carpeta privada, descarga PDF válida y acceso denegado al Cliente y al visitante anónimo |
| Facturación | Factura con alquiler y mantenimiento; ITBIS y total correctos; factura duplicada por concepto/período rechazada; PDF válido |
| Cuentas por cobrar | Pago parcial de prueba, saldo actualizado, pago final, saldo cero y rechazo de pago adicional |
| Mantenimientos | Generación mensual sin duplicados y cambio a Realizado; solicitud de reparación del Cliente, persistencia, actualización por Administrador y consulta del estado por el Cliente |
| Navegador | Login público en Chrome con TLS validado; login de ambos roles en copia privada; 11 pantallas, formulario de condominios, menú por rol, sin excepciones JavaScript ni recursos locales faltantes en las pantallas visitadas |
| Infraestructura | Apache Syntax OK; HTTPS y PDF en producción responden correctamente; configuraciones privadas siguen bloqueadas |

Chrome accedió al entorno de pruebas mediante un túnel SSH local. Solo para ese extremo local se permitió el nombre localhost distinto al certificado IP; la URL pública se verificó sin omitir la validación del certificado.

Los contratos existentes funcionan con PDF adjunto y descarga privada. Esta aplicación no contiene un generador automático del texto contractual; no se añadió uno ni se cambió el flujo existente.

## Errores corregidos y desplegados

1. contratos.php permitía abrir la página administrativa a Clientes. Ahora verifica el rol real mediante require_admin.php antes de consultar o guardar datos.
2. ver_contrato.php enviaba un salto de línea antes de los bytes del PDF. Se retiró la salida previa al bloque PHP; ahora el archivo comienza por %PDF- y mantiene sus cabeceras y protección.
3. Los botones de condominios llamaban a Condominio.php, inexistente en Linux por diferencia de mayúsculas. Las llamadas y redirecciones usan condominio.php.
4. La eliminación de condominios se ejecutaba mediante GET. Ahora usa POST con token CSRF; las otras operaciones de ese formulario también exigen el token. Se conservan el icono y el flujo del formulario.
5. Faltaban nueve fotos referenciadas por el catálogo en Google Cloud. Se recuperaron las imágenes existentes del proyecto local, sin sobrescribir archivos del servidor. Se verificó su carga por HTTPS.
6. El login aceptaba una suscripción local Activo aunque su fecha de próximo pago hubiese vencido. Ahora comprueba la fecha cuando está disponible; los registros legados sin fecha mantienen su compatibilidad.
7. Las suscripciones nuevas de PayPal se vinculan al registro mediante custom_id=viora-user-ID. El backend comprueba esa vinculación antes de activar una cuenta, además del plan, estado, importe, moneda y reutilización del identificador.
8. Gemini ahora admite GEMINI_MODEL en configuración privada y responde 503 si falta la clave. Los errores de conexión/proveedor entregan un mensaje genérico sin exponer detalles internos al navegador.

Los ocho archivos PHP corregidos se validaron antes de escribirlos y se respaldaron en /var/backups/viora/functional-production-20261110043632. También existen respaldos locales y respaldos de las iteraciones de QA. No se hizo commit ni push; se conservaron los cambios previos del repositorio.

## PayPal Sandbox: comprobado y pendiente

Se comprobó OAuth contra el proveedor real Sandbox y se creó una suscripción nueva usando el plan existente. No se invocaron endpoints PayPal Live ni se realizaron cobros reales.

VIORA rechazó correctamente el intento anónimo, el identificador mal formado y la suscripción todavía no aprobada. El usuario indicó haber aprobado el enlace. Sin embargo, la consulta posterior a la API oficial de PayPal aún devolvió APPROVAL_PENDING y ningún last_payment. Por ello VIORA devolvió HTTP 409 y mantuvo la cuenta de esa prueba inactiva. No se simuló una confirmación ni se forzó su activación.

Falta recibir de PayPal ACTIVE y un primer pago confirmado para cerrar la prueba de registro del pago/suscripción y login de ese cliente. Se solicitó completar el botón final con la cuenta Personal Sandbox. Los identificadores y la sesión temporal están en /var/lib/viora-qa-20261110/paypal-fixture.json (0600), fuera del sitio y de Git. deployment/verify-paypal-approval.py vuelve a consultar el proveedor y verifica la activación y el catálogo cuando haya confirmación válida.

La facturación recurrente requiere además planificar la sincronización segura de renovaciones/cancelaciones de PayPal (webhook verificado o conciliación periódica), que no existe actualmente en este repositorio. La fecha local de próximo pago no se actualizará sola. No se habilitó un mecanismo no probado ni se modificaron suscripciones reales. Esta es una tarea pendiente para uso continuado de las suscripciones.

## Gemini: instalación segura preparada

El usuario confirma que tiene una clave, pero todavía no está instalada en el archivo privado del servidor. No hay claves API en el proyecto Google Cloud consultado. No se intentó inventar una clave ni se solicitó pegarla en el chat.

En PowerShell, desde la carpeta del proyecto:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\deployment\Configure-Gemini.ps1
```

El script pide la clave con entrada oculta, limita los permisos del temporal local, la transfiere mediante SSH a una carpeta privada temporal y la retira después. El instalador del servidor valida el modelo y envía una pregunta de prueba antes de guardar la clave. No imprime la clave ni la incorpora a argumentos, URLs o Git. Conserva un respaldo privado de la configuración previa y escribe /etc/viora/environment.json con root:www-data y permisos 0640. No hace falta publicar ni compartir la clave.

Después de ejecutarlo se debe comprobar el módulo completo de VIORA con una cuenta QA y una pregunta/respuesta. Por ahora se verificó el error seguro ante la ausencia de clave y se preparó la instalación; no se afirma que Gemini ya responda.

Referencias oficiales: https://ai.google.dev/gemini-api/docs/api-key y https://developer.paypal.com/platforms/subscriptions/test-subscriptions/ .

## Límites y pendientes antes de uso continuado

- Completar la aprobación efectiva de la suscripción Sandbox y verificar el pago, la activación y el acceso del cliente de esa prueba.
- Ejecutar el instalador oculto de Gemini y verificar el módulo con preguntas reales de prueba.
- Implementar y probar sincronización periódica/webhook de suscripciones recurrentes antes de depender del cobro mensual.
- PayPal permanece en Sandbox; no se habilitó el entorno de pagos reales.
- La emisión fiscal sigue deshabilitada. Faltan credenciales y validación fiscal/autorización para activarla.
- Las contraseñas legadas sin hash detectadas en la base original siguen intactas. Deben migrarse de forma planificada sin revelar ni cambiar arbitrariamente credenciales.
- Las reglas administrativas públicas de SSH/RDP preexistentes siguen pendientes de restringir conforme a las IP administrativas; MySQL solo escucha localmente.
- El entorno QA y sus datos permanecen privados para cerrar las dos verificaciones pendientes. No es otra VM. Retirarlo de forma controlada después del cierre; no eliminar bases ni archivos de producción.
- Esta intervención verificó aprobación de solicitudes y las ramas enumeradas. No se probaron todas las variantes de rechazo/cancelación de contratos, carreras concurrentes, reembolsos, múltiples monedas ni todas las combinaciones de datos. No se declara el proyecto completamente listo para producción con estos pendientes.

## Evidencias reproducibles

- deployment/functional-results.json: resultados funcionales y adicionales, más comparación SHA-256 de producción.
- deployment/browser-qa-results.json: comprobaciones de Chrome.
- deployment/functional-qa.py y deployment/extra-functional-qa.py: escriben exclusivamente en la copia QA indicada.
- deployment/browser-functional-qa.py: pruebas del navegador sobre el túnel privado.
- deployment/setup-functional-qa.py: creación de la copia, no ejecutar de nuevo sobre la existente.
- deployment/apply-functional-fixes.py: aplicación respaldada del paquete de correcciones a QA o producción.
- deployment/paypal-sandbox-qa.py y deployment/verify-paypal-approval.py: creación Sandbox y comprobación real del proveedor.
- deployment/Configure-Gemini.ps1 y deployment/configure-gemini.py: entrada oculta e instalación privada de la clave.