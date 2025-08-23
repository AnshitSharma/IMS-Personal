# BDC Inventory Management System - Backend Architecture Guide

This guide provides an overview of all backend files in the BDC IMS system and their core functionalities.

## Core API Gateway

### `/api/api.php`
- **Main API Gateway**: Single entry point for all API requests with centralized routing
- **Request Processing**: Handles HTTP methods (GET, POST) and routes to appropriate modules
- **Authentication Layer**: Integrates JWT token validation and user session management
- **Response Standardization**: Ensures consistent JSON response format across all endpoints
- **Module Delegation**: Routes requests to specialized handlers (auth, components, server, ACL)

## Authentication & Authorization System

### `/includes/JWTHelper.php`
- **JWT Token Management**: Generates, validates, and refreshes JSON Web Tokens for stateless authentication
- **Token Blacklisting**: Maintains revoked tokens in database to prevent unauthorized access
- **Session Integration**: Bridges JWT tokens with traditional PHP session handling
- **Token Refresh Logic**: Implements automatic token renewal for seamless user experience
- **Security Validation**: Verifies token signatures and expiration timestamps

### `/includes/ACL.php`
- **Role-Based Access Control**: Implements granular permission system with roles and user assignments
- **Permission Management**: Handles creation, modification, and assignment of system permissions
- **Role Hierarchy**: Manages role definitions with display names and descriptions
- **User-Role Mapping**: Links users to roles and enforces permission-based access control
- **Dynamic Permission Checking**: Runtime verification of user permissions for specific actions

### `/api/auth/login_api.php`
- **User Authentication**: Validates username/password credentials against encrypted database records
- **JWT Token Generation**: Creates authentication tokens upon successful login
- **Login Logging**: Records authentication attempts for security monitoring
- **Rate Limiting**: Implements protection against brute force attacks
- **Session Initialization**: Establishes user session with role and permission data

### `/api/auth/register_api.php`
- **User Registration**: Handles new user account creation with validation
- **Password Encryption**: Securely hashes passwords using bcrypt algorithm
- **Duplicate Prevention**: Checks for existing usernames and email addresses
- **Default Role Assignment**: Assigns base permissions to newly registered users
- **Input Sanitization**: Validates and sanitizes all user input data

### `/api/auth/logout_api.php`
- **Session Termination**: Safely ends user sessions and clears authentication state
- **Token Blacklisting**: Adds logout tokens to blacklist for immediate invalidation
- **Security Cleanup**: Removes sensitive data from server-side session storage
- **Activity Logging**: Records logout events for audit trails
- **Cross-Device Logout**: Supports invalidating tokens across multiple sessions

### `/api/auth/forgot_password_api.php`
- **Password Reset**: Initiates secure password recovery process via email
- **Token Generation**: Creates time-limited reset tokens for security
- **Email Integration**: Sends password reset links to verified user email addresses
- **Token Validation**: Verifies reset tokens and enforces expiration policies
- **Password Update**: Handles secure password changes with proper hashing

### `/api/auth/change_password_api.php`
- **Password Updates**: Allows authenticated users to change their passwords
- **Current Password Verification**: Validates existing password before allowing changes
- **Password Policy Enforcement**: Ensures new passwords meet security requirements
- **Session Maintenance**: Keeps user logged in after successful password change
- **Activity Logging**: Records password change events for security monitoring

### `/api/auth/check_session_api.php`
- **Session Validation**: Verifies current user authentication status and token validity
- **Token Refresh**: Automatically renews expiring tokens to maintain user sessions
- **Permission Sync**: Updates user permissions and role data in active sessions
- **Cross-Request Validation**: Ensures consistent authentication state across API calls
- **Health Monitoring**: Provides session diagnostics for debugging and monitoring

## Core System Components

### `/includes/BaseFunctions.php`
- **Utility Functions**: Provides core helper functions used throughout the application
- **Database Abstraction**: Common database operations and connection management utilities
- **Response Formatting**: Standardized JSON response generation with success/error handling
- **Input Validation**: Common validation functions for user input and data sanitization
- **Logging Integration**: Centralized logging functions for debugging and audit trails

### `/includes/config.php`
- **System Configuration**: Central configuration file containing application-wide settings
- **Environment Variables**: Defines development/production environment specific values
- **Database Settings**: Connection parameters and database configuration options
- **Security Constants**: JWT secrets, encryption keys, and security-related settings
- **Feature Flags**: Toggles for enabling/disabling specific system features

### `/includes/db_config.php`
- **Database Connection**: Establishes PDO connections to MySQL database
- **Connection Pooling**: Manages database connection reuse and optimization
- **Error Handling**: Provides robust database error handling and logging
- **Transaction Support**: Enables database transaction management for data consistency
- **Query Optimization**: Includes database-specific performance optimizations

### `/debug_auth.php`
- **Authentication Testing**: Debug utility for testing authentication mechanisms
- **Token Validation**: Tools for manually testing JWT token generation and validation
- **Permission Testing**: Utilities for debugging role-based access control
- **Session Debugging**: Helps troubleshoot session management issues
- **Development Tools**: Debug functions available only in development environment

