# Revisión de Gemini, PayPal y e-CF — 12 de noviembre de 2026

**Actualización posterior del chatbot:** Gemini ya fue configurado y el chatbot se desplegó y comprobó con ambos roles desde Cloud Run (`viora-00004-moh`). Consulta `CHATBOT_REPORT_2026-11-12.md` y `chatbot-public-results.json` para los resultados finales. El resto de este informe conserva el estado de la revisión inicial; los pendientes de PayPal y e-CF no se consideran resueltos por esa activación.

Se corrigió el código local y se ejecutaron pruebas aisladas. **Los tres proveedores no están certificados como operativos de extremo a extremo. No se desplegaron estos cambios ni se modificaron registros de producción.** No se activaron PayPal Live ni emisión fiscal.

## Entorno comprobado

- Google Cloud, proyecto `viora-514300`, servicio Cloud Run `viora`, revisión lista `viora-00002-pbz`.
- URL activa: https://viora-6ikx2ezlba-uc.a.run.app ; login público verificado por HTTPS, HTTP 200.
- Configuración del servicio: `DB_NAME=viora_production`, Cloud SQL `viora-staging-db`, `PAYPAL_MODE=sandbox`, `ECF_ENABLED=false`. El nombre de la instancia no implica que la base sea de pruebas.
- Cloud SQL consultado mediante proxy autenticado y transacción de solo lectura: MySQL `8.0.46-google`. Existen las nueve tablas originales relevantes. Falta `suscripcion_pago`, incorporada en la migración aditiva local. Se guardaron únicamente metadatos de columnas en `integration-cloud-schema.json`.
- Secret Manager: Gemini está sin configurar. Las referencias y valores de las tres variables PayPal existen; eso no demuestra que las credenciales sean válidas. Ningún secreto se imprimió ni se guardó en Git.
- OAuth real Sandbox devolvió HTTP 503 desde este equipo. No se obtuvo token, no se consultó el plan ni se creó una nueva suscripción. No es posible atribuir el 503 a credenciales incorrectas con esa evidencia.
- Las pruebas de escritura usaron exclusivamente `127.0.0.1:13308/viora_integrations_qa`, en una instancia independiente bajo `.cache/integration-mysql`, importada del dump del repositorio. No se ejecutaron los scripts antiguos que escriben en la VM.

## Errores encontrados y correcciones

### Chatbot

El historial solo vivía en `sessionStorage` y el backend aceptaba el historial suministrado por el navegador. Las tablas de conversaciones no se utilizaban. Ahora el backend recupera y guarda los turnos en `conversacion_ia` y `mensaje_ia`, siempre vinculados al usuario autenticado. La interfaz carga el historial propio desde el servidor y «Nueva conversación» cierra la anterior sin borrar registros. Se eliminó la lectura inicial del historial compartido del navegador para evitar mostrar mensajes de otra cuenta en el mismo equipo.

Se valida el usuario activo y su rol en la base, la suscripción del Cliente y un token CSRF. Los errores inesperados devuelven JSON genérico. Las firmas/metadatos del modelo se conservan en las llamadas a herramientas; se propaga el identificador de llamada cuando existe y se excluyen partes de pensamiento de la respuesta visible.

Las acciones de visita/solicitud existentes se conservan. Antes, `confirmado=true` generado por el modelo bastaba para escribir. Ahora se conserva la propuesta en la sesión y se exige un «sí» explícito en un turno posterior; el servidor usa los argumentos de la propuesta confirmada. El Administrador conserva herramientas de solo lectura.

**Operativo en pruebas:** autenticación, historial persistente, recuperación propia, nueva conversación y error seguro sin clave. **Pendiente:** respuesta real de Gemini y prueba completa de herramientas desde la interfaz con una clave válida. No se simuló una respuesta de Gemini para declararlo operativo.

### PayPal

