# Despliegue actual de VIORA

Proyecto: `viora-514300`, region: `us-central1`, repositorio: `Ericka0618/Vioraa-5`, rama: `main`.

El pipeline vigente es `.github/workflows/deploy.yml` y utiliza `cloudbuild.yaml`. No ejecutar los scripts antiguos de configuracion de VM para actualizar Cloud Run.

## Arquitectura

`push main -> GitHub Actions -> Cloud Build -> Docker -> Artifact Registry -> Cloud Run`

La autenticacion de GitHub utiliza Workload Identity Federation. Las variables del repositorio son `WIF_PROVIDER` y `PRODUCTION_DEPLOY_ENABLED=true`. No se almacenan claves JSON de servicio en GitHub.

Cloud Run utiliza `viora-production@viora-514300.iam.gserviceaccount.com`, con acceso a Cloud SQL, a los secretos de produccion y a los dos buckets privados. Cloud Build utiliza `viora-build`; GitHub utiliza `viora-github`.

## Persistencia

- Instancia Cloud SQL `viora-staging-db`, base `viora_production`, usuario `viora_prod`.
- Conexion Unix `/cloudsql/viora-514300:us-central1:viora-staging-db`.
- Subidas: `viora-514300-production-uploads`, montadas en `/var/www/html/uploads`.
- Contratos: `viora-514300-production-documents`, montados en `/var/www/viora_documentos_privados`, fuera del directorio publico.
- Sesiones PHP: tabla `viora_runtime_sessions` en la base de produccion.
- Secretos con prefijo `viora-production-`: password de base, client ID y secret de PayPal, plan de PayPal y clave Gemini.

La instancia conserva el nombre staging porque se creo antes de la instruccion de pasar directamente a produccion. No se aprovisiono otra instancia innecesaria.

## Actualizaciones y recuperacion

Al publicar cambios en main se construye una imagen etiquetada con el SHA del commit y se despliega una revision de Cloud Run. Los datos y documentos se conservan fuera del contenedor. Una recuperacion de codigo puede dirigir el trafico a una revision anterior desde Cloud Run; no restaura ni revierte automaticamente datos de MySQL.

La VM `servidor-viora` y su base original permanecen intactas. La copia a Cloud SQL es una instantanea, no una replica: usar Cloud Run como punto de entrada despues del cambio para evitar escrituras divergentes en la VM.

## Integraciones y limites

PayPal esta fijado al endpoint Sandbox. `ECF_ENABLED=false` bloquea emision fiscal. Falta la clave de Gemini: ejecutar `python deployment/set-gemini-secret.py` en una consola local con entrada oculta; despues ejecutar nuevamente el workflow desde GitHub Actions para cargar la version nueva del secreto. No compartir claves por chat ni guardarlas en Git.

Se omitieron las pruebas funcionales de Cloud Run por instruccion del usuario. Consultar `deployment/CLOUD_RUN_REPORT.md` para las comprobaciones anteriores y el estado final del despliegue.

URL de produccion: https://viora-6ikx2ezlba-uc.a.run.app/login/login.php
