# VIORA - Complete Deployment Script for Windows PowerShell
# Execute this in PowerShell as Administrator

$ErrorActionPreference = "Stop"

Write-Host "================================================" -ForegroundColor Cyan
Write-Host "VIORA - Complete Deployment to Google Cloud" -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan

# Variables
$PROJECT_ID = "viora-514300"
$VM_NAME = "servidor-viora"
$ZONE = "us-central1-a"
$DB_PASSWORD = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 20 | ForEach-Object {[char]$_})
$REPO_URL = "https://github.com/Ericka0618/Vioraa-5.git"
$DEPLOY_DIR = "/var/www/vioraa-5"

Write-Host "`nGenerating secure database password..." -ForegroundColor Yellow
Write-Host "Password: $DB_PASSWORD" -ForegroundColor Green

# Check if gcloud is installed
Write-Host "`nChecking Google Cloud SDK..." -ForegroundColor Yellow

try {
    $gcloudVersion = gcloud --version 2>&1
    Write-Host "✓ Google Cloud SDK found" -ForegroundColor Green
} catch {
    Write-Host "✗ Google Cloud SDK not found" -ForegroundColor Red
    Write-Host "Please install from: https://cloud.google.com/sdk/docs/install" -ForegroundColor Yellow
    exit 1
}

# Check authentication
Write-Host "`nChecking authentication..." -ForegroundColor Yellow

try {
    $authInfo = gcloud auth list 2>&1
    Write-Host "✓ Authenticated with Google Cloud" -ForegroundColor Green
} catch {
    Write-Host "✗ Not authenticated" -ForegroundColor Red
    Write-Host "Running: gcloud auth login" -ForegroundColor Yellow
    gcloud auth login
}

# Main deployment command
Write-Host "`n[Step 1/7] Connecting to VM and starting deployment..." -ForegroundColor Yellow
Write-Host "This may take 2-3 minutes..." -ForegroundColor Cyan

$deploymentCommand = @"
set -e

PROJECT_ID="$PROJECT_ID"
VM_NAME="$VM_NAME"
ZONE="$ZONE"
DB_PASSWORD="$DB_PASSWORD"
REPO_URL="$REPO_URL"
DEPLOY_DIR="$DEPLOY_DIR"

echo "================================"
echo "VIORA - Production Deployment"
echo "================================"
echo ""

# Step 2: Update system
echo "[2/7] Updating system packages..."
sudo apt-get update -qq
sudo apt-get upgrade -y -qq

# Step 3: Verify services
echo "[3/7] Verifying services..."
echo "Apache: $(apache2 -v 2>&1 | grep -o 'Apache/[^ ]*')"
echo "PHP: $(php -v 2>&1 | head -1)"
echo "MySQL: $(mysql --version)"
echo ""

# Step 4: Create MySQL Database
echo "[4/7] Creating MySQL database and user..."
sudo mysql -e "CREATE DATABASE IF NOT EXISTS viora;" 2>/dev/null
sudo mysql -e "DROP USER IF EXISTS 'viora_user'@'localhost';" 2>/dev/null || true
sudo mysql -e "CREATE USER 'viora_user'@'localhost' IDENTIFIED BY '\$DB_PASSWORD';"
sudo mysql -e "GRANT ALL PRIVILEGES ON viora.* TO 'viora_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
echo "✓ MySQL configured"
echo ""

# Step 5: Clone Repository
echo "[5/7] Cloning VIORA repository..."
if [ -d "\$DEPLOY_DIR" ]; then
  sudo rm -rf "\$DEPLOY_DIR"
fi
sudo git clone \$REPO_URL \$DEPLOY_DIR
echo "✓ Repository cloned"
echo ""

