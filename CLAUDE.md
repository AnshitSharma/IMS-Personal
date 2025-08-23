# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BDC Inventory Management System (IMS) - A complete PHP-based REST API with JWT authentication, role-based access control (ACL), and hardware component management. The project includes server building capabilities with hardware compatibility checking.

## Architecture Overview

**Backend Structure:**
- **API Gateway**: Single entry point at `api/api.php` with routing to specialized modules
- **Authentication**: JWT-based authentication with session management
- **ACL System**: Granular permission system with roles and user assignments
- **Component Management**: Modular CRUD operations for hardware components (CPU, RAM, Storage, NIC, Motherboard, PSU, GPU, Cabinet)
- **Server Builder**: Component compatibility validation and server configuration management
- **Database Models**: Specialized classes for compatibility engine, server configuration, and component management

**Key Classes:**
- `ACL.php`: Permission and role management system
- `BaseFunctions.php`: Core utility functions and JWT integration
- `JWTHelper.php`: JWT token generation and validation
- `ServerBuilder.php`: Server configuration and component management
- `CompatibilityEngine.php`: Hardware compatibility validation
- `QueryModel.php`: Database abstraction layer

## Development Commands

**Starting Development Server:**
```bash
# PHP built-in server for API testing
php -S localhost:8000 -t .

# For frontend development (if setting up)
cd ims_frontend
# Initialize package.json first, then install dependencies
```

**Database Operations:**
```bash
# Import current database schema
mysql -u username -p database_name < "shubhams_bdc_ims memory ref.sql"

# Database connection details (update in includes/db_config.php)
# Host: localhost:3306
# Database: shubhams_bdc_ims
# User: shubhams_api
```

**Testing API Endpoints:**
```bash
# Test authentication
curl -X POST http://localhost:8000/api/api.php \
  -d "action=auth-login&username=admin&password=password"

# Test component operations (requires JWT token)
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=cpu-list"
```

## Configuration Management

**Environment Setup:**
- Configuration uses environment variables loaded from `includes/config.env`
- Database credentials in `includes/db_config.php` (update for local development)
- JWT secrets and application settings in `includes/config.php`

**Key Environment Variables:**
- `JWT_SECRET`: JWT signing key (critical for security)
- `APP_ENV`: Environment mode (development/production)
- `DATABASE_*`: Database connection parameters
- `CORS_ALLOWED_ORIGINS`: Frontend domain allowlist

## API Integration Patterns

**Authentication Flow:**
1. Login via `action=auth-login` to receive JWT token
2. Include `Authorization: Bearer <token>` header in subsequent requests
3. Tokens expire based on `JWT_EXPIRY_HOURS` configuration

**Permission System:**
- Actions require specific permissions (e.g., `cpu.create`, `server.edit`)
- Check user permissions before component operations
- Use ACL methods: `hasPermission()`, `getUserRoles()`, `assignPermission()`

**Component CRUD Pattern:**
- All component types follow consistent naming: `{type}-{action}`
- Actions: `list`, `create`, `edit`, `delete`, `view`
- UUIDs auto-generated for all components
- Status codes: 0=Failed, 1=Available, 2=In Use

## Server Building System

**Compatibility Engine:**
- Hardware compatibility validation between components
- Motherboard socket compatibility with CPUs
- Power supply wattage calculations
- Memory slot and type validation

**Component Integration:**
- Use `ServerBuilder` class for configuration management
- Validate compatibility before adding components
- Track component quantities and configurations
- Generate server specifications and cost estimates

## Security Considerations

**Authentication & Authorization:**
- JWT tokens for stateless authentication
- Role-based permission system with granular controls
- Input validation and SQL injection prevention via PDO prepared statements
- CORS configuration for cross-origin requests

**Database Security:**
- Parameterized queries throughout codebase
- Connection credentials in separate config files
- Error logging without exposing sensitive information

## Frontend Integration Notes

**API Base URL:** `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`
**Content Types:** `application/x-www-form-urlencoded` or `application/json`
**Response Format:** Consistent JSON with `success`, `authenticated`, `message`, `timestamp`, and `data` fields

## Database Schema Overview

**Core Inventory Tables:**
- `cpuinventory`: CPU components with specifications and status tracking
- `raminventory`: Memory modules with detailed specifications
- `storageinventory`: Storage devices (HDD, SSD, NVMe)
- `motherboardinventory`: Motherboard components with socket compatibility
- `nicinventory`: Network interface cards and adapters
- `caddyinventory`: Drive caddies and mounting hardware
- `pciecardinventory`: PCIe expansion cards (GPU, RAID cards, etc.)

**Authentication & Authorization:**
- `users`: User accounts with encrypted passwords
- `roles`: Role definitions with descriptions
- `permissions`: Granular permission system
- `user_roles`: User-role assignments
- `role_permissions`: Role-permission mappings
- `jwt_blacklist`: Revoked JWT tokens
- `auth_tokens`: Active authentication tokens
- `refresh_tokens`: Token refresh management

**Server Configuration System:**
- `server_configurations`: Complete server builds with component assignments
- `server_configuration_components`: Individual components within configurations
- `server_configuration_history`: Audit trail of configuration changes
- `server_build_templates`: Predefined server templates
- `server_deployments`: Production deployment tracking

**Compatibility Engine:**
- `component_compatibility`: Component compatibility mappings
- `compatibility_rules`: Validation rules for component combinations
- `compatibility_log`: Compatibility check audit logs
- `component_specifications`: Detailed technical specifications
- `component_usage_tracking`: Component allocation and deployment tracking

**Component Types Available:**
- CPU, RAM, Storage, NIC, Motherboard, Caddy, PCIe Cards
- Each type has dedicated inventory table with UUID-based identification
- Serial numbers must be unique across the system
- Status codes: 0=Failed/Decommissioned, 1=Available, 2=In Use
- All components support server assignment via `ServerUUID` field