# Manual deploy: builds the Astro site and SCPs dist/* to the JF staging host.
# Use this when CI is unavailable or for ad-hoc one-off pushes.
#
# Run from PowerShell (Windows): .\deploy.ps1
# Or Git Bash:                   pwsh ./deploy.ps1

$ErrorActionPreference = 'Stop'

$localRoot  = Join-Path $PSScriptRoot 'dist'
# Shared Hostinger coordinates — env-overridable, current shared values as
# defaults. Source of truth: CW Vault secrets + claude-shared/seo/KEYS.md.
# NON-SECRET coordinates; SSH private key stays in $HOME\.ssh, referenced by path.
$sshHost    = if ($env:HOSTINGER_HOST) { $env:HOSTINGER_HOST } else { '145.79.25.15' }
$port       = if ($env:HOSTINGER_PORT) { [int]$env:HOSTINGER_PORT } else { 65002 }
$user       = if ($env:HOSTINGER_USER) { $env:HOSTINGER_USER } else { 'u778119288' }
$keyPath    = if ($env:HOSTINGER_KEY)  { $env:HOSTINGER_KEY }  else { "$HOME\.ssh\Hostinger-site-deploy" }
$remoteRoot = "/home/$user/domains/jfselfdefense.com/public_html"
$liveUrl    = 'https://jfselfdefense.com/'

Write-Host 'Building Astro site...' -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) { Write-Host 'BUILD FAILED' -ForegroundColor Red; exit 1 }

if (-not (Test-Path $localRoot)) {
    Write-Host "ERROR: $localRoot not found after build" -ForegroundColor Red
    exit 1
}

Write-Host 'Pushing dist to Hostinger...' -ForegroundColor Cyan
$sources = Get-ChildItem -Path $localRoot | ForEach-Object { $_.FullName }
& scp -i $keyPath -P $port -r -p -B -o StrictHostKeyChecking=accept-new @sources "${user}@${sshHost}:${remoteRoot}/"

if ($LASTEXITCODE -ne 0) {
    Write-Host "Deploy FAILED (scp exit $LASTEXITCODE)" -ForegroundColor Red
    exit $LASTEXITCODE
}

try {
    $resp = Invoke-WebRequest -Uri $liveUrl -UseBasicParsing -TimeoutSec 15
    Write-Host ("OK: HTTP {0} - {1} bytes - {2}" -f $resp.StatusCode, $resp.RawContentLength, $liveUrl) -ForegroundColor Green
} catch {
    Write-Host "Deployed but live URL check failed: $_" -ForegroundColor Yellow
}
