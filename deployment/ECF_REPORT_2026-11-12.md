# Preparación fiscal VIORA — 12 de noviembre de 2026

**Actualización posterior: reparación de Facturación Mensual desplegada.** La página pública devolvía HTTP 200 con solo dos bytes: las líneas vacías anteriores a `<?php` impedían iniciar la sesión y redirigir correctamente. Se retiró ese espacio inicial. La revisión `viora-00009-vav` recibe el tráfico público después de 12 comprobaciones en Chrome (seis en candidata y seis públicas): login administrativo, formulario visible, widget Gemini presente, ausencia de excepciones JavaScript, rechazo de evento PayPal sin firma y redirección de anónimos al login. Cloud Build `ba11ea2a-2b10-43f0-950e-7cd4d8d2a3b9`: SUCCESS. La imagen modifica únicamente el inicio de `mantenimientos/facturacion.php` sobre la imagen activa de PayPal; el build comprobó el hash exacto del archivo original. No incluye las demás correcciones fiscales locales descritas abajo. Configuración, secretos, Cloud SQL, Gemini y PayPal conservados; emisión fiscal deshabilitada. No se crearon facturas de producción en esta prueba. Evidencia: `monthly-billing-release.json`; procedimiento: `fix-monthly-billing.py`. No se hizo commit ni push.

Se corrigió y probó el módulo local. **No se desplegó, no se emitieron comprobantes fiscales y no se escribieron registros en Cloud SQL.** `ECF_ENABLED=false` permanece en Cloud Run. Gemini y PayPal conservan los hashes de sus paquetes desplegados.

## Correcciones

- Se conservaron E31, E32 y E34, el JSON existente y la numeración delegada al proveedor. No se generaron secuencias ni se presume su autorización.
- Se eliminaron los datos heredados de UTESA como valores automáticos del emisor. Un envío requiere emisor confirmado, contrato validado, ambiente `pruebas` y tipos expresamente configurados. La emisión de producción continúa fuera de esta preparación.
- Se validan importes en centavos sin aceptar redondeos silenciosos de más de dos decimales. Se comprueban fechas, conceptos, comprador y concordancia entre factura, líneas e ITBIS. La validación de identificación es de formato; no sustituye una consulta al registro fiscal ni acredita autorización.
- E34 valida el e-NCF original, fecha y código de modificación. La fecha original guardada ahora corresponde al encabezado de emisión, en lugar de la fecha de firma. Los documentos previos no se reescriben: sus referencias deben conciliarse antes de emitir notas.
- Se preservan los identificadores y metadatos existentes cuando una actualización no los incluye. Un rechazo HTTP, cuerpo inválido o respuesta sin identificador queda incierto y bloquea el reenvío automático. Una nota incierta también bloquea nuevas notas hasta su conciliación.
- Una misma operación E34 conserva su identificador al reintentar desde el formulario: devuelve la nota anterior y no vuelve a enviarla. El servidor rechaza reutilizar el identificador con otros argumentos. La desactivación fiscal no crea una nota vacía ni abre un PDF inexistente.
- Se añadió CSRF a generación de facturas, reenvío y notas, sin cambiar el diseño. Los errores de base de datos y los mensajes privados del proveedor no se exponen al navegador.
- La migración aditiva `Viora/conf/ecf_auditoria.sql` crea `ecf_envio` y `ecf_nota_operacion`. Guarda la respuesta JSON del proveedor, HTTP, estado y hash de la solicitud, ligados a la factura/nota. Se eliminan credenciales de la respuesta; cuerpos no JSON o demasiado grandes se representan por hash y longitud. El registro previo al transporte conserva el bloqueo incluso ante caída del proceso. **Aplicada únicamente a la copia QA local; pendiente de autorización y respaldo en el destino.**
- `consultar_ecf.php` incluye las notas y declara la fuente local. No se inventó una API de consulta remota.

## Comprobaciones de Cloud SQL y proveedor

Se revisaron por conexión autenticada y transacción de solo lectura `factura`, `factura_detalle`, `factura_ecf`, `factura_ecf_nota`, `factura_pago` y `comprobante_fiscal`. Las siete relaciones declaradas no tienen registros huérfanos. `comprobante_fiscal.pago_id` referencia `pago.id_pagos`, no `factura_pago`: son relaciones existentes distintas y no se mezclaron automáticamente.

