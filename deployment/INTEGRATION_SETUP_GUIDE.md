# VIORA: Guía de Configuración de Integraciones Externas

**Fecha:** 15 de Noviembre de 2026  
**Estado:** Sistema operativo - Integraciones pendientes de configuración

## Resumen

El sistema VIORA está **completamente funcional** y desplegado en Google Cloud Run. Las siguientes integraciones externas requieren credenciales para completar la configuración:

| Integración | Estado | Requiere | Prioridad |
|------------|--------|---------|-----------|
| **PayPal Sandbox** | Configurado | PAYPAL_WEBHOOK_ID | ALTA |
| **IMECF/ECF Fiscal** | Deshabilitado | IMECF_API_KEY + Credenciales | MEDIA |
| **Gemini AI** | Disponible | GEMINI_API_KEY | MEDIA |

---

## 1. PayPal Sandbox - Configuración de Webhooks

### Problema Actual
PayPal está configurado en Sandbox, pero falta el **WEBHOOK_ID** para recibir notificaciones de:
- Pagos completados
- Reembolsos
- Cancelaciones de suscripción
- Renovaciones

Sin esto, los eventos de PayPal no se procesarán automáticamente.

### Pasos para Configurar

#### Paso 1: Acceder a PayPal Developer Dashboard
1. Ir a https://developer.paypal.com/dashboard/
2. Iniciar sesión con la cuenta del negocio
3. Seleccionar la aplicación Sandbox de VIORA

#### Paso 2: Crear o Registrar el Webhook
1. En el Dashboard, ir a **Apps & Credentials**
2. Ir a **Webhooks** o **Event Subscriptions**
3. Crear un nuevo webhook con los siguientes datos:

   **URL del Webhook:**
   ```
   https://viora-1055595302579.us-central1.run.app/login/paypal_webhook.php
   ```

   **Eventos a Suscribirse:**
   - BILLING.SUBSCRIPTION.CREATED
   - BILLING.SUBSCRIPTION.ACTIVATED
   - BILLING.SUBSCRIPTION.UPDATED
   - BILLING.SUBSCRIPTION.CANCELLED
   - BILLING.SUBSCRIPTION.SUSPENDED
   - BILLING.SUBSCRIPTION.RE-ACTIVATED
   - BILLING.SUBSCRIPTION.PAYMENT.FAILED
   - PAYMENT.SALE.COMPLETED
   - PAYMENT.SALE.REFUNDED
   - PAYMENT.SALE.REVERSED

#### Paso 3: Copiar el Webhook ID
4. PayPal generará un **Webhook ID** (formato: `XX-XXX-XXX...`)
5. Copiar este ID

#### Paso 4: Configurar en Secret Manager

**Windows PowerShell (recomendado):**

```powershell
# Requiere autenticación previa: gcloud auth login

$webhook_id = Read-Host "Ingresa el PAYPAL_WEBHOOK_ID" -AsSecureString
$webhook_id_text = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToCoTaskMemUnicode($webhook_id))

gcloud secrets create viora-production-paypal-webhook-id --replication-policy="automatic" --data-file="-" <<EOF
$webhook_id_text
EOF
```

**O desde Linux/macOS:**

```bash
read -sp "Ingresa PAYPAL_WEBHOOK_ID: " WEBHOOK_ID
echo -n "$WEBHOOK_ID" | gcloud secrets create viora-production-paypal-webhook-id --replication-policy="automatic" --data-file=-
```

#### Paso 5: Desplegar Cambios
El archivo `cloudbuild.yaml` ya incluye la variable `PAYPAL_WEBHOOK_ID`. Solo es necesario hacer:

```bash
git push origin main
```

Esto activará GitHub Actions → Cloud Build → Cloud Run con la nueva configuración.

---

## 2. IMECF/ECF - Configuración Fiscal

### Problema Actual
ECF está **deshabilitado** (`ECF_ENABLED=false`) y requiere:
1. Credenciales del proveedor IMECF
2. Datos verificados del emisor (RNC, razón social, etc.)
3. Autorización de tipos de comprobantes (E31, E32, E34)

### Información Requerida

Necesitarás obtener del proveedor fiscal:

```
Credencial de Acceso IMECF:
- URL de API: [Proveedor debe proporcionar]
- API Key / Token: [Generar en plataforma del proveedor]
- Ambiente: pruebas (antes de producción)

Datos del Emisor:
- RNC (9 u 11 dígitos):         [Completar]
- Razón Social:                 [Completar]
- Nombre Comercial:             [Completar]
- Dirección:                    [Completar]
- Teléfono:                     [Completar]
- Correo Electrónico:           [Completar]

Autorización de Tipos:
- E31 (Factura de Crédito):     Si / No
- E32 (Factura de Consumo):     Si / No
- E34 (Nota de Crédito):        Si / No
```

### Configuración en Secret Manager

```bash
# 1. Crear el secreto con la API Key
echo -n "YOUR_IMECF_API_KEY" | gcloud secrets create viora-production-imecf-api-key \
  --replication-policy="automatic" \
  --data-file=-

# 2. Verificar que se creó correctamente
gcloud secrets versions list viora-production-imecf-api-key
```

