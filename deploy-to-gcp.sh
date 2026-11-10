#!/bin/bash

# VIORA - Deploy to Google Cloud
# This script builds and deploys VIORA to Cloud Run

PROJECT_ID="viora-514300"
SERVICE_NAME="vioraa-5"
REGION="us-central1"
IMAGE_NAME="gcr.io/$PROJECT_ID/$SERVICE_NAME"

echo "=========================================="
echo "VIORA - Deploying to Google Cloud"
echo "=========================================="

# Step 1: Authenticate
echo ""
echo "[Step 1] Authenticating with Google Cloud..."
gcloud auth login --project=$PROJECT_ID
gcloud config set project $PROJECT_ID

# Step 2: Enable APIs
echo ""
echo "[Step 2] Enabling required APIs..."
gcloud services enable run.googleapis.com cloudbuild.googleapis.com containerregistry.googleapis.com --quiet

# Step 3: Configure Docker
echo ""
echo "[Step 3] Configuring Docker authentication..."
gcloud auth configure-docker

# Step 4: Build Docker image
echo ""
echo "[Step 4] Building Docker image locally..."
cd /c/xampp/htdocs/Vioraa_5
docker build -t $IMAGE_NAME:latest -f Dockerfile .

# Step 5: Push to Container Registry
echo ""
echo "[Step 5] Pushing image to Google Container Registry..."
docker push $IMAGE_NAME:latest

# Step 6: Deploy to Cloud Run
echo ""
echo "[Step 6] Deploying to Cloud Run..."

# Read environment variables
echo "Enter database password:"
read -s DB_PASSWORD

echo "Enter Gemini API Key:"
read -s GEMINI_API_KEY

echo "Enter ECF API Key:"
read -s ECF_API_KEY

gcloud run deploy $SERVICE_NAME \
  --image=$IMAGE_NAME:latest \
  --region=$REGION \
  --platform=managed \
  --allow-unauthenticated \
  --memory=1Gi \
  --cpu=1 \
  --timeout=3600 \
  --set-env-vars=\
GEMINI_API_KEY=$GEMINI_API_KEY,\
ECF_API_KEY=$ECF_API_KEY,\
DB_HOST=cloudsql-proxy,\
DB_USER=viora_user,\
DB_PASSWORD=$DB_PASSWORD,\
DB_NAME=viora

# Step 7: Get service URL
echo ""
echo "[Step 7] Getting service URL..."
SERVICE_URL=$(gcloud run services describe $SERVICE_NAME --region=$REGION --format='value(status.url)')

echo ""
echo "=========================================="
echo "DEPLOYMENT COMPLETED!"
echo "=========================================="
echo ""
echo "Service URL: $SERVICE_URL"
echo ""
echo "Next steps:"
echo "1. Visit: $SERVICE_URL/login/login.php"
echo "2. Configure your domain (if needed)"
echo "3. Monitor logs:"
echo "   gcloud logging read 'resource.type=cloud_run_revision AND resource.labels.service_name=$SERVICE_NAME' --limit=50"
