# PLAN DE PRUEBAS EXHAUSTIVAS DEL SISTEMA VIORA
**Fecha:** 15 de Noviembre de 2026  
**Objetivo:** Validar cada módulo, funcionalidad y botón del sistema

## MÓDULOS A PROBAR (69 archivos PHP)

### GRUPO 1: AUTENTICACIÓN Y SEGURIDAD
- [ ] Login (login.php, procesar_login.php)
- [ ] Registro (registro.php, guardar_registro.php)
- [ ] Rate Limiting (5 intentos/15 min)
- [ ] Logout (logout.php)
- [ ] Sesiones (verificación en Cloud SQL)

### GRUPO 2: SUBSCRIPCIONES Y PAGOS
- [ ] Suscripciones (suscripcion.php)
- [ ] Verificación de suscripción (verificar_suscripcion.php)
- [ ] PayPal Sandbox (paypal_config.php)
- [ ] Webhook PayPal (paypal_webhook.php)
- [ ] Eventos PayPal (paypal_events.php)
- [ ] Servicio PayPal (paypal_service.php)

### GRUPO 3: GESTIÓN DE USUARIOS Y ROLES
- [ ] Usuarios (configuracion/usuarios.php)
- [ ] Roles (configuracion/roles.php)
- [ ] Acceso por rol (require_admin.php)

### GRUPO 4: PROPIEDADES Y CONDOMINIOS
- [ ] Condominios (condominio.php)
- [ ] Edificios (edificio.php)
- [ ] Apartamentos (apartamento.php)
- [ ] Administrar propiedades (administrar_propiedad.php)
- [ ] Catálogo (catalogo.php)

### GRUPO 5: SOLICITUDES Y CONTRATOS
- [ ] Solicitudes (solicitudes.php)
- [ ] Administrar solicitudes (administrar_solicitudes.php)
- [ ] Contratos (contratos.php)
- [ ] Ver contrato (ver_contrato.php)
- [ ] Generación PDF (factura_pdf.php, pdf_simple.php)

### GRUPO 6: CLIENTES
- [ ] Gestión de clientes (clientes.php)
- [ ] Consulta de clientes (clientes_consulta.php)
- [ ] Búsqueda de clientes (buscar_clientes_factura.php)

### GRUPO 7: FACTURACIÓN
- [ ] Facturación (facturacion.php)
- [ ] Guardar factura (guardar_factura.php)
- [ ] Factura JSON (facturacion_json.php)
- [ ] Factura PDF (factura_pdf.php)
- [ ] Acceso a factura (factura_acceso.php)
- [ ] Documento factura (factura_documento.php)
- [ ] Render factura (factura_render.php)
- [ ] Mis facturas (mis_facturas.php)
- [ ] Consulta facturas (consulta_facturas.php)

### GRUPO 8: FACTURACIÓN ELECTRÓNICA (IMECF/ECF)
- [ ] Servicio ECF (ecf_service.php)
- [ ] Validación ECF (ecf_validacion.php)
- [ ] Consultar ECF (consultar_ecf.php)
- [ ] Emitir nota de crédito (emitir_nota_credito.php)
- [ ] Reenviar ECF (reenviar_ecf.php)
- [ ] Configuración ECF (conf/ecf.php)

### GRUPO 9: CUENTAS POR COBRAR
- [ ] CXC Clientes (cxc_clientes.php)
- [ ] Consulta de cobros (consulta_cobros.php)
- [ ] Registrar pago (registrar_pago.php)

### GRUPO 10: ASISTENTE IA
- [ ] Chatbot API (chatbot_api.php)
- [ ] Herramientas chatbot (chatbot_herramientas.php)
- [ ] Historial chatbot (chatbot_historial.php)
- [ ] Widget chatbot (chatbot_widget.php)
- [ ] Configuración Gemini (conf/gemini.php)

### GRUPO 11: MANTENIMIENTOS Y REPARACIONES
- [ ] Mantenimiento (mantenimiento.php)
- [ ] Mantenimiento mensual (mantenimiento_mensual.php)
- [ ] Reparaciones (reparaciones.php)
- [ ] Servicio reparaciones (reparaciones_servicio.php)
- [ ] Solicitar reparación (solicitar_reparacion.php)
- [ ] Reparación (reparacion.php)

### GRUPO 12: UTILIDADES
- [ ] Menu lateral (menu_lateral.php)
- [ ] Health check (healthz.php)
- [ ] Index (index.php)
- [ ] QR Simple (qr_simple.php)
- [ ] Obtener propiedades cliente (obtener_propiedades_cliente.php)
- [ ] Vencimiento contratos (vencimiento_contratos.php)
- [ ] Consulta visitas (consulta_visitas.php)

## FLUJOS CRÍTICOS A VALIDAR

### Flujo 1: Registro e Ingreso de Cliente
1. Acceder a /login/registro.php
2. Completar formulario con datos válidos
3. Verificar que se registra en BD
4. Ir a login
5. Ingresar credenciales
6. Verificar sesión en Cloud SQL
7. Acceder a catálogo

### Flujo 2: Pago de Suscripción PayPal
1. Seleccionar plan de suscripción
2. Iniciar pago PayPal Sandbox
3. Autorizarse en PayPal
4. Volver a VIORA
5. Verificar que suscripción está activa
6. Webhook recibe evento
7. Estado en BD se actualiza

### Flujo 3: Solicitud de Propiedad
1. Cliente ve catálogo
2. Selecciona propiedad
3. Hace solicitud
4. Admin ve solicitud
5. Admin la aprueba
6. Sistema genera contrato
7. Cliente descarga contrato PDF

### Flujo 4: Facturación Completa
1. Admin crea factura
2. Se genera factura PDF
3. Se envía a IMECF (si está configurado)
4. Se genera comprobante E31/E32/E34
5. Cliente ve factura en mis_facturas
6. Cliente descarga PDF
7. Admin registra pago
8. Factura pasa a "Pagado"
9. CXC se actualiza

### Flujo 5: Consultas y Reportes
1. Admin accede a cxc_clientes
2. Ve estado de cobros por cliente
3. Filtra por rango de fechas
4. Exporta si es posible
5. Cliente accede a mis_facturas
6. Ve historial de facturas
7. Descarga facturas anteriores

## ERRORES A BUSCAR

- [ ] Errores HTTP (500, 404, 403, 401)
- [ ] Errores de BD (conexión, queries)
- [ ] Errores de seguridad (SQL injection, XSS, CSRF)
- [ ] Validaciones faltantes en formularios
- [ ] Campos requeridos sin validación
- [ ] Redirecciones rotas
- [ ] Links que no funcionan
- [ ] Botones deshabilitados sin razón
- [ ] PDFs que no se generan
- [ ] Datos no se guardan en BD
- [ ] Sesiones que se pierden
- [ ] Permisos no se validan
- [ ] Datos de otros usuarios visibles
- [ ] Integraciones no funcionan

## CHECKLIST DE PRUEBA

Para cada módulo:
1. ✅ Acceder sin errores
2. ✅ Verificar que todos los campos cargan
3. ✅ Probar formularios con datos válidos
4. ✅ Probar formularios con datos inválidos
5. ✅ Verificar validaciones (mostrar errores claros)
6. ✅ Verificar que datos se guardan en BD
7. ✅ Probar botones y links
8. ✅ Verificar permisos por rol
9. ✅ Verificar que cambios se reflejan en otros módulos
10. ✅ Verificar que no hay datos de otros usuarios visibles

## ESTADO DE PRUEBAS
(Se actualizará conforme se completen)