### Configuración en Cloud Run

Los datos del emisor se configuran como variables de entorno. Para actualizar:

```bash
gcloud run services update viora \
  --region=us-central1 \
  --update-env-vars=\
ECF_ENABLED=true,\
ECF_AMBIENTE=pruebas,\
ECF_CONTRATO_VALIDADO=true,\
ECF_EMISOR_CONFIRMADO=true,\
ECF_EMISOR_RNC=00000000000,\
ECF_EMISOR_RAZON_SOCIAL="Tu Empresa S.A.",\
ECF_EMISOR_NOMBRE_COMERCIAL="Tu Empresa",\
ECF_EMISOR_DIRECCION="Calle Tal #123",\
ECF_EMISOR_TELEFONO="809-000-0000",\
ECF_EMISOR_CORREO="contacto@empresa.com"
```

### Verificación

Después de configurar, prueba:
1. Generar una factura de prueba
2. Verificar que se intente enviar a IMECF
3. Revisar los logs en Cloud Run para errores de conexión

---

## 3. Gemini - Configuración de API Key

### Problema Actual
Gemini está **disponible pero no configurado**. La aplicación intentará usar el chat, pero devolverá error 503 si falta la API Key.

### Obtener API Key

1. Ir a https://ai.google.dev/gemini-api/docs/api-key
2. Autenticarse con cuenta Google
3. Hacer clic en "Create API Key"
4. Copiar la clave (formato: `AIzaSyXXX...`)

### Configuración en Secret Manager

**IMPORTANTE:** NO compartas ni pegues la clave en el chat. Úsala directamente en el comando.

**Windows PowerShell:**

```powershell
$api_key = Read-Host "Ingresa tu GEMINI_API_KEY" -AsSecureString
$api_key_text = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToCoTaskMemUnicode($api_key))

gcloud secrets create viora-production-gemini-api-key --replication-policy="automatic" --data-file="-" <<EOF
$api_key_text
EOF
```

### Despliegue

```bash
git push origin main
```

---

## 4. Verificación Final

### Checklist de Configuración

- [ ] PAYPAL_WEBHOOK_ID configurado en Secret Manager
- [ ] Webhook registrado en PayPal Dashboard
- [ ] IMECF_API_KEY configurado en Secret Manager
- [ ] Datos del emisor ECF completados
- [ ] GEMINI_API_KEY configurado en Secret Manager
- [ ] Cambios en cloudbuild.yaml incluyen todas las variables
- [ ] Último push a main completó el despliegue
- [ ] Cloud Run muestra la revisión más reciente activa

### Prueba de Conexión

Después de configurar cada integración, verifica:

**PayPal:**
```bash
# Verificar que el webhook recibe eventos
curl -v https://viora-1055595302579.us-central1.run.app/login/paypal_webhook.php
```

**Gemini:**
```bash
# Hacer una pregunta al chatbot
# Acceder a https://viora-1055595302579.us-central1.run.app/login/login.php
# Login > Catálogo > Chat (esquina inferior derecha)
```

**ECF:**
```bash
# Crear una factura de prueba
# Verificar que se intente enviar a IMECF
# Revisar logs: gcloud run services logs read viora --region=us-central1
```

---

## 5. Troubleshooting

### PayPal: "Webhook no configurado"
- Verificar que PAYPAL_WEBHOOK_ID existe en Secret Manager
- Confirmar que Cloud Run tiene acceso a este secreto
- Revisar logs de Cloud Run

### ECF: "Credenciales no configuradas"
- Verificar que IMECF_API_KEY existe en Secret Manager
- Confirmar que ECF_ENABLED=true
- Revisar logs para errores de API

### Gemini: "El asistente no está disponible"
- Verificar que GEMINI_API_KEY existe y es válida
- Confirmar que el usuario está logueado
- Revisar que la suscripción del usuario esté activa

---

## 6. Estado Actual del Despliegue

```
Sistema VIORA en producción:
URL: https://viora-1055595302579.us-central1.run.app
Última revisión: 443d60d
Commit: Actualiza configuración de Cloud Run para incluir PAYPAL_WEBHOOK_ID e IMECF_API_KEY

Secretos Configurados:
✓ DB_PASSWORD
✓ PAYPAL_CLIENT_ID
✓ PAYPAL_CLIENT_SECRET
✓ PAYPAL_PLAN_ID
⚠ PAYPAL_WEBHOOK_ID (pendiente)
⚠ IMECF_API_KEY (pendiente)
⚠ GEMINI_API_KEY (pendiente)

Módulos:
✓ Login/Autenticación (con rate limiting)
✓ Condominios y propiedades
✓ Facturación
✓ PDF de contratos
✓ Cloud SQL para sesiones
✓ PayPal (esperando webhook ID)
⚠ ECF/IMECF (esperando credenciales)
⚠ Gemini (esperando API Key)
```

---

**Próximo paso:** Configurar los secretos faltantes siguiendo esta guía.
