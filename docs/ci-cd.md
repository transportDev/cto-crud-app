# Production CI/CD: Single-Host Docker + GitHub Actions (Self-Hosted Runner)

This repository contains a complete, production-grade CI/CD pipeline for deploying a Laravel application to a single Ubuntu Server host that runs Docker and a self-hosted GitHub Actions runner (on the same host). It uses:

- Docker multi-stage builds (PHP-FPM app + Nginx static/edge)
- GitHub Actions (build, test, package, push to GHCR, then deploy)
- Blue/Green deployment with near-zero downtime (brief port handover)
- Health gates and idempotent, safe re-runs
- Clear rollback using image tags

Files:

- Workflow: [.github/workflows/cicd.yml](.github/workflows/cicd.yml)
- Dockerfiles:
  - PHP-FPM: [docker/prod/php.Dockerfile](docker/prod/php.Dockerfile)
  - Nginx: [docker/prod/nginx.Dockerfile](docker/prod/nginx.Dockerfile)
- PHP config: [docker/prod/php.ini](docker/prod/php.ini), [docker/prod/opcache.ini](docker/prod/opcache.ini)
- Nginx config: [docker/prod/nginx.conf](docker/prod/nginx.conf)
- Entrypoint + health: [docker/prod/php-entrypoint.sh](docker/prod/php-entrypoint.sh), [docker/prod/healthcheck.sh](docker/prod/healthcheck.sh)
- Compose:
  - App Blue/Green stack: [docker-compose.prod.yml](docker-compose.prod.yml)
  - Persistent DB: [docker-compose.db.yml](docker-compose.db.yml)
- Server bootstrap (minimal): [scripts/server-bootstrap-minimal.sh](scripts/server-bootstrap-minimal.sh)
- Self-hosted Actions runner: [scripts/runner-install.sh](scripts/runner-install.sh)
- Env examples: [env/example.env](env/example.env), [env/.gitignore](env/.gitignore)
- Build context hygiene: [.dockerignore](.dockerignore)

## Overview

- CI job (build_test): Composer install (prod), Node build, PHP tests.
- Package job (package_push): Buildx multi-arch images (amd64/arm64) for PHP-FPM and Nginx, push to GHCR with cache.
- Deploy job (deploy): Runs on the self-hosted runner on your server. Creates a new color stack (blue/green) on a staging port, waits healthy, migrates DB, swaps traffic to port 80, starts queue/scheduler, and stops old services.

## Requirements

- Ubuntu Server with Docker Engine and Compose v2
- Self-hosted GitHub Actions runner configured with Docker permissions
- GHCR (GitHub Container Registry) access via GITHUB_TOKEN (default)

## Server Preparation (Minimal)

Do NOT change firewall or reinstall Docker (assumes already configured). On the server:

1. Minimal bootstrap. As root:
   sudo bash scripts/server-bootstrap-minimal.sh

This ensures:

- Docker network: cto_net
- Volumes: cto_db_data, cto_app_storage
- Blue/green state file: /opt/cto-crud/state/active_color
- Deploy user ‘deploy’ (created if missing) added to docker group

2. Install self-hosted runner as the deploy user:
   sudo -u deploy -H bash -lc 'export GITHUB_OWNER_REPO="owner/repo"; export RUNNER_TOKEN="REDACTED"; bash scripts/runner-install.sh'

Get the RUNNER_TOKEN from: Repo Settings → Actions → Runners → New self-hosted runner.

## GitHub Configuration

1. Actions permissions:

   - Settings → Actions → General → Workflow permissions → Read and write permissions.
   - Allow GitHub Actions to create and approve pull requests → not required.

2. Production environment:

   - Settings → Environments → New environment: production
   - Optionally add reviewers for manual approval
   - Add environment secrets:
     - PRODUCTION_HOST: your-server-ip-or-hostname
     - APP_KEY: output of: (cd laravel && php artisan key:generate --show)
     - DB_DATABASE: cto_crud
     - DB_USERNAME: cto_user
     - DB_PASSWORD: a-strong-password
     - DB_ROOT_PASSWORD: a-strong-root-password

3. GHCR (no PAT needed):
   - Images are pushed with GITHUB_TOKEN and permissions.packages: write.

## Workflow Triggers

