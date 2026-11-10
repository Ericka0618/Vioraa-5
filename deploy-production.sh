#!/bin/bash

# ============================================================
# VIORA - Deployment Script for Google Cloud VM
# ============================================================
# This script deploys VIORA to Apache on your GCP VM
# Execute this in Cloud Shell connected to servidor-viora
# ============================================================

set -e  # Exit on any error

PROJECT_ID="viora-514300"
VM_NAME="servidor-viora"
ZONE="us-central1-a"

echo "=================================================="
echo "VIORA - Deployment Script"
echo "=================================================="
echo ""

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Generate secure password
DB_PASSWORD=$(openssl rand -base64 16)
REPO_URL="https://github.com/Ericka0618/Vioraa-5.git"
DEPLOY_DIR="/var/www/vioraa-5"

echo -e "${YELLOW}[Step 1/7] Connecting to VM...${NC}"
echo "VM: $VM_NAME"
echo "Zone: $ZONE"
echo ""

# Execute deployment on remote VM
gcloud compute ssh $VM_NAME \
  --project=$PROJECT_ID \
  --zone=$ZONE \
  --command="
set -e

echo 'Step 2/7: Updating system packages...'
sudo apt-get update
sudo apt-get upgrade -y

echo 'Step 3/7: Verifying Apache, PHP and MySQL...'
apache2 -v 2>&1 | head -1
php -v 2>&1 | head -1
mysql --version

echo ''
echo 'Step 4/7: Creating MySQL database and user...'

# Create database and user
sudo mysql -e \"CREATE DATABASE IF NOT EXISTS viora;\" 2>/dev/null || echo 'Database may already exist'

# Create user with new password
sudo mysql -e \"DROP USER IF EXISTS 'viora_user'@'localhost';\" 2>/dev/null || true
sudo mysql -e \"CREATE USER 'viora_user'@'localhost' IDENTIFIED BY '$DB_PASSWORD';\"
sudo mysql -e \"GRANT ALL PRIVILEGES ON viora.* TO 'viora_user'@'localhost';\"
sudo mysql -e \"FLUSH PRIVILEGES;\"

echo '✓ MySQL configured'

echo ''
echo 'Step 5/7: Cloning VIORA repository...'

# Remove existing deployment if present
if [ -d \"$DEPLOY_DIR\" ]; then
  echo 'Removing existing deployment...'
  sudo rm -rf \"$DEPLOY_DIR\"
fi

# Clone repository
sudo git clone $REPO_URL $DEPLOY_DIR
cd $DEPLOY_DIR

echo '✓ Repository cloned'

echo ''
echo 'Step 6/7: Creating .env file with credentials...'

# Create .env file
sudo tee \"$DEPLOY_DIR/.env\" > /dev/null <<EOF
# Database Configuration
DB_HOST=localhost
DB_USER=viora_user
DB_PASSWORD=$DB_PASSWORD
DB_NAME=viora

# API Keys (ADD YOUR KEYS HERE)
GEMINI_API_KEY=your_gemini_api_key_here
ECF_API_KEY=your_ecf_api_key_here

# Application Settings
APP_URL=http://$(hostname -I | awk '{print \$1}')
APP_ENV=production
EOF

echo '✓ .env file created'

echo ''
echo 'Step 7/7: Configuring Apache and permissions...'

# Set proper permissions
sudo chown -R www-data:www-data \"$DEPLOY_DIR\"
sudo chmod -R 755 \"$DEPLOY_DIR\"
sudo chmod -R 775 \"$DEPLOY_DIR/Viora/uploads\"

# Create Apache virtual host
sudo tee /etc/apache2/sites-available/vioraa-5.conf > /dev/null <<'APACHE_CONF'
<VirtualHost *:80>
    ServerName viora.local
    ServerAlias *
    DocumentRoot /var/www/vioraa-5/Viora

    <Directory /var/www/vioraa-5/Viora>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteBase /
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^(.*)$ index.php [QSA,L]
        </IfModule>
    </Directory>

    # Security Headers
    <IfModule mod_headers.c>
        Header set X-Content-Type-Options \"nosniff\"
        Header set X-Frame-Options \"SAMEORIGIN\"
        Header set X-XSS-Protection \"1; mode=block\"
    </IfModule>

    ErrorLog \${APACHE_LOG_DIR}/vioraa-5-error.log
    CustomLog \${APACHE_LOG_DIR}/vioraa-5-access.log combined
</VirtualHost>
APACHE_CONF

# Enable site and required modules
sudo a2enmod rewrite 2>/dev/null || true
sudo a2enmod headers 2>/dev/null || true
sudo a2ensite vioraa-5.conf 2>/dev/null || true
sudo a2dissite 000-default.conf 2>/dev/null || true

# Test Apache config
sudo apache2ctl configtest

# Restart Apache
sudo systemctl restart apache2

echo '✓ Apache configured'

echo ''
echo '=================================================='
echo 'DEPLOYMENT COMPLETED SUCCESSFULLY!'
echo '=================================================='
echo ''
echo 'IMPORTANT - Save these credentials:'
echo ''
echo 'Database User: viora_user'
echo 'Database Password: $DB_PASSWORD'
echo 'Database Name: viora'
echo ''
echo 'Application URL: http://$(hostname -I | awk '{print \$1}')/login/login.php'
echo ''
echo 'Next steps:'
echo '1. Add your API keys to: $DEPLOY_DIR/.env'
echo '2. Import your database backup:'
echo '   mysql -u viora_user -p $DB_PASSWORD viora < /path/to/backup.sql'
echo '3. Configure firewall if needed'
echo ''
"

echo ""
echo "=================================================="
echo "✓ Deployment Complete!"
echo "=================================================="
echo ""
echo "Database Password has been generated and configured."
echo "Store it safely:"
echo "  Password: $DB_PASSWORD"
echo ""
echo "Next: Import your database backup and add API keys."
