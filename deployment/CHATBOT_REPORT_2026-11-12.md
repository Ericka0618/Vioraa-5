# Chatbot VIORA: revisión, pruebas y preparación de activación

Fecha: 12 de noviembre de 2026, República Dominicana.

**Estado final: Gemini conectado, chatbot desplegado y comprobado desde la URL pública con Administrador y Cliente. La revisión `viora-00004-moh` recibe el 100 % del tráfico. Las conversaciones reales se guardan en Cloud SQL y se recuperan desde la interfaz.**

## Entorno y acceso

- Servicio `viora`, proyecto `viora-514300`, región `us-central1`; revisión pública inicial `viora-00002-pbz`, final `viora-00004-moh`.
- https://viora-6ikx2ezlba-uc.a.run.app/login/login.php responde HTTP 200 con TLS validado.
- Cloud Run consume la versión 2 de `viora-production-gemini-api-key`, validada con solicitudes reales. Modelo: `gemini-flash-lite-latest`. Inicialmente faltaba la clave; se instaló la facilitada por el usuario mediante entrada oculta y transferencia directa a Secret Manager, sin archivos privados ni argumentos con su valor.
- La cuenta `viora-production@viora-514300.iam.gserviceaccount.com` ya tiene `roles/secretmanager.secretAccessor` sobre ese secreto. No se modificó IAM.
- Cloud SQL `viora-staging-db`, base efectiva `viora_production`, MySQL `8.0.46-google`. Se comprobaron columnas y claves de `conversacion_ia` y `mensaje_ia` en modo de solo lectura. Hay cero mensajes sin conversación asociada. Las pruebas posteriores crearon conversaciones QA independientes y verificaron sus mensajes; al terminar se cerraron y desactivaron únicamente esas conversaciones nuevas, conservando sus mensajes para auditoría. No se cambiaron cuentas, contraseñas, conversaciones previas ni registros financieros. Evidencia: `chatbot-cloud-check.json` y resultados de las pruebas reales.

## Errores y correcciones de esta continuación

1. Los helpers de lectura/escritura confiaban en que el llamador hubiera validado al propietario. Ahora también filtran por `usuario_id` en SQL y rechazan escrituras en conversaciones ajenas o cerradas. El guardado del par usuario/asistente sigue siendo transaccional.
2. El navegador seguía guardando una copia del historial en una clave de almacenamiento compartida. Se retiró esa copia y se limpia la clave antigua; MySQL sigue siendo la fuente. Se muestra un mensaje si falla la recuperación del historial.
3. El protocolo anterior podía pedir confirmación dos veces: primero Gemini y después el servidor. Se alinearon las declaraciones y las instrucciones: el modelo prepara la propuesta y el servidor exige un «sí» posterior antes de ejecutar los argumentos guardados. La propuesta vence a los diez minutos y el resumen identifica la propiedad real del catálogo.
4. Se añadieron límite de tamaño de solicitud, validación de mensaje como texto, fecha de República Dominicana, tiempo máximo de conexión, manejo de JSON inválido y mensaje específico ante límites HTTP 429. Los detalles del proveedor y la clave no se envían al navegador.
5. El instalador solo comprobaba que la clave pudiera listar modelos. Ahora descubre modelos disponibles y exige una respuesta real de texto y llamadas reales compatibles con las declaraciones de Administrador y Cliente antes de guardar una versión del secreto. El modelo elegido y la versión quedan en un recibo sin claves. No se considera esa prueba una ejecución de herramientas contra la base de VIORA: eso corresponde a la prueba posterior del módulo desplegado.
6. La primera prueba real de propiedades detectó que la herramienta administrativa no devolvía el identificador/código del apartamento. Se añadieron `id` y `codigo` a esa consulta de solo lectura. La nueva prueba comparó los códigos devueltos por Gemini con propiedades reales autorizadas, para ambos roles.

