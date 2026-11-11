# VIORA en Cloud Run

Proyecto: viora-514300. Region: us-central1.

La solicitud posterior del usuario omite las pruebas funcionales y autoriza el paso directo a produccion. No se certifican los modulos ni los flujos de pago completos.

## Proceso para presentar

1. Un cambio en main inicia GitHub Actions.
2. GitHub obtiene credenciales temporales mediante Workload Identity Federation, sin claves JSON permanentes.
3. Actions entrega el codigo a Cloud Build, que construye el Docker de PHP 8.3 y Apache y publica la imagen en Artifact Registry.
4. Cloud Build despliega esa imagen en Cloud Run, que proporciona HTTPS.
5. Cloud Run conecta por socket autenticado a Cloud SQL. Secret Manager entrega las credenciales y Cloud Storage conserva las subidas y contratos privados.

## Datos y recursos

- VM servidor-viora y MySQL viora conservados sin cambios como respaldo.
- Se reutiliza la instancia Cloud SQL viora-staging-db para la base viora_production; no se crea una segunda instancia innecesaria.
- Copia consistente mediante mysqldump --single-transaction; importacion en otra base, sin sobrescribir la original.
- Archivos copiados a viora-514300-production-uploads y viora-514300-production-documents. Ambos buckets privados.
- Los contratos se sirven mediante la autorizacion de la aplicacion; el bucket de documentos no es publico.
- Sesiones compartidas en MySQL para sobrevivir a reinicios y multiples instancias.
- Cloud Run: minimo 0, maximo 2 instancias, 512 MiB, 1 CPU. Cloud SQL: db-f1-micro, 10 GB, respaldo diario.
- PayPal conserva endpoint Sandbox. ECF_ENABLED=false.
- GEMINI_API_KEY no esta disponible. Para configurarla sin compartirla por chat: ejecutar python deployment/set-gemini-secret.py en consola local y volver a ejecutar el workflow.

## Validacion y limites

Antes del cambio de instruccion pasaron 58 comprobaciones locales de sintaxis PHP y una compilacion Docker en Cloud Build (7c59407a-3ce9-4bd2-aa3d-2d1e6a683ffd), con extensiones, Apache y arranque del contenedor. Las pruebas funcionales de Cloud Run no se ejecutaron por solicitud expresa. Los resultados historicos de la VM no acreditan Cloud Run.

La migracion es una instantanea. Desde el cambio a la URL de Cloud Run, dejar de registrar operaciones en la VM para evitar divergencias. La VM se conserva como respaldo, pero sus datos no se sincronizan automaticamente con Cloud SQL.

Los recursos de pruebas previamente creados permanecen en el proyecto y tambien pueden generar cargos. La instancia originalmente llamada staging se usa ahora para produccion. No ejecutar los antiguos deploy-production.sh, deploy-viora-gcp.ps1 ni setup-gcp: no forman parte del pipeline nuevo.

El despliegue no implica validacion funcional de login, roles, propiedades, solicitudes, contratos, facturacion, suscripciones, PayPal ni Gemini. HTTPS y estado Ready se informan desde el estado del servicio al finalizar.

## Resultado final

- URL publica HTTPS: https://viora-6ikx2ezlba-uc.a.run.app/login/login.php
- Servicio Cloud Run: viora; revision lista: viora-00001-k9s; Ready=True. Acceso publico mediante roles/run.invoker para allUsers.
- GitHub Actions: https://github.com/Ericka0618/Vioraa-5/actions/runs/42939791653, completed/success.
- Cloud Build de produccion: 1380b7d4-a68b-4c9c-8198-5769aa1c26b0, SUCCESS.
- Imagen publicada: us-central1-docker.pkg.dev/viora-514300/viora/web:0b7a380d (el registro utiliza el SHA completo del commit).
- El primer intento fallo por exclusion de los archivos de runtime del paquete de codigo; se corrigio .gcloudignore y el segundo despliegue completo termino correctamente.
- Estos resultados acreditan la cadena de despliegue y el estado informado por GCP. No se hicieron pruebas HTTP, de navegador ni de negocio contra Cloud Run despues de solicitar el paso directo a produccion.
- Para usar Gemini: python deployment/set-gemini-secret.py y volver a ejecutar el workflow Deploy desde GitHub Actions; la clave permanece privada.
- No seguir registrando operaciones en la URL antigua de la VM. No existe sincronizacion bidireccional entre su MySQL y Cloud SQL.
