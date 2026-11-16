# Guía de Pruebas Manuales del Sistema VIORA

**Fecha:** 15 de Noviembre de 2026  
**Sistema:** VIORA Real Estate Management  
**Ambiente:** Producción (Google Cloud Run)  
**URL:** https://viora-1055595302579.us-central1.run.app

---

## 1. Pruebas de Accesibilidad Básica

### 1.1 Verificar que el sistema está disponible

Abre tu navegador e ingresa a:
```
https://viora-1055595302579.us-central1.run.app
```

**Esperado:**
- ✅ La página carga sin errores
- ✅ Se muestra la interfaz en español
- ✅ La barra de dirección muestra un candado (HTTPS)
- ✅ No hay mensajes de certificado inválido

**Si falla:**
- Verificar conexión a internet
- Esperar 30 segundos (Cold start de Cloud Run)
- Limpiar cache del navegador (Ctrl+Shift+Delete)

---

## 2. Flujo de Registro e Ingreso

### 2.1 Crear una cuenta de prueba

**Paso 1:** Ir a la página de registro
```
https://viora-1055595302579.us-central1.run.app/login/registro.php
```

**Paso 2:** Completar el formulario
- Usuario: `test_usuario_1` (o con fecha actual para unicidad)
- Email: `test@viora.local`
- Contraseña: `Test123!@#`
- Confirmar: `Test123!@#`

**Esperado:**
- ✅ Formulario carga sin errores
- ✅ Campos de entrada están disponibles
- ✅ Botón "Registrarse" es interactivo

**Paso 3:** Enviar formulario
- Click en "Registrarse"

**Esperado:**
- ✅ Se redirige a página de login
- ✅ Mensaje "Registro exitoso" o similar
- ✅ Puede ingresarse la sesión

### 2.2 Ingresar al sistema

**Paso 1:** Ir a página de login
```
https://viora-1055595302579.us-central1.run.app/login/login.php
```

**Paso 2:** Completar credenciales
- Usuario: `test_usuario_1` (o la del paso 2.1)
- Contraseña: `Test123!@#`

**Paso 3:** Click en "Iniciar Sesión"

**Esperado:**
- ✅ Se redirige al catálogo de propiedades
- ✅ La interfaz muestra botones de navegación
- ✅ Se puede ver información del usuario en la esquina superior

### 2.3 Probar Rate Limiting

**Objetivo:** Verificar que el sistema bloquea intentos de login fallidos

**Paso 1:** Ir a página de login

**Paso 2:** Intentar login 5 veces con contraseña INCORRECTA
- Usuario: `test_usuario_1`
- Contraseña: `WrongPassword123` (o similar incorrecta)

**Esperado después de 5 intentos fallidos:**
- ✅ Mensaje: "Demasiados intentos fallidos. Intente en 15 minutos"
- ✅ Formulario bloqueado
- ✅ El bloqueo dura 15 minutos

---

## 3. Navegación por el Sistema

### 3.1 Explorar el Catálogo de Propiedades

**Una vez logueado como cliente:**

**Paso 1:** Ir a Catálogo (generalmente el menú principal)
```
https://viora-1055595302579.us-central1.run.app/mantenimientos/catalogo.php
```

**Esperado:**
- ✅ Se carga lista de propiedades disponibles
- ✅ Se pueden ver detalles: precio, ubicación, descripción
- ✅ Hay botones para "Ver detalles", "Solicitar", etc.

**Paso 2:** Hacer clic en una propiedad

**Esperado:**
- ✅ Se muestra información detallada
- ✅ Se puede ver galería de fotos (si existen)
- ✅ Hay opción para hacer solicitud de alquiler/venta

### 3.2 Hacer una Solicitud de Propiedad

**Paso 1:** En catálogo, click en "Solicitar" para una propiedad

**Paso 2:** Completar formulario
- Tipo de solicitud: Alquiler o Venta
- Fecha deseada: Seleccionar fecha
- Comentarios: Agregar si es necesario

**Paso 3:** Click en "Enviar Solicitud"

**Esperado:**
- ✅ Mensaje de confirmación: "Solicitud enviada"
- ✅ La solicitud aparece en historial
- ✅ Admin recibe notificación

---

## 4. Flujo de Administrador

### 4.1 Ingresar como Administrador

**Si tienes credenciales de admin:**

