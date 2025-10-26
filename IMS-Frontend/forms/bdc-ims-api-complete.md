# BDC Inventory Management System - Complete API Documentation

## Table of Contents
1. [Base Configuration](#base-configuration)
2. [Authentication Endpoints](#1-authentication-endpoints)
3. [Dashboard Endpoints](#2-dashboard-endpoints)
4. [Component Management Endpoints](#3-component-management-endpoints)
5. [Search Endpoints](#4-search-endpoints)
6. [User Management Endpoints](#5-user-management-endpoints)
7. [Role Management Endpoints](#6-role-management-endpoints)
8. [Permission Management Endpoints](#7-permission-management-endpoints)
9. [ACL (Access Control) Endpoints](#8-acl-access-control-endpoints)
10. [Available Component Types](#9-available-component-types)
11. [Permission System Reference](#10-permission-system--role-management)
12. [Admin Setup Guide](#11-how-to-create-admin-role--assign-permissions)
13. [Common Error Responses](#12-common-error-responses)

---

## Base Configuration

**Base URL:** `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`  
**Content-Type:** `application/x-www-form-urlencoded` OR `application/json`  
**Authorization:** `Bearer <jwt_token>` (for protected endpoints)

---

## 1. Authentication Endpoints

### 1.1 Login (Get JWT Token)
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** No  
**Permission Required:** None

**Request Body (form-data):**
```
action: auth-login
username: johnadmin
password: admin123
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Login successful",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user": {
      "id": 37,
      "username": "johnadmin",
      "email": "john.admin@company.com",
      "firstname": "John",
      "lastname": "Administrator",
      "primary_role": "admin",
      "roles": [
        {
          "id": 2,
          "name": "admin",
          "display_name": "Administrator",
          "description": "Administrative access with most permissions"
        }
      ]
    },
    "tokens": {
      "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
      "refresh_token": "bd9e4734d0f83a64bd880e8baba5691ac2098d2c91e766980a1e79d5050f4bc7",
      "token_type": "Bearer",
      "expires_in": 3600
    }
  }
}
```

**Error Response (401):**
```json
{
  "success": false,
  "authenticated": false,
  "message": "Invalid username or password",
  "timestamp": "2025-07-25 01:45:19",
  "code": 401
}
```

---

### 1.2 Refresh Token
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** No  
**Permission Required:** None

**Request Body (form-data):**
```
action: auth-refresh
refresh_token: bd9e4734d0f83a64bd880e8baba5691ac2098d2c91e766980a1e79d5050f4bc7
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Token refreshed successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user": {
      "id": 37,
      "username": "johnadmin",
      "email": "john.admin@company.com",
      "firstname": "John",
      "lastname": "Administrator"
    },
    "tokens": {
      "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
      "refresh_token": "new_refresh_token_here",
      "token_type": "Bearer",
      "expires_in": 3600
    }
  }
}
```

**Error Response (401):**
```json
{
  "success": false,
  "authenticated": false,
  "message": "Invalid refresh token",
  "timestamp": "2025-07-25 01:45:19",
  "code": 401
}
```

---

### 1.3 Verify Token
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `auth.login`

**Request Body (form-data):**
```
action: auth-verify_token
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Token is valid",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user": {
      "id": 37,
      "username": "johnadmin",
      "email": "john.admin@company.com",
      "firstname": "John",
      "lastname": "Administrator"
    },
    "expires_at": "2025-07-25 02:45:19"
  }
}
```

**Error Response (401):**
```json
{
  "success": false,
  "authenticated": false,
  "message": "Invalid or expired token",
  "timestamp": "2025-07-25 01:45:19",
  "code": 401
}
```

---

### 1.4 Logout
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `auth.logout`

**Request Body (form-data):**
```
action: auth-logout
refresh_token: bd9e4734d0f83a64bd880e8baba5691ac2098d2c91e766980a1e79d5050f4bc7
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Logout successful",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200
}
```

---

### 1.5 Change Password
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `auth.change_password`

**Request Body (form-data):**
```
action: auth-change_password
current_password: oldpassword123
new_password: newpassword456
confirm_password: newpassword456
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Password changed successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200
}
```

**Error Response (400):**
```json
{
  "success": false,
  "authenticated": true,
  "message": "Current password is incorrect",
  "timestamp": "2025-07-25 01:45:19",
  "code": 400
}
```

---

## 2. Dashboard Endpoints

### 2.1 Get Dashboard Data
**Method:** `GET` or `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `dashboard.view`

**Request Body (form-data):**
```
action: dashboard-get_data
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Dashboard stats retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "stats": {
      "cpu": {
        "total": 6,
        "available": 2,
        "in_use": 4,
        "failed": 0,
        "permissions": {
          "can_view": true,
          "can_create": true,
          "can_edit": true,
          "can_delete": true
        }
      },
      "ram": {
        "total": 3,
        "available": 1,
        "in_use": 2,
        "failed": 0,
        "permissions": {
          "can_view": true,
          "can_create": true,
          "can_edit": true,
          "can_delete": true
        }
      },
      "storage": {
        "total": 10,
        "available": 5,
        "in_use": 4,
        "failed": 1,
        "permissions": {
          "can_view": true,
          "can_create": true,
          "can_edit": true,
          "can_delete": true
        }
      },
      "motherboard": {
        "total": 4,
        "available": 1,
        "in_use": 3,
        "failed": 0,
        "permissions": {
          "can_view": true,
          "can_create": true,
          "can_edit": true,
          "can_delete": true
        }
      },
      "nic": {
        "total": 8,
        "available": 3,
        "in_use": 5,
        "failed": 0,
        "permissions": {
          "can_view": true,
          "can_create": true,
          "can_edit": true,
          "can_delete": true
        }
      },
      "caddy": {
        "total": 20,
        "available": 15,
        "in_use": 5,
        "failed": 0,
        "permissions": {
          "can_view": true,
          "can_create": true,
          "can_edit": true,
          "can_delete": true
        }
      },
      "users": {
        "total": 10,
        "active": 8,
        "inactive": 2
      },
      "system": {
        "version": "1.0.0",
        "last_backup": "2025-07-24 23:00:00",
        "uptime_days": 15
      }
    }
  }
}
```

---

### 2.2 Get Admin Dashboard Data
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `dashboard.admin`

**Request Body (form-data):**
```
action: dashboard-get_admin_data
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Admin dashboard data retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "system_health": {
      "api_uptime": "99.9%",
      "database_size": "1.2GB",
      "error_rate": "0.01%",
      "avg_response_time": "120ms"
    },
    "user_activity": {
      "active_sessions": 15,
      "recent_logins": 45,
      "failed_logins_today": 3
    },
    "permission_usage": {
      "most_used": ["cpu.view", "dashboard.view", "search.global"],
      "least_used": ["system.maintenance", "reports.export"]
    }
  }
}
```

---

## 3. Component Management Endpoints

### 3.1 List Components (CPU/RAM/Storage/Motherboard/NIC/Caddy)
**Method:** `GET` or `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `{component}.view` (e.g., `cpu.view`)

**Request Body (form-data):**
```
action: cpu-list              # Replace 'cpu' with component type
status: all                   # Optional: all, 0 (failed), 1 (available), 2 (in_use)
limit: 50                    # Optional: default 50, max 1000
offset: 0                    # Optional: default 0
search: CPU789               # Optional: search term
sort_by: SerialNumber        # Optional: field to sort by
sort_order: ASC              # Optional: ASC or DESC
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Components retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "components": [
      {
        "ID": 15,
        "UUID": "545e143b-57b3-419e-86e5-1df6f7aa8fy9",
        "SerialNumber": "CPU789032",
        "Status": 2,
        "StatusText": "In Use",
        "ServerUUID": "server-123-uuid",
        "Location": "Warehouse East",
        "RackPosition": "Shelf B4",
        "PurchaseDate": "2024-01-31",
        "InstallationDate": "2024-02-15",
        "WarrantyEndDate": "2026-01-31",
        "Flag": "Backup",
        "Notes": "AMD EPYC 64-core 2.9GHz",
        "CreatedAt": "2025-07-22 19:50:05",
        "UpdatedAt": "2025-07-22 19:50:05",
        "CreatedBy": "johnadmin",
        "UpdatedBy": "johnadmin"
      }
    ],
    "total": 6,
    "limit": 50,
    "offset": 0,
    "permissions": {
      "can_create": true,
      "can_edit": true,
      "can_delete": true
    }
  }
}
```

---

### 3.2 Get Single Component
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `{component}.view`

**Request Body (form-data):**
```
action: cpu-get              # Replace 'cpu' with component type
id: 15                       # Component ID
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Component retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "component": {
      "ID": 15,
      "UUID": "545e143b-57b3-419e-86e5-1df6f7aa8fy9",
      "SerialNumber": "CPU789032",
      "Status": 2,
      "StatusText": "In Use",
      "ServerUUID": "server-123-uuid",
      "Location": "Warehouse East",
      "RackPosition": "Shelf B4",
      "PurchaseDate": "2024-01-31",
      "InstallationDate": "2024-02-15",
      "WarrantyEndDate": "2026-01-31",
      "Flag": "Backup",
      "Notes": "AMD EPYC 64-core 2.9GHz",
      "CreatedAt": "2025-07-22 19:50:05",
      "UpdatedAt": "2025-07-22 19:50:05",
      "CreatedBy": "johnadmin",
      "UpdatedBy": "johnadmin",
      "history": [
        {
          "action": "status_change",
          "from": "Available",
          "to": "In Use",
          "timestamp": "2024-02-15 10:30:00",
          "user": "johnadmin"
        }
      ]
    }
  }
}
```

---

### 3.3 Add Component
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `{component}.create` (e.g., `cpu.create`)

**Request Body (form-data):**
```
action: cpu-add              # Replace 'cpu' with component type
SerialNumber: CPU789033      # Required
Status: 1                    # Required: 0=Failed, 1=Available, 2=In Use
ServerUUID:                  # Optional: Required if Status=2
Location: Warehouse East     # Optional
RackPosition: Shelf B5       # Optional
PurchaseDate: 2024-02-01     # Optional (YYYY-MM-DD)
WarrantyEndDate: 2027-02-01  # Optional (YYYY-MM-DD)
Flag: Production             # Optional
Notes: Intel Xeon 32-core    # Optional

# Additional fields for NIC components:
MacAddress: 00:1A:2B:3C:4D:5F  # Optional
IPAddress: 192.168.1.101       # Optional
NetworkName: Internal-Prod     # Optional

# Additional fields for Storage components:
Capacity: 1TB                  # Optional
Type: SSD                      # Optional: HDD, SSD, NVMe
Interface: SATA                # Optional: SATA, SAS, NVMe
```

**Success Response (201):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Component added successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 201,
  "data": {
    "id": 16,
    "uuid": "generated-uuid-here"
  }
}
```

**Error Response (409):**
```json
{
  "success": false,
  "authenticated": true,
  "message": "Component with serial number CPU789033 already exists",
  "timestamp": "2025-07-25 01:45:19",
  "code": 409
}
```

---

### 3.4 Update Component
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `{component}.edit` (e.g., `cpu.edit`)

**Request Body (form-data):**
```
action: cpu-update           # Replace 'cpu' with component type
id: 15                       # Required: Component ID
Status: 1                    # Optional
Notes: Updated notes         # Optional
Location: New Location       # Optional
RackPosition: New Position   # Optional
Flag: Updated Flag           # Optional
ServerUUID: server-uuid      # Optional
InstallationDate: 2024-03-01 # Optional

# For NIC components:
MacAddress: 00:1A:2B:3C:4D:5F  # Optional
IPAddress: 192.168.1.102       # Optional
NetworkName: Updated-Network   # Optional
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Component updated successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "updated_fields": ["Status", "Notes", "Location"]
  }
}
```

---

### 3.5 Delete Component
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `{component}.delete` (e.g., `cpu.delete`)

**Request Body (form-data):**
```
action: cpu-delete           # Replace 'cpu' with component type
id: 15                       # Required: Component ID
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Component deleted successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200
}
```

**Error Response (403):**
```json
{
  "success": false,
  "authenticated": true,
  "message": "Cannot delete component that is currently in use",
  "timestamp": "2025-07-25 01:45:19",
  "code": 403
}
```

---

### 3.6 Bulk Update Components
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `{component}.edit`

**Request Body (form-data):**
```
action: cpu-bulk_update      # Replace 'cpu' with component type
ids[]: 15                    # Component IDs (array)
ids[]: 16
ids[]: 17
Status: 1                    # Fields to update
Location: New Bulk Location
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "3 components updated successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "updated": 3,
    "failed": 0
  }
}
```

---

## 4. Search Endpoints

### 4.1 Global Component Search
**Method:** `GET` or `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `search.global`

**Request Body (form-data):**
```
action: search-components
q: CPU789                    # Required: search query
type: all                    # Optional: all, cpu, ram, storage, motherboard, nic, caddy
limit: 20                    # Optional: default 20
offset: 0                    # Optional: default 0
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Search completed successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "results": [
      {
        "ID": 15,
        "UUID": "545e143b-57b3-419e-86e5-1df6f7aa8fy9",
        "SerialNumber": "CPU789032",
        "Status": 2,
        "StatusText": "In Use",
        "Location": "Warehouse East",
        "RackPosition": "Shelf B4",
        "Notes": "AMD EPYC 64-core 2.9GHz",
        "CreatedAt": "2025-07-22 19:50:05",
        "component_type": "cpu",
        "relevance_score": 0.95
      }
    ],
    "query": "CPU789",
    "type": "all",
    "total": 1,
    "facets": {
      "component_types": {
        "cpu": 1
      },
      "statuses": {
        "in_use": 1
      }
    }
  }
}
```

---

### 4.2 Advanced Search
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `search.advanced`

**Request Body (form-data):**
```
action: search-advanced
filters[serial_number]: CPU*         # Wildcards supported
filters[status]: 1,2                # Multiple values
filters[location]: Warehouse*
filters[purchase_date_from]: 2024-01-01
filters[purchase_date_to]: 2024-12-31
filters[warranty_expires_in_days]: 90
component_types[]: cpu
component_types[]: ram
sort_by: PurchaseDate
sort_order: DESC
limit: 50
offset: 0
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Advanced search completed successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "results": [
      {
        "ID": 15,
        "component_type": "cpu",
        "SerialNumber": "CPU789032",
        "Status": 2,
        "Location": "Warehouse East",
        "PurchaseDate": "2024-01-31",
        "WarrantyEndDate": "2026-01-31",
        "warranty_days_remaining": 554
      }
    ],
    "total": 25,
    "applied_filters": {
      "serial_number": "CPU*",
      "status": [1, 2],
      "location": "Warehouse*",
      "purchase_date_range": ["2024-01-01", "2024-12-31"]
    }
  }
}
```

---

## 5. User Management Endpoints

### 5.1 List Users
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.view`

**Request Body (form-data):**
```
action: users-list
limit: 50                    # Optional: default 50
offset: 0                    # Optional: default 0
search: john                 # Optional: search in username, email, name
status: all                  # Optional: all, active, inactive
role_id: 2                   # Optional: filter by role
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Users retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "users": [
      {
        "id": 37,
        "username": "johnadmin",
        "email": "john.admin@company.com",
        "firstname": "John",
        "lastname": "Administrator",
        "status": "active",
        "created_at": "2025-06-18 11:03:56",
        "last_login": "2025-07-25 01:45:19",
        "roles": [
          {
            "id": 2,
            "name": "admin",
            "display_name": "Administrator"
          }
        ],
        "permissions_count": 40
      }
    ],
    "total": 10,
    "limit": 50,
    "offset": 0
  }
}
```

---

### 5.2 Get Single User
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.view`

**Request Body (form-data):**
```
action: users-get
id: 37                       # Required: User ID
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "User retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user": {
      "id": 37,
      "username": "johnadmin",
      "email": "john.admin@company.com",
      "firstname": "John",
      "lastname": "Administrator",
      "status": "active",
      "created_at": "2025-06-18 11:03:56",
      "updated_at": "2025-07-24 15:30:00",
      "last_login": "2025-07-25 01:45:19",
      "login_count": 156,
      "roles": [
        {
          "id": 2,
          "name": "admin",
          "display_name": "Administrator",
          "assigned_at": "2025-06-18 11:03:56"
        }
      ],
      "activity": {
        "last_action": "Updated CPU component",
        "last_action_time": "2025-07-25 01:30:00"
      }
    }
  }
}
```

---

### 5.3 Create User
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.create`

**Request Body (form-data):**
```
action: users-create
username: newuser            # Required: unique, alphanumeric + underscore
email: newuser@example.com   # Required: valid email
password: password123        # Required: min 8 characters
firstname: New               # Optional
lastname: User               # Optional
role_id: 5                   # Optional: initial role to assign
send_welcome_email: true     # Optional: default false
```

**Success Response (201):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "User created successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 201,
  "data": {
    "id": 38,
    "username": "newuser",
    "temporary_password": null,
    "welcome_email_sent": true
  }
}
```

**Error Response (409):**
```json
{
  "success": false,
  "authenticated": true,
  "message": "Username already exists",
  "timestamp": "2025-07-25 01:45:19",
  "code": 409
}
```

---

### 5.4 Update User
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.edit`

**Request Body (form-data):**
```
action: users-update
id: 38                       # Required: User ID
email: updated@example.com   # Optional
firstname: Updated           # Optional
lastname: Username           # Optional
status: active              # Optional: active, inactive
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "User updated successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "updated_fields": ["email", "firstname", "lastname"]
  }
}
```

---

### 5.5 Delete User
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.delete`

