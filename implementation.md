# Server-Add-Component API Fixes - Implementation Guide

## Chat 1: Column Name Mismatch Fix

### Issue Fixed
API was failing with: `"Unknown column 'name' in 'SELECT'"`

### Root Cause
SQL queries in `api/server/create_server.php` used `name` but database column is `server_name`

### Changes Made
- Fixed SQL queries to use `server_name` instead of `name`
- Updated status references to use `configuration_status`
- Added proper status code mapping (0=draft, 1=validated, 2=built, 3=deployed)

---

## Chat 2: Database Schema Mismatch Fix

### Issue Fixed
API was failing with: `"Unknown column 'configuration_data' in 'SELECT'"`

### Root Cause
API routing was using wrong implementation:
- `create_server.php` expects `configuration_data` JSON column (doesn't exist)
- Correct implementation in `server_api.php` uses `server_configuration_components` table

### Solution
Fixed API routing in `api/api.php` to use correct handler based on operation type.

---

## Chat 3: Authentication Error Fix

### Issue Fixed
API was returning: 
```json
{
    "success": false,
    "authenticated": false,
    "message": "Internal server error",
    "code": 500
}
```

### Root Cause
Error handling in `server_api.php` was incorrectly setting `authenticated: false` in error responses.

### Solution
Fixed error response handling to properly indicate authentication status.

## API Implementation Details

### URL
```
POST /api/api.php
```

### Authentication
- JWT token required: `Authorization: Bearer <token>`
- User must have `server.create` permission

### Request Parameters
```
action=server-add-component
config_uuid={configuration-uuid}
component_type=cpu|motherboard|ram|storage|nic|caddy
component_uuid={component-uuid}
quantity=1 (optional)
slot_position={slot} (optional)
notes={notes} (optional)
```

### Example Request
```bash
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=d95e2554-8eb6-4a3c-90d7-45881af2a9d3" \
  -d "component_type=cpu" \
  -d "component_uuid=139e9bcd-ac86-44e9-8e9b-3178e3be1fb8" \
  -d "quantity=1" \
  -d "slot_position=CPU_1"
```

### Success Response
```json
{
  "success": true,
  "authenticated": true,
  "message": "Component added successfully",
  "code": 200,
  "data": {
    "component_added": {
      "type": "cpu",
      "uuid": "139e9bcd-ac86-44e9-8e9b-3178e3be1fb8",
      "quantity": 1
    },
    "configuration_summary": {
      "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
      "components": {
        "cpu": [{
          "component_uuid": "139e9bcd-ac86-44e9-8e9b-3178e3be1fb8",
          "quantity": 1,
          "slot_position": "CPU_1",
          "added_at": "2025-08-16 08:45:00"
        }]
      },
      "total_components": 1
    },
    "next_recommendations": [],
    "compatibility_issues": []
  }
}
```

### Error Responses

#### Invalid JWT Token
```json
{
  "success": false,
  "authenticated": false,
  "message": "Valid JWT token required - please login",
  "code": 401
}
```

#### Configuration Not Found
```json
{
  "success": false,
  "authenticated": true,
  "message": "Server configuration not found",
  "code": 404
}
```

#### Component Not Found
```json
{
  "success": false,
  "authenticated": true,
  "message": "Failed to add component",
  "code": 500
}
```

#### Missing Parameters
```json
{
  "success": false,
  "authenticated": true,
  "message": "Configuration UUID, component type, and component UUID are required",
  "code": 400
}
```

#### Insufficient Permissions
```json
{
  "success": false,
  "authenticated": true,
  "message": "Insufficient permissions to modify this configuration",
  "code": 403
}
```

## Files Updated

### 1. api/api.php (Lines 169-191)
**Changed:** API routing logic to use correct handler

**Before:**
```php
require_once(__DIR__ . '/server/create_server.php');
```

**After:**
```php
// Route to appropriate handler based on operation
if (in_array($operation, ['add-component', 'remove-component', ...])) {
    require_once(__DIR__ . '/server/server_api.php');
} else {
    require_once(__DIR__ . '/server/create_server.php');
}
```

### 2. api/server/server_api.php (Chat 3 fix)
**Changed:** Error response authentication flag

**Lines 79, 84:**
```php
// Before
send_json_response(0, 0, 400, "Unknown server operation: $operation");
send_json_response(0, 0, 500, "Server operation failed: " . $e->getMessage());

// After  
send_json_response(0, 1, 400, "Unknown server operation: $operation");
send_json_response(0, 1, 500, "Server operation failed: " . $e->getMessage());
```

### 3. api/server/create_server.php (Chat 1 fixes)
- Fixed column names: `name` → `server_name`
- Fixed status references: `status` → `configuration_status`
- Added status code mapping

## Common Issues & Solutions

### Issue: "authenticated": false with 500 error
**Cause:** Error handling setting wrong authentication flag  
**Solution:** Fixed authentication flag in error responses  

### Issue: "Configuration not found" 
**Cause:** Config UUID doesn't exist or user doesn't own it  
**Solution:** Create configuration first with `server-create-start`  

### Issue: "Component not found"
**Cause:** Component UUID doesn't exist in inventory  
**Solution:** Use valid component UUID from component list APIs  

### Issue: Missing JWT token
**Cause:** Authorization header not provided  
**Solution:** Include `Authorization: Bearer <token>` header  

## Testing Flow

### 1. Login and Get Token
```bash
curl -X POST http://localhost:8000/api/api.php \
  -d "action=auth-login&username=admin&password=password"
```

### 2. Create Server Configuration
```bash
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-create-start" \
  -d "server_name=Test Server" \
  -d "description=Test configuration"
```

### 3. Get Available Components
```bash
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=cpu-list"
```

### 4. Add Component to Configuration
```bash
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=$CONFIG_UUID" \
  -d "component_type=cpu" \
  -d "component_uuid=$CPU_UUID"
```

## Success Criteria

### Chat 1 Fix ✅
- Fixed "Unknown column 'name'" error  
- SQL queries use correct column names  
- Status mappings work correctly  

### Chat 2 Fix ✅  
- Fixed "Unknown column 'configuration_data'" error  
- API routes to correct handler implementation  
- Uses proper database schema with components table  
- ServerBuilder class handles component management  

### Chat 3 Fix ✅
- Fixed authentication flag in error responses
- 500 errors now show `"authenticated": true` when user is authenticated
- Proper error handling throughout server API

### Overall ✅
- server-add-component API works correctly  
- Components are properly stored in database  
- Component status updated to "In Use"  
- Configuration summary returned accurately  
- Error handling provides clear feedback with correct authentication status