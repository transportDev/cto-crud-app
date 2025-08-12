#!/usr/bin/env bash
# GitHub Actions self-hosted runner install as a system service.
# Run this script as the deploy user (non-root) who is in the 'docker' group.
# Required env vars before running:
#   export GITHUB_OWNER_REPO="owner/repo"
#   export RUNNER_TOKEN="<registration token from GitHub UI>"
# Optional:
#   export RUNNER_LABELS="self-hosted,linux,prod,docker"
#   export RUNNER_VERSION="2.316.1"
set -euo pipefail

RUNNER_VERSION="${RUNNER_VERSION:-2.316.1}"
RUNNER_LABELS="${RUNNER_LABELS:-self-hosted,linux,prod,docker}"
GITHUB_OWNER_REPO="${GITHUB_OWNER_REPO:?Set GITHUB_OWNER_REPO=owner/repo}"
RUNNER_TOKEN="${RUNNER_TOKEN:?Set RUNNER_TOKEN from GitHub runner registration page}"

# Sanity checks
id -u >/dev/null
command -v curl >/dev/null 2>&1 || { echo "curl not found"; exit 1; }
command -v tar >/dev/null 2>&1 || { echo "tar not found"; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "docker not found (ensure user is in docker group)"; exit 1; }

REPO_URL="https://github.com/${GITHUB_OWNER_REPO}"

cd "${HOME}"
mkdir -p actions-runner
cd actions-runner

# If already configured, exit
if [ -f .runner ]; then
  echo "Runner already configured in $(pwd)"
  exit 0
fi

# Download runner
RUNNER_PKG="actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
RUNNER_URL="https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${RUNNER_PKG}"
echo "Downloading ${RUNNER_URL}"
curl -fsSL -o "${RUNNER_PKG}" "${RUNNER_URL}"
tar xzf "${RUNNER_PKG}"

# Install dependencies (sudo might be required for some packages)
./bin/installdependencies.sh

# Configure runner
./config.sh --unattended \
  --url "${REPO_URL}" \
  --token "${RUNNER_TOKEN}" \
  --labels "${RUNNER_LABELS}" \
  --name "$(hostname)-prod" \
  --work "_work"

# Install and start as system service (requires sudo privileges for this step)
sudo ./svc.sh install
sudo ./svc.sh start
sudo ./svc.sh status

echo "Runner installed and started. Labels: ${RUNNER_LABELS}"