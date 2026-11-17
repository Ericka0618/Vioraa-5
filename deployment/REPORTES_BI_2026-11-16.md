# Reportes e Inteligencia de Negocios — VIORA

## Entrega

Módulo administrativo `/mantenimientos/reportes.php`, con el estilo verde de VIORA y la organización de las referencias: indicadores, centro de reportes con acordeones, búsqueda, categorías, paginación, filtros, resultados, asistente e historial privado.

Incluye filtros de fechas, origen real/demostración/combinado, dos zonas para comparaciones, condominio, edificio, propiedad y horizonte de proyección de 1–12 meses. Hay 12 indicadores enlazados y gráficos de barras, líneas y distribución circular. Los resultados se obtienen de MySQL; los gráficos se dibujan localmente sin dependencias CDN.

Se pueden registrar gastos operativos, inversiones, tipología física y fecha de disponibilidad desde la sección «Completar gastos, inversiones y datos de propiedades». Se valida el vínculo propiedad–condominio, el origen y la referencia única del movimiento. Los mantenimientos realizados se consultan desde su módulo existente; no deben registrarse otra vez como gastos operativos.

## Los 30 reportes

| ID | Categoría | Reporte |
|---|---|---|
|1|Finanzas|Zonas con mayor rentabilidad|
|2|Finanzas|Zonas con menor rentabilidad|
|3|Finanzas|Condominios con mayor rentabilidad|
|4|Finanzas|Propiedades que generan mayores ingresos|
|5|Finanzas|Comparación de ingresos y gastos por condominio|
|6|Finanzas|Ingresos mensuales por alquiler|
|7|Finanzas|Ingresos por venta de propiedades|
|8|Finanzas|Evolución mensual y anual de los ingresos|
|9|Finanzas|Proyección de ingresos futuros|
|10|Finanzas|Rentabilidad según el tipo de propiedad|
|11|Propiedades|Propiedades disponibles por zona|
|12|Propiedades|Propiedades ocupadas por zona|
|13|Propiedades|Porcentaje de ocupación por condominio|
|14|Propiedades|Zonas con mayor demanda de propiedades|
|15|Propiedades|Propiedades con mayor tiempo sin ocupar|
|16|Propiedades|Precio promedio de alquiler por zona|
|17|Propiedades|Precio promedio de venta por zona|
|18|Propiedades|Comparación entre propiedades vendidas y alquiladas|
|19|Clientes|Clientes con mayor historial de pagos|
|20|Clientes|Clientes con pagos pendientes o atrasados|
|21|Clientes|Cantidad de clientes activos e inactivos|
|22|Clientes|Solicitudes aprobadas, rechazadas y pendientes|
|23|Clientes|Zonas con mayor cantidad de solicitudes|
|24|Facturación|Facturas pagadas y pendientes|
|25|Facturación|Cuentas por cobrar por cliente y condominio|
|26|Facturación|Métodos de pago más utilizados|
|27|Facturación|Comportamiento mensual de los cobros|
|28|Mantenimiento|Condominios con mayores gastos de mantenimiento|
|29|Mantenimiento|Solicitudes de mantenimiento por estado, frecuencia y propiedad|
|30|Mantenimiento|Ingresos, renovaciones, vencimientos y cancelaciones de suscripciones|

## Datos y migración

Se guardó un respaldo local en `.cache/reportes/viora-before-bi.sql`, fuera de Git. Respaldo administrado de Cloud SQL previo a los cambios: **1794878242419**, estado **SUCCESSFUL**. La migración CLI `deployment/migrate-reportes.php` se ejecutó dos veces en Cloud SQL y no duplicó registros.

No se alteraron las columnas ni los registros de las tablas originales. Se agregaron estas tablas reales:

| Tabla | Finalidad | Registros iniciales de negocio |
|---|---|---|
|`bi_gasto`|Gastos operativos verificados, con atribución opcional a inmueble|0|
|`bi_inversion`|Inversiones verificadas|0|
|`bi_propiedad_info`|Tipología física y disponibilidad conocida|0|
|`bi_suscripcion_pago`|Conciliaciones complementarias, con referencia única|0|
|`bi_reporte_historial`|Resultado inmutable, filtros, autor, método, PDF y SHA-256|Crece al generar reportes|
|`bi_demo_lote`|Control de la carga idempotente|1|

Se agregaron **542 registros de negocio de demostración**, aislados en 18 tablas, además del registro de control del lote:

| Tabla de demostración | Registros |
|---|---:|
|`bi_demo_condominio`|30|
|`bi_demo_edificio`|30|
|`bi_demo_apartamento`|30|
|`bi_demo_propiedad`|30|
|`bi_demo_persona`|30|
|`bi_demo_usuario`|30|
|`bi_demo_contrato`|30|
|`bi_demo_factura`|30|
|`bi_demo_factura_pago`|32|
|`bi_demo_ocupacion`|30|
|`bi_demo_solicitud_propiedad`|30|
|`bi_demo_solicitud_mantenimiento`|30|
|`bi_demo_mantenimiento`|30|
|`bi_demo_suscripcion`|30|
|`bi_demo_gasto`|30|
|`bi_demo_inversion`|30|
|`bi_demo_suscripcion_pago`|30|
|`bi_demo_propiedad_info`|30|

Las seis zonas se representan mediante `condominio.ciudad`, respetando el modelo existente: Santiago, Santo Domingo, Punta Cana, La Vega, Puerto Plata y Moca. Las relaciones entre las tablas aisladas tienen claves foráneas. Las cuentas DEMO están desactivadas y no se incorporan a la tabla real de usuarios. No se crearon cobros, eventos PayPal, comprobantes fiscales, contratos operativos ni notificaciones reales.

## Definiciones y límites de los datos

- Cobros inmobiliarios: filas de `factura_pago` vinculadas a facturas que no están anuladas ni en borrador. No se suman las tablas históricas `pago` y `viora_pago`, para evitar duplicar las mismas operaciones. Los importes son cobros brutos de factura, incluidos conceptos e impuestos; no se presentan como ingreso contable sin impuestos.
- Gastos: gastos operativos registrados y costos de mantenimientos realizados según su fecha de realización. Los trabajos programados no se contabilizan como gastos realizados.
- Beneficio = cobros − gastos. Margen = beneficio / cobros × 100. ROI = beneficio / inversión acumulada a la fecha final × 100. Un denominador inválido o la falta de gastos/inversión devuelve **N/D**. Los márgenes calculados son provisionales mientras no se confirme la integridad de los costos.
- Los gastos comunes permanecen en el condominio: no se distribuyen artificialmente por apartamento, edificio o tipología. Los indicadores de esos niveles solo incluyen costos directos. Se advierte esa limitación en la interfaz y el PDF.
- La ocupación se reconstruye con fechas de `ocupacion`; se excluyen vendidos y propiedades destinadas solo a venta de la base ocupable. Los estados de disponibilidad, precios, solicitudes y saldos de facturas son actuales. No existe información suficiente para reconstruir saldos históricos fiables.
- La tipología física se guarda en `bi_propiedad_info`; el inventario original de apartamentos se clasifica por defecto como Apartamento hasta que el administrador detalle otra tipología.
- La proyección utiliza el promedio de tres meses completos, incluidos meses sin cobros. Requiere esos tres meses dentro del período. Es una **estimación**, sin garantías ni ajuste estacional.
- Las suscripciones utilizan los pagos confirmados de `suscripcion_pago` y conciliaciones complementarias sin repetir la misma referencia. USD y DOP permanecen separados. Una fecha de próximo pago no se interpreta como ingreso cobrado. Las cancelaciones reflejan el estado actual: el esquema existente no conserva la fecha histórica de cada cancelación. Los pagos de renovación cuentan transacciones posteriores al mes de inicio; no prueban una renovación contractual distinta.

No se inventaron inversiones, gastos históricos ni fechas faltantes para completar indicadores reales. El administrador puede registrar los datos verificados desde el módulo. Los resultados y documentos explican estas limitaciones.

## Gemini y seguridad

El asistente del módulo y el flotante comparten la conversación guardada en MySQL. Gemini puede descubrir el catálogo y solicitar reportes mediante `catalogo_reportes` y `generar_reporte_bi`. No recibe acceso SQL libre. Las funciones verifican el rol real del usuario en MySQL, validan filtros y ejecutan consultas parametrizadas. Los nuevos registros financieros se introducen únicamente mediante formularios administrativos con CSRF; la IA no puede registrarlos.

Consultas admitidas: zonas más/menos rentables, condominios con mayores ingresos, comparación Santiago–Santo Domingo, pagos pendientes, ocupación, demanda, cobros del mes, proyecciones y «Genera un PDF de ese resultado». El contexto conserva reporte y filtros para preguntas posteriores. Los resultados no exponen cédulas, correos ni teléfonos. El modelo recibe hasta 100 grupos por herramienta y se le informa del total; el PDF conserva todas las filas.