## Component Management System

### `/api/components/components_api.php`
- **CRUD Operations**: Complete Create, Read, Update, Delete operations for all hardware components
- **Component Types**: Handles CPU, RAM, Storage, Motherboard, NIC, and Caddy inventory management
- **Status Management**: Tracks component availability (Available, In Use, Failed states)
- **Search and Filtering**: Advanced search capabilities with multiple filter criteria
- **Bulk Operations**: Support for batch updates and mass component management

### `/api/components/add_form.php`
- **Component Creation Forms**: Generates dynamic forms for adding new hardware components
- **Field Validation**: Client and server-side validation for component specifications
- **Auto-UUID Generation**: Automatically assigns unique identifiers to new components
- **Specification Templates**: Pre-configured templates for common component types
- **Input Sanitization**: Ensures data integrity and prevents injection attacks

### `/api/components/edit_form.php`
- **Component Modification**: Handles editing existing component details and specifications
- **Change Tracking**: Maintains audit trail of component modifications
- **Status Updates**: Allows changing component status (Available/In Use/Failed)
- **Bulk Editing**: Support for modifying multiple components simultaneously
- **Validation Rules**: Ensures data consistency during component updates

## Component-Specific APIs

### CPU Management (`/api/functions/cpu/`)
- **`add_cpu.php`**: Creates new CPU inventory entries with detailed specifications
- **`list_cpu.php`**: Retrieves CPU inventory with filtering, sorting, and pagination
- **`remove_cpu.php`**: Handles CPU removal with dependency checking and status updates
- **Socket Compatibility**: Manages CPU socket types for motherboard compatibility
- **Performance Specifications**: Stores detailed CPU performance metrics and specifications

### RAM Management (`/api/functions/ram/`)
- **`add_ram.php`**: Adds memory modules with capacity, speed, and type specifications
- **`list_ram.php`**: Lists available RAM with filtering by capacity, type, and status
- **`remove_ram.php`**: Removes RAM modules with server configuration dependency checks
- **Memory Configuration**: Handles different RAM types (DDR3, DDR4, DDR5) and speeds
- **Slot Management**: Tracks memory slot assignments and configurations

### Storage Management (`/api/functions/storage/`)
- **`add_storage.php`**: Creates storage device entries (HDD, SSD, NVMe) with specifications
- **`list_storage.php`**: Retrieves storage inventory with capacity and interface filtering
- **`remove_storage.php`**: Handles storage device removal with data integrity checks
- **Interface Types**: Manages SATA, SAS, NVMe, and other storage interfaces
- **Capacity Tracking**: Monitors storage capacity utilization and availability

### Motherboard Management (`/api/functions/motherboard/`)
- **`add_motherboard.php`**: Creates motherboard entries with socket and chipset information
- **`list_motherboard.php`**: Lists motherboards with socket compatibility filtering
- **`remove_motherboard.php`**: Removes motherboards with server dependency validation
- **Socket Compatibility**: Manages CPU socket compatibility and chipset support
- **Expansion Slots**: Tracks PCIe slots, RAM slots, and other expansion capabilities

### Network Interface Management (`/api/functions/nic/`)
- **`add_nic.php`**: Adds network interface cards with speed and connector specifications
- **`list_nic.php`**: Lists NICs with filtering by speed, type, and availability
- **`remove_nic.php`**: Removes network interfaces with configuration dependency checks
- **Interface Types**: Manages Ethernet, fiber, and wireless network interfaces
- **Speed Categories**: Handles different network speeds (1Gb, 10Gb, 25Gb, etc.)

### Drive Caddy Management (`/api/functions/caddy/`)
- **`add_caddy.php`**: Creates drive caddy entries for hot-swap storage systems
- **`list_caddy.php`**: Lists available caddies with form factor and compatibility filtering
- **`remove_caddy.php`**: Removes drive caddies with storage assignment validation
- **Form Factor Support**: Manages 2.5", 3.5", and other drive form factors
- **Compatibility Tracking**: Ensures caddy compatibility with storage devices and servers

## Server Building System

### `/api/server/server_api.php`
- **Server Configuration Management**: Complete CRUD operations for server build configurations
- **Component Assignment**: Handles adding/removing components to/from server configurations
- **Compatibility Validation**: Integrates with compatibility engine for component validation
- **Configuration Templates**: Supports predefined server templates for common use cases
- **Build Workflow**: Manages step-by-step server building process with progress tracking

### `/api/server/create_server.php`
- **Server Creation Workflow**: Handles the complete server building process from start to finish
- **Step Management**: Manages multi-step server creation with validation at each stage
- **Component Selection**: Provides compatible component options based on current configuration
- **Cost Calculation**: Calculates estimated costs for complete server configurations
- **Template Support**: Allows creation of servers from predefined templates

### `/api/server/compatibility_api.php`
- **Compatibility Rules Management**: CRUD operations for component compatibility rules
- **Rule Engine Configuration**: Manages compatibility rules for different component combinations
- **Validation Logic**: Implements business rules for hardware compatibility checking
- **Custom Rules**: Supports creation of custom compatibility rules for specific scenarios
- **Rule Testing**: Provides tools for testing and validating compatibility rules

