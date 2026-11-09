# VIORA - Configuración para Google Cloud Platform

## Requisitos previos

1. **Google Cloud Project**
   - Crear un proyecto en GCP
   - Habilitar billing

2. **Herramientas**
   - Google Cloud SDK instalado
   - Docker instalado
   - Git instalado
   - GitHub cuenta

3. **Permisos necesarios**
   - Cloud Run Admin
   - Cloud SQL Admin (si usas Cloud SQL)
   - Container Registry Service Agent
   - Cloud Build Service Account

---

## Paso 1: Crear proyecto en GCP

```bash
# Set your project ID
export PROJECT_ID="vioraa-5-prod"

# Create project
gcloud projects create $PROJECT_ID --name="VIORA Property Management"

# Set as default
gcloud config set project $PROJECT_ID
```

---

## Paso 2: Habilitar APIs necesarias

```bash
gcloud services enable \
  run.googleapis.com \
  cloudbuild.googleapis.com \
  containerregistry.googleapis.com \
  sqladmin.googleapis.com \
  compute.googleapis.com \
  iam.googleapis.com
```

---

## Paso 3: Crear Cloud SQL Instance (MySQL)

```bash
# Create MySQL instance
gcloud sql instances create vioraa-mysql \
  --database-version=MYSQL_8_0 \
  --tier=db-f1-micro \
  --region=us-central1 \
  --no-assign-ip \
  --enable-bin-log

# Get instance connection name
gcloud sql instances describe vioraa-mysql \
  --format='get(connectionName)'
```

---

## Paso 4: Crear base de datos y usuario

```bash
# Create database
gcloud sql databases create viora \
  --instance=vioraa-mysql

# Create user
gcloud sql users create viora_user \
  --instance=vioraa-mysql \
  --password=YOUR_SECURE_PASSWORD

# Restore backup
gcloud sql import sql vioraa-mysql \
  gs://your-bucket/viora.sql \
  --database=viora
```

---

## Paso 5: Configurar GitHub Secrets

En GitHub repository settings → Secrets and variables → Actions:

```
GCP_PROJECT_ID: vioraa-5-prod
WIF_PROVIDER: projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/providers/github-provider
WIF_SERVICE_ACCOUNT: github-actions@vioraa-5-prod.iam.gserviceaccount.com

GEMINI_API_KEY: your-gemini-api-key
ECF_API_KEY: your-ecf-api-key
DB_HOST: your-cloud-sql-connection-name
DB_USER: viora_user
DB_PASSWORD: your-secure-password
DB_NAME: viora
```

---

## Paso 6: Subir backup a Cloud Storage

```bash
# Create bucket
gsutil mb gs://vioraa-backups-prod/

# Upload SQL backup
gsutil cp bd/viora.sql gs://vioraa-backups-prod/

# Import to Cloud SQL
gcloud sql import sql vioraa-mysql \
  gs://vioraa-backups-prod/viora.sql \
  --database=viora
```

---

## Paso 7: Configurar Workload Identity Federation (para GitHub Actions)

```bash
# Create Workload Identity Pool
gcloud iam workload-identity-pools create "github-pool" \
  --project=$PROJECT_ID \
  --location="global" \
  --display-name="GitHub Actions"

# Create Workload Identity Provider
gcloud iam workload-identity-pools providers create-oidc "github-provider" \
  --project=$PROJECT_ID \
  --location="global" \
  --workload-identity-pool="github-pool" \
  --display-name="GitHub" \
  --attribute-mapping="google.subject=assertion.sub,assertion.aud=assertion.aud" \
  --issuer-uri="https://token.actions.githubusercontent.com" \
  --attribute-condition="assertion.repository == 'Ericka0618/Vioraa-5'"

# Create Service Account
gcloud iam service-accounts create github-actions \
  --project=$PROJECT_ID \
  --display-name="GitHub Actions Service Account"

# Grant Cloud Run Admin role
gcloud projects add-iam-policy-binding $PROJECT_ID \
  --member="serviceAccount:github-actions@$PROJECT_ID.iam.gserviceaccount.com" \
  --role="roles/run.admin"

# Grant Cloud Build Service Account role
gcloud projects add-iam-policy-binding $PROJECT_ID \
  --member="serviceAccount:github-actions@$PROJECT_ID.iam.gserviceaccount.com" \
  --role="roles/cloudbuild.builds.editor"

# Configure Workload Identity binding
gcloud iam service-accounts add-iam-policy-binding \
  github-actions@$PROJECT_ID.iam.gserviceaccount.com \
  --project=$PROJECT_ID \
  --role="roles/iam.workloadIdentityUser" \
  --member="principalSet://iam.googleapis.com/projects/$(gcloud projects describe $PROJECT_ID --format='value(projectNumber)')/locations/global/workloadIdentityPools/github-pool/attribute.repository/Ericka0618/Vioraa-5"
```