Se reparó la inicialización del endpoint existente del asistente: lectura JSON, conexión, identificación y autorización del usuario, recuperación de conversación y acciones de historial/nueva conversación. Las credenciales permanecen en Secret Manager y variables de entorno.

## PDF e historial

Los 30 reportes generan PDF mediante `PdfSimple`, con logo, fecha y zona horaria, período, filtros, resumen e indicadores, tabla completa, gráfico cuando hay una serie numérica, interpretación, límites, conclusiones, recomendaciones y páginas numeradas. Se corrigió la reutilización de la misma imagen JPEG entre páginas para evitar duplicar el logo en el archivo.

El historial conserva el resultado y el PDF originales; abrir un documento antiguo no vuelve a consultar datos actuales. Guarda autor, método manual/IA, fecha, filtros y SHA-256. Cloud SQL conserva la copia privada durable y el volumen existente de Google Cloud Storage guarda una réplica en `reportes/`. Los endpoints comprueban autorización administrativa antes de entregar PDF o resultados. Se verificaron 30 réplicas privadas y la coincidencia del hash del documento más reciente.

## Archivos

Nuevos: `Viora/mantenimientos/reportes.php`, `reportes.js`, `reportes_api.php`, `reportes_catalogo.php`, `reportes_servicio.php`, `reportes_documento.php`, `reportes_ia.php`; `Viora/estilos/reportes.css`; `deployment/migrate-reportes.php`, `test-reportes.php`, `test-reportes-browser.py`, `release-reportes.py` y los archivos de evidencia `reportes-*.json`.

Modificados: `menu_lateral.php`, `chatbot_api.php`, `chatbot_herramientas.php`, `chatbot_widget.php`, `chatbot.js`, `pdf_simple.php` y `ecf_service.php`. En la copia local se eliminó una declaración duplicada de `ecf_ahora`, ya definida en el validador fiscal compartido. La prueba del renderizador de facturas funciona en memoria, sin emitir ni registrar una factura. La imagen publicada conserva los archivos fiscales de la versión de producción anterior, comprobados por hash.

## Verificación y publicación

Las comprobaciones incluyen 90 combinaciones de reportes y origen de datos, igualdad de cobros contra SQL, fórmulas financieras, aislamiento de demostración, filtros inválidos/inyección, snapshots e integridad PDF, autorización de herramientas y seguimiento de contexto. Las pruebas de navegador revisan inicio de sesión real, 12 indicadores, búsqueda, categorías, paginación, formularios, los 30 PDF con extracción de texto y numeración, descarga, historial, móvil, CSRF y denegación de acceso anónimo y de cliente con rol de sesión falsificado.

Gemini se verifica mediante llamadas reales, incluida la pregunta de seguimiento y generación del PDF. Un intento recibió HTTP 503 del proveedor; la prueba admite un único reintento para ese error temporal y falla si persiste.

El build se construye sobre la imagen de producción fijada por digest. Comprueba hashes de archivos protegidos de PayPal, facturación y configuración fiscal. La revisión candidata se valida sin tráfico público. Se conserva la configuración del servicio, secretos, identidad, volúmenes y flags actuales. La evidencia definitiva de revisión, build, URL, almacenamiento y validaciones se guarda en `deployment/reportes-release.json` y `deployment/reportes-browser-results.json`.

La publicación consiste en promover únicamente la revisión candidata validada. El commit incluye `[skip ci]` porque este despliegue se realiza con la imagen verificada; así se evita lanzar además el workflow general, que tiene una configuración distinta a la producción existente.

Resultado final: revisión **viora-00027-viz**, publicada con **100% del tráfico**. URL: **https://viora-6ikx2ezlba-uc.a.run.app/mantenimientos/reportes.php**. Se aprobaron **147 comprobaciones de navegador** en la revisión candidata y otras **147 en producción**, con llamadas reales a Gemini, los 30 PDF y controles de acceso. Se verificaron las réplicas de Google Cloud Storage y que el documento no puede descargarse de ese almacenamiento de forma anónima.

No quedan bloqueos técnicos de despliegue. Los datos históricos inexistentes —inversiones, gastos no registrados, fechas exactas de cancelación y saldos históricos reconstruidos— conservan las limitaciones descritas; no se fabricaron valores para completarlos.
