#!/bin/bash
# TWINT VPS Deployment Script
# VPS: root@168.231.117.247 (srv1508966.hstgr.cloud)

set -e

VPS_HOST="168.231.117.247"
VPS_USER="root"
REMOTE_DIR="/root/pipelines/twint"

echo "=== TWINT VPS Deployment ==="

# Step 1: Test connection
echo "[1/4] Testing SSH connection..."
ssh -o ConnectTimeout=10 ${VPS_USER}@${VPS_HOST} "echo 'Connected successfully to $(hostname)'"

# Step 2: Create remote directory and sync code
echo "[2/4] Syncing TWINT code to VPS..."
ssh ${VPS_USER}@${VPS_HOST} "mkdir -p ${REMOTE_DIR}"
rsync -avz --exclude '.git' --exclude '__pycache__' --exclude '*.pyc' --exclude 'venv' \
    "$(dirname "$0")/" ${VPS_USER}@${VPS_HOST}:${REMOTE_DIR}/

# Step 3: Set up Python environment and install dependencies
echo "[3/4] Setting up Python environment on VPS..."
ssh ${VPS_USER}@${VPS_HOST} << 'SETUP'
cd /root/pipelines/twint
python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
pip install -e .
echo "Python version: $(python3 --version)"
echo "TWINT installed: $(pip show twint 2>/dev/null | grep Version || echo 'checking...')"
SETUP

# Step 4: Verify installation
echo "[4/4] Verifying TWINT installation..."
ssh ${VPS_USER}@${VPS_HOST} << 'VERIFY'
cd /root/pipelines/twint
source venv/bin/activate
python3 -c "import twint; print('TWINT imported successfully, version:', twint.__version__)"
VERIFY

echo ""
echo "=== Deployment complete! ==="
echo "To use TWINT on the VPS:"
echo "  ssh ${VPS_USER}@${VPS_HOST}"
echo "  cd ${REMOTE_DIR} && source venv/bin/activate"
echo "  twint -u username -o output.csv --csv"
