#!/bin/bash

# VIORA - Google Cloud Platform Setup Script (Bash version)

PROJECT_ID="viora-514300"

echo "=========================================="
echo "VIORA - Google Cloud Setup"
echo "=========================================="

# Step 1: Authenticate with Google Cloud
echo ""
echo "[Step 1] Authenticating with Google Cloud..."
echo "This will open a browser window. Please authenticate with your Google account."

gcloud auth login --project=$PROJECT_ID

# Step 2: Set project
echo ""
echo "[Step 2] Setting project..."
gcloud config set project $PROJECT_ID

# Step 3: Enable APIs
echo ""
echo "[Step 3] Enabling required APIs..."

apis=(
  "run.googleapis.com"
  "cloudbuild.googleapis.com"
  "containerregistry.googleapis.com"
  "sqladmin.googleapis.com"
  "compute.googleapis.com"
  "iam.googleapis.com"
  "logging.googleapis.com"
  "monitoring.googleapis.com"
)

for api in "${apis[@]}"; do
  echo "Enabling $api..."
  gcloud services enable $api --quiet
done

echo "APIs enabled!"

# Step 4: Create Cloud SQL Instance
echo ""
echo "[Step 4] Creating Cloud SQL instance..."

SQL_INSTANCE="vioraa-mysql"

if gcloud sql instances describe $SQL_INSTANCE --quiet 2>/dev/null; then
  echo "Cloud SQL instance already exists"
else
  gcloud sql instances create $SQL_INSTANCE \
    --database-version=MYSQL_8_0 \
    --tier=db-f1-micro \
    --region=us-central1 \
    --no-assign-ip \
    --enable-bin-log \
    --backup-start-time=03:00 \
    --retained-backups-count=7 \
    --quiet

  echo "Waiting for instance to be ready..."
  sleep 60
fi

# Step 5: Create database and user
echo ""
echo "[Step 5] Creating database and user..."

gcloud sql databases create viora \
  --instance=$SQL_INSTANCE \
  --quiet 2>/dev/null || echo "Database already exists"

# Generate secure password
DB_PASSWORD=$(openssl rand -base64 16)

gcloud sql users create viora_user \
  --instance=$SQL_INSTANCE \
  --password=$DB_PASSWORD \
  --quiet 2>/dev/null || echo "User already exists"

# Get connection name
CONNECTION_NAME=$(gcloud sql instances describe $SQL_INSTANCE --format='get(connectionName)')

# Step 6: Get project number
PROJECT_NUMBER=$(gcloud projects describe $PROJECT_ID --format='get(projectNumber)')

# Step 7: Create Workload Identity
echo ""
echo "[Step 7] Setting up Workload Identity for GitHub Actions..."

gcloud iam workload-identity-pools create "github-pool" \
  --project=$PROJECT_ID \
  --location="global" \
  --display-name="GitHub Actions" \
  --quiet 2>/dev/null || echo "Pool already exists"

gcloud iam workload-identity-pools providers create-oidc "github-provider" \
  --project=$PROJECT_ID \
  --location="global" \
  --workload-identity-pool="github-pool" \
  --display-name="GitHub" \
  --attribute-mapping="google.subject=assertion.sub,assertion.aud=assertion.aud" \
  --issuer-uri="https://token.actions.githubusercontent.com" \
  --attribute-condition="assertion.repository == 'Ericka0618/Vioraa-5'" \
  --quiet 2>/dev/null || echo "Provider already exists"

gcloud iam service-accounts create github-actions \
  --project=$PROJECT_ID \
  --display-name="GitHub Actions Service Account" \
  --quiet 2>/dev/null || echo "Service account already exists"

gcloud projects add-iam-policy-binding $PROJECT_ID \
  --member="serviceAccount:github-actions@$PROJECT_ID.iam.gserviceaccount.com" \
  --role="roles/run.admin" \
  --quiet 2>/dev/null

gcloud projects add-iam-policy-binding $PROJECT_ID \
  --member="serviceAccount:github-actions@$PROJECT_ID.iam.gserviceaccount.com" \
  --role="roles/cloudbuild.builds.editor" \
  --quiet 2>/dev/null

gcloud iam service-accounts add-iam-policy-binding \
  "github-actions@$PROJECT_ID.iam.gserviceaccount.com" \
  --project=$PROJECT_ID \
  --role="roles/iam.workloadIdentityUser" \
  --member="principalSet://iam.googleapis.com/projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/attribute.repository/Ericka0618/Vioraa-5" \
  --quiet 2>/dev/null

WIF_PROVIDER="projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/providers/github-provider"

# Display results
echo ""
echo "=========================================="
echo "SETUP COMPLETED!"
echo "=========================================="
echo ""
echo "Save these values for GitHub Secrets:"
echo ""
echo "GCP_PROJECT_ID=$PROJECT_ID"
echo "WIF_PROVIDER=$WIF_PROVIDER"
echo "WIF_SERVICE_ACCOUNT=github-actions@$PROJECT_ID.iam.gserviceaccount.com"
echo "DB_HOST=$CONNECTION_NAME"
echo "DB_USER=viora_user"
echo "DB_PASSWORD=$DB_PASSWORD"
echo "DB_NAME=viora"
echo ""

# Save to file
SECRETS_FILE="$HOME/Desktop/vioraa-secrets.txt"
cat > "$SECRETS_FILE" << EOF
=== VIORA - GitHub Secrets ===
Generated: $(date)

GCP_PROJECT_ID=$PROJECT_ID
WIF_PROVIDER=$WIF_PROVIDER
WIF_SERVICE_ACCOUNT=github-actions@$PROJECT_ID.iam.gserviceaccount.com
DB_HOST=$CONNECTION_NAME
DB_USER=viora_user
DB_PASSWORD=$DB_PASSWORD
DB_NAME=viora
GEMINI_API_KEY=[TODO: Add your Gemini API key]
ECF_API_KEY=[TODO: Add your ECF API key]
EOF

echo "Secrets saved to: $SECRETS_FILE"
