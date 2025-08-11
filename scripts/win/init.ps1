Param()

$ErrorActionPreference = "Stop"

Write-Host "Initializing Dockerized Laravel + Filament dev environment..."

# Ensure .env exists
if (-not (Test-Path -Path ".\.env")) {
  Copy-Item ".\.env.example" ".\.env"
  Write-Host "Copied .env.example to .env"
}

Write-Host "Building images..."
docker compose build

Write-Host "Starting services..."
docker compose up -d

# Wait for bootstrap marker inside the app container
$timeoutSec = 300
$start = Get-Date
$markerFound = $false
Write-Host "Waiting for Laravel bootstrap (.bootstrap_done) up to $timeoutSec seconds..."

while ((Get-Date) - $start -lt (New-TimeSpan -Seconds $timeoutSec)) {
  try {
    docker compose exec -T app sh -lc "test -f /var/www/html/laravel/.bootstrap_done" | Out-Null
    if ($LASTEXITCODE -eq 0) {
      $markerFound = $true
      break
    }
  } catch {
    # ignore transient errors during container startup
  }
  Start-Sleep -Seconds 2
}

if (-not $markerFound) {
  Write-Error "Timeout waiting for Laravel bootstrap to complete."
  exit 1
}

Write-Host "Bootstrap completed."
Write-Host "App:    http://localhost:8080 (Filament at /admin)"
Write-Host "Vite:   http://localhost:5173"