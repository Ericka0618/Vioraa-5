# VIORA

Sistema PHP y MySQL para gestionar propiedades, clientes, solicitudes, contratos, facturacion, mantenimiento y suscripciones, con PayPal Sandbox y asistente Gemini.

## Despliegue en GCP

Cada actualizacion de `main` ejecuta GitHub Actions. Actions autentica con credenciales temporales y encarga a Cloud Build construir el Docker PHP/Apache, publicar la imagen en Artifact Registry y desplegarla en Cloud Run con HTTPS.

MySQL permanece en Cloud SQL, los secretos en Secret Manager y las subidas y contratos en buckets privados de Cloud Storage. La VM original se conserva como respaldo.

Consultar [configuracion vigente](GCP_SETUP.md) e [informe del despliegue](deployment/CLOUD_RUN_REPORT.md). El flujo actual utiliza `.github/workflows/deploy.yml` y `cloudbuild.yaml`; los antiguos scripts de despliegue de VM no forman parte de este proceso.

PayPal permanece en Sandbox y la emision fiscal deshabilitada. Gemini requiere configurar su clave privada. Las pruebas funcionales de Cloud Run se omitieron por solicitud expresa.

## Desarrollo local

1. Copiar `.env.example` a `.env` y configurar `DB_PASSWORD` y `DB_ROOT_PASSWORD` privados.
2. Ejecutar `docker compose up -d --build`.
3. Importar una copia de desarrollo autorizada de la base en el MySQL del contenedor; Compose no importa ni sobrescribe la base original automaticamente.
4. Abrir `http://localhost:8080`.

Los volumenes de Compose conservan MySQL, subidas y documentos. No ejecutar `docker compose down -v` si se necesita conservarlos.
