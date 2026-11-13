# PayPal Sandbox VIORA — 12 de noviembre de 2026

La revisión `viora-00006-gis` recibe el 100 % del tráfico público. Se desplegó el paquete autorizado sobre la imagen del chatbot; la configuración protegida de Cloud Run coincide con la anterior. Gemini, Cloud SQL y los archivos fiscales de la imagen se conservaron. PayPal permanece en Sandbox y la emisión fiscal deshabilitada.

## Correcciones

Se retiraron dos bytes anteriores a `<?php` en `guardar_registro.php`, que impedían iniciar correctamente la sesión y redirigir después del registro. El login permite retomar una suscripción pendiente sin registrar otra cuenta. La confirmación conserva CSRF y reintentos; el catálogo comprueba la autorización vigente.

El servicio común valida propietario, plan mensual de USD10, transacción real y estado actual del proveedor. El ID único de pago impide duplicados. La conciliación usa ventanas históricas y paginación restringida al proveedor; cuando Sandbox devuelve un listado vacío, recupera eventos autenticados y consulta la venta original. Un evento antiguo no revierte reembolsos ni cancelaciones. Las suscripciones legadas sin un pago recuperable se señalan para revisión, conservando su estado anterior.

El webhook verifica la firma con PayPal antes de escribir. Los reembolsos se relacionan mediante la venta original. `paypal_evento` registra únicamente identificadores procesados; no almacena el cuerpo privado del comprador.

Archivos principales: `Viora/login/{guardar_registro,procesar_login,suscripcion,verificar_suscripcion,paypal_service,paypal_reconciliation,paypal_events,paypal_webhook}.php`, `Viora/mantenimientos/catalogo.php`, `Viora/conf/integraciones.sql`, `deployment/reconcile-paypal.php`. El pipeline conserva variables y secretos opcionales. Se excluyó `.cache` del contexto de Cloud Build.

## Base de datos y credenciales

OAuth real y plan activo comprobados con las credenciales existentes en Secret Manager. No se imprimieron claves. Se hizo una copia privada completa antes de aplicar la migración aditiva de `suscripcion_pago` y `paypal_evento`; no se reemplazaron tablas originales ni registros financieros. El respaldo permanece fuera de Git.

## Evidencia

- `paypal-real-flow-results.json`: 13 comprobaciones reales aprobadas: aprobación Sandbox, pago de USD10, registro único, acceso al catálogo, reembolso, cancelación y revocación de acceso. La suscripción QA de este ciclo terminó cancelada y reembolsada.
- `paypal-real-webhook-results.json`: 14 comprobaciones aprobadas con eventos reales reenviados por PayPal y firmas verificadas: activación, pago, reembolso, cancelación, repetición y entrega fuera de orden. Una petición sin firma fue rechazada.
- `paypal-candidate-smoke.json` y `paypal-public-smoke.json`: 12 comprobaciones por entorno, incluyendo registro, SDK real, recuperación de sesión pendiente, CSRF, restricción de acceso sin pago y login administrativo.
- `paypal-event-test-results.json`: 16 pruebas locales con proveedor simulado; no sustituyen las transacciones reales. `paypal-registration-results.json`: seis pruebas locales de navegador.
- Cloud Build `776c2cba-6099-4a55-8d2c-b36832c335e7`: SUCCESS, PHP 8.3, Apache, health check y comparación de los archivos protegidos con la imagen anterior.

Webhook público: `/login/paypal_webhook.php`, registrado en la aplicación Sandbox. Se comprobaron entregas reales después del despliegue.

## Conciliación y límites pendientes

Se crearon los recursos privados autorizados `viora-paypal-reconcile` y `viora-paypal-reconcile-hourly`, cada hora a minuto cero, zona `America/Santo_Domingo`. Scheduler invoca la API autenticada de Cloud Run; no existe un cron público. El resultado de la primera ejecución se registra en `paypal-scheduler-result.json`.

La segunda aprobación desde el botón público necesita contrastarse con la cuenta QA; no se considera completada por abrir la ventana. El cierre de la sesión de automatización impidió conservar evidencia del callback de esa segunda prueba. El primer ciclo real y los webhooks sí tienen evidencia independiente.

Una renovación mensual real requiere esperar el siguiente ciclo. La lógica de renovación, suspensión y reversión se probó con fixtures; no se presenta como una renovación o suspensión real ya ocurrida. Dos suscripciones legadas activas no tienen una transacción histórica recuperable: requieren conciliación histórica y no se deshabilitaron arbitrariamente.

Los cambios se preparan en `main` incluyendo los archivos Gemini ya desplegados, para preservarlos en el repositorio. No se autoriza ni realiza push en esta intervención. Los cambios fiscales locales anteriores quedan fuera del paquete y del índice preparado para esta entrega.
