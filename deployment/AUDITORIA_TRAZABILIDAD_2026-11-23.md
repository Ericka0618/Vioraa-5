# Auditoría y Trazabilidad en VIORA

## Estado al 23 de noviembre de 2026

La implementación inicial se verificó con PHP 8.2.12 y MariaDB 10.4.32 en un entorno local aislado. Incluye historial paginado, filtros, indicadores, cuatro gráficos, diferencias de valores, líneas de tiempo, acceso a documentos mediante los controladores existentes y 20 reportes exportables en PDF y XLSX. La última sección recoge la verificación posterior en PHP 8.3, MySQL 8.0 y Google Cloud.

## Archivos y base de datos

| Cambio | Archivos |
|---|---|
| Captura, catálogo y consultas | `Viora/conf/auditoria.php`, `auditoria_catalogo.php`, `auditoria_consulta.php` |
| Exportación y Gemini | `Viora/conf/auditoria_exportacion.php`, `auditoria_ia.php` |
| Interfaz y autorización | `Viora/configuracion/auditoria.php`, `Viora/estilos/auditoria.css`, `Viora/mantenimientos/menu_lateral.php`, `Viora/conf/require_admin.php` |
| Contexto de base de datos y configuración | `Viora/conf/conexion.php`, `Viora/configuracion/roles.php`, `Viora/configuracion/usuarios.php` |
| Accesos, registro y PayPal | `Viora/login/procesar_login.php`, `logout.php`, `guardar_registro.php`, `paypal_webhook.php`, `verificar_suscripcion.php`, `deployment/reconcile-paypal.php` |
| Resultados de procesos | `Viora/mantenimientos/apartamento.php`, `clientes.php`, `condominio.php`, `contratos.php`, `edificio.php`, `factura_pdf.php`, `mantenimiento_mensual.php`, `registrar_pago.php`, `reparaciones.php`, `solicitar_reparacion.php`, `solicitudes.php`, `ver_contrato.php` |
| Migración, verificación y pruebas | `deployment/migrate-auditoria.php`, `verify-auditoria.php`, `test-auditoria.php`, `test-auditoria-http.py`, este informe |

- **`auditoria`** conserva eventos, identidad del actor, hora UTC con microsegundos, resultado, origen, IP observada por el servidor, correlación, referencias y cambios de campos permitidos. Índices por fecha, actor, módulo, acción, resultado, seguimiento y entidad.
- **`auditoria_referencia`** indexa tipo de entidad e identificador en cada evento. Su única clave foránea apunta a la bitácora. Las referencias sobreviven a la eliminación de las entidades de negocio.
- **135 disparadores de negocio** cubren INSERT, UPDATE y DELETE explícitos en 45 tablas InnoDB. Seis disparadores adicionales sellan eventos, indexan referencias y bloquean modificaciones o eliminaciones en ambas tablas de auditoría. La migración instala disparadores y crea tablas nuevas. Los datos previos sirven como relaciones actuales cuando hace falta resolver un vínculo, y la bitácora comienza vacía.

## Matriz de cobertura

