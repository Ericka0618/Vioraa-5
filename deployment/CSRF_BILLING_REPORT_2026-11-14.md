# Corrección CSRF y prueba integral en navegador — 14 de noviembre de 2026

## Causa comprobada en producción

La revisión activa `viora-00018-tuy` servía el JavaScript actualizado, pero conservaba una versión antigua de `facturacion.php`. Al abrir el formulario con una sesión administrativa válida en la URL solicitada, se comprobó: HTTP 200, ausencia de `<meta name="ecf-csrf">` y ausencia de `ecf_csrf` en la sesión SQL. El JavaScript leía una etiqueta inexistente y enviaba `X-CSRF-Token` vacío. El servidor rechazaba correctamente la solicitud. El mensaje de caducidad no describía la causa: no había un token generado para ese formulario.

Las publicaciones previas incluyeron controlador y validador, pero omitieron el formulario en el paquete sobre la imagen antigua. La pantalla publicada `facturacion_viora.php` presentaba la misma omisión del encabezado CSRF en el envío de pagos. Las pruebas de API que obtenían el token del código local y las pruebas públicas que solo comprobaban rechazos no cubrían el envío real de esos formularios publicados. Esta corrección publica el módulo como conjunto y exige el recorrido completo de navegador sobre la imagen que se despliega.

## Cambios

- `Viora/mantenimientos/facturacion.php`: se incluye explícitamente en la imagen. Conserva un token aleatorio de 32 bytes por sesión autenticada, generado con `random_bytes` y almacenado en `$_SESSION['ecf_csrf']`. La etiqueta HTML utiliza exactamente ese valor, escapado. La generación ocurre después de autorizar al administrador. Se añaden `Cache-Control: no-store, private` y `Pragma: no-cache`. La conexión usa `require_once __DIR__`.
- `facturacion.js`: lee el token del formulario, comprueba que tenga 64 caracteres hexadecimales y lo envía en `X-CSRF-Token`. Si falta el token en un documento antiguo, bloquea el envío vacío e indica recargar el formulario. Se conserva `credentials: same-origin` para enviar la cookie y `cache: no-store` en las peticiones AJAX. No se intenta generar un token en JavaScript ni se reintenta automáticamente un cargo.
- `facturacion_viora.php`: se publica su versión correspondiente, que envía el mismo token de sesión al registrar pagos y reenviar documentos fiscales. Se deshabilita la caché de esta pantalla. Se prueban pagos parcial y completo desde el navegador, sin utilizar PayPal ni contactar a proveedores.
- `deployment/release-invoices.py`: el manifiesto general incluye ahora también `facturacion.php`. El nuevo `release-csrf-billing.py` empaqueta formulario, JS, controladores, servicios, validaciones, consultas y PDF juntos, con hashes de los archivos.
- `guardar_factura.php` y `ecf_validacion.php`: se revisaron y se mantienen las validaciones existentes, incluida `hash_equals`, POST, rol, contrato, conceptos, fechas y duplicados. No se ha desactivado ni relajado CSRF.

## Sesiones, cookies y HTTPS

Se verificó `DB_SESSION_ENABLED=true` en Cloud Run. `deployment/runtime.php` reconoce la terminación TLS de Cloud Run, establece HTTPS y cookies Secure. `deployment/mysql-session.php` guarda las sesiones en `viora_runtime_sessions` de Cloud SQL, con vencimiento y bloqueo por ID mediante `GET_LOCK`. No depende de archivos locales de una instancia ni de afinidad de sesiones.

No se modificaron esos archivos compartidos de sesiones, el login ni la política de cookies: no eran la causa del token ausente. Sus hashes se preservan en la imagen. Las pruebas usan el código real del backend compartido y dos réplicas PHP distintas, no sesiones administrativas inyectadas para el recorrido aislado.

El navegador inicia sesión mediante el formulario real, verifica la rotación del ID después del login, y recibe una cookie `VIORASID` Secure, HttpOnly y SameSite=Lax. Un proxy HTTPS de QA reproduce la terminación TLS delante de Apache: el login y un formulario van a la réplica A, mientras la consulta de clientes y el POST de guardado van a B. Una segunda pestaña carga el formulario desde B y conserva el token. Se compara el token del DOM con el de SQL y con el encabezado de la petición real, sin escribir los valores de tokens o cookies en la evidencia.

No se fuerza el escalado ni se cambia la configuración de las instancias de producción. La prueba de múltiples réplicas ocurre sobre la imagen exacta, contra MySQL aislado; las verificaciones públicas contrastan el formulario publicado con su sesión real en Cloud SQL.

## Pruebas funcionales positivas

`python deployment/run-csrf-local.py`: **37 comprobaciones aprobadas**. Crea exclusivamente la base desechable local `127.0.0.1:13308/viora_csrf_qa`, con datos sintéticos no fiscales. No modifica cuentas reales. Ejecuta dos servidores PHP con sesiones SQL y cookies seguras; el navegador Chrome entra por HTTPS. También se aprobaron las 42 comprobaciones del servicio fiscal (`deployment/test-ecf.php`), con respuestas del proveedor simuladas, sin emisión real.

El mismo `deployment/test-csrf-browser.py` se ejecuta en Cloud Build contra dos contenedores de la imagen exacta con PHP de destino, MySQL 8 y sesiones compartidas activadas. Solo se copia el esquema vacío a ese entorno y se crean fixtures mediante `seed-csrf-qa.php`; no se copian personas, contraseñas ni facturas de producción.

