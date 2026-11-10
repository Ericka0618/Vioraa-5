# Google Cloud SDK Installation Script for Windows PowerShell
# This script safely installs Google Cloud CLI and configures PATH

Write-Host "================================================" -ForegroundColor Cyan
Write-Host "Google Cloud SDK Installation" -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan

# Check if running as Administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")

if (-not $isAdmin) {
    Write-Host "`n❌ ERROR: This script must run as Administrator" -ForegroundColor Red
    Write-Host "Please:" -ForegroundColor Yellow
    Write-Host "1. Press Windows + X" -ForegroundColor Cyan
    Write-Host "2. Select 'Windows PowerShell (Admin)'" -ForegroundColor Cyan
    Write-Host "3. Run this script again" -ForegroundColor Cyan
    exit 1
}

Write-Host "`n✓ Running as Administrator" -ForegroundColor Green

# Check if already installed
Write-Host "`nStep 1: Checking if Google Cloud SDK is already installed..." -ForegroundColor Yellow

if (Test-Path "C:\Program Files\Google\Cloud SDK\bin\gcloud.cmd") {
    Write-Host "✓ Google Cloud SDK found at: C:\Program Files\Google\Cloud SDK" -ForegroundColor Green
    Write-Host "Skipping installation..." -ForegroundColor Yellow
} else {
    Write-Host "Google Cloud SDK not found. Proceeding with installation..." -ForegroundColor Yellow

    # Download SDK
    Write-Host "`nStep 2: Downloading Google Cloud SDK..." -ForegroundColor Yellow

    $downloadUrl = "https://dl.google.com/dl/cloudsdk/channels/rapid/GoogleCloudSDKInstaller.exe"
    $installerPath = "$env:TEMP\GoogleCloudSDKInstaller.exe"

    try {
        Write-Host "Downloading from: $downloadUrl" -ForegroundColor Cyan
        Invoke-WebRequest -Uri $downloadUrl -OutFile $installerPath -UseBasicParsing
        Write-Host "✓ Download completed" -ForegroundColor Green
    } catch {
        Write-Host "❌ Download failed: $_" -ForegroundColor Red
        exit 1
    }

    # Install SDK
    Write-Host "`nStep 3: Installing Google Cloud SDK..." -ForegroundColor Yellow
    Write-Host "This will open a setup window. Please follow the installation steps:" -ForegroundColor Cyan
    Write-Host "  1. Choose 'Next' to proceed" -ForegroundColor Cyan
    Write-Host "  2. Accept the license" -ForegroundColor Cyan
    Write-Host "  3. Keep default installation path" -ForegroundColor Cyan
    Write-Host "  4. Click 'Install'" -ForegroundColor Cyan
    Write-Host "  5. Wait for completion" -ForegroundColor Cyan
    Write-Host "  6. DO NOT run 'gcloud init' yet" -ForegroundColor Yellow

    & $installerPath

    Write-Host "`n✓ Installation wizard completed" -ForegroundColor Green

    # Wait for installation to finish
    Start-Sleep -Seconds 5
}

# Configure PATH
Write-Host "`nStep 4: Configuring PATH..." -ForegroundColor Yellow

$gcPath = "C:\Program Files\Google\Cloud SDK\bin"

if (-not (Test-Path $gcPath)) {
    Write-Host "❌ ERROR: Google Cloud SDK bin directory not found" -ForegroundColor Red
    Write-Host "Expected path: $gcPath" -ForegroundColor Cyan
    Write-Host "Please check if installation was successful" -ForegroundColor Yellow
    exit 1
}

# Add to PATH if not already there
$currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
if ($currentPath -notlike "*$gcPath*") {
    Write-Host "Adding to system PATH..." -ForegroundColor Cyan
    $newPath = $currentPath + ";" + $gcPath
    [Environment]::SetEnvironmentVariable("Path", $newPath, "Machine")
    Write-Host "✓ PATH configured" -ForegroundColor Green

    # Refresh current session PATH
    $env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")
} else {
    Write-Host "✓ Already in PATH" -ForegroundColor Green
}

# Verify installation
Write-Host "`nStep 5: Verifying installation..." -ForegroundColor Yellow

try {
    $version = & gcloud --version 2>&1
    Write-Host "✓ Google Cloud SDK verified:" -ForegroundColor Green
    Write-Host $version -ForegroundColor Cyan
} catch {
    Write-Host "⚠️  gcloud command not found in current session" -ForegroundColor Yellow
    Write-Host "This is normal after installation. Please:" -ForegroundColor Yellow
    Write-Host "  1. Close this PowerShell window" -ForegroundColor Cyan
    Write-Host "  2. Open a NEW PowerShell window (as Administrator)" -ForegroundColor Cyan
    Write-Host "  3. Run: gcloud --version" -ForegroundColor Cyan
    Write-Host "  4. Continue with authentication setup" -ForegroundColor Cyan
    exit 0
}

Write-Host "`n================================================" -ForegroundColor Green
Write-Host "✓ Google Cloud SDK Installation Complete!" -ForegroundColor Green
Write-Host "================================================" -ForegroundColor Green

Write-Host "`nNext steps:" -ForegroundColor Yellow
Write-Host "1. Run: gcloud auth login" -ForegroundColor Cyan
Write-Host "2. Run: gcloud config set project viora-514300" -ForegroundColor Cyan
Write-Host "3. Then execute your deployment script" -ForegroundColor Cyan

Write-Host "`n✓ Ready for Google Cloud configuration!" -ForegroundColor Green