**Request Body (form-data):**
```
action: users-delete
id: 38                       # Required: User ID
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "User deleted successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200
}
```

**Error Response (403):**
```json
{
  "success": false,
  "authenticated": true,
  "message": "Cannot delete your own user account",
  "timestamp": "2025-07-25 01:45:19",
  "code": 403
}
```

---

### 5.6 Reset User Password
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.edit`

**Request Body (form-data):**
```
action: users-reset_password
id: 38                       # Required: User ID
new_password: newpass123     # Optional: if not provided, generates temporary password
send_email: true            # Optional: send new password via email
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Password reset successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "temporary_password": "TempPass123!",
    "email_sent": true
  }
}
```

---

### 5.7 Manage User Roles
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.manage_roles`

**Request Body (form-data):**
```
action: users-manage_roles
user_id: 38                  # Required
roles[]: 2                   # Array of role IDs to assign
roles[]: 5
replace: true               # Optional: true to replace all roles, false to add
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "User roles updated successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "assigned": ["admin", "technician"],
    "removed": ["viewer"]
  }
}
```

---

## 6. Role Management Endpoints

### 6.1 List All Roles
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.view`

**Request Body (form-data):**
```
action: roles-list
include_permissions: true    # Optional: include permission details
include_users: true         # Optional: include user count
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Roles retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "roles": [
      {
        "id": 1,
        "name": "super_admin",
        "display_name": "Super Administrator",
        "description": "Full system access with all permissions",
        "is_default": 0,
        "is_system": 1,
        "created_at": "2025-06-01 00:00:00",
        "updated_at": "2025-06-01 00:00:00",
        "user_count": 1,
        "permission_count": 45,
        "permissions": [
          {
            "id": 1,
            "name": "auth.login",
            "display_name": "Login to System"
          }
        ]
      }
    ],
    "total": 6
  }
}
```

---

### 6.2 Create New Role
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.create`

