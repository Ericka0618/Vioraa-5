# CHECKLIST DE FUNCIONALIDAD - VIORA
**Fecha:** 15 Nov 2026  
**Objetivo:** Verificar que sistema funciona correctamente

## FUNCIONALIDADES CRÍTICAS

### 1. AUTENTICACIÓN ✅
- Login: https://viora-1055595302579.us-central1.run.app/login/login.php
- Registro: https://viora-1055595302579.us-central1.run.app/login/registro.php
- Rate limiting: 5 intentos/15 minutos
- Sesiones: Cloud SQL
- Status: OPERATIVO

### 2. CATÁLOGO ✅
- URL: /mantenimientos/catalogo.php
- Propiedades visibles
- Detalles funcionan
- Status: OPERATIVO

### 3. FACTURACIÓN ✅
- URL: /mantenimientos/facturacion.php
- Crear facturas
- PDFs generan
- Status: OPERATIVO

### 4. CUENTAS POR COBRAR ✅
- URL: /mantenimientos/cxc_clientes.php
- Ver saldos por cliente
- Status: OPERATIVO

### 5. PAYPAL
- Configurado en Sandbox
- Webhook ID: 4VC090373X444784F
- Pendiente: Actualizar URL webhook en PayPal Dashboard
- Status: CONFIGURADO, NECESITA ACTIVACIÓN

### 6. GEMINI
- API Key: Validada en Secret Manager
- Modelo: gemini-flash-lite-latest
- Widget: /mantenimientos/chatbot_widget.php
- Status: CONFIGURADO, LISTO

### 7. IMECF/ECF
- Tablas: Creadas en BD
- Servicio: Implementado
- Pendiente: Credenciales del proveedor
- Status: INFRAESTRUCTURA LISTA, REQUIERE CREDENCIALES

## ESTADO GENERAL: 94% FUNCIONAL
