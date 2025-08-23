# BDC IMS Server Management API Guide

## Base URL
```
https://shubham.staging.cloudmate.in/bdc_ims/api/api.php
```

## Authentication
All server management endpoints require JWT authentication token in the header:
```
Authorization: Bearer {jwt_token}
```

---

## Available Server API Endpoints

Based on the actual implementation in `api/server/server_api.php`, here are the available endpoints:

### 1. Server Configuration Creation

#### 1.1 Start Server Creation
**Action:** `server-create-start`  
**Method:** `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.create`

**Request Body (form-data):**
```
action: server-create-start
server_name: Production Server 01
description: High-performance production server (optional)
category: custom (optional, default: "custom")
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Server configuration created successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "server_name": "Production Server 01",
        "description": "High-performance production server",
        "category": "custom",
        "next_step": "motherboard",
        "progress": {
            "total_steps": 6,
            "completed_steps": 0,
            "current_step": "component_selection",
            "components_added": []
        },
        "compatibility_engine_available": true
    }
}
```

**Error Response (400):**
```json
{
    "success": false,
    "authenticated": true,
    "message": "Server name is required",
    "code": 400
}
```

#### 1.2 Add Component to Configuration
**Action:** `server-add-component`  
**Method:** `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.create` (owner) or `server.edit_all` (admin)

**Request Body (form-data):**
```
action: server-add-component
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: cpu
component_uuid: 41849749-8d19-4366-b41a-afda6fa46b58
quantity: 1 (optional, default: 1)
slot_position: CPU_1 (optional)
notes: Primary CPU (optional)
override: false (optional, default: false - set to true to override status issues)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component added successfully",
    "data": {
        "component_added": {
            "type": "cpu",
            "uuid": "41849749-8d19-4366-b41a-afda6fa46b58",
            "quantity": 1,
            "status_override_used": false,
            "original_status": "Component is Available"
        },
        "configuration_summary": {
            // Current configuration details
        },
        "next_recommendations": [
            // Array of compatible components if CompatibilityEngine available
        ],
        "compatibility_issues": []
    }
}
```

**Error Response (400 - Component Not Available):**
```json
{
    "success": false,
    "authenticated": true,
    "message": "Component is not available",
    "data": {
        "component_status": 2,
        "status_message": "Component is currently In Use",
        "component_details": {
            "uuid": "41849749-8d19-4366-b41a-afda6fa46b58",
            "serial_number": "CPU001",
            "current_status": "In Use"
        },
        "can_override": true,
        "suggested_alternatives": [
            // Array of alternative components
        ]
    }
}
```

#### 1.3 Remove Component from Configuration
**Action:** `server-remove-component`  
**Method:** `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.create` (owner) or `server.edit_all` (admin)

**Request Body (form-data):**
```
action: server-remove-component
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: cpu
component_uuid: 41849749-8d19-4366-b41a-afda6fa46b58
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component removed successfully",
    "data": {
        "component_removed": {
            "type": "cpu",
            "uuid": "41849749-8d19-4366-b41a-afda6fa46b58"
        },
        "configuration_summary": {
            // Updated configuration summary
        }
    }
}
```

---

### 2. Configuration Management

#### 2.1 Get Configuration Details
**Action:** `server-get-config`  
**Method:** `GET` or `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.view` (owner) or `server.view_all` (admin)

**Request Parameters (GET/POST):**
```
action: server-get-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration retrieved successfully",
    "data": {
        "configuration": {
            // Full configuration object with all details
        },
        "summary": {
            // Configuration summary
        },
        "validation": {
            // Configuration validation results
        }
    }
}
```

