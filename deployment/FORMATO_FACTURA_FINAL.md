# FORMATO FINAL DE FACTURA - VIORA
**Estado:** ✅ COMPLETADO 100%  
**Fecha:** 15 de Noviembre de 2026

---

## DESCRIPCIÓN DE LA FACTURA

La factura en VIORA ahora incluye TODO lo necesario:

### ENCABEZADO
```
[LOGO VIORA]  ←← Imagen del negocio
VIORA
Razón Social del Emisor
RNC: 00000000000
Dirección, Santo Domingo, RD
Tel: +1-809-000-0000   Correo: admin@viora.local
```

### TIPO DE DOCUMENTO (Esquina Superior Derecha)
```
FACTURA
No. FAC-2026-0001
e-NCF: E31-2026-000001   [← Número del comprobante fiscal]
Fecha: 15/11/2026   Período: Noviembre 2026
```

### INFORMACIÓN DEL CLIENTE
```
CLIENTE                          COMPRADOR FISCAL
Nombre: [Cliente]                RNC: [Si aplica]
Cédula: [Cédula]                Razón Social: [Si aplica]
Teléfono: [Teléfono]
```

### DETALLES DE LA FACTURA (Tabla)
```
┌─────────────────────────────────────────────────────┐
│ CONCEPTO | DESCRIPCIÓN | PERÍODO | MONTO           │
├─────────────────────────────────────────────────────┤
│ Alquiler | Apartamento 2-B | Nov-2026 | RD$ 8,000 │
│ Mantenimiento | Cuota | Nov-2026 | RD$ 1,500     │
└─────────────────────────────────────────────────────┘
                            Subtotal: RD$ 9,500
                            ITBIS (18%): RD$ 1,710
                         ────────────────────────
                            TOTAL: RD$ 11,210
```

### INFORMACIÓN DEL DOCUMENTO (Sección Baja)
```
eNCF: E31-2026-000001
Tipo: Factura de Crédito Fiscal Electrónica
Estado: Aprobado (Pruebas) / Validado (Producción)
Ambiente: Pruebas / Producción
Origen: API VIORA
```

### CÓDIGO QR (Esquina Inferior Derecha)
```
        ███████████
        ██ ▀▀ ██
        ██ ██ ██
        ██▄▄▄▄██
        
    Verifique este código QR
    
    ↑ Contiene:
    - ENCF: E31-2026-000001
    - Tipo: Factura de Crédito
    - Monto: RD$ 11,210.00
    - Fecha: 2026-11-15
    - RNC Emisor: 00000000000
    - Ambiente: Pruebas
```

### RESPUESTA DGII (Si validado)
```
Código de Seguridad: [Código DGII]
URL DGII: [Link de validación]
```

### PIE DE PÁGINA
```
Factura registrada en VIORA el 15/11/2026 14:30
```

---

## CARACTERÍSTICAS DEL CÓDIGO QR

### ✅ Contenido del QR

**Modo Pruebas (Sin validar en DGII):**
```json
{
  "encf": "E31-2026-000001",
  "tipo": "Factura de Crédito",
  "monto": 11210.00,
  "fecha": "2026-11-15",
  "rnc_emisor": "00000000000",
  "ambiente": "pruebas"
}
```

**Modo Producción (Validado en DGII):**
```
https://dgii.gov.do/validacion/e-cf/E31-2026-000001
```

### ✅ Características

- **Escaneable:** Cualquier teléfono puede escanear
- **Información:** Datos completos de la factura
- **Seguridad:** Integrado al PDF, no se puede separar
- **Automático:** Se genera automáticamente cada factura
- **Estándar:** Formato QR ISO/IEC 18004 (compatibilidad universal)
- **Ubicación:** Esquina inferior derecha
- **Etiqueta:** "Verifique este código QR"

---

## TIPOS DE FACTURAS SOPORTADAS

| Tipo | Código | En QR | Ejemplo ENCF |
|------|--------|-------|--------------|
| **Factura de Crédito** | E31 | ✅ Sí | E31-2026-000001 |
| **Factura de Consumo** | E32 | ✅ Sí | E32-2026-000002 |
| **Nota de Crédito** | E34 | ✅ Sí | E34-2026-000003 |

---

## CÓMO ACCEDER A LAS FACTURAS

### Cliente
1. Loguearse en https://viora-1055595302579.us-central1.run.app
2. Ir a "Mis Facturas"
3. Seleccionar factura
4. Click en "Descargar PDF"
5. PDF se abre con código QR visible

### Administrador
1. Loguearse como Admin
2. Ir a "Facturación"
3. Seleccionar factura
4. Click en "Ver Factura PDF"
5. PDF se abre con código QR visible

---

## VALIDACIÓN DEL QR

### Con teléfono
1. Abrir cámara del teléfono
2. Apuntar al código QR
3. Notificación aparece
4. Escanear

### Resultado
- **Modo Pruebas:** Se ve el JSON con datos
- **Modo Producción:** Redirige a validación DGII

---

## CALIDAD DEL PDF

✅ **Formato:** PDF estándar (compatible con todos los lectores)  
✅ **Compresión:** Optimizado para descarga rápida  
✅ **Contenido:** Todo el texto es seleccionable  
✅ **Código QR:** Alta resolución, escaneable  
✅ **Seguridad:** Hash SHA256 para integridad  
✅ **Almacenamiento:**
   - Copia en Cloud SQL (durable)
   - Réplica en Cloud Storage (backup)

---

## EJEMPLO REAL

Cuando generes una factura ahora:

```
1. Admin crea factura
   ↓
2. Sistema:
   - Calcula ITBIS (18%)
   - Genera número ENCF (E31-2026-000001)
   - Crea PDF con todos los datos
   - Inserta código QR automáticamente
   - Guarda en BD + Cloud Storage
   ↓
3. Cliente descarga PDF
   - Abre en navegador o lector PDF
   - Ve factura profesional completa
   - Escanea código QR con teléfono
   - Accede a información validada
```

---

## PROCEDIMIENTO PARA PRODUCCIÓN

Cuando tengas credenciales IMECF:

```
1. Configurar IMECF_API_KEY en Secret Manager
2. Cambiar ECF_AMBIENTE=produccion
3. Cambiar ECF_EMISOR_CONFIRMADO=true
4. Desplegar cambios

Entonces:
- Comprobantes se envían automáticamente a IMECF
- IMECF genera número ENCF real
- IMECF envía a DGII
- QR redirige a validación oficial en DGII
- Facturas tienen validez fiscal legal
```

---

## RESUMEN FINAL

✅ **Factura 100% Funcional**
- Datos completos y precisos
- Formato profesional
- Código QR integrado y funcional
- ITBIS calculado correctamente
- Almacenamiento seguro
- Acceso restringido por usuario
- Compatible con DGII (cuando esté en producción)

**Ahora las facturas en VIORA son COMPLETAS y están LISTA PARA USAR** 🚀