Se mantienen las herramientas existentes, el historial, el diseño y los permisos. El Administrador conserva consultas de solo lectura. El Cliente consulta el catálogo autorizado y recibe orientación sobre solicitudes, contratos y pagos; no se le habilitaron consultas administrativas ni se inventaron datos financieros.

## Archivos del chatbot corregidos

- `Viora/mantenimientos/chatbot_api.php`
- `Viora/mantenimientos/chatbot_historial.php`
- `Viora/mantenimientos/chatbot_herramientas.php`
- `Viora/mantenimientos/chatbot.js`
- `Viora/mantenimientos/chatbot_widget.php`
- `deployment/set-gemini-secret.py`

Se revisaron `Viora/conf/conexion.php`, `Viora/conf/gemini.php` y la autorización de suscripciones. La conexión compartida no se modificó en esta continuación.

Nuevas herramientas de preparación/verificación: `prepare-chatbot-release.py`, `stage-chatbot-release.py`, `check-chatbot-cloud.py`, `test-chatbot-storage.php` y `test-chatbot-browser.py`. Se ajustaron las llamadas del test local anterior a la nueva firma de los helpers de historial.

Los cambios locales de PayPal y facturación de la intervención anterior permanecen en el workspace, pero **no se incluyeron en la imagen de este chatbot**.

## Pruebas realizadas y límites

- **12 comprobaciones SQL aprobadas**, `chatbot-storage-results.json`: persistencia del par de mensajes, recuperación tras reiniciar sesión, denegación de lectura/escritura con ID de otra cuenta, ausencia de cambios tras el intento denegado, cierre sin borrado, conversación nueva y límites de herramientas del Cliente.
- **9 comprobaciones en Chrome aprobadas**, `chatbot-browser-results.json`: login Cliente en la copia local, recuperación desde MySQL, recarga, cambio de cuenta en el mismo navegador sin filtración, nueva conversación, eliminación de la copia compartida del historial, mensaje visible ante clave ausente y ausencia de excepciones JavaScript. Se usaron cuentas y suscripciones de acceso exclusivamente de la copia QA; no se validaron pagos de PayPal ni se simularon respuestas de Gemini. Captura privada local: `.cache/chatbot-browser.png`.
- La batería HTTP local anterior se repitió: 11 comprobaciones aprobadas, incluyendo sesión, CSRF, historial y respuesta JSON 503 sin clave. Sus comprobaciones de otros endpoints son de la copia local y no implican cambios en esos módulos.
- Los tests de escritura se ejecutaron en la instancia independiente `127.0.0.1:13308/viora_integrations_qa` de XAMPP, PHP 8.2.12/MariaDB 10.4.32. No se presentan como pruebas funcionales de Gemini ni de Cloud Run.
- **Compilación final real con PHP 8.3**, comprobación de sintaxis de todos los PHP de la imagen, `apache2ctl -t`, arranque del contenedor y health check HTTP: Cloud Build `67276cb7-462c-4a74-aa91-9cd5870b8fc3`, `SUCCESS`.
- El instalador, los scripts de preparación y los verificadores pasan compilación Python. El intento de validar el secreto existente detectó que faltaba la clave y terminó sin crear otra versión. El script de despliegue también se detuvo antes de mutar Cloud Run por faltar el recibo de validación.

**Pruebas reales finales:** 20 comprobaciones aprobadas en la revisión candidata y otras 20 desde la URL pública. Ambos roles iniciaron sesión en Chrome sin restablecer sus credenciales, recibieron respuestas reales de Gemini, persistieron el par usuario/asistente en MySQL y recuperaron su historial tras recargar. Se comprobaron rechazo de CSRF ausente, aislamiento entre cuentas aun enviando el ID de otra conversación y códigos de propiedades contrastados con la base. El Cliente recibió el resumen de una visita para confirmar; preparar y rechazar la propuesta no creó registros de negocio. No se confirmó una visita real ni se generaron solicitudes, pagos o facturas de prueba en producción.