**Request Body (form-data):**
```
action: roles-create
name: inventory_manager      # Required: lowercase, numbers, underscores only
display_name: Inventory Manager  # Required: human-readable name
description: Manages all inventory  # Optional
basic_permissions: true      # Optional: auto-assign basic permissions (default: true)
is_default: false           # Optional: set as default for new users
```

**Success Response (201):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role created successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 201,
  "data": {
    "role_id": 7,
    "name": "inventory_manager",
    "display_name": "Inventory Manager",
    "basic_permissions_assigned": 3
  }
}
```

---

### 6.3 Get Role Details with Permissions
**Method:** `GET` or `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.view`

**Request Body (form-data):**
```
action: roles-get
id: 6                        # Required: role ID
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role details retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "role": {
      "id": 6,
      "name": "custom_editor",
      "display_name": "Custom Editor",
      "description": "Custom role for editors",
      "is_default": 0,
      "is_system": 0,
      "created_at": "2025-07-25 01:45:19",
      "updated_at": "2025-07-25 01:45:19"
    },
    "permissions": [
      {
        "id": 1,
        "name": "auth.login",
        "display_name": "Login to System",
        "category": "authentication",
        "granted": 1
      },
      {
        "id": 13,
        "name": "cpu.view",
        "display_name": "View CPUs",
        "category": "inventory",
        "granted": 1
      }
    ],
    "users": [
      {
        "id": 38,
        "username": "testuser",
        "email": "test@example.com",
        "firstname": "Test",
        "lastname": "User",
        "assigned_at": "2025-07-25 01:45:19"
      }
    ],
    "statistics": {
      "total_permissions": 45,
      "granted_permissions": 15,
      "denied_permissions": 30,
      "total_users": 2
    }
  }
}
```

---

### 6.4 Update Role Details
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.edit`

