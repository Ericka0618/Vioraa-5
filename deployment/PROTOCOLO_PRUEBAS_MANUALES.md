# PROTOCOLO DE PRUEBAS MANUALES POR MÓDULO - VIORA
**Fecha:** 15 de Noviembre de 2026  
**Sistema:** VIORA en Producción  
**URL:** https://viora-1055595302579.us-central1.run.app

---

## FASE 1: AUTENTICACIÓN SEGURA

### 1.1 Prueba: Registro de Usuario
**Objetivo:** Verificar que el registro crea usuarios correctamente en BD

**Pasos:**
1. Abrir https://viora-1055595302579.us-central1.run.app/login/registro.php
2. Completar formulario:
   - Usuario: `usuario_test_` + timestamp (ej: usuario_test_20261115_2230)
   - Email: `test_` + timestamp + `@viora.local`
   - Contraseña: `Test123!@#$%`
   - Confirmar: `Test123!@#$%`
3. Click en "Registrarse"

**Validaciones:**
- ✅ Formulario valida campos requeridos
- ✅ Contraseñas deben coincidir
- ✅ Se redirige a login.php
- ✅ Mensaje de éxito
- ✅ Usuario se crea en BD con rol "Cliente"
- ✅ Contraseña está hasheada en BD (no plaintext)

**Comando BD para verificar:**
```sql
SELECT id_usuarios, usuario, email, id_rol, activo FROM usuario WHERE usuario LIKE 'usuario_test_%' ORDER BY fecha_registro DESC LIMIT 1;
```

---

### 1.2 Prueba: Login Exitoso
**Objetivo:** Verificar que login autentica correctamente

**Pasos:**
1. Abrir https://viora-1055595302579.us-central1.run.app/login/login.php
2. Ingresar credenciales del usuario creado en 1.1
3. Click en "Iniciar Sesión"

**Validaciones:**
- ✅ Se redirige a /mantenimientos/catalogo.php (cliente)
- ✅ Sesión se establece (VIORASID cookie)
- ✅ $_SESSION contiene: logged_in, usuario, nombre_usuario, rol, id_usuario/id_usuarios
- ✅ Sesión se guarda en Cloud SQL tabla `sessions` (o equivalente)

---

### 1.3 Prueba: Login Fallido
**Objetivo:** Verificar validación de credenciales

**Pasos:**
1. Abrir login
2. Ingresar usuario válido + contraseña INCORRECTA
3. Click en login

**Validaciones:**
- ✅ Muestra error: "Usuario o contraseña incorrectos"
- ✅ NO redirige
- ✅ No crea sesión

---

### 1.4 Prueba: Rate Limiting (5 intentos / 15 min)
**Objetivo:** Verificar protección contra fuerza bruta

**Pasos:**
1. Hacer 5 logins fallidos consecutivos con mismo usuario
2. Intentar 6to login

**Validaciones:**
- ✅ Después de 5 intentos fallidos, muestra: "Demasiados intentos fallidos. Intente en 15 minutos"
- ✅ El 6to intento es bloqueado completamente
- ✅ Después de 15 minutos, se puede volver a intentar
- ✅ $_SESSION tiene: login_intentos_fallidos, login_último_intento

**Comando BD para verificar:**
```sql
SELECT id_usuarios, login_intentos_fallidos, login_último_intento FROM usuario WHERE usuario='usuario_test_...';
```

---

### 1.5 Prueba: Logout
**Objetivo:** Verificar que logout termina sesión

**Pasos:**
1. Estar logueado
2. Click en "Logout" o ir a /login/logout.php
3. Intentar acceder a /mantenimientos/catalogo.php

**Validaciones:**
- ✅ Se redirige a login.php
- ✅ Sesión se destruye (cookie VIORASID se elimina)
- ✅ $_SESSION se vacía
- ✅ No se puede acceder a páginas protegidas

---

## FASE 2: PROPIEDADES Y CATÁLOGO

### 2.1 Prueba: Ver Catálogo de Propiedades
**Objetivo:** Verificar que el catálogo muestra propiedades

**Pasos:**
1. Loguearse como cliente
2. Acceder a https://viora-1055595302579.us-central1.run.app/mantenimientos/catalogo.php