---

## Paso 8: Deploy inicial desde GitHub

```bash
# Push changes to main branch
git add .
git commit -m "Add GCP deployment configuration"
git push origin main

# GitHub Actions ejecutará automáticamente el workflow
# Verifica en GitHub → Actions → Deploy to GCP Cloud Run
```

---

## Paso 9: Verificar deployment

```bash
# Get Cloud Run URL
gcloud run services describe vioraa-5 \
  --region=us-central1 \
  --format='value(status.url)'

# Check logs
gcloud run services describe vioraa-5 \
  --region=us-central1

# Stream logs
gcloud logging read "resource.type=cloud_run_revision AND resource.labels.service_name=vioraa-5" \
  --limit=50 \
  --format=json
```

---

## Desarrollo local con Docker Compose

```bash
# Copiar .env.example a .env
cp .env.example .env

# Editar .env con tus valores
nano .env

# Iniciar servicios
docker-compose up -d

# Ver logs
docker-compose logs -f web

# Detener servicios
docker-compose down
```

---

## Configuración de dominio personalizado

```bash
# Mapear dominio a Cloud Run
gcloud run domain-mappings create \
  --service=vioraa-5 \
  --domain=viora.tu-dominio.com \
  --region=us-central1

# Configurar DNS records (en tu proveedor DNS)
# Type: A
# Name: viora
# Value: 35.201.127.72 (dirección IP de Cloud Run)
```

---

## Actualizar secretos en Cloud Run

```bash
gcloud run deploy vioraa-5 \
  --region=us-central1 \
  --platform=managed \
  --set-env-vars=GEMINI_API_KEY=new_key,ECF_API_KEY=new_key
```

---

## Troubleshooting

### Conexión a Base de Datos
```bash
# Verificar instancia Cloud SQL
gcloud sql instances describe vioraa-mysql

# Ver eventos de instancia
gcloud sql operations list --instance=vioraa-mysql
```

### Cloud Run Issues
```bash
# Ver detalles del servicio
gcloud run services describe vioraa-5 --region=us-central1

# Ver eventos
gcloud events list --filter="resource:vioraa-5"
```

### GitHub Actions
- Verificar secrets están configurados
- Ver logs en GitHub → Actions tab
- Verificar permisos de Workload Identity

---

## Costos estimados

- **Cloud Run**: ~$0.40/millón de solicitudes (siempre en standby gratis)
- **Cloud SQL (db-f1-micro)**: ~$9/mes
- **Container Registry**: ~$0.10 por GB almacenado
- **Cloud Storage**: ~$0.02 por GB para backups

**Total estimado**: ~$15-25/mes para baja a media carga

---

## Seguridad

✅ API keys en variables de entorno
✅ Database encriptada
✅ SSL/TLS automático en Cloud Run
✅ Secrets en GitHub Actions encriptados
✅ Network isolation con Cloud SQL
✅ Logs centralizados en Cloud Logging

---

## Siguientes pasos

1. [ ] Crear proyecto GCP
2. [ ] Habilitar APIs
3. [ ] Crear Cloud SQL instance
4. [ ] Configurar GitHub Secrets
5. [ ] Subir SQL backup
6. [ ] Ejecutar deployment inicial
7. [ ] Configurar dominio personalizado
8. [ ] Monitoreo con Cloud Logging/Monitoring