**Request Body (form-data):**
```
action: roles-update
id: 6                        # Required: role ID
display_name: Updated Name   # Required
description: Updated desc    # Optional
is_default: true            # Optional
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role updated successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200
}
```

---

### 6.5 Update Role Permissions (Bulk)
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.edit`

**Request Body (form-data):**
```
action: roles-update_permissions
role_id: 6                   # Required: role ID
permissions[1]: 1            # Permission ID 1 = granted (1) or denied (0)
permissions[2]: 1            # auth.logout = granted
permissions[3]: 1            # auth.change_password = granted
permissions[13]: 1           # cpu.view = granted
permissions[14]: 1           # cpu.create = granted
permissions[15]: 0           # cpu.edit = denied
permissions[16]: 0           # cpu.delete = denied
permissions[37]: 1           # dashboard.view = granted
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role permissions updated successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "updated": 8,
    "granted": 5,
    "denied": 3
  }
}
```

---

### 6.6 Clone Role
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.create`

**Request Body (form-data):**
```
action: roles-clone
source_role_id: 6            # Required: role to clone from
name: cloned_role           # Required: new role name
display_name: Cloned Role   # Required: new display name
description: Cloned from X  # Optional
```

**Success Response (201):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role cloned successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 201,
  "data": {
    "role_id": 8,
    "name": "cloned_role",
    "permissions_copied": 15
  }
}
```

---

### 6.7 Delete Custom Role
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.delete`

**Request Body (form-data):**
```
action: roles-delete
id: 6                        # Required: role ID (cannot delete system roles)
reassign_to: 5              # Optional: reassign users to this role ID
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role deleted successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "users_reassigned": 3,
    "new_role": "viewer"
  }
}
```

---

## 7. Permission Management Endpoints

### 7.1 Get All Available Permissions
**Method:** `GET` or `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.view`