# Step 6: Create .env file
echo "[6/7] Creating .env file..."
sudo tee "\$DEPLOY_DIR/.env" > /dev/null <<EOF
DB_HOST=localhost
DB_USER=viora_user
DB_PASSWORD=\$DB_PASSWORD
DB_NAME=viora
GEMINI_API_KEY=your_gemini_api_key_here
ECF_API_KEY=your_ecf_api_key_here
APP_URL=http://\$(hostname -I | awk '{print \$1}')
APP_ENV=production
EOF
echo "✓ .env file created at \$DEPLOY_DIR/.env"
echo ""

# Step 7: Configure Apache
echo "[7/7] Configuring Apache and permissions..."
sudo chown -R www-data:www-data "\$DEPLOY_DIR"
sudo chmod -R 755 "\$DEPLOY_DIR"
sudo chmod -R 775 "\$DEPLOY_DIR/Viora/uploads"

# Create Apache config
sudo tee /etc/apache2/sites-available/vioraa-5.conf > /dev/null <<'APACHE_CONF'
<VirtualHost *:80>
    ServerName viora.local
    ServerAlias *
    DocumentRoot /var/www/vioraa-5/Viora

    <Directory /var/www/vioraa-5/Viora>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/vioraa-5-error.log
    CustomLog \${APACHE_LOG_DIR}/vioraa-5-access.log combined
</VirtualHost>
APACHE_CONF

sudo a2enmod rewrite 2>/dev/null || true
sudo a2ensite vioraa-5.conf 2>/dev/null || true
sudo a2dissite 000-default.conf 2>/dev/null || true
sudo apache2ctl configtest
sudo systemctl restart apache2
echo "✓ Apache configured"
echo ""

# Get VM IP
VM_IP=\$(hostname -I | awk '{print \$1}')

echo "================================"
echo "✓ DEPLOYMENT COMPLETED!"
echo "================================"
echo ""
echo "DATABASE CREDENTIALS:"
echo "  User: viora_user"
echo "  Password: \$DB_PASSWORD"
echo "  Database: viora"
echo ""
echo "SERVER INFO:"
echo "  VM IP: \$VM_IP"
echo "  Application: http://\$VM_IP/login/login.php"
echo "  Deployment: \$DEPLOY_DIR"
echo ""
echo "NEXT STEPS:"
echo "1. Import database backup:"
echo "   mysql -u viora_user -p viora < backup.sql"
echo "2. Add API keys to .env file"
echo "3. Test from: http://\$VM_IP/login/login.php"
echo ""
echo "IMPORTANT:"
echo "Save the database password in a secure location!"
echo "================================"
"@

# Execute deployment via gcloud
Write-Host "`nExecuting deployment script on VM..." -ForegroundColor Cyan

gcloud compute ssh $VM_NAME `
  --project=$PROJECT_ID `
  --zone=$ZONE `
  --command=$deploymentCommand

Write-Host "`n" -ForegroundColor Green
Write-Host "================================================" -ForegroundColor Green
Write-Host "✓ DEPLOYMENT COMPLETED SUCCESSFULLY!" -ForegroundColor Green
Write-Host "================================================" -ForegroundColor Green

Write-Host "`n📋 SAVE THESE CREDENTIALS:" -ForegroundColor Yellow
Write-Host "Database Password: $DB_PASSWORD" -ForegroundColor Cyan
Write-Host ""
Write-Host "📌 NEXT STEPS:" -ForegroundColor Yellow
Write-Host "1. SSH to VM and import database:" -ForegroundColor Cyan
Write-Host "   mysql -u viora_user -p viora < /path/to/bd/viora.sql" -ForegroundColor Cyan
Write-Host ""
Write-Host "2. Get your VM's public IP address" -ForegroundColor Cyan
Write-Host ""
Write-Host "3. Visit: http://[VM-IP]/login/login.php" -ForegroundColor Cyan
Write-Host ""
Write-Host "4. Update .env with your API keys:" -ForegroundColor Cyan
Write-Host "   GEMINI_API_KEY" -ForegroundColor Cyan
Write-Host "   ECF_API_KEY" -ForegroundColor Cyan

Write-Host "`n✓ Done! Your VIORA application is now deployed on Google Cloud!" -ForegroundColor Green