| Módulo | Cambios transaccionales | Consultas, fallos y trazabilidad |
|---|---|---|
| Autenticación y seguridad | Eventos independientes de inicio y cierre de sesión | Intentos sin sesión autorizada y denegaciones de los controles instrumentados |
| Clientes y usuarios | `persona`, `persona_rol`, `usuario` | Alta de cliente, acceso, cambios de estado y asociación de cuenta con suscripción |
| Configuración | `role`, `usuario.id_rol`, `concepto_pago` | Consulta de usuarios y roles, cambios en el modelo de permisos por rol vigente |
| Condominios y edificios | `condominio`, `edificio` | Consultas de administración, cambios y bajas |
| Catálogo y propiedades | `apartamento`, `propiedad`, `ocupacion`, `visita_propiedad` | Catálogo, propiedades asignadas, consultas de visitas y precio anterior/nuevo |
| Compra y alquiler | `solicitud_propiedad`, `contrato`, `contrato_detalle_persona`, `renovacion_contrato`, `documento` | Solicitud, cambio de aprobación, contrato y vínculos con inmueble, cliente, facturas y pagos. PDF de contrato sujeto al control del documento original |
| Mantenimiento | `solicitud_mantenimiento`, `mantenimiento`, `mantenimiento_solicitud`, `suplidor` | Solicitud y estados, proveedor relacionado, ejecución y costo cuando se registran |
| Facturación y cuentas por cobrar | `factura`, `factura_detalle`, `viora_factura`, `viora_factura_detalle`, `factura_documento` | Emisión, saldo anterior/nuevo, estados y consultas de cobros. PDF de factura mediante su controlador existente |
| Pagos | `pago`, `factura_pago`, `viora_pago`, `transaccion_paypal` | Intentos, aplicación a factura, cambio de saldo y fallos HTTP del registro de pago |
| Suscripciones PayPal | `suscripcion`, `suscripcion_pago`, `paypal_evento`, `bi_suscripcion_pago` | Verificación, webhook y conciliación programada. Correlación por cuenta e identificadores persistidos de suscripción |
| Fiscal y e-CF | `comprobante_fiscal`, `factura_ecf`, `factura_ecf_nota`, `ecf_envio`, `ecf_nota_operacion` | Comprobante, nota, envío y cambios de estado relacionados con factura o pago |
| Notificaciones | `notificacion` | Creación y cambios de estado. El cuerpo del mensaje queda fuera de la bitácora |
| Reportes BI | `bi_propiedad_info`, `bi_gasto`, `bi_inversion`, `bi_reporte_historial` | Consultas y generación por la API existente. Se registra metadata del reporte |
| IA | `conversacion_ia`, `mensaje_ia` | Consulta del asistente, resultado HTTP y uso del asistente de Auditoría sin copiar mensajes a la bitácora |
| Auditoría | Inserción de eventos propios sin recursión | Consultas, detalles, líneas de tiempo, exportaciones, IA y acceso denegado |

El identificador de seguimiento une las operaciones de una petición. La línea de tiempo cruza sesiones mediante claves de negocio persistidas y referencias de eventos, incluidas las entidades eliminadas después de activar la auditoría. Para contratos y solicitudes agrega alta y suscripción del cliente vinculado. Los eventos muestran estados de negocio cuando hay un valor permitido disponible. Los reportes y las líneas de tiempo usan los límites visibles de 2,000 y 1,500 eventos, respectivamente. Las exportaciones de líneas de tiempo mantienen orden cronológico.

## Seguridad e integridad

- La entrada del módulo exige sesión, usuario activo y rol administrador activo comprobados en MySQL. Los formularios de exportación y Gemini verifican CSRF. Las respuestas son privadas y se excluyen de caché. El módulo expone operaciones de lectura y descarga.
- La bitácora usa listas de columnas autorizadas. Se excluyen credenciales, tokens, contraseñas, tarjetas, rutas privadas, PDF binarios, texto libre y cuerpos de proveedores. Si una modificación solo toca un campo protegido, queda el evento sin el valor del campo.
- Los cambios de negocio y sus eventos se confirman en la misma transacción. Las operaciones que revierten registran el fallo por una conexión independiente cuando la ruta puede identificarlo. Si la base de datos está caída, la aplicación emite un aviso técnico con el identificador de seguimiento en sus logs.
- Las huellas SHA-256 y los disparadores protegen la integridad ordinaria. `verify-auditoria.php` comprueba las huellas y las referencias derivadas, y compara un punto de control previo para detectar cambios o eliminación de filas ya archivadas. El punto de control debe guardarse fuera de MySQL con permisos independientes de la cuenta de aplicación.
- Gemini interpreta preguntas como filtros permitidos. VIORA aplica consultas `SELECT` parametrizadas y presenta el total y los eventos en que se basa el resultado. A Gemini se envían la pregunta y los identificadores seleccionados, mientras los registros consultados se procesan en VIORA. La aplicación no acepta SQL del proveedor.
- La trazabilidad refleja las relaciones y etapas que el negocio realmente guarda. Los accesos y fallos se cubren mediante estados HTTP, autenticación y manejadores instrumentados. Los cambios DDL, escrituras en archivos fuera de las rutas observadas, fallos de red previos a la aplicación y cascadas de claves foráneas de MySQL quedan fuera de los disparadores de filas. La cobertura presentada corresponde a datos reales de módulos de negocio, separados de las tablas BI de demostración.

