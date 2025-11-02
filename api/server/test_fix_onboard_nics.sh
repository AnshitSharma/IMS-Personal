#!/bin/bash
# Test script for fixing onboard NICs in configuration 52cf78cd-746d-4599-aa99-490743ad7cff

# Configuration
API_URL="http://localhost/bdc_ims/api/api.php"  # Update with your actual API URL
CONFIG_UUID="52cf78cd-746d-4599-aa99-490743ad7cff"

echo "===== BDC IMS: Fix Onboard NICs Test ====="
echo ""

# Step 1: Login to get JWT token
echo "Step 1: Login to get JWT token..."
LOGIN_RESPONSE=$(curl -s -X POST "$API_URL" \
  -d "action=auth-login" \
  -d "username=admin" \
  -d "password=your_password")

echo "Login Response:"
echo "$LOGIN_RESPONSE" | jq '.'
echo ""

# Extract JWT token
JWT_TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.data.tokens.access_token')

if [ "$JWT_TOKEN" == "null" ] || [ -z "$JWT_TOKEN" ]; then
    echo "ERROR: Failed to get JWT token. Check your credentials."
    exit 1
fi

echo "JWT Token obtained: ${JWT_TOKEN:0:50}..."
echo ""

# Step 2: Check current configuration (before fix)
echo "Step 2: Get current configuration (before fix)..."
BEFORE_RESPONSE=$(curl -s -X POST "$API_URL" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -d "action=server-get-config" \
  -d "config_uuid=$CONFIG_UUID")

echo "Before Fix - NIC Summary:"
echo "$BEFORE_RESPONSE" | jq '.data.nic_summary'
echo ""

# Step 3: Fix onboard NICs
echo "Step 3: Fixing onboard NICs..."
FIX_RESPONSE=$(curl -s -X POST "$API_URL" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -d "action=server-fix-onboard-nics" \
  -d "config_uuid=$CONFIG_UUID")

echo "Fix Response:"
echo "$FIX_RESPONSE" | jq '.'
echo ""

# Step 4: Check configuration after fix
echo "Step 4: Get configuration (after fix)..."
AFTER_RESPONSE=$(curl -s -X POST "$API_URL" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -d "action=server-get-config" \
  -d "config_uuid=$CONFIG_UUID")

echo "After Fix - NIC Summary:"
echo "$AFTER_RESPONSE" | jq '.data.nic_summary'
echo ""

echo "After Fix - Full NIC Configuration:"
echo "$AFTER_RESPONSE" | jq '.data.nic_configuration'
echo ""

echo "===== Test Complete ====="
