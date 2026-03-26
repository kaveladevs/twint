#!/bin/bash
# Quick SSH connect to VPS
# Usage: ./vps-connect.sh [command]
# Examples:
#   ./vps-connect.sh              # Interactive shell
#   ./vps-connect.sh "twint -u user"  # Run a command

VPS_HOST="168.231.117.247"
VPS_USER="root"

if [ -z "$1" ]; then
    echo "Connecting to VPS (${VPS_HOST})..."
    ssh ${VPS_USER}@${VPS_HOST}
else
    echo "Running command on VPS..."
    ssh ${VPS_USER}@${VPS_HOST} "cd /root/pipelines/twint && source venv/bin/activate && $1"
fi