## Pruebas realizadas

| Prueba | Resultado observado |
|---|---|
| Migración en esquema local aislado y segunda ejecución | 135 disparadores de negocio en ambas ejecuciones. La primera dejó el historial vacío |
| Creación, modificación sin duplicado y borrado | La actualización de alquiler conservó 15,000.00 → 18,000.00. La actualización idéntica se omitió. La eliminación conservó el evento |
| Reversión y fallo independiente | El cambio transaccional revertido desapareció. El fallo de prueba permaneció |
| Flujo de negocio con relaciones | Se verificó con datos sintéticos el recorrido cliente, suscripción, solicitud, aprobación desde otra conexión, contrato, factura, e-CF, documento y pago |
| Compra, mantenimiento y PayPal | Se verificaron el rechazo de una compra sin mezclar el alquiler del mismo cliente, la relación de mantenimiento con su ejecución y la relación de suscripción con webhook y pago sintéticos |
| Accesos desde HTTP | Inicio y cierre mediante rutas reales, intento incorrecto, visitante redirigido, cliente rechazado y administrador autorizado |
| Interfaz y exportación desde HTTP | Indicadores, filtro y orden, comparación, huella, línea de tiempo y acceso al PDF original de factura con sesión. PDF válido y XLSX ZIP/XML válido con filas del filtro. Exportación con CSRF inválido rechazada |
| Navegador Chromium | Se comprobaron los cuatro gráficos, la navegación por detalles y seguimiento, la descarga de la línea de tiempo y la vista móvil de 390 píxeles |
| Dos procesos PHP y sesiones independientes | Dos solicitudes HTTP de pago completo sobre una factura de QA. Una se aplicó y la otra se rechazó. El rechazo apareció en su línea de tiempo |
| Integridad y privacidad | Edición bloqueada en BD. Contraseña excluida. Verificador confirmó el historial anterior y las referencias. Respaldo y restauración locales recuperaron 98 eventos con la misma huella |
| Gemini | Control de error y auditoría verificados desde HTTP en un entorno sin clave. La credencial de staging estaba sin configurar para la prueba real |
| Infraestructura de Google Cloud | Consulta de solo lectura confirmó Cloud SQL MySQL 8.0, respaldo automático exitoso del 23 de noviembre a las 05:49 de Santo Domingo y revisión activa `viora-00027-viz`. Pendientes la migración, la restauración de ensayo y las pruebas de carga e integraciones en Cloud |

Para repetir las pruebas, cargue únicamente el esquema del dump en una base local aislada llamada `viora_audit_qa`, aplique las migraciones previas de documentos, integraciones, e-CF y Reportes BI, y configure `DB_HOST=127.0.0.1`, `DB_PORT` y `DB_NAME`. Ejecute la migración de auditoría y `php deployment/test-auditoria.php RUTA_TEMPORAL_FIXTURE`. Inicie dos servidores PHP con directorio público `Viora`, acceso a esa base y la extensión ZIP. Use `python deployment/test-auditoria-http.py RUTA_TEMPORAL_FIXTURE PUERTO_1 PUERTO_2`. El fixture contiene una contraseña de prueba y debe quedarse fuera del repositorio.

## Despliegue y recuperación

