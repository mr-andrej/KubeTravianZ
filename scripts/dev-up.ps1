# KubeTravianZ - one-command local dev environment (minikube)
#
# Usage:
#   .\scripts\dev-up.ps1              # start everything (idempotent)
#   .\scripts\dev-up.ps1 -Rebuild     # force a fresh image build first
#   .\scripts\dev-up.ps1 -Down        # uninstall the game, stop port-forward
#
# What it does:
#   1. Makes sure Docker Desktop and minikube are running
#   2. Builds the game image (skippable if unchanged) and loads it into minikube
#   3. helm upgrade --install the travianz chart (db-init runs on first install)
#   4. Waits for pods, then port-forwards the game to http://localhost:8082
#
# Admin login: admin / adminpass

param(
    [switch]$Rebuild,
    [switch]$Down
)

$ErrorActionPreference = 'Stop'
$Release   = 'tz'
$Chart     = Join-Path $PSScriptRoot '..\deploy\charts\travianz'
$Image     = 'kubetravianz-web:citest'
$Namespace = 'default'
$Port      = 8082

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

if ($Down) {
    Write-Step 'Uninstalling the game'
    helm uninstall $Release 2>$null
    kubectl delete pvc -l app.kubernetes.io/instance=$Release --ignore-not-found
    Write-Host 'Done. minikube itself is still running (minikube stop to halt it).'
    exit 0
}

# --- 1. Docker ---------------------------------------------------------------
Write-Step 'Checking Docker'
$dockerOk = $false
try { docker info *> $null; $dockerOk = $true } catch {}
if (-not $dockerOk) {
    Write-Host 'Docker not responding, starting Docker Desktop...'
    Start-Process "$env:LOCALAPPDATA\Programs\DockerDesktop\Docker Desktop.exe"
    $deadline = (Get-Date).AddMinutes(3)
    do {
        Start-Sleep -Seconds 5
        try { docker info *> $null; $dockerOk = $true } catch {}
    } until ($dockerOk -or (Get-Date) -gt $deadline)
    if (-not $dockerOk) { throw 'Docker Desktop did not start in time.' }
}
Write-Host 'Docker is up.'

# --- 2. minikube ---------------------------------------------------------------
Write-Step 'Checking minikube'
$mkStatus = (minikube status 2>$null | Out-String)
if ($mkStatus -notmatch 'host:\s*Running') {
    Write-Host 'Starting minikube...'
    minikube start
} else {
    Write-Host 'minikube already running.'
}

# --- 3. Image --------------------------------------------------------------------
$needBuild = $Rebuild
if (-not $needBuild) {
    docker image inspect $Image *> $null
    $needBuild = -not $?
}
if ($needBuild) {
    Write-Step "Building image $Image"
    docker build -t $Image (Join-Path $PSScriptRoot '..')
} else {
    Write-Host "Image $Image exists (use -Rebuild to force)."
}

Write-Step 'Loading image into minikube'
minikube image load $Image --overwrite

# --- 4. Helm -----------------------------------------------------------------------
Write-Step "Deploying chart (helm upgrade --install $Release)"
helm upgrade --install $Release $Chart `
    --set image.repository=kubetravianz-web `
    --set image.tag=citest `
    --set image.pullPolicy=IfNotPresent `
    --set game.publicUrl=http://localhost:$Port/ `
    --namespace $Namespace

# --- 5. Wait for the stack ------------------------------------------------------------
Write-Step 'Waiting for pods (db-init takes ~1 min on first install)'
kubectl wait --for=condition=Ready pod -l app.kubernetes.io/component=mariadb --timeout=180s
kubectl wait --for=condition=Ready pod -l app.kubernetes.io/component=web --timeout=300s
kubectl get pods

Write-Host "`nGame is up. Admin login: admin / adminpass" -ForegroundColor Green
Write-Host "Opening http://localhost:$Port (Ctrl+C stops the port-forward)`n"
Start-Process "http://localhost:$Port"
kubectl port-forward "svc/$Release-web" "${Port}:80"
