#!/bin/bash

# VIORA - GCP Quick Setup Script
# This script automates the initial setup for Google Cloud Platform deployment

set -e

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║          VIORA - Google Cloud Platform Setup                  ║"
echo "╚════════════════════════════════════════════════════════════════╝"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Step 1: Validate prerequisites
echo -e "\n${YELLOW}[Step 1/6]${NC} Checking prerequisites..."

if ! command -v gcloud &> /dev/null; then
    echo -e "${RED}✗ Google Cloud SDK not installed${NC}"
    echo "  Install from: https://cloud.google.com/sdk/docs/install"
    exit 1
fi

if ! command -v docker &> /dev/null; then
    echo -e "${RED}✗ Docker not installed${NC}"
    echo "  Install from: https://www.docker.com/products/docker-desktop"
    exit 1
fi

echo -e "${GREEN}✓ Prerequisites OK${NC}"

# Step 2: Get GCP Project ID
echo -e "\n${YELLOW}[Step 2/6]${NC} Setting up GCP Project..."

read -p "Enter your GCP Project ID: " PROJECT_ID

if [ -z "$PROJECT_ID" ]; then
    echo -e "${RED}✗ Project ID cannot be empty${NC}"
    exit 1
fi

gcloud config set project $PROJECT_ID
echo -e "${GREEN}✓ Project set to: $PROJECT_ID${NC}"

# Step 3: Enable APIs
echo -e "\n${YELLOW}[Step 3/6]${NC} Enabling required APIs..."

gcloud services enable \
    run.googleapis.com \
    cloudbuild.googleapis.com \
    containerregistry.googleapis.com \
    sqladmin.googleapis.com \
    compute.googleapis.com \
    iam.googleapis.com \
    logging.googleapis.com \
    monitoring.googleapis.com

echo -e "${GREEN}✓ APIs enabled${NC}"

# Step 4: Create Cloud SQL Instance
echo -e "\n${YELLOW}[Step 4/6]${NC} Creating Cloud SQL instance..."

read -p "Enter database root password: " -s DB_ROOT_PASSWORD
echo

DB_INSTANCE="vioraa-mysql"

if gcloud sql instances describe $DB_INSTANCE --quiet 2>/dev/null; then
    echo -e "${YELLOW}! Cloud SQL instance already exists${NC}"
else
    gcloud sql instances create $DB_INSTANCE \
        --database-version=MYSQL_8_0 \
        --tier=db-f1-micro \
        --region=us-central1 \
        --no-assign-ip \
        --enable-bin-log \
        --backup-start-time=03:00 \
        --retained-backups-count=7

    echo -e "${GREEN}✓ Cloud SQL instance created${NC}"

    # Wait for instance to be ready
    echo "Waiting for Cloud SQL instance to be ready..."
    sleep 30
fi

# Step 5: Create database and user
echo -e "\n${YELLOW}[Step 5/6]${NC} Setting up database and user..."

# Create database
gcloud sql databases create viora \
    --instance=$DB_INSTANCE \
    --quiet 2>/dev/null || echo "Database already exists"

# Create user
read -p "Enter database user password: " -s DB_PASSWORD
echo

gcloud sql users create viora_user \
    --instance=$DB_INSTANCE \
    --password=$DB_PASSWORD \
    --quiet 2>/dev/null || echo "User already exists"

echo -e "${GREEN}✓ Database and user created${NC}"

# Step 6: Display next steps
echo -e "\n${YELLOW}[Step 6/6]${NC} Deployment Configuration"

CONNECTION_NAME=$(gcloud sql instances describe $DB_INSTANCE --format='get(connectionName)')

echo -e "\n${GREEN}✓ Setup completed!${NC}\n"

echo "Next steps:"
echo "1. Update GitHub Secrets with:"
echo "   - GCP_PROJECT_ID: $PROJECT_ID"
echo "   - DB_HOST: $CONNECTION_NAME"
echo "   - DB_USER: viora_user"
echo "   - DB_PASSWORD: [use the password you entered]"
echo "   - GEMINI_API_KEY: [your Gemini API key]"
echo "   - ECF_API_KEY: [your ECF API key]"
echo ""
echo "2. Upload SQL backup to Cloud Storage:"
echo "   gsutil cp bd/viora.sql gs://vioraa-backups-prod/"
echo ""
echo "3. Import SQL backup to Cloud SQL:"
echo "   gcloud sql import sql $DB_INSTANCE gs://vioraa-backups-prod/viora.sql --database=viora"
echo ""
echo "4. Push changes to main branch:"
echo "   git add ."
echo "   git commit -m 'Add GCP deployment configuration'"
echo "   git push origin main"
echo ""
echo "5. GitHub Actions will automatically deploy to Cloud Run!"
echo ""
echo "For detailed instructions, see: GCP_SETUP.md"
