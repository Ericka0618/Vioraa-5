param(
    [string]$Project = 'viora-514300',
    [string]$SecretName = 'viora-production-imecf-api-key',
    [switch]$BindCloudRun
)
$ErrorActionPreference = 'Stop'
# Nunca poner la clave en argumentos, archivos, Write-Host o variables persistentes.
$ErrorActionPreference = 'Continue'
& gcloud.cmd secrets describe $SecretName --project=$Project --format='value(name)' 2>$null
$secretExists = $LASTEXITCODE -eq 0
$ErrorActionPreference = 'Stop'
if (!$secretExists) {
    & gcloud.cmd secrets create $SecretName --project=$Project --replication-policy=automatic --quiet
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear el secreto.' }
}
$secureKey = Read-Host 'Nueva clave IMECF (entrada oculta; sustituir la expuesta)' -AsSecureString
$keyPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
try {
    $privateKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($keyPointer)
    if ([string]::IsNullOrWhiteSpace($privateKey) -or $privateKey -match '[\r\n]') { throw 'Clave vacía o inválida.' }
    # Solo stdin. La salida de gcloud contiene el ID de versión, nunca el valor.
    $privateKey | & gcloud.cmd secrets versions add $SecretName --project=$Project --data-file=- --quiet
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo añadir la versión.' }
} finally {
    $privateKey = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($keyPointer)
    $secureKey.Dispose()
}
& gcloud.cmd secrets add-iam-policy-binding $SecretName --project=$Project --member='serviceAccount:viora-production@viora-514300.iam.gserviceaccount.com' --role=roles/secretmanager.secretAccessor --quiet
if ($LASTEXITCODE -ne 0) { throw 'No se pudo conceder acceso al servicio.' }
if ($BindCloudRun) {
    & gcloud.cmd run services update viora --project=$Project --region=us-central1 "--update-secrets=IMECF_API_KEY=${SecretName}:latest" --update-env-vars=ECF_ENABLED=false --quiet
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo vincular el secreto a Cloud Run.' }
}
Write-Host 'Configuración preparada. No se habilitó la emisión fiscal.'
