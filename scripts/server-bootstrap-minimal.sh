#!/usr/bin/env bash
# Minimal bootstrap: no firewall changes and no Docker installation.
# Pre-reqs: Docker Engine and Compose v2 plugin already installed; firewall already configured.
set -euxo pipefail

DEPLOY_USER=deploy

# Verify Docker availability (fail fast if missing)
command -v docker >/dev/null 2>&1 || { echo "Docker not found. Please install Docker Engine before running this script."; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 plugin not found. Please install docker-compose-plugin."; exit 1; }

# Create non-root deploy user if missing; do not modify existing users
if ! id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" "${DEPLOY_USER}"
fi

# Ensure deploy user can use Docker
usermod -aG docker "${DEPLOY_USER}"

# Do NOT touch firewall or Docker daemon config here.

# Create shared network and volumes used by compose (idempotent)
docker network create cto_net || true
docker volume create cto_db_data || true
docker volume create cto_app_storage || true

# CI/CD state directory to track active blue/green color
install -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" -d /opt/cto-crud/state
[ -f /opt/cto-crud/state/active_color ] || echo blue > /opt/cto-crud/state/active_color
chown "${DEPLOY_USER}:${DEPLOY_USER}" /opt/cto-crud/state/active_color

echo "Minimal bootstrap complete. No firewall or Docker installation changes were made."