**Seis facturas no anuladas no concuerdan con el total derivado de sus conceptos aplicando la regla actual de 18 % del código.** No se modificaron sus importes. Esto exige revisar los datos y el tratamiento tributario de alquiler/mantenimiento con el emisor autorizado; no se afirma que todos esos conceptos deban tributar al 18 %. La preparación bloquea discrepancias, no recalcula documentos históricos.

Cloud Run solo tiene `ECF_ENABLED=false`; no hay referencia `ECF_API_KEY` ni secretos fiscales identificados en Secret Manager. El servidor heredado responde HTTP 200 a `/` con identificación del servicio; `/openapi.json`, `/api-docs` y `/swagger.json` responden 404. Esto acredita conectividad pública, no autenticación ni disponibilidad del contrato fiscal. No se envió ningún POST de emisión.

El usuario indicó disponer de documentación y acceso de pruebas. **Pendiente de recibir:** enlace o archivo del contrato, URL de pruebas, autenticación, consulta de estados, idempotencia, numeración/rangos autorizados y datos confirmados del emisor y tipos habilitados. No se recibieron claves en esta intervención ni se creó un secreto sin validar el contrato. La clave deberá introducirse por entrada oculta, nunca en el chat ni Git.

## Pruebas y límites

**53 comprobaciones aprobadas:** 40 de servicio/base de datos y 13 HTTP. Destino fijo: `127.0.0.1:13308/viora_ecf_qa`, copia independiente de los fixtures existentes. El test HTTP cambia una contraseña administrativa, amplía la vigencia de un contrato y genera facturas exclusivamente en esa copia. Nunca debe apuntarse a datos reales.

Las pruebas cubren generación real en MySQL local, detalles y totales, PDF existente, preparación E31/E32/E34, identificadores persistentes, idempotencia E34, tratamiento de respuestas simuladas, auditoría con credenciales eliminadas, prevención de reenvíos, CSRF y consulta local. **Las respuestas del proveedor fueron fixtures; no hubo envío ni consulta autenticada reales.**

Se verificó sintaxis de diez archivos PHP de aplicación con PHP 8.2.12. No se presenta como una compilación o certificación del destino PHP 8.3/MySQL 8; las pruebas locales usan MariaDB de XAMPP. El código y diseño del PDF permanecen intactos y se comprobó una respuesta `%PDF` generada desde una factura nueva QA.

```powershell
python deployment/prepare-ecf-qa.py
C:\xampp\php\php.exe deployment/test-ecf.php
python deployment/test-ecf-http.py
git diff --cached --check
```

La instancia QA local del puerto 13308 y la base fuente `viora_integrations_qa` deben existir previamente. Evidencia: `ecf-test-results.json`, `ecf-http-results.json`, `ecf-lint-results.json`, `ecf-cloud-audit.json` y `ecf-provider-discovery.json`.

## Archivos preparados y activación pendiente

Aplicación: `Viora/conf/ecf.php`, nueva `ecf_auditoria.sql`, `ecf_service.php`, nuevo `ecf_validacion.php`, `consultar_ecf.php`, `guardar_factura.php`, `reenviar_ecf.php`, `emitir_nota_credito.php`, `facturacion.php`, `facturacion.js`, `facturacion_viora.php` y `consulta_facturas.php`. También `.gitignore`, los tres scripts QA, resultados y este informe. No se modificaron los archivos de Gemini, PayPal ni el PDF.

Los cambios fiscales se prepararon en el índice de Git, conservando los cambios previos de Gemini y PayPal que ya estaban preparados. No se hizo commit ni push. Tampoco se desplegó ni modificó la configuración del servicio.

Después de recibir el contrato habrá que validar autenticación, adaptar la consulta documentada, verificar E31/E32/E34 y numeración con el proveedor, resolver las discrepancias de las facturas y ejecutar las pruebas reales del ambiente fiscal de pruebas. Solo después corresponderá solicitar autorización para aplicar la migración y desplegar. **El módulo aún no está certificado ni listo para activar emisión real.**

Referencias oficiales: [tipos y autorización e-CF de DGII](https://dgii.gov.do/cicloContribuyente/facturacion/comprobantesFiscalesElectronicosE-CF/Paginas/TipoyEstructurae-CF.aspx) y [documentación técnica y XSD](https://dgii.gov.do/cicloContribuyente/facturacion/comprobantesFiscalesElectronicosE-CF/Paginas/documentacionSobreE-CF.aspx). Se conservaron los tipos 31/32/34; no se afirma que el emisor esté autorizado ni que el JSON del proveedor haya superado la certificación DGII.