**Paso 1:** Ir a login
```
https://viora-1055595302579.us-central1.run.app/login/login.php
```

**Paso 2:** Ingresar credenciales de administrador
- Usuario: `admin` (o usuario admin existente)
- Contraseña: (contraseña del admin)

**Paso 3:** Login

**Esperado:**
- ✅ Se redirige a Dashboard de Administrador (principal.php)
- ✅ Se pueden ver estadísticas y opciones de gestión
- ✅ Menú muestra opciones administrativas

### 4.2 Gestionar Solicitudes de Clientes

**Una vez en Dashboard Admin:**

**Paso 1:** Ir a sección de Solicitudes o Requests
- Menú: Mantenimientos > Solicitudes (u opción similar)

**Esperado:**
- ✅ Lista de solicitudes pendientes
- ✅ Se pueden ver detalles: cliente, propiedad, fecha
- ✅ Botones para "Aprobar", "Rechazar"

**Paso 2:** Aprobar una solicitud
- Click en "Aprobar"

**Esperado:**
- ✅ Mensaje: "Solicitud aprobada"
- ✅ Estado cambia a "Aprobado"
- ✅ Se puede generar contrato

### 4.3 Generar y Descargar Contrato

**Paso 1:** En solicitud aprobada, click en "Generar Contrato"

**Esperado:**
- ✅ Se abre PDF en nueva ventana
- ✅ PDF contiene: partes, términos, firma
- ✅ Se puede descargar (Ctrl+S)

**Paso 2:** Descargar documento
- Click derecho > Guardar como
- O click en ícono de descargar

**Esperado:**
- ✅ Archivo se descarga como .pdf
- ✅ Puede abrirse en Adobe Reader u otro visor

---

## 5. Facturación

### 5.1 Crear una Factura

**Como Admin:**

**Paso 1:** Ir a Facturación
```
https://viora-1055595302579.us-central1.run.app/mantenimientos/facturacion.php
```

**Paso 2:** Click en "Nueva Factura"

**Paso 3:** Completar datos
- Cliente: Seleccionar de lista
- Propiedad: Seleccionar propiedad
- Monto: Cantidad a facturar
- Descripción: Detalle del servicio

**Paso 4:** Click en "Generar Factura"

**Esperado:**
- ✅ Factura se crea
- ✅ Se puede descargar PDF
- ✅ Estado inicial: "Pendiente de Pago"

### 5.2 Descargar Factura en PDF

**Paso 1:** En lista de facturas, click en factura

**Paso 2:** Click en "Descargar PDF" o ícono de descarga

**Esperado:**
- ✅ Se abre PDF en navegador
- ✅ PDF muestra: número, cliente, items, total
- ✅ Se puede descargar

---

## 6. Flujo PayPal (Cuando URL esté actualizada)

### 6.1 Realizar Pago de Prueba (Sandbox)

**NOTA:** Este flujo requiere que el webhook PayPal esté actualizado a:
```
https://viora-1055595302579.us-central1.run.app/login/paypal_webhook.php
```

**Paso 1:** Como cliente, ir a opciones de pago

**Paso 2:** Seleccionar "Pagar con PayPal"

**Paso 3:** Se abre PayPal Sandbox
- Email: `sb-buyer@business.example.com` (o usuario sandbox)
- Contraseña: Usar credenciales de sandbox

**Paso 4:** Confirmar pago

**Esperado:**
- ✅ Se redirige de vuelta a VIORA
- ✅ Mensaje: "Pago procesado"
- ✅ Estado de factura cambia a "Pagado"
- ✅ Suscripción se activa (si es plan)

### 6.2 Verificar en PayPal Dashboard

**Paso 1:** Ir a https://developer.paypal.com/dashboard/

**Paso 2:** Ir a Sandbox > Transactions

**Esperado:**
- ✅ Se ve la transacción realizada
- ✅ Monto coincide
- ✅ Estado: Completado

---

## 7. Chatbot Gemini (Cuando esté activado)

### 7.1 Acceder al Chatbot

**Como usuario logueado:**

**Paso 1:** En esquina inferior derecha de la página, buscar widget del chat

**Paso 2:** Click en botón de chat

**Esperado:**
- ✅ Se abre ventana de chat
- ✅ Se puede escribir preguntas
- ✅ Gemini responde (en español)