**Request Body (form-data):**
```
action: permissions-get_all
grouped: true               # Optional: group by category
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Permissions retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "permissions": {
      "authentication": [
        {
          "id": 1,
          "name": "auth.login",
          "display_name": "Login to System",
          "description": "Basic login access",
          "category": "authentication",
          "is_basic": 1,
          "created_at": "2025-07-24 00:05:50"
        },
        {
          "id": 2,
          "name": "auth.logout",
          "display_name": "Logout from System",
          "description": "Basic logout access",
          "category": "authentication",
          "is_basic": 1,
          "created_at": "2025-07-24 00:05:50"
        },
        {
          "id": 3,
          "name": "auth.change_password",
          "display_name": "Change Own Password",
          "description": "Allow user to change their own password",
          "category": "authentication",
          "is_basic": 1,
          "created_at": "2025-07-24 00:05:50"
        }
      ],
      "inventory": [
        {
          "id": 13,
          "name": "cpu.view",
          "display_name": "View CPUs",
          "description": "View CPU inventory",
          "category": "inventory",
          "is_basic": 1,
          "created_at": "2025-07-24 00:05:50"
        },
        {
          "id": 14,
          "name": "cpu.create",
          "display_name": "Add CPUs",
          "description": "Add new CPU components",
          "category": "inventory",
          "is_basic": 0,
          "created_at": "2025-07-24 00:05:50"
        }
      ],
      "user_management": [
        {
          "id": 4,
          "name": "users.view",
          "display_name": "View Users",
          "description": "View user list and details",
          "category": "user_management",
          "is_basic": 0,
          "created_at": "2025-07-24 00:05:50"
        }
      ]
    },
    "total": 128,
    "categories": [
      "authentication",
      "inventory",
      "user_management",
      "role_management",
      "dashboard",
      "reports",
      "utilities",
      "system"
    ]
  }
}
```

---

### 7.2 Get Permissions by Category
**Method:** `GET` or `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.view`

**Request Body (form-data):**
```
action: permissions-get_by_category
category: inventory          # Required: category name
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Category permissions retrieved successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "category": "inventory",
    "permissions": [
      {
        "id": 13,
        "name": "cpu.view",
        "display_name": "View CPUs",
        "description": "View CPU inventory",
        "is_basic": 1
      },
      {
        "id": 14,
        "name": "cpu.create",
        "display_name": "Add CPUs",
        "description": "Add new CPU components",
        "is_basic": 0
      },
      {
        "id": 15,
        "name": "cpu.edit",
        "display_name": "Edit CPUs",
        "description": "Edit CPU component details",
        "is_basic": 0
      },
      {
        "id": 16,
        "name": "cpu.delete",
        "display_name": "Delete CPUs",
        "description": "Delete CPU components",
        "is_basic": 0
      }
    ],
    "count": 24
  }
}
```

---

### 7.3 Search Permissions
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.view`

**Request Body (form-data):**
```
action: permissions-search
query: cpu                   # Required: search term
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Permissions search completed",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "permissions": [
      {
        "id": 13,
        "name": "cpu.view",
        "display_name": "View CPUs",
        "category": "inventory"
      },
      {
        "id": 14,
        "name": "cpu.create",
        "display_name": "Add CPUs",
        "category": "inventory"
      }
    ],
    "query": "cpu",
    "count": 4
  }
}
```

---

## 8. ACL (Access Control) Endpoints

### 8.1 Get User Permissions
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `users.view` (for other users) or own permissions

**Request Body (form-data):**
```
action: acl-get_user_permissions
user_id: 37                  # Optional: defaults to current user
detailed: true              # Optional: include permission details
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "User permissions retrieved",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user_id": 37,
    "username": "johnadmin",
    "roles": [
      {
        "id": 2,
        "name": "admin",
        "display_name": "Administrator",
        "description": "Administrative access with most permissions",
        "assigned_at": "2025-06-18 11:03:56"
      }
    ],
    "permissions": {
      "authentication": [
        {
          "id": 1,
          "name": "auth.login",
          "display_name": "Login to System",
          "category": "authentication",
          "granted": true,
          "source": "role:admin"
        }
      ],
      "inventory": [
        {
          "id": 13,
          "name": "cpu.view",
          "display_name": "View CPUs",
          "category": "inventory",
          "granted": true,
          "source": "role:admin"
        }
      ]
    },
    "summary": {
      "total_permissions": 128,
      "granted_permissions": 40,
      "denied_permissions": 88,
      "permission_sources": {
        "role:admin": 40
      }
    }
  }
}
```

---

### 8.2 Get All Roles
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.view`

**Request Body (form-data):**
```
action: acl-get_all_roles
include_system: true        # Optional: include system roles
include_stats: true         # Optional: include statistics
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Roles retrieved",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "roles": [
      {
        "id": 1,
        "name": "super_admin",
        "display_name": "Super Administrator",
        "description": "Full system access with all permissions",
        "is_default": 0,
        "is_system": 1,
        "user_count": 1,
        "permission_count": 128,
        "created_at": "2025-06-01 00:00:00"
      },
      {
        "id": 2,
        "name": "admin",
        "display_name": "Administrator",
        "description": "Administrative access with most permissions",
        "is_default": 0,
        "is_system": 1,
        "user_count": 3,
        "permission_count": 40,
        "created_at": "2025-06-01 00:00:00"
      }
    ],
    "total": 6,
    "system_roles": 5,
    "custom_roles": 1
  }
}
```

---

### 8.3 Assign Role to User
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.assign`

**Request Body (form-data):**
```
action: acl-assign_role
user_id: 38                  # Required
role_id: 2                   # Required
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role assigned successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user": "newuser",
    "role": "admin",
    "permissions_gained": 35
  }
}
```

---

### 8.4 Remove Role from User
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.assign`

**Request Body (form-data):**
```
action: acl-remove_role
user_id: 38                  # Required
role_id: 2                   # Required
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role removed successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user": "newuser",
    "role": "admin",
    "permissions_lost": 35,
    "remaining_roles": ["viewer"]
  }
}
```

---

### 8.5 Check User Permission
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** None (checks own) or `users.view` (checks others)

**Request Body (form-data):**
```
action: acl-check_permission
user_id: 38                  # Optional: defaults to current user
permission: cpu.create       # Required: permission name
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Permission check completed",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "user_id": 38,
    "permission": "cpu.create",
    "granted": true,
    "source": "role:admin"
  }
}
```

---

### 8.6 Get Role Hierarchy
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `roles.view`

**Request Body (form-data):**
```
action: acl-get_role_hierarchy
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Role hierarchy retrieved",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "hierarchy": [
      {
        "level": 1,
        "roles": [
          {
            "id": 1,
            "name": "super_admin",
            "display_name": "Super Administrator",
            "permission_count": 128
          }
        ]
      },
      {
        "level": 2,
        "roles": [
          {
            "id": 2,
            "name": "admin",
            "display_name": "Administrator",
            "permission_count": 40
          }
        ]
      },
      {
        "level": 3,
        "roles": [
          {
            "id": 3,
            "name": "manager",
            "display_name": "Manager",
            "permission_count": 25
          }
        ]
      }
    ]
  }
}
```

