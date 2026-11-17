# Read-only checks against the fixed development origins. No credentials needed.
$ErrorActionPreference = 'Stop'
$checks = @()
$targets = @(
    'https://api-dev.cozyhealth.org/up',
    'https://api-dev.cozyhealth.org/api/v1/config',
    'https://dev.cozyhealth.org/guardian-authorization',
    'https://dashboard-dev.cozyhealth.org'
)

foreach ($target in $targets) {
    try {
        $response = Invoke-WebRequest -Uri $target -UseBasicParsing -TimeoutSec 20
        $passed = [int]$response.StatusCode -eq 200
        $detail = 'HTTP ' + [int]$response.StatusCode
        if ($target.EndsWith('/config')) {
            $configuration = $response.Content | ConvertFrom-Json
            $passed = $passed -and $null -ne $configuration.age -and
                $configuration.age.enforced -is [bool] -and
                $configuration.age.enforced -eq $false
            $detail = 'Public age.enforced must be boolean false'
        }
        if ($target.EndsWith('/guardian-authorization')) {
            $passed = $passed -and $response.Headers['Cache-Control'] -match 'no-store' -and
                $response.Headers['Referrer-Policy'] -eq 'no-referrer'
            $detail = 'Guardian page must respond with no-store and no-referrer'
        }
        $checks += [PSCustomObject]@{url=$target; passed=[bool]$passed; detail=$detail}
    } catch {
        $checks += [PSCustomObject]@{url=$target; passed=$false; detail='Request failed'}
    }
}

$checks | ConvertTo-Json -Depth 3
if (@($checks | Where-Object { -not $_.passed }).Count -gt 0) { exit 1 }
exit 0
