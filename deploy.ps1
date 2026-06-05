# Manual deploy: builds the Astro site and SCPs dist/* to the JF staging host.
# Use this when CI is unavailable or for ad-hoc one-off pushes.
#
# Run from PowerShell (Windows): .\deploy.ps1
# Or Git Bash:                   pwsh ./deploy.ps1

$ErrorActionPreference = 'Stop'

$localRoot  = Join-Path $PSScriptRoot 'dist'
$remoteRoot = '/home/u778119288/domains/lightskyblue-camel-545209.hostingersite.com/public_html'
$sshHost    = '145.79.25.15'
$port       = 65002
$user       = 'u778119288'
$keyPath    = "$HOME\.ssh\Hostinger-site-deploy"
$liveUrl    = 'https://lightskyblue-camel-545209.hostingersite.com/'

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