**Validaciones:**
- ✅ Página carga sin errores
- ✅ Se muestra lista de propiedades (si existen)
- ✅ Cada propiedad muestra: nombre, ubicación, precio, descripción
- ✅ Hay botones: "Ver Detalles", "Solicitar", etc.
- ✅ Paginación funciona (si hay muchas propiedades)
- ✅ Filtros/búsqueda funcionan

---

### 2.2 Prueba: Ver Detalles de Propiedad
**Objetivo:** Verificar vista detallada de propiedad

**Pasos:**
1. En catálogo, click en una propiedad

**Validaciones:**
- ✅ Se abre vista de detalles completos
- ✅ Se muestra: nombre, precio, ubicación, descripción, fotos (si hay)
- ✅ Se puede ver información de condominio/edificio asociado
- ✅ Hay opciones para "Solicitar Alquiler" o "Solicitar Compra"

---

### 2.3 Prueba: Solicitar Propiedad
**Objetivo:** Verificar creación de solicitud de cliente

**Pasos:**
1. En detalles de propiedad, click en "Solicitar"
2. Completar formulario:
   - Tipo de solicitud: Alquiler / Compra
   - Fecha deseada: Seleccionar una futura
   - Comentarios: Agregue texto
3. Click en "Enviar Solicitud"

**Validaciones:**
- ✅ Formulario valida campos requeridos
- ✅ Solicitud se crea en BD tabla `solicitudes`
- ✅ Estado inicial: "Pendiente"
- ✅ Se redirige a página de confirmación
- ✅ Muestra: "Solicitud enviada. El administrador la revisará"
- ✅ Solicitud aparece en "Mis Solicitudes" del cliente

**Comando BD:**
```sql
SELECT * FROM solicitudes WHERE persona_id=? ORDER BY fecha_solicitud DESC LIMIT 1;
```

---

## FASE 3: ADMINISTRADOR - GESTIÓN DE SOLICITUDES

### 3.1 Prueba: Ver Solicitudes Pendientes (Admin)
**Objetivo:** Verificar que admin ve las solicitudes de clientes

**Pasos:**
1. Loguearse como administrador
2. Acceder a Dashboard (principal.php)
3. Ir a Mantenimientos > Solicitudes (o menú equivalente)

**Validaciones:**
- ✅ Se muestran todas las solicitudes pendientes
- ✅ Información visible: cliente, propiedad, tipo, fecha, estado
- ✅ Se pueden filtrar por estado
- ✅ Hay botones: "Aprobar", "Rechazar", "Ver Detalles"

---

### 3.2 Prueba: Aprobar Solicitud
**Objetivo:** Verificar cambio de estado de solicitud

**Pasos:**
1. En lista de solicitudes, seleccionar una pendiente
2. Click en "Aprobar"

**Validaciones:**
- ✅ Solicitud pasa a estado "Aprobada"
- ✅ Se actualiza en BD tabla `solicitudes`
- ✅ Aparece opción para "Generar Contrato"
- ✅ Cliente ve solicitud aprobada en "Mis Solicitudes"

---

### 3.3 Prueba: Rechazar Solicitud
**Objetivo:** Verificar rechazo de solicitud

**Pasos:**
1. En lista de solicitudes, seleccionar una pendiente
2. Click en "Rechazar"
3. Escribir motivo del rechazo (si es requerido)
4. Confirmar

**Validaciones:**
- ✅ Solicitud pasa a estado "Rechazada"
- ✅ Se guarda motivo del rechazo
- ✅ Cliente ve solicitud rechazada con motivo

---

## FASE 4: GENERACIÓN DE DOCUMENTOS PDF

### 4.1 Prueba: Generar Contrato PDF
**Objetivo:** Verificar generación de PDF de contrato

**Pasos:**
1. En solicitud aprobada, click en "Generar Contrato"
2. Se abre PDF en navegador
3. Descargar PDF (Ctrl+S o botón de descarga)

**Validaciones:**
- ✅ PDF se abre sin errores
- ✅ Contrato contiene:
  - Encabezado: "CONTRATO"
  - Datos del cliente (nombre, cédula)
  - Datos de la propiedad (ubicación, precio)
  - Términos y condiciones
  - Fechas (firma, vigencia)
  - Espacio para firmas
