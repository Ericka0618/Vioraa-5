# Clientes y propiedades de facturación — 12 de noviembre de 2026

**Corregido y probado en el código local; pendiente de autorización para desplegar y validar la corrección desde Cloud Run.** No se hizo commit, push, build remoto ni despliegue. La revisión pública sigue siendo `viora-00009-vav`, con emisión fiscal deshabilitada.

## Causa exacta y diagnóstico público

`facturacion.js` solicita `mantenimientos/buscar_clientes_factura.php` y, después de seleccionar el cliente, `mantenimientos/obtener_propiedades_cliente.php?persona_id=ID`. Las rutas públicas son correctas.

Se inició sesión de Administrador en la URL pública sin cambiar credenciales. Ambos endpoints respondieron HTTP 200, `Content-Type: text/html; charset=UTF-8` y **un solo byte de espacio inicial**, sin JSON. Los registros de Cloud Run confirman avisos de `session_start` y cabeceras ya enviadas; no se detectaron excepciones SQL en las ocho entradas inspeccionadas. Ambos archivos tenían una línea vacía anterior a `<?php`: al enviar ese contenido se impedía iniciar la sesión y el guardia administrativo terminaba la petición intentando redirigir. Por eso el navegador recibía una respuesta vacía inválida en lugar de los clientes.

Había además un problema independiente: el guardia HTML podía redirigir o devolver texto plano en APIs JSON, y la conexión compartida podía terminar con texto cuando fallaba. No bastaba con corregir la ruta del navegador.

## Archivos corregidos

- `Viora/mantenimientos/buscar_clientes_factura.php`: comienza directamente con PHP, usa autorización JSON y conserva la consulta de clientes activos con alquiler activo. Responde `clientes: []` cuando no hay resultados.
- `Viora/mantenimientos/obtener_propiedades_cliente.php`: mismo control de sesión/permisos; valida el identificador y filtra también la persona activa. Conserva el contrato, propiedad y montos necesarios para el formulario. Sin resultados devuelve `propiedades: []`.
- Nuevo `Viora/mantenimientos/facturacion_json.php`: respuestas `application/json`, `Cache-Control: no-store`, 401 sin sesión, 403 sin rol autorizado, 405 para método incorrecto, 400 para identificador inválido y 503 ante fallo interno. Comprueba rol y usuario activo en la base. Captura salidas de la conexión compartida para impedir que su texto sustituya al JSON. No devuelve consultas, trazas, credenciales ni errores privados.
- `Viora/mantenimientos/facturacion.js`: distingue una respuesta inesperada y presenta un mensaje comprensible; conserva las rutas relativas correctas y el encabezado CSRF existente. No cambia el diseño ni el cálculo fiscal.

`facturacion.php` fue inspeccionado; no necesitó otra modificación en esta corrección. Se conservaron su token CSRF y las validaciones fiscales locales que ya estaban preparadas. La publicación anterior de la página mensual y las mejoras fiscales aún no publicadas siguen diferenciadas en `ECF_REPORT_2026-11-12.md`.

## Pruebas

**46 comprobaciones aprobadas:** 24 contra QA local y 22 sirviendo el PHP corregido localmente con consultas SELECT a Cloud SQL. Resultados: `billing-clients-local-results.json` y `billing-clients-cloud-readonly-results.json`.

Se verificaron:

- JSON y permisos para Administrador, Cliente y sesión ausente; método incorrecto e identificador inválido.
- Listas vacías, fallos reales inducidos de conexión y SQL en el entorno local, sin exponer detalles internos.
- Carga de todos los clientes y sus propiedades, sin errores SQL, tanto en la copia QA como consultando Cloud SQL.
- Chrome: desplegable poblado, propiedad única completada automáticamente, selector para varias propiedades y concordancia del contrato y apartamento con el JSON.
- Preparación del formulario y habilitación de «Generar factura», conservando CSRF y sin pulsar el botón. Se bloquearon por interceptación las rutas de guardar factura, reenviar e-CF y emitir nota; no se registró ninguna petición a ellas.
- Ausencia de excepciones JavaScript y conteos financieros iguales antes y después.

Los tests usan sesiones de prueba almacenadas exclusivamente en archivos locales con IDs de usuarios cuyos roles se verifican en la base; no crean sesiones de prueba en Cloud Run ni cambian cuentas. La comprobación pública anterior sí utilizó el login real de Administrador. No se generaron facturas, notas ni pagos. Las escrituras auxiliares para preparar un fixture vacío ocurrieron exclusivamente en MySQL local `127.0.0.1:13308/viora_billing_empty_qa`, sin tocar tablas financieras existentes.

```powershell
python deployment/test-billing-clients.py
python deployment/test-billing-clients.py --cloud-read-only
```

Requisitos: instancia QA local del puerto 13308 con `viora_ecf_qa`, PHP de XAMPP, Chrome/Playwright y, para la segunda ejecución, autenticación de Google Cloud y proxy existente. Los tests PHP usan PHP 8.2.12; no se presenta esta ejecución como un build de PHP 8.3 en Cloud Run. Ninguna prueba simuló las respuestas de clientes o propiedades: procedieron de las consultas SQL de los endpoints corregidos.

## Preparación y pendiente

El paquete de cuatro archivos y sus hashes está en `billing-clients-release.json`, preparado sobre la revisión pública actual. No necesita migración ni cambiar Gemini, PayPal, secretos o configuración fiscal. Los cambios están preparados en Git junto con las modificaciones anteriores; no se retiraron cambios ya preparados.

**Falta autorización expresa para desplegar.** Tras recibirla se comprobará una revisión sin tráfico en Chrome, conservando Cloud SQL y la configuración activa, y se repetirá la prueba desde la URL pública antes de declarar resuelto el error publicado. No se afirma que Cloud Run ya tenga esta corrección.