---

## 9. Available Component Types

All component endpoints support these types by replacing `{component}` in the action:

- **cpu** - CPU components
- **ram** - RAM/Memory components  
- **storage** - Storage devices (HDD/SSD)
- **motherboard** - Motherboard components
- **nic** - Network Interface Cards
- **caddy** - Drive caddies/trays

**Example Actions:**
- `cpu-list`, `cpu-add`, `cpu-update`, `cpu-delete`, `cpu-get`, `cpu-bulk_update`
- `ram-list`, `ram-add`, `ram-update`, `ram-delete`, `ram-get`, `ram-bulk_update`
- `storage-list`, `storage-add`, `storage-update`, `storage-delete`, `storage-get`, `storage-bulk_update`
- `motherboard-list`, `motherboard-add`, `motherboard-update`, `motherboard-delete`
- `nic-list`, `nic-add`, `nic-update`, `nic-delete`
- `caddy-list`, `caddy-add`, `caddy-update`, `caddy-delete`

---

## 10. Permission System & Role Management

### Complete Permission Reference (All 128 Permissions)

#### Authentication Permissions (ID: 1-3)
- **1** - `auth.login` - Login to System
- **2** - `auth.logout` - Logout from System  
- **3** - `auth.change_password` - Change Own Password

#### User Management Permissions (ID: 4-8)
- **4** - `users.view` - View Users
- **5** - `users.create` - Create New Users
- **6** - `users.edit` - Edit User Details
- **7** - `users.delete` - Delete Users
- **8** - `users.manage_roles` - Assign/Remove User Roles

#### Role Management Permissions (ID: 9-12, 128)
- **9** - `roles.view` - View Roles
- **10** - `roles.create` - Create New Roles
- **11** - `roles.edit` - Edit Role Details
- **12** - `roles.delete` - Delete Custom Roles
- **128** - `roles.assign` - Assign Roles to Users

#### CPU Management Permissions (ID: 13-16)
- **13** - `cpu.view` - View CPU Components
- **14** - `cpu.create` - Add CPU Components
- **15** - `cpu.edit` - Edit CPU Details
- **16** - `cpu.delete` - Delete CPU Components

#### RAM Management Permissions (ID: 17-20)
- **17** - `ram.view` - View RAM Components
- **18** - `ram.create` - Add RAM Components
- **19** - `ram.edit` - Edit RAM Details
- **20** - `ram.delete` - Delete RAM Components

#### Storage Management Permissions (ID: 21-24)
- **21** - `storage.view` - View Storage Components
- **22** - `storage.create` - Add Storage Components
- **23** - `storage.edit` - Edit Storage Details
- **24** - `storage.delete` - Delete Storage Components

#### Motherboard Management Permissions (ID: 25-28)
- **25** - `motherboard.view` - View Motherboard Components
- **26** - `motherboard.create` - Add Motherboard Components
- **27** - `motherboard.edit` - Edit Motherboard Details
- **28** - `motherboard.delete` - Delete Motherboard Components

#### NIC Management Permissions (ID: 29-32)
- **29** - `nic.view` - View Network Interface Cards
- **30** - `nic.create` - Add Network Interface Cards
- **31** - `nic.edit` - Edit NIC Details
- **32** - `nic.delete` - Delete Network Interface Cards

#### Caddy Management Permissions (ID: 33-36)
- **33** - `caddy.view` - View Drive Caddies
- **34** - `caddy.create` - Add Drive Caddies
- **35** - `caddy.edit` - Edit Caddy Details
- **36** - `caddy.delete` - Delete Drive Caddies

#### Dashboard Permissions (ID: 37, 95)
- **37** - `dashboard.view` - View Dashboard
- **95** - `dashboard.admin` - View Admin Dashboard

#### Search Permissions (ID: 40-41)
- **40** - `search.global` - Global Search Access
- **41** - `search.advanced` - Advanced Search Features

#### Report Permissions (ID: 42-45)
- **42** - `reports.view` - View Reports
- **43** - `reports.create` - Create Reports
- **44** - `reports.export` - Export Reports
- **45** - `reports.schedule` - Schedule Reports

#### System Permissions (ID: 96-100)
- **96** - `system.settings` - Manage System Settings
- **97** - `system.backup` - Perform System Backups
- **98** - `system.restore` - Restore from Backup
- **99** - `system.maintenance` - Maintenance Mode Access
- **100** - `system.logs` - View System Logs

### System-Defined Roles

1. **super_admin** (ID: 1)
   - Full system access with all 128 permissions
   - Cannot be modified or deleted
   - Typically reserved for system administrators

2. **admin** (ID: 2)
   - Administrative access with 40 permissions
   - All inventory management permissions
   - User and role management (except system roles)
   - Dashboard and reporting access

3. **manager** (ID: 3)
   - Management level access (25 permissions)
   - Full inventory management
   - Read-only user access
   - Dashboard and basic reporting

4. **technician** (ID: 4)
   - Technical staff access (20 permissions)
   - Create and edit inventory components
   - Cannot delete components
   - Basic dashboard access

5. **viewer** (ID: 5)
   - Read-only access (10 permissions)
   - View all inventory components
   - View dashboard
   - Default role for new users

---

## 11. How to Create Admin Role & Assign Permissions

### Step-by-Step Guide: Create Custom Admin Role

#### Step 1: Create the Role
```bash
POST {{base_url}}
Authorization: Bearer {{token}}

action: roles-create
name: custom_admin
display_name: Custom Administrator
description: Custom admin with specific permissions
basic_permissions: true
```

#### Step 2: Get All Available Permissions
```bash
POST {{base_url}}
Authorization: Bearer {{token}}

action: permissions-get_all
```