- ✅ Folio/número de contrato es único
- ✅ PDF es descargable
- ✅ PDF se almacena en Cloud Storage
- ✅ Contrato se guarda en BD tabla `contratos`

**Comando BD:**
```sql
SELECT contrato_id, solicitud_id, fecha_creacion, estado FROM contratos ORDER BY contrato_id DESC LIMIT 1;
```

---

### 4.2 Prueba: Ver Contrato Anterior (Cliente)
**Objetivo:** Verificar que cliente puede acceder a contratos

**Pasos:**
1. Loguearse como cliente propietario del contrato
2. Ir a "Mis Contratos" o sección equivalente
3. Click en contrato

**Validaciones:**
- ✅ Se abre PDF del contrato
- ✅ PDF muestra la información correcta
- ✅ Puede descargar

---

## FASE 5: FACTURACIÓN

### 5.1 Prueba: Crear Factura (Admin)
**Objetivo:** Verificar creación de factura

**Pasos:**
1. Loguearse como admin
2. Ir a Facturación > Nueva Factura
3. Completar formulario:
   - Cliente: Seleccionar cliente
   - Propiedad: Seleccionar propiedad
   - Concepto: "Alquiler del Mes" (o similar)
   - Monto: 5000
   - Fecha: Hoy
4. Click en "Generar Factura"

**Validaciones:**
- ✅ Factura se crea en BD tabla `factura`
- ✅ Se asigna número único de factura
- ✅ Estado inicial: "Pendiente de Pago"
- ✅ Factura aparece en lista
- ✅ Muestra: número, cliente, monto, fecha

**Comando BD:**
```sql
SELECT factura_id, numero_factura, persona_id, monto, estado_factura FROM factura ORDER BY factura_id DESC LIMIT 1;
```

---

### 5.2 Prueba: Descargar Factura en PDF
**Objetivo:** Verificar generación de PDF de factura

**Pasos:**
1. En lista de facturas, seleccionar una
2. Click en "Descargar PDF"

**Validaciones:**
- ✅ Se abre/descarga PDF
- ✅ PDF contiene:
  - Número de factura
  - Datos de empresa
  - Datos de cliente
  - Detalle de items
  - Montos (subtotal, impuestos, total)
  - Fecha de emisión
  - Fecha de vencimiento
- ✅ PDF se almacena en Cloud Storage
- ✅ Se puede descargar múltiples veces

---

### 5.3 Prueba: Consultar Facturas (Cliente)
**Objetivo:** Verificar que cliente puede ver sus facturas

**Pasos:**
1. Loguearse como cliente
2. Ir a "Mis Facturas"
3. Ver lista de facturas del cliente

**Validaciones:**
- ✅ Se muestran SOLO facturas del cliente logueado
- ✅ NO muestra facturas de otros clientes
- ✅ Información: número, monto, fecha, estado
- ✅ Puede filtrar por fecha/estado
- ✅ Puede descargar PDF

**Validación de seguridad:**
- ✅ Si cliente A intenta acceder a factura de cliente B (ej: URL /mantenimientos/factura_pdf.php?id=999), retorna 403 Forbidden

---

### 5.4 Prueba: Registrar Pago
**Objetivo:** Verificar cambio de estado de factura a pagada

**Pasos:**
1. Como admin, en factura pendiente
2. Click en "Registrar Pago"
3. Completar:
   - Fecha de pago: Hoy
   - Método: Efectivo / Transferencia / PayPal
   - Referencia: (número de recibo, etc)
4. Click en "Confirmar Pago"

**Validaciones:**
- ✅ Estado de factura pasa a "Pagada"
- ✅ Pago se registra en BD tabla `factura_pago`
- ✅ Fecha y método se guardan
- ✅ Cliente ve factura como "Pagada"

**Comando BD:**
```sql
SELECT factura_id, monto, estado_factura, fecha_pago FROM factura WHERE factura_id=?;
SELECT factura_id, monto_pago, fecha_pago, metodo_pago FROM factura_pago WHERE factura_id=?;
```

---

## FASE 6: CUENTAS POR COBRAR