1. **Validar en staging.** Utilizar MySQL 8.0 y el mismo esquema de VIORA. Con una cuenta de migración persistente, confirmar las 45 tablas y permisos para crear tablas, índices, claves y disparadores. Revisar el registro binario y el flag `log_bin_trust_function_creators` antes de instalar disparadores. El flag no figuraba entre las opciones explícitas del recurso inspeccionado. El cambio de flags, si corresponde, afecta toda la instancia. [Configuración de disparadores en Cloud SQL](https://docs.cloud.google.com/sql/docs/mysql/diagnose-issues)
2. **Respaldar y ensayar recuperación.** Antes de la intervención en producción, tomar un respaldo bajo demanda, esperar el estado `SUCCESSFUL` y registrar ID, fecha y responsable. Restaurar primero a una instancia separada y verificar recuentos, documentos y acceso. Confirmar qué bases comparten la instancia y el alcance de una restauración antes de considerar cualquier recuperación sobre el original. [Restauración de Cloud SQL](https://docs.cloud.google.com/sql/docs/mysql/backup-recovery/restore)
3. **Migrar en una ventana controlada.** Conectar por el mecanismo privado de Cloud SQL, configurar `DB_HOST` o `DB_SOCKET`, `DB_PORT`, `DB_USER`, `DB_PASSWORD` desde Secret Manager y `DB_NAME` explícito. Ejecutar `php deployment/migrate-auditoria.php NOMBRE_BASE` y comprobar los 135 disparadores de negocio y los seis de integridad. La DDL confirma por separado. Si una instalación se interrumpe, corregir la causa y volver a ejecutar la misma migración. Mantener el usuario definidor de los disparadores. La cuenta de aplicación necesita SELECT en ambas tablas, INSERT en `auditoria` y sus permisos normales de negocio. Reservar DDL y cambios directos de la bitácora para el rol operativo correspondiente.
4. **Validar el candidato.** Usar el pipeline existente de Cloud Build y Artifact Registry para construir el commit aprobado. El Dockerfile ya incluye el directorio `Viora` y las extensiones mysqli, curl y zip. Validar la revisión candidata de Cloud Run con bases, sesiones y secretos del ambiente, el acceso a documentos vía Cloud Storage y solicitudes concurrentes en dos instancias. Probar Gemini con una pregunta de QA cuando staging tenga una clave operativa y probar PayPal/e-CF con credenciales de prueba de cada ambiente. Promover tráfico después de estas verificaciones.
5. **Comprobar integridad y preparar reversión.** Ejecutar `php deployment/verify-auditoria.php NOMBRE_BASE` y archivar el JSON resultante en almacenamiento privado de acceso independiente. Para una comprobación posterior, pasar la ruta del JSON como segundo argumento. Ante una regresión web, regresar el tráfico a la revisión anterior y mantener la bitácora. Las escrituras de esa revisión se registrarán como sistema si no aportan identidad de auditoría. Para recuperar datos, empezar por la instancia restaurada de ensayo y coordinar la reconciliación de escrituras posteriores antes de cualquier restauración sobre la instancia original.

## Verificación de despliegue del 23 de noviembre de 2026

| Área | Evidencia observada |
|---|---|
| Código | Commits locales `ab07eeb` y `3fb6419`. El segundo valida el esquema antes de ejecutar DDL y corrige la reapertura de sesión desde el menú |
| Respaldo de Cloud SQL | Respaldo bajo demanda `1795485705977`, `SUCCESSFUL`, finalizado a las 22:03 de Santo Domingo. Restauración `abd9f515-de94-4f66-af98-795800000032` finalizada a las 22:30 en `viora-audit-restore-1123`. Se leyeron el esquema, registros representativos y la huella SHA-256 de un PDF recuperado |
| Compatibilidad | PHP 8.3.37 con MySQL 8.0.46 nativo y MySQL 8.0.46-google restaurado. El ensayo identificó dos tablas de seguimiento fiscal ausentes, `ecf_envio` y `ecf_nota_operacion`. Se ejecutó la migración aditiva preexistente `Viora/conf/ecf_auditoria.sql`. La repetición de la migración en la restauración conservó los 141 disparadores, con tres por cada tabla de negocio |
| Reportes | Los 20 reportes ejecutaron su consulta y generaron PDF y XLSX. HTTP validó filtros, ordenamiento, diferencias de valores, trazabilidad, permisos, descargas y CSRF. Chromium comprobó las cuatro gráficas, los detalles, la descarga de la línea de tiempo y una pantalla de 390 px |
| Actividad de cliente y administrador | Un cliente consultó el catálogo y su actividad apareció con su rol en la bitácora. Dos sesiones administrativas solicitaron el mismo pago en QA. Una lo aplicó y la otra quedó como fallo trazable |
| Gemini | Consulta real satisfactoria mediante la clave configurada en Secret Manager. Se usó una pregunta sintética y el módulo aplicó los filtros a la base aislada |
| PayPal | OAuth y consulta de plan en Sandbox respondieron HTTP 200, con plan activo. Se comprobaron también, con respuestas simuladas, conciliación, reintentos idempotentes, reversión y orden de eventos. La validación se ejecutó sin cobros |
| e-CF | Suite de respuestas simuladas aprobada. El envío deshabilitado quedó registrado como fallo correlacionado. El ambiente fiscal externo requiere credenciales de prueba y los comprobantes de prueba se mantuvieron en QA |
| Cloud Storage | Se comprobó que un PDF de BI existente coincide con la huella en Cloud SQL y devuelve 403 a una descarga anónima. La aplicación registra creación de contratos y resultados de réplica de facturas y reportes. La prueba de fallo de réplica conservó un PDF válido en SQL |
| Integridad referencial | La consulta a MySQL 8.0.46 de producción mostró que el esquema no contiene FK con `ON DELETE` o `ON UPDATE CASCADE` o `SET NULL`. La bitácora conserva las filas eliminadas explícitamente. Las cascadas futuras requieren captura en la operación que las inicia |
| Compilación y escala | Cloud Build final `6702febe-1ad2-441f-a894-e0987d855b40` exitoso. La revisión privada final `viora-audit-qa-00003-8nz` pasó la suite HTTP con el módulo habilitado, Gemini y sesiones compartidas. Cloud Logging identificó dos instancias distintas que respondieron HTTP 200 al módulo autenticado |
| Migración de producción | `viora_production` en `viora-staging-db`. Se comprobaron `auditoria`, `auditoria_referencia`, los dos prerrequisitos fiscales y los 141 disparadores. La bitácora estaba vacía tras migrar. Los primeros tres eventos generados por las verificaciones de acceso pasaron el control de huellas y referencias |
| Servicio oficial | La revisión `viora-00029-jek` recibió el 100 % del tráfico. Se verificaron HTTP 200 para salud e inicio de sesión y redirección HTTP 302 al inicio de sesión desde Auditoría cuando no hay sesión. La denegación quedó registrada en Cloud SQL. [Auditoría y Trazabilidad en VIORA](https://viora-6ikx2ezlba-uc.a.run.app/configuracion/auditoria.php) |
| Conciliación de PayPal | El job programado `viora-paypal-reconcile` se actualizó con la imagen de Cloud Build `7514c805-d033-491b-a56e-67453c945cf6`. La actualización conservó la programación existente y no inició una conciliación manual |

### Alcance de las comprobaciones de producción

La matriz de permisos con sesiones de cliente y administrador, las exportaciones y Gemini se comprobaron en la revisión privada con la misma imagen web desplegada en producción. La URL oficial se comprobó sin sesión y el rechazo quedó registrado. En producción no había una cuenta administrativa dedicada para automatizar la prueba autenticada. Para el ensayo de datos y pagos se usaron entidades sintéticas en la base aislada. No se crearon cuentas, facturas, cobros ni comprobantes fiscales de prueba en producción.

Los disparadores protegen la edición y eliminación ordinarias de la bitácora. La cuenta de base de datos preexistente de producción conserva privilegios administrativos. La protección frente a cambios privilegiados se apoya en la comparación con puntos de control archivados aparte. Las modificaciones de objetos realizadas directamente por actores externos en Cloud Storage quedan fuera de los eventos de aplicación de VIORA.