### `/includes/models/ServerBuilder.php`
- **Server Configuration Logic**: Core class for managing server build configurations
- **Component Integration**: Handles adding, removing, and validating server components
- **Configuration Validation**: Ensures server configurations meet minimum requirements
- **Status Management**: Tracks server build status throughout the creation process
- **Configuration Cloning**: Supports duplicating existing server configurations

### `/includes/models/ServerConfiguration.php`
- **Configuration Data Model**: Object-relational mapping for server configuration database records
- **CRUD Operations**: Complete database operations for server configuration management
- **Relationship Management**: Handles relationships between configurations and components
- **Version Control**: Maintains version history of configuration changes
- **Export/Import**: Supports configuration data export and import functionality

### `/includes/models/CompatibilityEngine.php`
- **Hardware Compatibility Logic**: Advanced engine for checking component compatibility
- **Rule Processing**: Executes compatibility rules and provides detailed validation results
- **Socket Validation**: Specializes in CPU-motherboard socket compatibility checking
- **Memory Compatibility**: Validates RAM compatibility with motherboard specifications
- **Power Calculations**: Estimates power consumption and PSU requirements

### `/includes/models/ComponentCompatibility.php`
- **Component Relationship Mapping**: Manages compatibility relationships between component types
- **Compatibility Matrix**: Maintains database of known compatible component combinations
- **Conflict Detection**: Identifies incompatible component combinations
- **Recommendation Engine**: Suggests compatible alternatives for incompatible components
- **Performance Impact**: Analyzes performance implications of component combinations

## Access Control Management

### `/api/acl/roles_api.php`
- **Role Management**: Complete CRUD operations for user roles and role definitions
- **Permission Assignment**: Handles assignment and removal of permissions to/from roles
- **Role Hierarchy**: Manages role inheritance and hierarchical permission structures
- **User-Role Assignment**: Links users to roles and manages role-based access
- **System Roles**: Manages built-in system roles with protected permissions

### `/api/acl/permissions_api.php`
- **Permission Management**: CRUD operations for system permissions and access rights
- **Permission Categories**: Organizes permissions into logical categories for management
- **Dynamic Permissions**: Supports runtime permission creation and modification
- **Permission Validation**: Ensures permission consistency and prevents conflicts
- **Audit Trail**: Maintains logs of permission changes for compliance and security

## System Utilities and Models

### `/includes/QueryModel.php`
- **Database Query Abstraction**: Provides standardized database query interface
- **Query Builder**: Simplifies complex SQL query construction and execution
- **Result Processing**: Standardizes database result handling and formatting
- **Connection Management**: Handles database connection pooling and optimization
- **Transaction Support**: Provides transaction management for complex operations

### `/api/dashboard/dashboard_api.php`
- **System Metrics**: Provides dashboard statistics and key performance indicators
- **Inventory Summary**: Generates summary reports of component inventory levels
- **System Health**: Monitors system health and provides status information
- **Usage Analytics**: Tracks system usage patterns and user activity
- **Alert Management**: Handles system alerts and notification generation

### `/api/search/search_api.php`
- **Global Search**: Provides search functionality across all component types and configurations
- **Advanced Filtering**: Supports complex search criteria with multiple filters
- **Full-Text Search**: Implements text search across component descriptions and notes
- **Search History**: Maintains user search history for improved user experience
- **Search Optimization**: Includes search result ranking and relevance scoring

## Legacy Authentication (Deprecated)

### `/api/login/` Directory
- **`login.php`**: Legacy login implementation (superseded by JWT auth system)
- **`logout.php`**: Legacy logout functionality (replaced by token-based logout)
- **`signup.php`**: Legacy registration system (now handled by register_api.php)
- **Note**: These files are maintained for backward compatibility but should not be used in new development
- **Migration Path**: All authentication should use the JWT-based auth API endpoints

## System Architecture Notes

### Database Integration
- All backend files use PDO for database connectivity with prepared statements
- Consistent error handling and logging across all database operations
- Transaction support for complex operations requiring data consistency
- Connection pooling and optimization for improved performance

### Security Implementation
- JWT-based stateless authentication with token refresh capabilities
- Role-based access control with granular permissions
- Input validation and sanitization across all API endpoints
- SQL injection prevention through prepared statements
- Cross-Site Request Forgery (CSRF) protection

### API Design Patterns
- RESTful API design with consistent HTTP status codes
- Standardized JSON response format across all endpoints
- Centralized error handling and logging
- Modular architecture with specialized handlers for different functionality
- Comprehensive input validation and sanitization

### Performance Considerations
- Database query optimization with proper indexing
- Response caching for frequently accessed data
- Lazy loading of component relationships
- Batch operations for bulk data manipulation
- Connection pooling for database efficiency

### Monitoring and Logging
- Comprehensive error logging with contextual information
- User activity tracking for audit trails
- Performance monitoring with execution time tracking
- Security event logging for compliance requirements
- Debug utilities for development and troubleshooting

This architecture provides a robust, scalable foundation for the BDC Inventory Management System with proper separation of concerns, security implementation, and maintainable code structure.