### 6.1 Prueba: Ver CXC por Cliente (Admin)
**Objetivo:** Verificar reportes de cuentas por cobrar

**Pasos:**
1. Como admin, ir a Cuentas por Cobrar > Clientes
2. Ver lista de clientes con saldos pendientes

**Validaciones:**
- ✅ Se muestran clientes con deuda
- ✅ Información: cliente, total facturado, total pagado, saldo pendiente
- ✅ Se puede ordenar por cliente, saldo, fecha
- ✅ Hay opción para ver detalles (facturas pendientes del cliente)
- ✅ Totales coinciden: ∑Facturado - ∑Pagado = Saldo

---

### 6.2 Prueba: Ver Detalles CXC de Cliente
**Objetivo:** Verificar historial de facturas por cliente

**Pasos:**
1. En lista de CXC, click en cliente

**Validaciones:**
- ✅ Se muestran todas las facturas del cliente
- ✅ Información: número, fecha, monto, estado, saldo
- ✅ Facturas pagadas aparecen en verde o con ícono
- ✅ Facturas pendientes aparecen en rojo
- ✅ Total pendiente coincide con la lista anterior
- ✅ Puede filtrar por estado (pagada/pendiente)

---

## FASE 7: FACTURACIÓN ELECTRÓNICA (IMECF/ECF)

### 7.1 Prueba: Generar Comprobante E31 (Factura de Crédito)
**Objetivo:** Verificar generación de comprobante fiscal

**Pasos:**
1. Crear una factura (ver 5.1)
2. En detalle de factura, click en "Generar ECF" o "Comprobante Fiscal"
3. Esperar generación

**Validaciones:**
- ✅ Si IMECF está configurado:
  - Comprobante se envía a IMECF
  - Se guarda en BD tabla `factura_ecf`
  - Se asigna número ENCF (número de comprobante)
  - Se guarda ecf_api_id del proveedor
  - Estado inicial: "Enviado" o "En Revisión"
  
- ✅ Si IMECF NO está configurado:
  - Se muestra mensaje: "Facturación electrónica no configurada"
  - O se crea registro de comprobante local (estado "Pendiente de Envío")

---

### 7.2 Prueba: Consultar Estado ECF
**Objetivo:** Verificar estado de comprobante fiscal

**Pasos:**
1. En factura con ECF, click en "Ver Estado ECF" o similar

**Validaciones:**
- ✅ Se muestra estado del comprobante:
  - Número ENCF
  - Fecha de emisión
  - Estado (Aprobado, Pendiente, Rechazado)
  - Intentos de envío
- ✅ Si está aprobado, se puede descargar XML o PDF del comprobante

---

### 7.3 Prueba: Emitir Nota de Crédito (E34)
**Objetivo:** Verificar emisión de nota de crédito

**Pasos:**
1. En factura con ECF aprobado, click en "Emitir Nota de Crédito"
2. Completa formulario:
   - Razón: "Devolución de mercancía" (o similar)
   - Monto: 500 (parcial) o 5000 (total)
3. Click en "Generar Nota"

**Validaciones:**
- ✅ Si IMECF configurado:
  - Nota de crédito se envía como comprobante E34
  - Se guarda en tabla `factura_ecf_nota`
  - Se referencia la factura original
  - Estado del crédito se guarda
  
- ✅ Si éxito:
  - Se genera número ENCF para la nota
  - Se actualiza saldo si es parcial
  - Factura actualiza su estado a "Parcialmente Pagada" o se crea nuevo registro de pago

---

## FASE 8: PAYPAL Y SUSCRIPCIONES

### 8.1 Prueba: Seleccionar Plan de Suscripción
**Objetivo:** Verificar acceso a planes de pago

**Pasos:**
1. Estar en página de suscripción o similar
2. Ver opciones de planes

**Validaciones:**
- ✅ Se muestran planes disponibles
- ✅ Información: nombre, precio, duración, características
- ✅ Hay botón "Pagar con PayPal" o similar

---

### 8.2 Prueba: Pago PayPal Sandbox
**Objetivo:** Verificar flujo completo de pago

**NOTA:** Esta prueba requiere que webhook PayPal esté actualizado a:
`https://viora-1055595302579.us-central1.run.app/login/paypal_webhook.php`

