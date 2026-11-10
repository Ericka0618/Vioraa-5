# VIORA - Google Cloud Platform Setup Script
# Este script configura automáticamente todo en GCP

param(
    [string]$ProjectId = "viora-514300",
    [string]$GeminiApiKey = "",
    [string]$EcfApiKey = "",
    [string]$DbPassword = ""
)

Write-Host "╔════════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║          VIORA - Google Cloud Platform Auto Setup             ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

# Step 1: Instalar Google Cloud SDK
Write-Host "`n[Step 1/7] Verificando Google Cloud SDK..." -ForegroundColor Yellow

if (!(Test-Path "$env:ProgramFiles\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd")) {
    Write-Host "Instalando Google Cloud SDK..." -ForegroundColor Yellow

    $sdkUrl = "https://dl.google.com/dl/cloudsdk/channels/rapid/GoogleCloudSDKInstaller.exe"
    $installerPath = "$env:TEMP\GoogleCloudSDKInstaller.exe"

    Write-Host "Descargando SDK..." -ForegroundColor Cyan
    Invoke-WebRequest -Uri $sdkUrl -OutFile $installerPath

    Write-Host "Ejecutando instalador..." -ForegroundColor Cyan
    & $installerPath

    Write-Host "Por favor completa la instalación en la ventana emergente y regresa aquí." -ForegroundColor Yellow
    Read-Host "Presiona Enter cuando hayas terminado"
} else {
    Write-Host "✓ Google Cloud SDK ya instalado" -ForegroundColor Green
}

# Step 2: Establecer variables
Write-Host "`n[Step 2/7] Configurando variables..." -ForegroundColor Yellow

if ([string]::IsNullOrEmpty($GeminiApiKey)) {
    $GeminiApiKey = Read-Host "Ingresa tu Gemini API Key"
}

if ([string]::IsNullOrEmpty($EcfApiKey)) {
    $EcfApiKey = Read-Host "Ingresa tu ECF API Key"
}

if ([string]::IsNullOrEmpty($DbPassword)) {
    $DbPassword = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 16 | ForEach-Object {[char]$_})
    Write-Host "Contraseña BD generada: $DbPassword" -ForegroundColor Green
}

# Step 3: Autenticar con Google Cloud
Write-Host "`n[Step 3/7] Autenticando con Google Cloud..." -ForegroundColor Yellow
Write-Host "Se abrirá una ventana de navegador para autenticar." -ForegroundColor Cyan

gcloud auth login --project=$ProjectId

# Step 4: Habilitar APIs necesarias
Write-Host "`n[Step 4/7] Habilitando APIs necesarias..." -ForegroundColor Yellow

$apis = @(
    "run.googleapis.com",
    "cloudbuild.googleapis.com",
    "containerregistry.googleapis.com",
    "sqladmin.googleapis.com",
    "compute.googleapis.com",
    "iam.googleapis.com",
    "logging.googleapis.com",
    "monitoring.googleapis.com"
)

foreach ($api in $apis) {
    Write-Host "Habilitando $api..." -ForegroundColor Cyan
    gcloud services enable $api --project=$ProjectId --quiet
}

Write-Host "✓ APIs habilitadas" -ForegroundColor Green

# Step 5: Crear Cloud SQL Instance
Write-Host "`n[Step 5/7] Creando Cloud SQL Instance..." -ForegroundColor Yellow

$sqlInstance = "vioraa-mysql"
$sqlExists = gcloud sql instances describe $sqlInstance --project=$ProjectId 2>$null

