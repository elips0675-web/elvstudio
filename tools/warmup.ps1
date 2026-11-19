param(
    [int]$Port = 8090,
    [string]$Site = ''
)

$ErrorActionPreference = 'SilentlyContinue'
$root = "http://127.0.0.1:$Port"

for ($i = 0; $i -lt 60; $i++) {
    $probe = Invoke-WebRequest -Uri ($root + '/') -UseBasicParsing -TimeoutSec 2

    if ($probe.StatusCode -eq 200) {
        break
    }

    Start-Sleep -Milliseconds 500
}

@(
    '/',
    '/blog/',
    '/blog/?page=2',
    '/calculator/',
    '/pricing/',
    '/modules/',
    '/kit/'
) | ForEach-Object {
    $null = Invoke-WebRequest -Uri ($root + $_) -UseBasicParsing -TimeoutSec 20
}

if ($Site -ne '') {
    Start-Process $Site
}