**Pasos:**
1. Click en "Pagar con PayPal"
2. Se abre PayPal Sandbox
3. Ingresar credenciales Sandbox:
   - Email: `sb-buyer@business.example.com`
   - Contraseña: (contraseña sandbox)
4. Autorizar pago
5. Se redirige a VIORA

**Validaciones:**
- ✅ Se redirige de vuelta a VIORA sin errores
- ✅ Muestra: "Pago procesado correctamente"
- ✅ Transacción se registra en BD tabla `factura_pago`
- ✅ Estado de factura pasa a "Pagada"
- ✅ Suscripción se activa (si es plan)
- ✅ Webhook recibe evento (verificar en logs de Cloud Run):
  ```
  gcloud run services logs read viora --region=us-central1
  ```
  Buscar: "BILLING.SUBSCRIPTION.ACTIVATED" o "PAYMENT.SALE.COMPLETED"

---

### 8.3 Prueba: Renovación de Suscripción
**Objetivo:** Verificar renovación automática

**Pasos:**
1. Verificar fecha de vencimiento de suscripción en BD
2. Esperar a que venza (o crear manual en BD)
3. Sistema intenta renovación

**Validaciones:**
- ✅ En fecha de vencimiento, se intenta renovación
- ✅ Si pago exitoso, suscripción se extiende
- ✅ Si falla, se registra intento fallido
- ✅ Se envía notificación al cliente

---

### 8.4 Prueba: Cancelación de Suscripción (Admin)
**Objetivo:** Verificar cancelación de plan

**Pasos:**
1. Como admin, en sección de suscripciones
2. Seleccionar cliente con suscripción activa
3. Click en "Cancelar Suscripción"

**Validaciones:**
- ✅ Suscripción pasa a estado "Cancelada"
- ✅ Se registra fecha de cancelación
- ✅ Se envía confirmación al cliente
- ✅ Cliente pierde acceso a funciones de suscripción

---

## FASE 9: ASISTENTE GEMINI

### 9.1 Prueba: Acceder al Widget de Chat
**Objetivo:** Verificar que chatbot está disponible

**Pasos:**
1. Loguearse como cliente
2. Buscar widget de chat (esquina inferior derecha generalmente)
3. Click en el chat

**Validaciones:**
- ✅ Se abre ventana de chat
- ✅ Muestra: "¿Cómo puedo ayudarte?" o similar
- ✅ Campo de entrada para mensajes
- ✅ Botón de envío

---

### 9.2 Prueba: Enviar Mensaje al Chatbot
**Objetivo:** Verificar respuesta de Gemini

**Pasos:**
1. En widget de chat, escribir pregunta:
   - "¿Cuál es el proceso para alquilar una propiedad?"
2. Click en enviar

**Validaciones:**
- ✅ Mensaje se envía al servidor
- ✅ API de Gemini responde (en segundos)
- ✅ Respuesta aparece en el chat
- ✅ Respuesta está en español
- ✅ Respuesta es relevante a la pregunta
- ✅ Historial se mantiene (puedo ver mensajes anteriores)

---

### 9.3 Prueba: Historial de Chat
**Objetivo:** Verificar que chat recuerda conversación

**Pasos:**
1. Enviar varios mensajes al chat
2. Cerrar y abrir el widget nuevamente
3. Ver historial

**Validaciones:**
- ✅ Se muestran mensajes anteriores
- ✅ Historial es persistente (guardado en BD)
- ✅ Puede continuar conversación
- ✅ Gemini tiene contexto de mensajes anteriores

---

## FASE 10: VALIDACIONES DE SEGURIDAD

### 10.1 Prueba: Acceso sin Autenticación
**Objetivo:** Verificar que páginas protegidas redirigen a login

**Pasos:**
1. Sin estar logueado, intentar acceder a:
   - /mantenimientos/catalogo.php
   - /mantenimientos/facturacion.php
   - /login/principal.php

**Validaciones:**
- ✅ Se redirige a /login/login.php
- ✅ NO se muestra contenido protegido

---

### 10.2 Prueba: Escalación de Privilegios (Cliente → Admin)
**Objetivo:** Verificar que clientes no pueden acceder a funciones de admin

