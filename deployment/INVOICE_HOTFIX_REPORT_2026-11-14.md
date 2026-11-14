# Corrección urgente de generación de facturas — 14 de noviembre de 2026

## Causa exacta

El registro de Cloud Run de `2026-11-14T13:37:31Z` (09:37:31 en Santo Domingo), revisión `viora-00015-hik`, confirma el error `Call to undefined function ecf_validar_peticion()` en `guardar_factura.php` desde la URL solicitada por el usuario.

La función se define en `Viora/mantenimientos/ecf_validacion.php:2` y se ejecuta en `guardar_factura.php:56`, antes de abrir la transacción. También la llaman los endpoints de reenvío y notas de crédito. En el código fuente, `ecf_service.php:2` importa el validador. Sin embargo, el paquete anterior publicó el validador y el controlador sobre una imagen antigua sin incluir la versión correspondiente de `ecf_service.php`.

La comprobación sobre la imagen realmente activa, por digest, devolvió `base_validator_loaded=false` después de cargar su servicio fiscal. SHA-256 del servicio antiguo: `855ef056184e835bd1316ce62b1a754bf3a2b1fc7c2b61b821b3c6ae39cafebc`. No era una ausencia de la función en el repositorio: era una dependencia omitida en la publicación. Las pruebas anteriores locales no reprodujeron la combinación de archivos de esa imagen; las comprobaciones públicas anteriores tampoco enviaron el formulario de facturación. Esta revisión añade esa prueba a la imagen Linux exacta antes de permitir su despliegue.

## Corrección

- `guardar_factura.php`, `reenviar_ecf.php` y `emitir_nota_credito.php`: importación explícita de conexión, validador y servicio mediante `require_once __DIR__`.
- El paquete publica juntos `ecf_service.php`, `ecf_validacion.php`, `conf/ecf.php` y los tres controladores. Se respetan exactamente los nombres en minúsculas del repositorio; las rutas se comprueban en Linux.
- `deployment/release-invoices.py`: el manifiesto general ahora incluye servicio, reenvío y notas; el build comprueba que cargar el servicio define el validador. La comprobación de sintaxis por sí sola no detectaba el error.
- `conf/ecf.php` fue revisado y se publica en la misma versión. Conserva el retorno de configuración mediante `require __DIR__` en cada lectura: cambiarlo por `require_once` devolvería `true` en lecturas posteriores en vez del array. Las importaciones de funciones sí usan `require_once`.
- No se omiten CSRF, control de método, autorización administrativa, validaciones de importes, conceptos, contrato, fechas, duplicados ni reglas fiscales.
- La emisión continúa con `ECF_ENABLED=false`. Las facturas ordinarias se guardan y generan su PDF; el registro fiscal queda pendiente de configuración. No se suministraron credenciales ni se contactó al proveedor para emitir documentos.

## Revisión de registros tras el intento fallido

Cloud SQL mantiene 13 facturas y su última fecha de registro es `2026-10-20 02:35:08`, anterior al error de noviembre 14. Además, la llamada que falló precede al inicio de la transacción. No se encontró una factura creada por ese intento ni fue necesario eliminar registros.

Se detectaron **dos grupos de contrato/período/concepto con duplicidad activa preexistente**. No se borran ni se recalculan: deben conciliarse como datos históricos. El flujo corregido rechaza un nuevo intento duplicado, comprobado en QA local y en el MySQL 8 aislado de la imagen.

Conteos de referencia: factura 13; detalles 26; pagos de factura 6; registros e-CF 12; notas e-CF 6; comprobantes fiscales 5. La publicación compara esos mismos conteos antes y después de las verificaciones.

## Pruebas

- `python deployment/test-ecf-http.py`: 35 comprobaciones aprobadas en QA local; incluyen generación ordinaria con fiscal deshabilitado, PDF automático persistido, vista y descarga, consulta administrativa, Mis Facturas, separación entre clientes, duplicados y ciclo de pagos.
- `C:\xampp\php\php.exe deployment/test-ecf.php`: 42 comprobaciones aprobadas de validaciones, cálculos, preparación E31/E32/E34, auditoría, idempotencia y bloqueo de claves de producción. Los resultados fiscales usan fixtures; no representan una certificación real del proveedor.
- Carga del servicio y del validador desde un directorio de trabajo ajeno: aprobada. Lecturas repetidas de configuración: array válido.
- `deployment/test-invoice-image.php`: prueba HTTP contra la imagen exacta, con PHP de destino y MySQL 8 aislado. Solo se envía al build el esquema vacío, sin datos ni credenciales de clientes. El propio test crea fixtures no fiscales, genera una factura y comprueba CSRF, importes, estado fiscal deshabilitado, PDF automático, descarga, titularidad, aparición en ambos módulos y rechazo de duplicados.
- El build compara los hashes de PayPal, Gemini, contratos, mantenimiento, PDF y consultas contra la imagen base. No modifica esos archivos ni sus secretos.
- Las pruebas públicas usan sesiones temporales de usuarios existentes, eliminadas al terminar. No crean facturas, pagos ni notas en producción. El POST público sin CSRF debe devolver 403; si faltara la función, devolvería el error 400 previo. La generación completa se verifica dentro de la imagen contra la base aislada.

Los primeros intentos del laboratorio detectaron dos problemas del propio montaje: la comprobación de disponibilidad de MySQL se adelantaba a la creación de la base, y el modo estricto de sesiones rechazaba IDs de fixtures todavía inexistentes. Se corrigieron la espera TCP a la base específica y la creación local de sesiones de prueba. Ninguno de esos intentos recibió tráfico público.

## Evidencia de publicación

**Corrección desplegada y verificada** en `https://viora-1055595302579.us-central1.run.app`, revisión `viora-00018-tuy`, con 100 % del tráfico. Cloud Build `48d2530b-d67f-4f93-a66f-21ec147f0757`: SUCCESS. Digest: `sha256:0cc14124adfc9d926c0cd1cb8c5251fb0833791482f92f3175b96cb72c67e4ad`.

Se aprobaron 14 pruebas funcionales de la imagen, 14 comprobaciones de navegador en candidata y 14 en la URL pública. Además, en ambos destinos se comprobó mediante POST que el validador se ejecuta y devuelve 403 sin CSRF, sin registrar facturas. Las 9 comprobaciones adicionales en la URL pública confirmaron que el cliente puede ver y descargar su PDF, que no puede acceder a uno ajeno, y que el PDF contiene referencias válidas y el logo existente. Evidencia adicional: `deployment/invoice-hotfix-ownership-results.json`.

Los seis archivos publicados coinciden con los hashes del manifiesto. No se encontraron registros de función indefinida en la revisión corregida durante la verificación. Los conteos financieros antes/después coinciden. Las credenciales y toda la configuración existente se conservan, incluyendo `ECF_ENABLED=false`; no se aplica ninguna migración de tablas de producción. `deployment/invoice-hotfix-release.json` contiene el diagnóstico de la base, las pruebas de imagen, las revisiones, resultados y conteos.

Archivos de esta corrección: los tres controladores de facturación indicados, `deployment/release-invoices.py`, `deployment/verify-invoice-ownership.py` (acepta URL de verificación explícita), nuevos `deployment/release-invoice-hotfix.py`, `deployment/test-invoice-image.php`, los dos archivos de evidencia JSON y este informe. `ecf_service.php`, `ecf_validacion.php` y `conf/ecf.php` se incluyen explícitamente en la imagen aunque sus fuentes ya estuvieran correctas. La modificación previa del usuario en `ECF_REPORT_2026-11-12.md` permanece fuera de esta corrección.