if ($sqlExists) {
    Write-Host "! Cloud SQL Instance ya existe" -ForegroundColor Yellow
} else {
    Write-Host "Creando $sqlInstance..." -ForegroundColor Cyan
    gcloud sql instances create $sqlInstance `
        --database-version=MYSQL_8_0 `
        --tier=db-f1-micro `
        --region=us-central1 `
        --no-assign-ip `
        --enable-bin-log `
        --backup-start-time=03:00 `
        --retained-backups-count=7 `
        --project=$ProjectId `
        --quiet

    Write-Host "Esperando a que la instancia esté lista..." -ForegroundColor Cyan
    Start-Sleep -Seconds 60
}

# Step 6: Crear base de datos y usuario
Write-Host "`n[Step 6/7] Configurando base de datos..." -ForegroundColor Yellow

Write-Host "Creando base de datos 'viora'..." -ForegroundColor Cyan
gcloud sql databases create viora `
    --instance=$sqlInstance `
    --project=$ProjectId `
    --quiet 2>$null

Write-Host "Creando usuario 'viora_user'..." -ForegroundColor Cyan
gcloud sql users create viora_user `
    --instance=$sqlInstance `
    --password=$DbPassword `
    --project=$ProjectId `
    --quiet 2>$null

# Obtener connection name
$ConnectionName = gcloud sql instances describe $sqlInstance `
    --project=$ProjectId `
    --format='get(connectionName)'

Write-Host "✓ Base de datos configurada" -ForegroundColor Green
Write-Host "  Connection Name: $ConnectionName" -ForegroundColor Cyan

# Step 7: Crear Workload Identity (para GitHub Actions)
Write-Host "`n[Step 7/7] Configurando Workload Identity..." -ForegroundColor Yellow

$ProjectNumber = gcloud projects describe $ProjectId --format='get(projectNumber)'

# Crear Workload Identity Pool
Write-Host "Creando Workload Identity Pool..." -ForegroundColor Cyan
gcloud iam workload-identity-pools create "github-pool" `
    --project=$ProjectId `
    --location="global" `
    --display-name="GitHub Actions" `
    --quiet 2>$null

# Crear Provider
gcloud iam workload-identity-pools providers create-oidc "github-provider" `
    --project=$ProjectId `
    --location="global" `
    --workload-identity-pool="github-pool" `
    --display-name="GitHub" `
    --attribute-mapping="google.subject=assertion.sub,assertion.aud=assertion.aud" `
    --issuer-uri="https://token.actions.githubusercontent.com" `
    --attribute-condition="assertion.repository == 'Ericka0618/Vioraa-5'" `
    --quiet 2>$null

# Crear Service Account
Write-Host "Creando Service Account..." -ForegroundColor Cyan
gcloud iam service-accounts create github-actions `
    --project=$ProjectId `
    --display-name="GitHub Actions Service Account" `
    --quiet 2>$null

# Asignar permisos
Write-Host "Asignando permisos..." -ForegroundColor Cyan
gcloud projects add-iam-policy-binding $ProjectId `
    --member="serviceAccount:github-actions@$ProjectId.iam.gserviceaccount.com" `
    --role="roles/run.admin" `
    --quiet

gcloud projects add-iam-policy-binding $ProjectId `
    --member="serviceAccount:github-actions@$ProjectId.iam.gserviceaccount.com" `
    --role="roles/cloudbuild.builds.editor" `
    --quiet

gcloud iam service-accounts add-iam-policy-binding `
    "github-actions@$ProjectId.iam.gserviceaccount.com" `
    --project=$ProjectId `
    --role="roles/iam.workloadIdentityUser" `
    --member="principalSet://iam.googleapis.com/projects/$ProjectNumber/locations/global/workloadIdentityPools/github-pool/attribute.repository/Ericka0618/Vioraa-5" `
    --quiet

# Obtener WIF Provider
$WifProvider = "projects/$ProjectNumber/locations/global/workloadIdentityPools/github-pool/providers/github-provider"

Write-Host "`n╔════════════════════════════════════════════════════════════════╗" -ForegroundColor Green
Write-Host "║                  ✓ CONFIGURACIÓN COMPLETADA                   ║" -ForegroundColor Green
Write-Host "╚════════════════════════════════════════════════════════════════╝" -ForegroundColor Green

Write-Host "`n📋 GUARDA ESTOS VALORES PARA GITHUB SECRETS:`n" -ForegroundColor Yellow

Write-Host "GCP_PROJECT_ID: $ProjectId" -ForegroundColor Cyan
Write-Host "WIF_PROVIDER: $WifProvider" -ForegroundColor Cyan
Write-Host "WIF_SERVICE_ACCOUNT: github-actions@$ProjectId.iam.gserviceaccount.com" -ForegroundColor Cyan
Write-Host "DB_HOST: $ConnectionName" -ForegroundColor Cyan
Write-Host "DB_USER: viora_user" -ForegroundColor Cyan
Write-Host "DB_PASSWORD: $DbPassword" -ForegroundColor Cyan
Write-Host "GEMINI_API_KEY: $GeminiApiKey" -ForegroundColor Cyan
Write-Host "ECF_API_KEY: $EcfApiKey" -ForegroundColor Cyan

# Guardar en archivo
$secretsFile = "$env:USERPROFILE\Desktop\vioraa-secrets.txt"
@"
=== VIORA - GitHub Secrets ===
Guardado: $(Get-Date)

GCP_PROJECT_ID=$ProjectId
WIF_PROVIDER=$WifProvider
WIF_SERVICE_ACCOUNT=github-actions@$ProjectId.iam.gserviceaccount.com
DB_HOST=$ConnectionName
DB_USER=viora_user
DB_PASSWORD=$DbPassword
GEMINI_API_KEY=$GeminiApiKey
ECF_API_KEY=$EcfApiKey
DB_NAME=viora
"@ | Out-File -FilePath $secretsFile

Write-Host "`n✓ Secrets guardados en: $secretsFile" -ForegroundColor Green

Write-Host "`n📚 Próximos pasos:" -ForegroundColor Yellow
Write-Host "1. Abre el archivo de secrets en tu escritorio" -ForegroundColor Cyan
Write-Host "2. Ve a: https://github.com/Ericka0618/Vioraa-5/settings/secrets/actions" -ForegroundColor Cyan
Write-Host "3. Agregar cada secret de la lista" -ForegroundColor Cyan
Write-Host "4. Hacer push a GitHub:" -ForegroundColor Cyan
Write-Host "   git push origin main" -ForegroundColor Cyan
Write-Host "5. GitHub Actions desplegará automáticamente" -ForegroundColor Cyan

Write-Host "`n¿Deseas agregar los secrets a GitHub automáticamente?" -ForegroundColor Yellow
$response = Read-Host "(S/N)"

if ($response -eq 'S' -or $response -eq 's') {
    Write-Host "`nIngresa tu GitHub Personal Access Token (o presiona Enter para saltarlo):" -ForegroundColor Cyan
    $ghToken = Read-Host -AsSecureString

    if ($ghToken) {
        # Convertir a texto plano (no es lo más seguro pero necesario para el CLI)
        $ptr = [System.Runtime.InteropServices.Marshal]::SecureStringToCoTaskMemUnicode($ghToken)
        $ghTokenPlain = [System.Runtime.InteropServices.Marshal]::PtrToStringUni($ptr)

        Write-Host "`nAgregando secrets a GitHub..." -ForegroundColor Cyan

        # Usar gh CLI para agregar secrets
        $env:GH_TOKEN = $ghTokenPlain
        gh secret set GCP_PROJECT_ID --body $ProjectId --repo Ericka0618/Vioraa-5
        gh secret set WIF_PROVIDER --body $WifProvider --repo Ericka0618/Vioraa-5
        gh secret set WIF_SERVICE_ACCOUNT --body "github-actions@$ProjectId.iam.gserviceaccount.com" --repo Ericka0618/Vioraa-5
        gh secret set DB_HOST --body $ConnectionName --repo Ericka0618/Vioraa-5
        gh secret set DB_USER --body "viora_user" --repo Ericka0618/Vioraa-5
        gh secret set DB_PASSWORD --body $DbPassword --repo Ericka0618/Vioraa-5
        gh secret set GEMINI_API_KEY --body $GeminiApiKey --repo Ericka0618/Vioraa-5
        gh secret set ECF_API_KEY --body $EcfApiKey --repo Ericka0618/Vioraa-5
        gh secret set DB_NAME --body "viora" --repo Ericka0618/Vioraa-5

        Write-Host "✓ Secrets agregados a GitHub" -ForegroundColor Green
    }
}

Write-Host "`n¡VIORA está listo para ser desplegado en Google Cloud!" -ForegroundColor Green