**Pasos:**
1. Loguearse como cliente
2. Intentar acceder a: /login/principal.php

**Validaciones:**
- ✅ Se redirige a /mantenimientos/catalogo.php
- ✅ NO muestra dashboard de admin
- ✅ NO puede ver menú de admin

---

### 10.3 Prueba: Acceso a Datos Ajenos
**Objetivo:** Verificar que clientes no pueden ver facturas de otros clientes

**Pasos:**
1. Loguearse como cliente A
2. En navegador, cambiar URL a:
   `/mantenimientos/factura_pdf.php?id=999` (factura de otro cliente)

**Validaciones:**
- ✅ Retorna HTTP 403 Forbidden
- ✅ Muestra: "Acceso denegado"
- ✅ NO descarga PDF de factura ajena

---

### 10.4 Prueba: CSRF Protection
**Objetivo:** Verificar tokens CSRF en formularios

**Pasos:**
1. Abrir formulario (ej: crear factura)
2. Ver código HTML (F12)
3. Buscar campo de token CSRF

**Validaciones:**
- ✅ Hay campo oculto con token (ej: `csrf_token`, `_token`)
- ✅ Token es único por sesión
- ✅ Al enviar formulario, token se valida
- ✅ Si falta o es inválido, se rechaza el envío

---

### 10.5 Prueba: SQL Injection
**Objetivo:** Verificar que consultas usan prepared statements

**Pasos:**
1. En búsqueda/filtro, escribir: `' OR '1'='1`
2. Enviar

**Validaciones:**
- ✅ Búsqueda NO retorna todos los registros
- ✅ Se trata como texto literal
- ✅ NO hay error de SQL visible

---

### 10.6 Prueba: XSS (Cross-Site Scripting)
**Objetivo:** Verificar que inputs se escapan

**Pasos:**
1. En formulario, escribir: `<script>alert('XSS')</script>`
2. Enviar

**Validaciones:**
- ✅ NO se ejecuta JavaScript
- ✅ Se muestra como texto: `<script>alert('XSS')</script>`
- ✅ No aparece alert en navegador

---

## RESUMEN DE CHECKLIST

Usar este checklist para validar completud:

```
AUTENTICACIÓN
[_] Registro funciona
[_] Login funciona
[_] Rate limiting bloquea (5 intentos/15 min)
[_] Logout funciona
[_] Sesiones en Cloud SQL

PROPIEDADES
[_] Catálogo carga propiedades
[_] Detalles de propiedad funciona
[_] Solicitudes se crean

ADMINISTRADOR
[_] Dashboard carga
[_] Ver solicitudes pendientes
[_] Aprobar/rechazar solicitudes
[_] Generar contratos PDF
[_] Crear facturas

DOCUMENTOS
[_] Contratos PDF se generan
[_] Facturas PDF se generan
[_] PDFs se descargan
[_] PDFs se almacenan en Cloud Storage

FACTURACIÓN
[_] Facturas se crean
[_] Facturas PDF se generan
[_] Pagos se registran
[_] CXC funciona
[_] Reportes de cobro

FACTURACIÓN ELECTRÓNICA
[_] Comprobantes E31 se generan
[_] Comprobantes E32 se generan
[_] Notas de crédito E34 se generan
[_] Estado ECF se consulta
[_] IMECF recibe comprobantes (si está configurado)

PAYPAL
[_] Planes de suscripción visibles
[_] Pago Sandbox funciona
[_] Webhook recibe eventos
[_] Suscripción se activa
[_] Renovación funciona
[_] Cancelación funciona

GEMINI
[_] Widget de chat visible
[_] Mensajes se envían
[_] Respuestas son relevantes
[_] Historial se mantiene

SEGURIDAD
[_] Acceso sin login redirige
[_] Cliente no accede a admin
[_] Cliente no ve facturas ajenas
[_] CSRF tokens en formularios
[_] No hay SQL injection
[_] No hay XSS

PERFORMANCE
[_] Páginas cargan en <3s
[_] PDFs generan en <5s
[_] No hay errores 500
[_] No hay timeouts
```

---

**Próximo paso:** Ejecutar estas pruebas manualmente en navegador y documentar hallazgos.