#### 2.2 List Server Configurations
**Action:** `server-list-configs`  
**Method:** `GET`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.view` (own configs) or `server.view_all` (all configs)

**Request Parameters (GET):**
```
action: server-list-configs
limit: 20 (optional, default: 20)
offset: 0 (optional, default: 0)
status: 1 (optional - filter by configuration status)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configurations retrieved successfully",
    "data": {
        "configurations": [
            {
                "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
                "server_name": "Production Server 01",
                "configuration_status": 1,
                "created_by": 1,
                "created_by_username": "admin",
                "created_at": "2025-01-15 10:00:00",
                // ... other configuration fields
            }
        ],
        "pagination": {
            "total": 45,
            "limit": 20,
            "offset": 0,
            "has_more": true
        }
    }
}
```

#### 2.3 Finalize Configuration
**Action:** `server-finalize-config`  
**Method:** `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.finalize` (owner) or admin permissions

**Request Body (form-data):**
```
action: server-finalize-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
notes: Final deployment notes (optional)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration finalized successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "finalization_details": {
            // Finalization result details
        }
    }
}
```

**Error Response (400 - Invalid Configuration):**
```json
{
    "success": false,
    "authenticated": true,
    "message": "Configuration is not valid for finalization",
    "data": {
        "validation_errors": [
            // Array of validation issues
        ]
    }
}
```

#### 2.4 Delete Configuration
**Action:** `server-delete-config`  
**Method:** `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.delete` (owner) or admin permissions

**Request Body (form-data):**
```
action: server-delete-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration deleted successfully"
}
```

**Error Response (403 - Cannot Delete Finalized):**
```json
{
    "success": false,
    "authenticated": true,
    "message": "Cannot delete finalized configurations",
    "code": 403
}
```

---

### 3. Component Management

#### 3.1 Get Available Components
**Action:** `server-get-available-components`  
**Method:** `GET` or `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.view`

**Request Parameters (GET/POST):**
```
action: server-get-available-components
component_type: cpu (required - options: cpu, ram, storage, motherboard, nic, caddy)
include_in_use: false (optional, default: false)
limit: 50 (optional, default: 50)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Available components retrieved successfully",
    "data": {
        "component_type": "cpu",
        "components": [
            {
                "UUID": "41849749-8d19-4366-b41a-afda6fa46b58",
                "SerialNumber": "CPU001",
                "Status": 1,
                "Model": "Intel Xeon E5-2680 v4",
                // ... other component-specific fields
            }
        ],
        "counts": {
            "total": 15,
            "available": 10,
            "in_use": 4,
            "failed": 1
        },
        "include_in_use": false,
        "total_returned": 10
    }
}
```

---

### 4. Configuration Validation

#### 4.1 Validate Server Configuration
**Action:** `server-validate-config`  
**Method:** `POST`  
**URL:** `api/server/server_api.php`  
**Auth Required:** Yes  
**Permission Required:** `server.view` (owner) or `server.view_all` (admin)

**Request Body (form-data):**
```
action: server-validate-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration validation completed",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "validation": {
            "is_valid": true,
            "compatibility_score": 0.95,
            "issues": [],
            "warnings": [],
            "recommendations": []
        }
    }
}
```

---

## Supported Component Types

The following component types are supported in the system:

- **cpu** - CPU components from `cpuinventory` table
- **ram** - RAM modules from `raminventory` table
- **storage** - Storage devices from `storageinventory` table
- **motherboard** - Motherboards from `motherboardinventory` table
- **nic** - Network interface cards from `nicinventory` table
- **caddy** - Drive caddies from `caddyinventory` table

---

## Component Status Codes

- **0** - Failed/Defective (cannot be used)
- **1** - Available (can be assigned to servers)
- **2** - In Use (currently assigned, can be overridden)

---

## Configuration Status Codes

- **0** - Draft
- **1** - Active 
- **2** - In Progress
- **3** - Finalized (cannot be modified without special permissions)

---

## Permission Requirements

### Server Management Permissions:
- `server.create` - Create new server configurations
- `server.view` - View own server configurations
- `server.edit` - Edit own configurations (same as create for component addition)
- `server.delete` - Delete own configurations
- `server.finalize` - Finalize configurations
- `server.view_all` - View all users' configurations (admin)
- `server.edit_all` - Edit all users' configurations (admin)
- `server.delete_finalized` - Delete finalized configurations (admin)

---

## Common Error Responses

### Authentication Required (401):
```json
{
    "success": false,
    "authenticated": false,
    "message": "Authentication required",
    "code": 401
}
```

### Invalid Action (400):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Invalid action specified",
    "code": 400
}
```

### Insufficient Permissions (403):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Insufficient permissions to modify this configuration",
    "code": 403
}
```

### Configuration Not Found (404):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Server configuration not found",
    "code": 404
}
```

### Component Not Found (404):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Component not found",
    "data": {
        "component_type": "cpu",
        "component_uuid": "invalid-uuid"
    }
}
```

### Server System Unavailable (500):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Server system unavailable",
    "code": 500
}
```

---

## Missing Endpoint

**Note:** The endpoint `server-get-compatible` that you were trying to access is **NOT IMPLEMENTED** in the current codebase. This is why you received an "Invalid action specified" error. This endpoint would need to be added to the switch statement in `server_api.php` along with its corresponding handler function.

---

## Complete Server Creation Workflow

1. **Start Configuration**
   ```
   POST: action=server-create-start
   → Returns: config_uuid
   ```

2. **Add Components** (repeat for each component type)
   ```
   POST: action=server-add-component
   → CPU, Motherboard, RAM, Storage, NIC, etc.
   ```

3. **Validate Configuration**
   ```
   POST: action=server-validate-config
   → Check compatibility and completeness
   ```

4. **Finalize Server**
   ```
   POST: action=server-finalize-config
   → Lock configuration and assign components
   ```

---

## Notes

- All timestamps are in MySQL datetime format (YYYY-MM-DD HH:MM:SS)
- UUIDs are auto-generated for all configurations and components
- Use `override=true` parameter to force add components with status issues
- Component availability is checked in real-time during addition
- Compatibility engine integration is available if `CompatibilityEngine` class exists
- Configuration validation is performed before finalization