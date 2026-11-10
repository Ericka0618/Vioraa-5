$ErrorActionPreference = 'Stop'
$gcloud = 'C:\Program Files (x86)\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd'
$secureKey = Read-Host 'Pega tu API Key de Gemini (entrada oculta)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
$temp = Join-Path $env:TEMP ('viora-gemini-' + [guid]::NewGuid().ToString('N') + '.key')
$remoteDir = '/tmp/viora-gemini-' + [guid]::NewGuid().ToString('N')
try {
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    [IO.File]::WriteAllText($temp, $plain, [Text.UTF8Encoding]::new($false))
    $plain = $null
    $acl = Get-Acl -LiteralPath $temp
    $acl.SetAccessRuleProtection($true, $false)
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    $rule = [Security.AccessControl.FileSystemAccessRule]::new($identity, 'FullControl', 'Allow')
    $acl.SetAccessRule($rule)
    Set-Acl -LiteralPath $temp -AclObject $acl
    & $gcloud compute ssh servidor-viora --project=viora-514300 --zone=us-central1-a --quiet --command="mkdir -m 700 $remoteDir"
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la transferencia privada.' }
    & $gcloud compute scp $temp "servidor-viora:$remoteDir/key" --project=viora-514300 --zone=us-central1-a --quiet
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo transferir la clave.' }
    & $gcloud compute ssh servidor-viora --project=viora-514300 --zone=us-central1-a --quiet --command="sudo python3 /var/backups/viora/configure-gemini.py --key-file $remoteDir/key"
    if ($LASTEXITCODE -ne 0) { throw 'Gemini no supero la validacion. La configuracion anterior se conserva.' }
    Write-Host 'Gemini instalado y pregunta de prueba validada. Indica a Codex que continue la prueba del modulo.'
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp }
    & $gcloud compute ssh servidor-viora --project=viora-514300 --zone=us-central1-a --quiet --command="rm -f $remoteDir/key; rmdir $remoteDir"
}