### 7.2 Hacer Preguntas al Asistente

**Ejemplos de preguntas:**
- "¿Cuál es el proceso para alquilar una propiedad?"
- "¿Cuánto cuesta una membresía?"
- "¿Cuáles son tus horarios de atención?"

**Esperado:**
- ✅ El chatbot responde en español
- ✅ Respuestas son relevantes
- ✅ Se puede mantener conversación

---

## 8. Checklist de Validación Completo

Usar este checklist para validar que todo funciona:

```
SEGURIDAD Y ACCESO
[ ] HTTPS funciona (candado visible)
[ ] Se puede registrar usuario
[ ] Se puede hacer login
[ ] Rate limiting bloquea después de 5 intentos
[ ] Logout funciona
[ ] Acceso a /login/principal.php requiere admin

NAVEGACIÓN Y INTERFACES
[ ] Catálogo de propiedades carga
[ ] Se pueden ver detalles de propiedades
[ ] Menú es responsive en móvil
[ ] Botones son clickeables
[ ] Formularios cargan correctamente

FUNCIONALIDADES CLIENTE
[ ] Puede hacer solicitud de propiedad
[ ] Puede ver historial de solicitudes
[ ] Puede descargar contrato en PDF
[ ] Puede ver facturas pendientes
[ ] Puede descargar factura en PDF

FUNCIONALIDADES ADMIN
[ ] Dashboard carga
[ ] Puede ver solicitudes pendientes
[ ] Puede aprobar/rechazar solicitudes
[ ] Puede generar contrato en PDF
[ ] Puede generar factura
[ ] Puede ver estado de pagos

INTEGRACIONES
[ ] PayPal (cuando webhook esté actualizado)
  [ ] Se puede iniciar pago
  [ ] Redirige correctamente
  [ ] Estado de pago se actualiza
  
[ ] Gemini (cuando esté activado)
  [ ] Widget de chat visible
  [ ] Puede hacer preguntas
  [ ] Responde en español
  
[ ] IMECF (cuando credenciales estén disponibles)
  [ ] Facturas se generan con comprobante fiscal
  [ ] Número de comprobante aparece en factura

PERFORMANCE
[ ] Página inicial carga en < 3 segundos
[ ] Login responde en < 2 segundos
[ ] PDF genera en < 5 segundos
[ ] No hay errores 500
[ ] No hay timeouts
```

---

## 9. Registro de Problemas

Si encuentras problemas durante las pruebas:

### 9.1 Documentar el Problema

**Para cada problema, anotar:**

1. **Qué pasos realizaste:**
   ```
   Ejemplo: Intenté login como admin, completé usuario/contraseña correcta,
   hice click en "Iniciar Sesión"
   ```

2. **Qué esperabas:**
   ```
   Esperaba: Que se muestre el dashboard de admin
   ```

3. **Qué sucedió realmente:**
   ```
   Sucedió: Página en blanco o error 500
   ```

4. **Hora exacta y navegador:**
   ```
   15 Nov 2026 22:35:00 UTC - Chrome 130.0 en Windows 11
   ```

### 9.2 Errores Comunes y Soluciones

| Error | Causa | Solución |
|-------|-------|----------|
| Página en blanco | Cold start de Cloud Run | Esperar 30 segundos e intentar de nuevo |
| Error 500 | Problema en base de datos | Revisar logs en Cloud Run |
| Formulario no carga | Bloqueador de JavaScript | Permitir JavaScript |
| Descarga no funciona | Bloqueador de pop-ups | Permitir descargas |
| PayPal no redirige | Webhook no actualizado | Actualizar URL en PayPal Developer Dashboard |

---

## 10. Comandos Útiles

### Ver Logs de Cloud Run

```bash
gcloud run services logs read viora --region=us-central1 --limit=50
```

### Verificar Secretos Configurados

```bash
gcloud secrets list | grep viora
gcloud secrets versions list viora-production-gemini-api-key
```

### Probar Webhook Localmente (curl)

```bash
curl -X POST https://viora-1055595302579.us-central1.run.app/login/paypal_webhook.php \
  -H "Content-Type: application/json" \
  -d '{"test":"webhook"}'
```

---

**Generado:** 15 de Noviembre de 2026  
**Sistema:** VIORA v1.0 - Google Cloud Run
