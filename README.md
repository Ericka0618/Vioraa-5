# VIORA - Property Management System

Sistema de gestión de propiedades desarrollado en PHP con MySQL. Diseñado para funcionar en Google Cloud Platform con Docker, Cloud Run y GitHub Actions.

## 📋 Características

- ✅ Gestión integral de propiedades y condominios
- ✅ Administración de clientes y contratos
- ✅ Sistema de facturación y cobros
- ✅ Control de reparaciones y mantenimiento
- ✅ Chatbot integrado con Gemini API
- ✅ Generación de reportes PDF
- ✅ Control de acceso por roles
- ✅ Integración con PayPal y ECF

## 🚀 Quick Start

### Opción 1: Desarrollo Local con Docker Compose

```bash
# 1. Clonar repositorio
git clone https://github.com/Ericka0618/Vioraa-5.git
cd Vioraa-5

# 2. Copiar y editar archivo de entorno
cp .env.example .env

# 3. Iniciar servicios
docker-compose up -d

# 4. Acceder a la aplicación
# VIORA: http://localhost:8080
# PhpMyAdmin: http://localhost:8081
```

### Opción 2: Desplegar en Google Cloud Platform

```bash
# 1. Ejecutar script de configuración
bash setup-gcp.sh

# 2. Configurar GitHub Secrets (ver GCP_SETUP.md)

# 3. Hacer push a rama main
git push origin main

# 4. GitHub Actions desplegará automáticamente
```

## 📁 Estructura del Proyecto

```
Vioraa-5/
├── Viora/                    # Código PHP de la aplicación
│   ├── conf/                 # Configuración (API keys, conexión BD)
│   ├── login/                # Autenticación y registro
│   ├── mantenimientos/       # Lógica principal de negocio
│   ├── configuracion/        # Roles y usuarios
│   ├── estilos/              # CSS
│   ├── imagenes/             # Assets
│   └── uploads/              # Archivos subidos por usuarios
├── bd/                       # Database
│   └── viora.sql             # Backup de base de datos
├── Dockerfile                # Configuración de contenedor
├── docker-compose.yml        # Servicios locales
├── cloudbuild.yaml           # Cloud Build configuration
├── .github/workflows/        # GitHub Actions CI/CD
└── GCP_SETUP.md              # Guía de configuración detallada
```

## 🔧 Configuración de Variables de Entorno

Crear archivo `.env` en la raíz del proyecto:

```bash
# Database
DB_HOST=db
DB_USER=viora_user
DB_PASSWORD=your_secure_password
DB_NAME=viora

# API Keys
GEMINI_API_KEY=your_gemini_key
ECF_API_KEY=your_ecf_key

# PayPal (opcional)
PAYPAL_CLIENT_ID=your_paypal_id
PAYPAL_CLIENT_SECRET=your_paypal_secret
PAYPAL_MODE=sandbox

# Aplicación
APP_URL=https://viora.tu-dominio.com
APP_ENV=production
```

## 🐳 Docker Compose Servicios

### Web (PHP-Apache)
- **Puerto**: 8080
- **Imagen**: PHP 8.2 + Apache
- **Volúmenes**: Código de aplicación
- **Extensiones**: MySQLi, PDO, PDO_MySQL

### Database (MySQL)
- **Puerto**: 3306
- **Versión**: MySQL 8.0
- **Backup**: Se restaura automáticamente al iniciar

### PhpMyAdmin (opcional)
- **Puerto**: 8081
- **Usuario**: viora_user
- **Contraseña**: `DB_PASSWORD`

## 🌐 Desplegar en Google Cloud Run

### Requisitos
- Proyecto activo en GCP con billing habilitado
- Permisos de Cloud Run Admin
- GitHub Actions configurado con Workload Identity

### Pasos
1. Ver `GCP_SETUP.md` para configuración detallada
2. Ejecutar `bash setup-gcp.sh`
3. Configurar GitHub Secrets
4. Hacer push a rama `main`
5. GitHub Actions desplegará automáticamente

### URL de Cloud Run
```
https://vioraa-5-region.run.app
```

## 📊 Database

### Tablas principales
- `usuarios` - Usuarios del sistema
- `propiedades` - Propiedades y apartamentos
- `clientes` - Clientes/inquilinos
- `contratos` - Contratos de alquiler
- `facturas` - Facturas generadas
- `cobros` - Pagos registrados
- `reparaciones` - Solicitudes de reparación

### Backup y Restore

**Crear backup local:**
```bash
docker-compose exec db mysqldump -u viora_user -p viora > backup.sql
```

**Restaurar desde backup:**
```bash
docker-compose exec -T db mysql -u viora_user -p viora < backup.sql
```

**En Cloud SQL:**
```bash
gcloud sql import sql vioraa-mysql gs://bucket/viora.sql --database=viora
```

## 🔐 Seguridad

- ✅ Variables de entorno para credenciales
- ✅ PHP con funciones deshabilitadas (`exec`, `system`, etc.)
- ✅ Headers de seguridad (X-Frame-Options, X-Content-Type-Options)
- ✅ Validación de entrada en formularios
- ✅ SQL prepared statements en consultas
- ✅ HTTPS/SSL automático en Cloud Run
- ✅ Logs centralizados en Cloud Logging

## 📝 Logs y Monitoreo

### Local con Docker Compose
```bash
# Ver logs en tiempo real
docker-compose logs -f web

# Ver logs de base de datos
docker-compose logs -f db

# Ver logs de contenedor específico
docker logs -f vioraa-web
```

### En Google Cloud Run
```bash
# Ver logs
gcloud logging read "resource.type=cloud_run_revision AND resource.labels.service_name=vioraa-5"

# Ver métricas
gcloud monitoring dashboards list
```

## 🐛 Troubleshooting

### Conexión a Base de Datos en Cloud Run
- Usar Cloud SQL Proxy
- Verificar Network Policies
- Revisar logs en Cloud Logging

### Permisos Insuficientes
- Verificar IAM roles
- Confirmar Workload Identity configurado
- Revisar GitHub Actions logs

### Imagen de Docker No Carga
- Verificar Container Registry
- Confirmar permisos en GCR
- Ver Cloud Build logs

Ver `GCP_SETUP.md` para troubleshooting detallado.

## 📞 Soporte y Documentación

- **Configuración GCP**: Ver `GCP_SETUP.md`
- **Código fuente**: `/Viora`
- **Backup BD**: `/bd`

## 📄 Licencia

Proyecto privado de VIORA. Todos los derechos reservados.

## 🤝 Contribuciones

Las contribuciones son bienvenidas. Por favor:
1. Fork el repositorio
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

---

**Última actualización**: 2026-11-08
**Versión**: 1.0.0-GCP
**Estado**: 🟢 Listo para producción
