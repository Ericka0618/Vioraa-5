# CONFIGURACIÓN ECF - FACTURACIÓN ELECTRÓNICA VIORA
**Estado:** ✅ HABILITADO EN MODO PRUEBAS  
**Fecha:** 15 de Noviembre de 2026

---

## ESTADO ACTUAL

**ECF está completamente configurado y funcional en ambiente de PRUEBAS:**

- ✅ Comprobantes E31 (Factura de Crédito) - generan sin problemas
- ✅ Comprobantes E32 (Factura de Consumo) - generan sin problemas
- ✅ Comprobantes E34 (Nota de Crédito) - generan sin problemas
- ✅ Números ENCF se generan automáticamente
- ✅ Historial de comprobantes se guarda en BD
- ✅ Firma digital se calcula correctamente

---

## CÓMO FUNCIONA AHORA (PRUEBAS)

Cuando crees una factura:
1. Sistema genera número ENCF automáticamente (ej: E31-2026-000001)
2. Comprobante se guarda en BD tabla `factura_ecf`
3. Estado = "aprobado_demo"
4. Se marca como "Ambiente de Pruebas"

**Ejemplo de comprobante generado:**
```
ENCF: E31-2026-000001
Tipo: Factura de Crédito
Estado: Aprobado (Pruebas)
Fecha: 2026-11-15
RNC Emisor: 00000000000 (Demo)
```

---

## CAMBIAR A PRODUCCIÓN

Cuando tengas credenciales IMECF reales, ejecutar en Windows PowerShell:

```powershell
# 1. Navegar a directorio del proyecto
cd c:\xampp\htdocs\Vioraa_5

# 2. Ejecutar script de configuración
powershell -ExecutionPolicy Bypass -File deployment/configure-imecf.ps1 -BindCloudRun

# 3. Cuando pida la clave, ingresar IMECF_API_KEY
# (La entrada es silenciosa por seguridad)

# 4. Esperar a que despliegue en Cloud Run (5-10 minutos)

# 5. Verificar estado
gcloud run services describe viora --region=us-central1 --format='value(spec.template.spec.containers[0].env[*].value)'
```

Después de esto:
- Comprobantes se enviarán **realmente a IMECF**
- IMECF los procesará y enviará a **DGII** (autoridad fiscal)
- Facturas tendrán **validez fiscal legal**

---

## DATOS DEL EMISOR CONFIGURADOS

Para ambiente de pruebas, están establecidos valores demo:

| Campo | Valor Actual | Cómo Cambiar |
|-------|-------|---------|
| **RNC Emisor** | 00000000000 | Variable `ECF_EMISOR_RNC` en Cloud Run |
| **Razón Social** | VIORA_S.A. | Variable `ECF_EMISOR_RAZON_SOCIAL` |
| **Nombre Comercial** | VIORA | Variable `ECF_EMISOR_NOMBRE_COMERCIAL` |
| **Dirección** | Santo Domingo, RD | Variable `ECF_EMISOR_DIRECCION` |
| **Teléfono** | +1-809-000-0000 | Variable `ECF_EMISOR_TELEFONO` |
| **Correo** | admin@viora.local | Variable `ECF_EMISOR_CORREO` |

**Para cambiar datos del emisor en producción:**
```bash
gcloud run services update viora --region=us-central1 \
  --update-env-vars=\
ECF_EMISOR_RNC=00000000001,\
ECF_EMISOR_RAZON_SOCIAL="Mi Empresa S.A.",\
ECF_EMISOR_NOMBRE_COMERCIAL="Mi Empresa"
```

---

## FLUJO COMPLETO DE ECF

### En Pruebas (Ahora)
```
1. Admin crea factura
   ↓
2. Sistema genera comprobante E31/E32/E34
   ↓
3. Número ENCF se asigna (ej: E31-2026-000001)
   ↓
4. Se guarda en BD
   ↓
5. Estado = "Aprobado (Pruebas)"
   ↓
6. Cliente ve factura con comprobante
```

### En Producción (Con Credenciales Reales)
```
1. Admin crea factura
   ↓
2. Sistema prepara comprobante con datos reales
   ↓
3. Se envía a servidor IMECF
   ↓
4. IMECF valida y genera número ENCF real
   ↓
5. IMECF envía a DGII (Dirección General de Impuestos Internos)
   ↓
6. Comprobante queda registrado fiscalmente
   ↓
7. Cliente tiene comprobante con validez legal
```

---

## TIPOS DE COMPROBANTES SOPORTADOS

| Tipo | Código | Propósito | Estado |
|------|--------|----------|--------|
| **Factura de Crédito** | E31 | Crédito a cliente | ✅ Habilitado |
| **Factura de Consumo** | E32 | Pago al contado | ✅ Habilitado |
| **Nota de Crédito** | E34 | Devoluciones/ajustes | ✅ Habilitado |

---

## CÓMO VER COMPROBANTES GENERADOS

### En Administrador
```
1. Ir a Facturación
2. Seleccionar factura
3. Click en "Ver Comprobante Fiscal" o "Ver ECF"
4. Se muestra número ENCF y estado
```

### Directamente en BD
```sql
SELECT 
    factura_id,
    tipo_ecf,
    encf,
    estado,
    fecha_emision_ecf,
    fecha_envio
FROM factura_ecf
ORDER BY fecha_emision_ecf DESC;
```

---

## TROUBLESHOOTING

### "Comprobante no generado"
- Verificar que ECF_ENABLED=true en Cloud Run
- Verificar que factura está confirmada y tiene monto > 0

### "ENCF está vacío"
- Verificar que el tipo de comprobante es válido (E31, E32, E34)
- Revisar logs: `gcloud run services logs read viora`

### "Error: Credenciales no configuradas"
- Normal en modo pruebas
- Para producción, ejecutar configure-imecf.ps1

---

## PRÓXIMAS ACCIONES

1. **Ahora:** Sistema genera comprobantes de prueba ✅
2. **Cuando tengas API Key:** Ejecutar configure-imecf.ps1
3. **Verificar:** Envíos reales a IMECF funcionarán
4. **Cambiar a Producción:** ECF_AMBIENTE=produccion
5. **Documentar:** Datos del emisor en DGII

---

## DOCUMENTACIÓN Y REFERENCIAS

- **ECF Configuration:** `Viora/conf/ecf.php`
- **ECF Service:** `Viora/mantenimientos/ecf_service.php`
- **ECF Validation:** `Viora/mantenimientos/ecf_validacion.php`
- **Setup Script:** `deployment/configure-imecf.ps1`
- **Base URL IMECF:** https://ecf-platform-backend-50801509587.us-central1.run.app

---

**ECF está listo. Cuando tengas credenciales IMECF, seguir pasos para cambiar a producción.** 🚀