El contenedor de navegador y el paquete Playwright usan la misma versión fija, conforme a la [documentación oficial de Playwright para Docker](https://playwright.dev/python/docs/docker). En Chromium headless se comprueba la respuesta PDF de la ventana abierta y el contenido del documento; la navegación al visor no se usa como única señal, porque algunas variantes headless descargan el PDF. La descarga por el cliente se realiza con un clic real y se comprueba el archivo recibido.

El recorrido comprueba:

1. Login real del administrador y cookies HTTPS; rotación del ID de sesión.
2. Token fuerte en el formulario, sin caché, idéntico al almacenado en SQL.
3. Conservación del token al elegir cliente, alternar dos propiedades y cambiar conceptos; cuatro consultas AJAX concurrentes sin pérdida de sesión.
4. Segunda pestaña y segunda réplica con el mismo token.
5. Confirmación y clic reales en «Generar factura», con el encabezado enviado por `facturacion.js` igual al token renderizado.
6. Aceptación del POST en la otra réplica; registro ordinario con `ECF_ENABLED=false`, conceptos y total correcto (subtotal 1,100.00; ITBIS 198.00; total 1,298.00, exclusivamente fixtures).
7. PDF automático persistido en SQL con hash correcto y apertura real de su ventana.
8. Rechazo de tokens ausentes e incorrectos y de un reintento duplicado; solo una factura en QA después de esos intentos.
9. Factura visible en consultas administrativas.
10. Login real del cliente; factura automáticamente visible en Mis Facturas, vista y descarga real del PDF desde el navegador.
11. Login real de otro cliente y rechazo del PDF y detalle ajenos.
12. Ausencia de excepciones JavaScript durante el flujo de facturación.
13. Pagos parcial y completo desde el formulario administrativo real con CSRF válido, actualización de estado y saldo, historial pagado y PDF disponible para el cliente.

## Datos existentes y límites de las verificaciones públicas

Antes de corregir: 13 facturas, 26 detalles, 6 pagos de factura, 12 registros e-CF, 6 notas y 5 comprobantes fiscales. Última factura: `2026-10-20 02:35:08`, anterior a los intentos fallidos de noviembre 14. El rechazo CSRF ocurre antes de la transacción: esos intentos no registraron facturas. Se mantienen los dos grupos de conceptos duplicados históricos previamente detectados, sin borrar ni recalcular datos.

En candidata y URL pública se usan sesiones administrativas temporales de usuarios existentes para revisar HTML, token persistido y consultas. Se comprueba además que un POST con CSRF válido llegue a la validación de contrato: con `contrato_id=0` devuelve «Contrato inválido», sin crear un documento de producción. **Esta comprobación pública no se presenta como una generación completa**: el login y la generación positivos se realizan mediante navegador con los datos aislados y la imagen exacta descritos arriba.

Se mantienen PayPal, Gemini, contratos y mantenimiento. El build compara hashes de los archivos protegidos y del runtime de sesiones. `ECF_ENABLED=false` permanece activo. No se proporcionan claves fiscales, no se envían documentos al proveedor y no se crean facturas de prueba en producción.

## Publicación y evidencia

**Desplegado y validado** en `https://viora-1055595302579.us-central1.run.app`, revisión `viora-00021-rok`, con 100 % del tráfico. Cloud Build `8703eb65-35a6-4c5f-93f8-587189c3326c`: SUCCESS. Imagen: `sha256:bdfc2f492cba5a8579ef5b8b0c9a86a2ed67bbe3a9c34c1ae8f79df7fc14fe9d`.

Se aprobaron las 37 comprobaciones de navegador contra la imagen Linux, las 37 locales y las 42 del servicio fiscal. Además, pasaron 14 comprobaciones en la candidata y 14 en la URL pública, incluyendo consulta, PDF y permisos; en ambos destinos se comprobó que el formulario principal y el de cobros usan el token correcto y que un POST con token válido supera CSRF y llega a la validación del contrato. Los 21 archivos de aplicación publicados coinciden con el manifiesto.

Los conteos financieros permanecen iguales antes y después. Se conservó toda la configuración de Cloud Run, incluyendo `DB_SESSION_ENABLED=true`, `ECF_ENABLED=false`, secretos, identidad del servicio y almacenamiento. No se aplicó ninguna migración ni se cambiaron los archivos compartidos de sesiones. Las sesiones auxiliares usadas para las comprobaciones públicas se eliminaron al concluir.

La evidencia está en `csrf-billing-release.json`, `csrf-browser-local-results.json` y `csrf-browser-image-results.json`. Los primeros ensayos del laboratorio no se publicaron: uno encontró una etiqueta de imagen de navegador no disponible; otro se canceló al ampliar la cobertura a pagos; y otro comprobó generación y token válidos pero se detuvo por la espera de navegación al visor PDF de Chromium headless. El build final repitió el flujo completo y pasó todas las comprobaciones.

La verificación pública adicional de titularidad aprobó sus 9 comprobaciones: PDF y descarga propios, rechazo de detalle/PDF ajenos, estructura y logo del documento y bloqueo fiscal. Está registrada en `csrf-billing-ownership-results.json`, con la URL exacta solicitada. Esta comprobación usa facturas existentes y tampoco genera registros financieros de prueba.

Archivos nuevos de pruebas/operación: `seed-csrf-qa.php`, `test-csrf-browser.py`, `run-csrf-local.py`, `release-csrf-billing.py`, los resultados y este informe. La edición previa del usuario en `ECF_REPORT_2026-11-12.md` permanece fuera del commit.

Después del despliegue hay que recargar una vez la pestaña del formulario que estaba abierta: el documento anterior en memoria no contiene el token. No se permite enviar un formulario antiguo sin token para eludir la protección.