#### Step 3: Update Role with Admin Permissions
```bash
POST {{base_url}}
Authorization: Bearer {{token}}

action: roles-update_permissions
role_id: 7
# Authentication - All
permissions[1]: 1      # auth.login
permissions[2]: 1      # auth.logout
permissions[3]: 1      # auth.change_password

# User Management - All
permissions[4]: 1      # users.view
permissions[5]: 1      # users.create
permissions[6]: 1      # users.edit
permissions[7]: 1      # users.delete
permissions[8]: 1      # users.manage_roles

# Role Management - View Only
permissions[9]: 1      # roles.view
permissions[10]: 0     # roles.create = DENIED
permissions[11]: 0     # roles.edit = DENIED
permissions[12]: 0     # roles.delete = DENIED
permissions[128]: 1    # roles.assign

# All Inventory - Full Access
permissions[13]: 1     # cpu.view
permissions[14]: 1     # cpu.create
permissions[15]: 1     # cpu.edit
permissions[16]: 1     # cpu.delete
permissions[17]: 1     # ram.view
permissions[18]: 1     # ram.create
permissions[19]: 1     # ram.edit
permissions[20]: 1     # ram.delete
# ... continue for all components

# Dashboard & Search
permissions[37]: 1     # dashboard.view
permissions[95]: 1     # dashboard.admin
permissions[40]: 1     # search.global
permissions[41]: 1     # search.advanced

# Reports - View Only
permissions[42]: 1     # reports.view
permissions[43]: 0     # reports.create = DENIED
permissions[44]: 1     # reports.export
permissions[45]: 0     # reports.schedule = DENIED

# System - No Access
permissions[96]: 0     # system.settings = DENIED
permissions[97]: 0     # system.backup = DENIED
permissions[98]: 0     # system.restore = DENIED
permissions[99]: 0     # system.maintenance = DENIED
permissions[100]: 0    # system.logs = DENIED
```

#### Step 4: Assign Role to User
```bash
POST {{base_url}}
Authorization: Bearer {{token}}

action: acl-assign_role
user_id: 38
role_id: 7
```

### Method 1: Direct Database Assignment (Emergency Admin Access)

```sql
-- Create admin user with hashed password
INSERT INTO users (username, email, password, firstname, lastname, created_at) 
VALUES (
  'emergency_admin', 
  'admin@yourcompany.com', 
  '$2y$10$YourHashedPasswordHere', -- Use password_hash('your_password', PASSWORD_DEFAULT) in PHP
  'Emergency', 
  'Admin',
  NOW()
);

-- Get the new user ID
SELECT id FROM users WHERE username = 'emergency_admin';

-- Assign super_admin role (assuming user ID is 39)
INSERT INTO user_roles (user_id, role_id, assigned_at) 
VALUES (39, 1, NOW());

-- Verify assignment
SELECT u.username, r.name, r.display_name 
FROM users u 
JOIN user_roles ur ON u.id = ur.user_id 
JOIN roles r ON ur.role_id = r.id 
WHERE u.id = 39;
```

### Method 2: Using API with Existing Admin

```bash
# Step 1: Create User
POST {{base_url}}
Authorization: Bearer {{admin_token}}

action: users-create
username: newadmin
email: newadmin@company.com
password: SecurePassword123!
firstname: New
lastname: Administrator
role_id: 2  # Assign admin role directly

# Step 2: If role wasn't assigned during creation
POST {{base_url}}
Authorization: Bearer {{admin_token}}

action: acl-assign_role
user_id: {{new_user_id}}
role_id: 2
```

### Method 3: Promote Existing User to Admin

```bash
# Get user details first
POST {{base_url}}
Authorization: Bearer {{admin_token}}

action: users-get
id: 38

# Remove current roles (optional)
POST {{base_url}}
Authorization: Bearer {{admin_token}}

action: acl-remove_role
user_id: 38
role_id: 5  # Remove viewer role

# Assign admin role
POST {{base_url}}
Authorization: Bearer {{admin_token}}

action: acl-assign_role
user_id: 38
role_id: 2  # Admin role
```

---

## 12. Common Error Responses

### Authentication Errors

#### 401 Unauthorized - No Token
```json
{
  "success": false,
  "authenticated": false,
  "message": "Valid JWT token required - please login",
  "timestamp": "2025-07-25 01:45:19",
  "code": 401,
  "error_code": "AUTH_TOKEN_MISSING"
}
```

#### 401 Unauthorized - Invalid Token
```json
{
  "success": false,
  "authenticated": false,
  "message": "Invalid or expired token",
  "timestamp": "2025-07-25 01:45:19",
  "code": 401,
  "error_code": "AUTH_TOKEN_INVALID"
}
```

#### 401 Unauthorized - Token Expired
```json
{
  "success": false,
  "authenticated": false,
  "message": "Token has expired - please refresh or login again",
  "timestamp": "2025-07-25 01:45:19",
  "code": 401,
  "error_code": "AUTH_TOKEN_EXPIRED"
}
```

### Permission Errors

#### 403 Forbidden - Insufficient Permissions
```json
{
  "success": false,
  "authenticated": true,
  "message": "Insufficient permissions: cpu.create required",
  "timestamp": "2025-07-25 01:45:19",
  "code": 403,
  "error_code": "PERMISSION_DENIED",
  "required_permission": "cpu.create",
  "user_permissions": ["cpu.view"]
}
```

#### 403 Forbidden - System Resource
```json
{
  "success": false,
  "authenticated": true,
  "message": "Cannot modify system role",
  "timestamp": "2025-07-25 01:45:19",
  "code": 403,
  "error_code": "SYSTEM_RESOURCE_PROTECTED"
}
```

### Validation Errors

#### 400 Bad Request - Missing Required Field
```json
{
  "success": false,
  "authenticated": true,
  "message": "Required field missing: SerialNumber",
  "timestamp": "2025-07-25 01:45:19",
  "code": 400,
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "SerialNumber": ["This field is required"]
  }
}
```

