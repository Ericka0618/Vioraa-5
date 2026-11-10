#!/bin/bash

# VIORA - VM Verification Script
# Ejecutar esto en Cloud Shell del proyecto viora-514300

PROJECT_ID="viora-514300"
VM_NAME="servidor-viora"
ZONE="us-central1-a"

echo "=================================================="
echo "Verificando VM: $VM_NAME"
echo "=================================================="

# Step 1: Connect to VM and check system
echo ""
echo "[Paso 1] Conectando a VM..."
echo "Ejecutando los siguientes comandos en la VM:"
echo ""

gcloud compute ssh $VM_NAME \
  --project=$PROJECT_ID \
  --zone=$ZONE \
  --command='
echo "=== INFORMACIÓN DEL SISTEMA ==="
lsb_release -a

echo ""
echo "=== APACHE ==="
apache2 -v 2>/dev/null && echo "✓ Apache instalado" || echo "✗ Apache NO instalado"
sudo systemctl status apache2 --no-pager 2>/dev/null | grep "active\|inactive"

echo ""
echo "=== PHP ==="
php -v

echo ""
echo "=== MYSQL ==="
mysql --version 2>/dev/null && echo "✓ MySQL instalado" || echo "✗ MySQL NO instalado"
sudo systemctl status mysql --no-pager 2>/dev/null | grep "active\|inactive"

echo ""
echo "=== ESTRUCTURA APACHE ==="
ls -la /var/www/

echo ""
echo "=== MÓDULOS PHP HABILITADOS ==="
php -m | grep -i "mysqli\|pdo\|mysql"

echo ""
echo "=== DIRECTORIOS Y PERMISOS ==="
pwd
whoami
'

echo ""
echo "=================================================="
echo "✓ Verificación completada"
echo "=================================================="