La verificación original no registraba identificadores de transacciones y solo actualizaba la suscripción al regresar del navegador. No existía sincronización de renovaciones/cancelaciones. Se extrajo un servicio común conservando la comprobación de pertenencia mediante `custom_id`, plan, usuario y proveedor Sandbox.

Ahora exige un plan mensual de US$10, una transacción `COMPLETED` de USD10 consultada directamente en PayPal, estado `ACTIVE`, siguiente cobro futuro y ausencia de saldo vencido. `APPROVAL_PENDING` y `ACTIVE` sin pago nunca activan una cuenta. El importe dejó de aceptarse por ser simplemente mayor o igual a 10.

La tabla nueva `suscripcion_pago` guarda los pagos de suscripción por ID único de transacción, sin mezclarlos con pagos de alquiler/mantenimiento ni sobrescribir sus registros. Confirmaciones repetidas son idempotentes. Cancelación, suspensión, vencimiento y reembolso afectan la autorización según el estado actual consultado al proveedor. La cuenta deshabilitada administrativamente no se reactiva indiscriminadamente. Los bloqueos MySQL serializan conciliaciones de una misma suscripción.

`login/paypal_webhook.php` verifica las cabeceras y el evento mediante la API oficial de verificación antes de consultar y persistir datos. Si falta `PAYPAL_WEBHOOK_ID` devuelve 503 y no procesa el evento. Una firma rechazada no produce escrituras. Los eventos fuera de orden provocan una consulta actual al proveedor; no se aplica ciegamente el estado del evento. La validación remota sigue el [procedimiento oficial de PayPal](https://developer.paypal.com/api/rest/webhooks/rest/).

Se añadió conciliación por CLI y comprobación de suscripción en cada acceso al catálogo y al chat, para bloquear sesiones abiertas tras cancelación/vencimiento. El retorno del navegador tiene CSRF y reintenta la verificación hasta cuatro veces sin crear otra suscripción. El pipeline conserva variables y secretos opcionales previamente instalados mediante `--update-env-vars` y `--update-secrets`.

**Operativo con fixtures:** pago confirmado, alta, renovación, cancelación, vencimiento, reembolso, rechazo de importe/moneda/usuario/plan incorrectos y prevención de duplicados. Las respuestas PayPal y de firma se simularon en estas pruebas. **Pendiente:** OAuth real, aprobación con cuenta Personal Sandbox, transacción real de prueba, entrega de webhook firmado y activación comprobada en el entorno Cloud Run.

El conciliador consulta los últimos 31 días; no hace recuperación histórica anterior. Si PayPal anuncia una página adicional, falla de forma segura en lugar de activar usando datos incompletos. La paginación adicional y una recuperación histórica necesitan ampliación si aparecen esos casos; no se certifican como implementadas.

La revisión del código no encontró un flujo activo de captura PayPal para alquiler/mantenimiento: `registrar_pago.php` utiliza el registro financiero existente. Los datos demo de `transaccion_paypal` no acreditan una captura con el proveedor. No se añadió un flujo de cobro distinto ni se alteraron esos pagos.

### Comprobantes fiscales

Se conservan E31/E32 y E34, sus payloads, numeración delegada al proveedor y el PDF existente. No se generó ninguna secuencia fiscal local ni se modificó el PDF. La DGII exige autorización previa para emitir; esta revisión no certifica al emisor ni sus reglas tributarias: [requisitos de e-CF de la DGII](https://dgii.gov.do/cicloContribuyente/facturacion/comprobantesFiscales/Paginas/comprobantesFiscalesElectronicos.aspx).

La protección de reenvío estaba solo en un endpoint y podía sufrir carreras. Ahora está centralizada en el servicio, con bloqueo y estado persistido antes del POST. Se conserva un documento con ID o e-NCF, y también se bloquean estados inciertos o legados que necesitan conciliación. Un fallo de red, respuesta 5xx o éxito sin identificador queda `PENDIENTE_CONCILIACION`; no autoriza repetir la emisión. Si el proceso cae durante el envío, `ENVIANDO` también bloquea un segundo envío. La desactivación fiscal queda `PENDIENTE_CONFIGURACION`, no como error de conexión.

Se validan fecha, conceptos positivos, RNC del comprador y concordancia del total con las líneas e ITBIS. Se detectó una factura del **dump local** con total 20500 y conceptos 18000+2500: el generador anterior enviaba 24190 al añadir 18% sin comprobarlo contra el total almacenado. Ahora se rechaza esa discrepancia. No se corrigieron automáticamente importes de facturas existentes ni se afirma que el mismo caso esté presente en Cloud SQL.

Las actualizaciones conservan IDs y e-NCF existentes cuando un resultado nuevo no los incluye. Se permite configurar URL y datos del emisor mediante variables, manteniendo los valores anteriores como respaldo. Los valores heredados identifican a UTESA; deben confirmarse con el titular autorizado antes de emitir.

`mantenimientos/consultar_ecf.php?factura_id=ID` permite al Administrador consultar el registro fiscal local y declara expresamente esa fuente. **No consulta el proveedor ni acredita un estado actualizado en DGII.** Las rutas públicas probadas `/openapi.json`, `/api-docs` y `/swagger.json` del proveedor devolvieron 404; falta el contrato de consulta/autenticación. No se inventó un endpoint remoto.

**Operativo en pruebas:** preparación E32 con datos consistentes, cálculo existente por centavos, validaciones, consulta local y protección de documentos aceptados/inciertos. **Pendiente:** envío y consulta en ambiente fiscal de pruebas, estados reales del proveedor, validación E31/E34 completa, numeración autorizada y relación documental definitiva del proveedor con los pagos. Las tablas `factura_pago` y `comprobante_fiscal` se inspeccionaron por esquema; sus registros no se alteraron.

## Archivos de aplicación modificados o añadidos

| Archivo | Cambio |
|---|---|
| `Viora/conf/integraciones.sql` | Migración aditiva de pagos de suscripción |
| `Viora/conf/subscription_access.php` | Autorización vigente del Cliente |
| `Viora/conf/ecf.php` | Variables para URL y emisor, conservando valores previos |
| `Viora/login/paypal_service.php` | API Sandbox, firma, validación y conciliación transaccional |
| `Viora/login/paypal_webhook.php` | Receptor de eventos verificados |
| `Viora/login/verificar_suscripcion.php` | Reutiliza el servicio desde el retorno |
| `Viora/login/suscripcion.php` | CSRF y reintentos de confirmación |
| `Viora/mantenimientos/catalogo.php` | Verificación de acceso en sesiones abiertas |
| `Viora/mantenimientos/chatbot_historial.php` | Persistencia y recuperación por usuario |
| `Viora/mantenimientos/chatbot_api.php` | Historial propio, autorización y confirmación de acciones |
| `Viora/mantenimientos/chatbot.js` | Sincronización del historial con el servidor |
| `Viora/mantenimientos/chatbot_widget.php` | CSRF y versión del recurso JavaScript |
| `Viora/mantenimientos/ecf_service.php` | Validación y emisión serializada sin duplicación automática |
| `Viora/mantenimientos/consultar_ecf.php` | Consulta administrativa local |
| `deployment/reconcile-paypal.php` | Conciliación por CLI, no accesible por web |
| `deployment/cloudbuild-production.yaml` | Preservación de configuración opcional |
| `.gitignore` | Incluir explícitamente la migración nueva |

Además: los dos scripts de prueba, sus resultados JSON, metadatos de Cloud SQL, resultado de sintaxis y este informe.

## Pruebas ejecutadas

**40 comprobaciones aprobadas:** 29 de servicio/base de datos y 11 HTTP, registradas en `integration-test-results.json` e `integration-http-results.json`. Se ejecutaron con PHP local 8.2.12 y MariaDB 10.4.32 de XAMPP. El destino usa PHP 8.3 y MySQL 8; no se presenta la prueba local como una certificación funcional de ese destino. Se verificó además la sintaxis de 63 archivos PHP sin errores; véase `integration-lint-results.json`.

```powershell
C:\xampp\php\php.exe deployment/test-integrations.php
python deployment/test-integration-http.py
git diff --check
```

Los scripts requieren la instancia QA local en puerto 13308, la base `viora_integrations_qa` importada desde `bd/viora (2).sql` y la migración nueva aplicada **solo a esa copia**. Contienen ese destino fijo para impedir reutilizarlos accidentalmente contra producción. La prueba HTTP cambia contraseñas de Administradores exclusivamente en el fixture; nunca debe apuntarse a datos reales. No se inició un servicio permanente de Windows.

No se realizaron pruebas de navegador en esta intervención. No se ejecutó una compilación ni despliegue nuevo de Cloud Run. Las pruebas antiguas de la VM no se contabilizan como resultados de hoy.

## Configuración y pasos pendientes de activación

1. Revisar el código, aplicar `Viora/conf/integraciones.sql` a la base de destino antes de desplegar y desplegar estos cambios. La migración se probó localmente; no se aplicó a Cloud SQL. No se requiere modificar tablas originales.
2. Instalar una clave Gemini válida con `deployment/set-gemini-secret.py` (entrada privada existente), elegir un modelo disponible y desplegar una revisión que consuma la nueva versión. Probar pregunta/respuesta, historial y herramientas desde las cuentas QA de ambos roles.
3. Recuperar OAuth Sandbox y comprobar el plan mensual de USD10. Completar la aprobación final con cuenta Personal Sandbox y verificar transacción, alta y catálogo. `APPROVAL_PENDING` solo se resuelve cuando el comprador completa la aprobación en PayPal; el código no fuerza ese estado.
4. Registrar en la misma aplicación PayPal Sandbox el webhook HTTPS `/login/paypal_webhook.php`; configurar su ID exacto en `PAYPAL_WEBHOOK_ID`. Suscribir eventos `BILLING.SUBSCRIPTION.*` relevantes y `PAYMENT.SALE.COMPLETED`, `PAYMENT.SALE.REFUNDED`, `PAYMENT.SALE.REVERSED`. Probar entrega real, repetición, cancelación y renovación. No basta el simulador sin verificación válida.
5. Programar `deployment/reconcile-paypal.php` en un ejecutor privado con las mismas variables y acceso a Cloud SQL. El Docker actual solo copia `Viora/`; ese script CLI **no forma parte de la imagen web**. Preparar un job privado que incluya ese archivo y la aplicación antes de programarlo. No se creó un cron público ni un recurso de pago adicional.
6. Obtener credenciales fiscales de pruebas (`ECF_API_KEY`), contrato del proveedor para envío/consulta/idempotencia, URL y datos del emisor autorizado. Confirmar los rangos y tipos autorizados con el proveedor/DGII. La configuración fiscal no se incluye actualmente en los secretos obligatorios del pipeline; debe incorporarse en Secret Manager y en el servicio cuando esté disponible.
7. Mantener `ECF_ENABLED=false` hasta completar esas verificaciones. Los registros `ENVIANDO`, `PENDIENTE_CONCILIACION` y errores legados deben conciliarse con el proveedor antes de autorizar otro envío. No limpiar sus identificadores ni cambiar estados para forzar un reintento.
8. La habilitación de PayPal Live y la emisión fiscal real requieren autorización expresa y pruebas completas. No forman parte de la activación realizada hoy.

El proyecto queda con correcciones revisables y pruebas locales reproducibles. Permanecen pendientes la integración real de los proveedores y la validación funcional en el destino, por las dependencias externas enumeradas.
