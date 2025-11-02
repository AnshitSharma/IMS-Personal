# Test script for fixing onboard NICs in configuration 52cf78cd-746d-4599-aa99-490743ad7cff

# Configuration
$API_URL = "http://localhost/bdc_ims/api/api.php"  # Update with your actual API URL
$CONFIG_UUID = "52cf78cd-746d-4599-aa99-490743ad7cff"
$USERNAME = "admin"
$PASSWORD = "your_password"  # Update with your actual password

Write-Host "===== BDC IMS: Fix Onboard NICs Test =====" -ForegroundColor Cyan
Write-Host ""

# Step 1: Login to get JWT token
Write-Host "Step 1: Login to get JWT token..." -ForegroundColor Yellow

$loginBody = @{
    action = "auth-login"
    username = $USERNAME
    password = $PASSWORD
}

try {
    $loginResponse = Invoke-RestMethod -Uri $API_URL -Method POST -Body $loginBody -ContentType "application/x-www-form-urlencoded"
    Write-Host "Login Response:" -ForegroundColor Green
    $loginResponse | ConvertTo-Json -Depth 10
    Write-Host ""

    if (-not $loginResponse.success) {
        Write-Host "ERROR: Login failed. Check your credentials." -ForegroundColor Red
        exit 1
    }

    $JWT_TOKEN = $loginResponse.data.tokens.access_token
    Write-Host "JWT Token obtained: $($JWT_TOKEN.Substring(0, [Math]::Min(50, $JWT_TOKEN.Length)))..." -ForegroundColor Green
    Write-Host ""

} catch {
    Write-Host "ERROR: Failed to connect to API: $_" -ForegroundColor Red
    exit 1
}

# Step 2: Check current configuration (before fix)
Write-Host "Step 2: Get current configuration (before fix)..." -ForegroundColor Yellow

$headers = @{
    "Authorization" = "Bearer $JWT_TOKEN"
}

$beforeBody = @{
    action = "server-get-config"
    config_uuid = $CONFIG_UUID
}

try {
    $beforeResponse = Invoke-RestMethod -Uri $API_URL -Method POST -Headers $headers -Body $beforeBody -ContentType "application/x-www-form-urlencoded"
    Write-Host "Before Fix - NIC Summary:" -ForegroundColor Green
    $beforeResponse.data.nic_summary | ConvertTo-Json
    Write-Host ""
} catch {
    Write-Host "ERROR: Failed to get configuration: $_" -ForegroundColor Red
}

# Step 3: Fix onboard NICs
Write-Host "Step 3: Fixing onboard NICs..." -ForegroundColor Yellow

$fixBody = @{
    action = "server-fix-onboard-nics"
    config_uuid = $CONFIG_UUID
}

try {
    $fixResponse = Invoke-RestMethod -Uri $API_URL -Method POST -Headers $headers -Body $fixBody -ContentType "application/x-www-form-urlencoded"
    Write-Host "Fix Response:" -ForegroundColor Green
    $fixResponse | ConvertTo-Json -Depth 10
    Write-Host ""

    if ($fixResponse.success) {
        Write-Host "SUCCESS: Onboard NICs fixed!" -ForegroundColor Green
    } else {
        Write-Host "WARNING: Fix operation returned success=false" -ForegroundColor Yellow
    }
} catch {
    Write-Host "ERROR: Failed to fix onboard NICs: $_" -ForegroundColor Red
}

# Step 4: Check configuration after fix
Write-Host "Step 4: Get configuration (after fix)..." -ForegroundColor Yellow

try {
    $afterResponse = Invoke-RestMethod -Uri $API_URL -Method POST -Headers $headers -Body $beforeBody -ContentType "application/x-www-form-urlencoded"

    Write-Host "After Fix - NIC Summary:" -ForegroundColor Green
    $afterResponse.data.nic_summary | ConvertTo-Json
    Write-Host ""

    Write-Host "After Fix - Full NIC Configuration:" -ForegroundColor Green
    $afterResponse.data.nic_configuration | ConvertTo-Json -Depth 10
    Write-Host ""

    # Compare before and after
    Write-Host "Comparison:" -ForegroundColor Cyan
    Write-Host "  Before: Onboard NICs = $($beforeResponse.data.nic_summary.onboard_nics)" -ForegroundColor Yellow
    Write-Host "  After:  Onboard NICs = $($afterResponse.data.nic_summary.onboard_nics)" -ForegroundColor Green
    Write-Host ""

} catch {
    Write-Host "ERROR: Failed to get configuration after fix: $_" -ForegroundColor Red
}

Write-Host "===== Test Complete =====" -ForegroundColor Cyan