#### 400 Bad Request - Invalid Format
```json
{
  "success": false,
  "authenticated": true,
  "message": "Validation failed",
  "timestamp": "2025-07-25 01:45:19",
  "code": 400,
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "email": ["Invalid email format"],
    "username": ["Username can only contain letters, numbers, and underscores"]
  }
}
```

### Resource Errors

#### 404 Not Found
```json
{
  "success": false,
  "authenticated": true,
  "message": "Component not found",
  "timestamp": "2025-07-25 01:45:19",
  "code": 404,
  "error_code": "RESOURCE_NOT_FOUND",
  "resource_type": "cpu",
  "resource_id": 999
}
```

#### 409 Conflict - Duplicate Resource
```json
{
  "success": false,
  "authenticated": true,
  "message": "Component with this serial number already exists",
  "timestamp": "2025-07-25 01:45:19",
  "code": 409,
  "error_code": "DUPLICATE_RESOURCE",
  "duplicate_field": "SerialNumber",
  "duplicate_value": "CPU789033"
}
```

### Server Errors

#### 500 Internal Server Error
```json
{
  "success": false,
  "authenticated": true,
  "message": "An unexpected error occurred",
  "timestamp": "2025-07-25 01:45:19",
  "code": 500,
  "error_code": "INTERNAL_ERROR",
  "request_id": "req_123456789"
}
```

#### 503 Service Unavailable
```json
{
  "success": false,
  "authenticated": false,
  "message": "Service temporarily unavailable - maintenance in progress",
  "timestamp": "2025-07-25 01:45:19",
  "code": 503,
  "error_code": "SERVICE_UNAVAILABLE",
  "retry_after": 300
}
```

### Rate Limiting

#### 429 Too Many Requests
```json
{
  "success": false,
  "authenticated": true,
  "message": "Rate limit exceeded",
  "timestamp": "2025-07-25 01:45:19",
  "code": 429,
  "error_code": "RATE_LIMIT_EXCEEDED",
  "limit": 100,
  "remaining": 0,
  "reset_at": "2025-07-25 02:00:00"
}
```

---

## Additional Endpoints

### Reports Endpoints

#### Generate Inventory Report
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `reports.view`

**Request Body (form-data):**
```
action: reports-generate_inventory
format: pdf                  # Options: pdf, csv, excel
include_components[]: cpu    # Component types to include
include_components[]: ram
include_components[]: storage
date_from: 2025-01-01       # Optional
date_to: 2025-07-25         # Optional
group_by: location          # Optional: location, status, type
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Report generated successfully",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "report_id": "rpt_123456",
    "download_url": "https://shubham.staging.cloudmate.in/bdc_ims/reports/download/rpt_123456",
    "expires_at": "2025-07-26 01:45:19",
    "size_bytes": 245678,
    "format": "pdf"
  }
}
```

### System Endpoints

#### Get System Status
**Method:** `POST`  
**URL:** `{{base_url}}`  
**Auth Required:** Yes (Bearer Token)  
**Permission Required:** `system.settings`

**Request Body (form-data):**
```
action: system-status
```

**Success Response (200):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "System status retrieved",
  "timestamp": "2025-07-25 01:45:19",
  "code": 200,
  "data": {
    "version": "1.0.0",
    "environment": "staging",
    "database": {
      "connected": true,
      "version": "8.0.33",
      "size_mb": 1234
    },
    "api": {
      "uptime_hours": 360,
      "requests_today": 15678,
      "avg_response_ms": 125
    },
    "storage": {
      "used_gb": 45,
      "total_gb": 100,
      "percent_used": 45
    }
  }
}
```

---

## API Best Practices

### Authentication Flow
1. Login with `auth-login` to get tokens
2. Use `access_token` in Authorization header for all requests
3. When token expires, use `auth-refresh` with refresh_token
4. Store refresh_token securely - it's long-lived
5. On 401 errors, attempt refresh before re-login

### Error Handling
1. Always check `success` field first
2. Use `error_code` for programmatic handling
3. Display `message` to users
4. Log `request_id` for debugging server errors
5. Implement exponential backoff for 429/503 errors

### Permission Checking
1. Check permissions before showing UI elements
2. Use `acl-check_permission` for single permission checks
3. Cache permission results to reduce API calls
4. Handle 403 errors gracefully with helpful messages

### Bulk Operations
1. Use bulk endpoints when updating multiple items
2. Limit bulk operations to 100 items per request
3. Implement progress indicators for large operations
4. Handle partial failures in bulk operations

### Search Optimization
1. Use specific component endpoints for simple lists
2. Use global search for cross-component queries
3. Implement debouncing for real-time search
4. Cache frequently accessed data
5. Use pagination for large result sets

---

## Webhook Events (If Configured)

### Available Webhook Events
- `component.created` - When any component is added
- `component.updated` - When any component is modified
- `component.deleted` - When any component is removed
- `user.created` - When a user is created
- `user.role_changed` - When user roles are modified
- `system.backup_completed` - When backup finishes
- `system.error` - When critical errors occur

### Webhook Payload Format
```json
{
  "event": "component.created",
  "timestamp": "2025-07-25 01:45:19",
  "data": {
    "component_type": "cpu",
    "component_id": 16,
    "component_uuid": "545e143b-57b3-419e-86e5-1df6f7aa8fy9",
    "user": {
      "id": 37,
      "username": "johnadmin"
    }
  },
  "signature": "sha256_hash_here"
}
```

---

## Appendix: Quick Reference

### Component Status Codes
- `0` - Failed/Defective
- `1` - Available
- `2` - In Use

### User Status Values
- `active` - Can login and use system
- `inactive` - Cannot login, preserved for records

### Permission Categories
- `authentication` - Login/logout/password
- `inventory` - Component management
- `user_management` - User CRUD
- `role_management` - Role/permission management
- `dashboard` - Dashboard access
- `reports` - Reporting features
- `utilities` - Search and tools
- `system` - System administration

### Date/Time Formats
- All dates: `YYYY-MM-DD` (e.g., 2025-07-25)
- All timestamps: `YYYY-MM-DD HH:MM:SS` (e.g., 2025-07-25 01:45:19)
- Timezone: System configured (check system-status endpoint)