Resultados: `chatbot-live-results.json` y `chatbot-public-results.json`, ambos con cero fallos. No se simularon las respuestas de Gemini. Las pruebas locales anteriores siguen identificadas como locales, no como sustituto de estas pruebas remotas. Se verificó además que la clave no aparece en ningún archivo visible para Git.

## Imagen y despliegue preparado

La imagen se construyó sobre el digest de la imagen pública existente, sin reconstruir el resto del repositorio:

```text
Base: sha256:49c8c7449b809687de9d3cb557f92e7ad0a05f62163ae9557310da2086a79a20
Nueva: us-central1-docker.pkg.dev/viora-514300/viora/web:chatbot-20261113001434
Digest: sha256:490b86d67592e8794aac98f4803a1aebb7704f3d4614c8793a964301f96e07ae
```

El paquete copia solamente siete archivos: API, historial, herramientas, JavaScript, widget, configuración Gemini y el helper de autorización que usa el chat. Conserva el resto de la imagen, incluyendo PayPal, e-CF, Apache y sesiones compartidas. No requiere migración de tablas para el chatbot. El manifiesto y hashes están en `chatbot-release.json`.

`stage-chatbot-release.py` exige compilación exitosa, coincidencia de hashes, validación real de Gemini, versión concreta del secreto y configuración activa sin cambios inesperados. Después prepara una revisión con `--no-traffic --tag=chatbot-review`, cambia únicamente la imagen y las dos referencias de Gemini, y comprueba que el resto de variables/secretos, cuenta de servicio, volúmenes y vínculo Cloud SQL se conserven. **No promueve tráfico automáticamente.** Se apoya en el [procedimiento oficial de revisiones sin tráfico de Cloud Run](https://docs.cloud.google.com/run/docs/rollouts-rollbacks-traffic-migration).

Una primera solicitud de build usó el bucket predeterminado y fue rechazada por falta de permiso de lectura de la cuenta de compilación. Se resolvió usando el bucket `viora-514300-build-source` ya configurado por el pipeline; no se ampliaron permisos.

## Activación completada y mantenimiento

La instalación de la clave y el despliegue ya se completaron. No queda una credencial pendiente para usar el chatbot. `promote-chatbot-release.py` exigió las pruebas reales de la candidata, promovió esa revisión y repitió la batería desde la URL pública. El proceso disponía de restauración del tráfico anterior si fallaba; no fue necesario usarla. No se modificaron las configuraciones de PayPal ni e-CF, y la conexión con Cloud SQL se conservó.

Para reemplazar la clave en el futuro, desde `C:\xampp\htdocs\Vioraa_5`:

```powershell
python deployment/set-gemini-secret.py
```

Introduce una nueva clave en la entrada oculta y actualiza después la revisión de Cloud Run a la versión validada del secreto. No la pegues en el chat, archivos PHP, `.env` del repositorio ni argumentos de comandos. El script envía la clave por la entrada estándar a Secret Manager, después de validar Gemini; no la imprime. Si hay un error, muestra el código HTTP y orientación, sin revelar el cuerpo privado del proveedor. Conviene reemplazar la clave que se compartió en el chat.

Si debes crear una nueva: abre [API keys de Google AI Studio](https://aistudio.google.com/apikey), selecciona o importa el proyecto `viora-514300`, crea una clave para Gemini API y úsala en ese mismo instalador. Revisa las restricciones y la cuota según la [documentación oficial de claves Gemini](https://ai.google.dev/gemini-api/docs/api-key).

El chatbot quedó operativo en https://viora-6ikx2ezlba-uc.a.run.app . Se conservó el diseño. No se hizo commit ni push: el despliegue se realizó con la imagen acotada al chatbot. Una futura publicación del repositorio debe incorporar estos cambios para conservarlos. PayPal Live y la emisión fiscal siguen fuera del alcance de esta activación.
