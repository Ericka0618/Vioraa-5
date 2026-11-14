# Facturación VIORA — 14 de noviembre de 2026

## Implementación

- Nuevo `mantenimientos/mis_facturas.php`, integrado en el menú del cliente. Reutiliza `consulta_facturas.php` y las tablas `factura`, `factura_detalle`, `factura_pago` y `factura_ecf`; las facturas aparecen automáticamente y permanecen al pagarse.
- `factura_acceso.php` verifica usuario activo, rol y persona en SQL. El listado y el detalle limitan por titular; `factura_pdf.php` comprueba también la titularidad de la factura original antes de entregar facturas o notas. Los identificadores enviados por el cliente no determinan su identidad. Acceso ajeno devuelve 404.
- Consultas con búsqueda por cliente/cédula/número, fechas desde/hasta, período, estado y paginación de 100 resultados. Incluyen concepto, e-NCF, total, pagado y saldo. El vencimiento se deriva sin modificar documentos históricos; las nuevas facturas vencen al cierre del período mensual. Los resúmenes corresponden a la página mostrada.
- PDF separado en `factura_render.php`, con logo existente, emisor configurable, cliente, propiedad, conceptos, importes, datos fiscales y QR cuando el proveedor aporta URL. Vista e impresión mediante visor PDF del navegador; descarga con `Content-Disposition: attachment`.
- `guardar_factura.php` genera y persiste el PDF automáticamente después del registro fiscal local. Si la generación falla, la factura se conserva y la interfaz comunica el PDF pendiente, recuperable desde consultas. No debe repetirse la factura para recuperar el documento.
- Migración aditiva `conf/factura_documento.sql`: copia privada del PDF en Cloud SQL con SHA-256. El visor genera el documento con los balances actuales y actualiza la copia. El contenido también se replica por hash en Cloud Storage, con identidad del servicio y sin URLs públicas. Una indisponibilidad de Storage no elimina la copia SQL; el acceso posterior vuelve a intentar la réplica. No existe una cola de reintentos independiente.
- `registrar_pago.php` ahora exige POST y CSRF. Se conservan el bloqueo de fila, rechazo de sobrepago y actualización en centavos de saldo/estado; el cliente consulta los pagos, y el administrador registra/confirma su recepción. **No se añadió una pasarela de pago de alquileres ni se reutilizó PayPal de suscripciones para ello.**
- `conf/ecf.php` prioriza `IMECF_API_KEY` y mantiene `ECF_API_KEY` como alias. `ecf_validacion.php` impide usar claves con prefijo `ecf_live_` en el circuito de pruebas. `ECF_ENABLED=false` continúa activo.

## IMECF: documentación encontrada y límites

Se inspeccionaron la configuración, servicio, validaciones y los informes existentes. Solo se encontró la URL heredada `https://ecf-platform-backend-50801509587.us-central1.run.app` y el envío `POST /api/v1/ecf/send`, con encabezado `X-API-Key`. **No hay contrato OpenAPI ni documentación oficial del proveedor en el proyecto que confirme esa URL o describa consulta remota, callbacks, idempotencia y ambiente.** Los informes previos documentan respuestas 404 de las rutas públicas de documentación. No se inventaron endpoints ni se enviaron POST fiscales.

El JSON existente usa encabezado `Version`, `IdDoc`, `Emisor`, `Comprador` y `Totales`, más detalles de servicios. E31 requiere comprador fiscal, E32 usa el flujo de consumo existente, y E34 exige el documento original, fecha, motivo y líneas de ajuste. Se mantienen sus validaciones, auditoría, bloqueos de reenvío e identificadores del proveedor. Las pruebas de E31/E32/E34 emplean fixtures; **no equivalen a certificación ni pruebas reales de IMECF/DGII**. La consulta fiscal conserva su declaración de fuente local.

No se encontraron secretos IMECF/ECF en Secret Manager ni referencias fiscales en el contenedor al revisar sus metadatos. No se creó ni almacenó la clave compartida en el chat. Debe revocarse y sustituirse antes de configurarla. No se imprime su valor en scripts, informes o Git.

La regla heredada de ITBIS 18 % se conserva para los conceptos actuales y se prueba en centavos. **No se ha certificado que corresponda a todo alquiler/mantenimiento ni se implementaron tratamientos tributarios distintos por propiedad/concepto.** El informe fiscal anterior identificó seis facturas históricas inconsistentes con esa regla; no se recalcularon. Faltan confirmar el tratamiento fiscal aplicable, emisor real, RNC, dirección, datos de contacto, autorización de tipos, rangos y contrato del proveedor. Los PDF indican emisor pendiente cuando falta su configuración; no se inventan datos ni e-NCF.

## Configuración segura de una nueva API Key desde PowerShell

1. Revocar la clave expuesta y obtener una nueva. Solicitar además credencial y URL del ambiente de pruebas; una clave de producción no habilita pruebas ni autoriza emisiones.
2. Iniciar sesión y seleccionar el proyecto:

```powershell
gcloud.cmd auth login
gcloud.cmd config set project viora-514300
gcloud.cmd secrets list --format="value(name)"
```

3. Desde la raíz del repositorio, ejecutar el script. Solicita la clave mediante entrada oculta, la transmite por stdin, crea/reutiliza el secreto y concede acceso únicamente a la identidad del servicio en ese secreto. La ejecución con `-BindCloudRun` vincula la variable `IMECF_API_KEY` y mantiene la emisión deshabilitada:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deployment\configure-imecf.ps1 -BindCloudRun
```

Para reutilizar otro nombre de secreto validado:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deployment\configure-imecf.ps1 -SecretName NOMBRE_DEL_SECRETO -BindCloudRun
```

El script no se ejecutó con la clave compartida. No escribir el valor en comandos, variables de entorno persistentes, archivos `.env`, Git o argumentos de gcloud. La aplicación elimina el salto de línea añadido por el pipeline. Puede configurarse primero sin `-BindCloudRun` y vincular después una versión específica, manteniendo siempre `ECF_ENABLED=false`.

4. Verificar solo metadatos, nunca `secrets versions access` en una terminal compartida:

```powershell
gcloud.cmd secrets versions list viora-production-imecf-api-key --format="table(name,state)"
gcloud.cmd run services describe viora --region=us-central1 --format="value(spec.template.spec.containers[0].env.name)"
```

5. Antes de habilitar pruebas: recibir contrato oficial y URL autorizada; confirmar emisor, tipos y tratamiento tributario; verificar envío y consulta reales de E31/E32/E34, numeración e idempotencia en pruebas. No cambiar `ECF_ENABLED` ni banderas de validación para intentar utilizar una clave de producción.

## Pruebas

- `C:\xampp\php\php.exe deployment/test-ecf.php`: 42 comprobaciones aprobadas de validaciones, cálculos, auditoría, fixtures del proveedor, idempotencia, CSRF, alias IMECF y bloqueo de clave de producción.
- `python deployment/test-ecf-http.py`: 35 comprobaciones aprobadas en `127.0.0.1:13308/viora_ecf_qa`. Generación real, PDF automáticamente persistido y hash, duplicados, aislamiento entre dos clientes, vista/descarga, filtros, pagos parcial/completo, rechazo de sobrepago y acceso administrativo, historial pagado y navegador móvil.
- `python deployment/test-billing-clients.py`: 24 comprobaciones aprobadas de carga de clientes/propiedades, navegador, JSON, permisos y fallos inducidos en QA.
- Build remoto: PHP de destino y configuración Apache; compara hashes de archivos protegidos de Gemini, PayPal, contratos y mantenimiento entre imagen original y nueva.
- Las escrituras de facturas/pagos de prueba ocurren exclusivamente en QA local. En producción solo se añade la tabla documental y se guardan PDF de facturas existentes; no se crean facturas, pagos ni comprobantes fiscales.

## Archivos

Aplicación: `conf/ecf.php`, nueva `conf/factura_documento.sql`; `mantenimientos/consulta_facturas.php`, `ecf_validacion.php`, `factura_pdf.php`, nuevos `factura_acceso.php`, `factura_documento.php`, `factura_render.php`, `mis_facturas.php`; `facturacion.js`, `guardar_factura.php`, `menu_lateral.php`, `pdf_simple.php`, `registrar_pago.php`.

Operación/pruebas: `.gitignore`, nuevos `deployment/release-invoices.py`, `configure-imecf.ps1`, este informe, `invoices-release.json`, `verify-invoice-ownership.py` e `invoice-ownership-public-results.json`; `test-ecf.php`, `test-ecf-http.py` y sus resultados. La edición previa del usuario en `ECF_REPORT_2026-11-12.md` se conserva fuera del commit de esta intervención.

Referencias técnicas utilizadas: [carga de objetos de Cloud Storage](https://docs.cloud.google.com/storage/docs/json_api/v1/objects/insert) e [identidad del servicio Cloud Run](https://docs.cloud.google.com/run/docs/securing/service-identity).

## Publicación

La evidencia final se añade después de verificar candidata y URL pública. `deployment/invoices-release.json` registra imagen, hashes, revisiones, pruebas y conteos financieros.

Validaci?n p?blica adicional: `python deployment/verify-invoice-ownership.py`, 9 comprobaciones aprobadas (PDF propio, descarga, rechazo de factura ajena, integridad de referencias PDF, logo y bloqueo fiscal). Sus sesiones temporales se eliminaron al concluir. Total de esta revisi?n: **150 comprobaciones aprobadas** (42 de servicio + 35 HTTP QA + 24 clientes/propiedades + 26 candidata + 14 p?blicas + 9 de titularidad/documentos). La edici?n previa del informe fiscal de noviembre 12 no se incluy? en el commit.