- Push to main: full CI, package, and deploy (if CI passes).
- Manual dispatch: Actions → CI-CD → Run workflow; optional parameters:
  - deploy: true/false
  - image_tag: Short SHA to deploy (for rollback)
  - seed_admin: true/false to run AdminUserSeeder (use true on first deploy only)

## Images and Tags

Built and pushed by CI:

- ghcr.io/owner/repo-php:SHORT_SHA and :latest
- ghcr.io/owner/repo-nginx:SHORT_SHA and :latest

The deploy job chooses tags automatically based on the triggering commit SHA, unless you pass image_tag (for rollback).

## Blue/Green Deploy Strategy (Single Host)

- Determine active color from /opt/cto-crud/state/active_color (blue or green).
- Bring up the other color on localhost staging port (8081 for blue, 8082 for green).
- Health-check via http://127.0.0.1:PORT/healthz (Nginx).
- Run php artisan migrate --force inside the new app container.
- Swap: Stop old web to free :80, start new web on :80 (brief blip), then start new queue and scheduler, stop old queue/scheduler/app.
- Persist the new active color.

This is idempotent and safe to re-run.

## First Deploy

1. Ensure server bootstrap and runner are set up.
2. Create environment secrets as above.
3. Trigger the workflow manually with:
   - deploy=true
   - seed_admin=true (only for the very first deploy if you want AdminUserSeeder)
4. After success, subsequent pushes to main will build/test and deploy automatically.

## Post-Deployment Verification

- Health endpoint:
  - Production: http://PRODUCTION_HOST/healthz
  - Staging (preflight, internal): http://127.0.0.1:8081/healthz or :8082/healthz
- Container status:
  docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"
- Logs:
  docker compose -p cto_blue logs --tail=100 web
  docker compose -p cto_green logs --tail=100 app
- App introspection:
  docker compose -p cto_green exec -T app php artisan about

## Rollback

Use the workflow’s manual dispatch:

- Set deploy=true
- Set image_tag to a previously known-good SHORT_SHA
  The pipeline will repeat the same preflight and swap process using that tag.

Manual procedure (if needed, on server):

- Choose target color and port as per current active color.
- Bring up the new color on staging with IMAGE_PHP and IMAGE_NGINX pointing to the desired SHORT_SHA.
- Verify staging health on localhost:8081 or :8082.
- Stop old web, start new web on :80, start queue/scheduler, stop old services, and update /opt/cto-crud/state/active_color.

## Logs, Rotation, Observability

- Docker json-file logs with rotation are recommended. If you already configured the daemon, keep your settings.
- Laravel logs via stack channel go to stdout/stderr; tail with docker compose logs -f.
- Consider adding cAdvisor or Prometheus exporters later for metrics; not included in this minimal plan.

## Troubleshooting

- Runner cannot access Docker:
  - Ensure deploy user is in docker group and runner service runs as that user.
- GHCR authentication fails:
  - Ensure workflow permissions include packages: write, and organization policies allow GITHUB_TOKEN pushes.
- Compose errors:
  - Check that env/production.env is created by the workflow on the runner.
  - Ensure network cto_net and volumes cto_db_data, cto_app_storage exist. Minimal bootstrap script creates them.
- Port 80 conflicts:
  - Check if any other service (Apache, host Nginx) listens on 80: sudo lsof -i :80
  - Stop the conflicting service if present.
- Health check fails:
  - View app and web logs for the new color stack:
    docker compose -p cto_green logs app web
  - Verify APP*KEY and DB*\* secrets. Ensure DB container is healthy:
    docker compose -p cto_db ps
  - Run migrations manually on the new stack:
    docker compose -p cto_green exec -T app php artisan migrate:status
- Migrations/Seeder errors:
  - The deploy stops before switching traffic if migrations fail. Fix and push a new commit or redeploy a known-good image_tag.

## Customization

- Change APP_URL (and PRODUCTION_HOST) if you add a domain.
- Add TLS/HTTPS by placing a reverse proxy (e.g., Traefik) in front of port 80; wire to the same cto_net network and route to web services per color.
- Adjust queue worker flags in docker-compose.prod.yml.
- Add Redis if desired (service + env in production.env); adjust CACHE_DRIVER/QUEUE_CONNECTION accordingly.
