<?php
/**
 * Complete JWT-Based API with ACL Integration, Server Management, and Compatibility System
 * File: api/api.php
 */

// Disable output buffering and clean any existing output
if (ob_get_level()) {
    ob_end_clean();
}

// Error reporting settings
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include required files
require_once(__DIR__ . '/../includes/db_config.php');
require_once(__DIR__ . '/../includes/BaseFunctions.php');

// Global error handler
set_error_handler(function($severity, $message, $file, $line) {
    error_log("PHP Error: $message in $file on line $line");
    if ($severity === E_ERROR || $severity === E_PARSE || $severity === E_CORE_ERROR) {
        send_json_response(0, 0, 500, "Internal server error");
    }
});

// Exception handler
set_exception_handler(function($exception) {
    error_log("Uncaught exception: " . $exception->getMessage());
    send_json_response(0, 0, 500, "Internal server error");
});

// Initialize ACL system
initializeACLSystem($pdo);

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if (empty($action)) {
        send_json_response(0, 0, 400, "Action parameter is required");
    }
    
    $parts = explode('-', $action, 2);
    $module = $parts[0] ?? '';
    $operation = $parts[1] ?? '';
    
    error_log("API called with action: $action (Module: $module, Operation: $operation)");
    
    // Authentication operations (no login required)
    if ($module === 'auth') {
        handleAuthOperations($operation);
        exit();
    }
    
    // All other operations require JWT authentication
    $user = authenticateWithJWT($pdo);
    if (!$user) {
        send_json_response(0, 0, 401, "Valid JWT token required - please login");
    }
    
    error_log("Authenticated user: " . $user['username'] . " (ID: " . $user['id'] . ")");
    
    // Route to appropriate module handlers
    switch ($module) {
        case 'server':
            handleServerModule($operation, $user);
            break;
            
        case 'compatibility':
            handleCompatibilityModule($operation, $user);
            break;
            
        case 'acl':
            handleACLOperations($operation, $user);
            break;
            
        case 'roles':
            handleRolesOperations($operation, $user);
            break;
            
        case 'permissions':
            handlePermissionsOperations($operation, $user);
            break;
            
        case 'dashboard':
            handleDashboardOperations($operation, $user);
            break;
            
        case 'search':
            handleSearchOperations($operation, $user);
            break;
            
        case 'users':
            handleUserOperations($operation, $user);
            break;
            
        // Component operations
        case 'cpu':
        case 'ram':
        case 'storage':
        case 'motherboard':
        case 'nic':
        case 'caddy':
            handleComponentOperations($module, $operation, $user);
            break;
            
        default:
            error_log("Invalid module requested: $module");
            send_json_response(0, 1, 400, "Invalid module: $module");
    }
    
} catch (Exception $e) {
    error_log("API error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Internal server error: " . $e->getMessage());
}

/**
 * Handle server creation and management operations
 */
function handleServerModule($operation, $user) {
    global $pdo;
    
    // Map operations to their required permissions
    $permissionMap = [
        'create-start' => 'server.create',
        'add-component' => 'server.create',
        'remove-component' => 'server.edit',
        'get-compatible' => 'server.view',
        'validate-config' => 'server.view',
        'save-config' => 'server.create',
        'load-config' => 'server.view',
        'list-configs' => 'server.view',
        'delete-config' => 'server.delete',
        'clone-config' => 'server.create',
        'get-statistics' => 'server.view_statistics',
        'update-config' => 'server.edit',
        'get-components' => 'server.view',
        'export-config' => 'server.view'
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? 'server.view';
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    // Check if server system is initialized
    if (!serverSystemInitialized($pdo)) {
        send_json_response(0, 1, 503, "Server system not initialized. Please run database migrations first.");
    }
    
    // Include server API handler
    require_once(__DIR__ . '/server/create_server.php');
}

/**
 * Handle compatibility checking operations
 */
function handleCompatibilityModule($operation, $user) {
    global $pdo;
    
    // Map operations to their required permissions
    $permissionMap = [
        'check' => 'compatibility.check',
        'check-pair' => 'compatibility.check',
        'check-multiple' => 'compatibility.check',
        'get-compatible-for' => 'compatibility.check',
        'batch-check' => 'compatibility.check',
        'analyze-configuration' => 'compatibility.check',
        'get-rules' => 'compatibility.view_statistics',
        'test-rule' => 'compatibility.manage_rules',
        'get-statistics' => 'compatibility.view_statistics'
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? 'compatibility.check';
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    // Check if compatibility system is initialized
    if (!serverSystemInitialized($pdo)) {
        send_json_response(0, 1, 503, "Compatibility system not initialized. Please run database migrations first.");
    }
    
    // Include compatibility API handler
    require_once(__DIR__ . '/server/compatibility_api.php');
}

/**
 * Handle ACL operations
 */
function handleACLOperations($operation, $user) {
    global $pdo;
    
    $acl = new ACL($pdo);
    
    switch ($operation) {
        case 'get_user_permissions':
            $requestedUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? $user['id'];
            
            // Check if user can view permissions (self or admin)
            if ($requestedUserId != $user['id'] && !$acl->hasPermission($user['id'], 'users.view')) {
                send_json_response(0, 1, 403, "Insufficient permissions to view user permissions");
            }
            
            $userRoles = $acl->getUserRoles($requestedUserId);
            $permissions = [];
            
            // Get permissions grouped by category
            $allPermissions = $acl->getAllPermissions();
            foreach ($allPermissions as $category => $categoryPermissions) {
                $permissions[$category] = [];
                foreach ($categoryPermissions as $permission) {
                    $hasPermission = $acl->hasPermission($requestedUserId, $permission['name']);
                    $permissions[$category][] = [
                        'name' => $permission['name'],
                        'display_name' => $permission['display_name'],
                        'category' => $permission['category'],
                        'granted' => $hasPermission
                    ];
                }
            }
            
            send_json_response(1, 1, 200, "User permissions retrieved", [
                'user_id' => $requestedUserId,
                'roles' => $userRoles,
                'permissions' => $permissions,
                'total_permissions' => array_sum(array_map('count', $permissions))
            ]);
            break;
            
        case 'get_all_roles':
            if (!$acl->hasPermission($user['id'], 'roles.view')) {
                send_json_response(0, 1, 403, "Insufficient permissions to view roles");
            }
            
            $roles = $acl->getAllRoles();
            
            send_json_response(1, 1, 200, "Roles retrieved", [
                'roles' => $roles,
                'total' => count($roles)
            ]);
            break;
            
        case 'get_all_permissions':
            if (!$acl->hasPermission($user['id'], 'roles.view')) {
                send_json_response(0, 1, 403, "Insufficient permissions to view permissions");
            }
            
            $permissions = $acl->getAllPermissions();
            
            send_json_response(1, 1, 200, "Permissions retrieved", [
                'permissions' => $permissions,
                'total' => array_sum(array_map('count', $permissions))
            ]);
            break;
            
        case 'assign_role':
            if (!$acl->hasPermission($user['id'], 'roles.assign')) {
                send_json_response(0, 1, 403, "Insufficient permissions to assign roles");
            }
            
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and Role ID are required");
            }
            
            if ($acl->assignRole($targetUserId, $roleId, $user['id'])) {
                logActivity($pdo, $user['id'], "Role assigned", 'user_management', $targetUserId, "Assigned role $roleId to user $targetUserId");
                send_json_response(1, 1, 200, "Role assigned successfully");
            } else {
                send_json_response(0, 1, 500, "Failed to assign role");
            }
            break;
            
        case 'remove_role':
            if (!$acl->hasPermission($user['id'], 'roles.assign')) {
                send_json_response(0, 1, 403, "Insufficient permissions to remove roles");
            }
            
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and Role ID are required");
            }
            
            if ($acl->removeRole($targetUserId, $roleId)) {
                logActivity($pdo, $user['id'], "Role removed", 'user_management', $targetUserId, "Removed role $roleId from user $targetUserId");
                send_json_response(1, 1, 200, "Role removed successfully");
            } else {
                send_json_response(0, 1, 500, "Failed to remove role");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid ACL operation: $operation");
    }
}

/**
 * Handle roles operations
 */
function handleRolesOperations($operation, $user) {
    global $pdo;
    
    // Initialize ACL instance
    $acl = new ACL($pdo);
    
    // Set global ACL for roles_api.php to use
    $GLOBALS['acl'] = $acl;
    
    // Include the roles API file which will handle the specific operation
    require_once(__DIR__ . '/acl/roles_api.php');
}

/**
 * Handle permissions operations
 */
function handlePermissionsOperations($operation, $user) {
    global $pdo;
    
    // Initialize ACL instance
    $acl = new ACL($pdo);
    
    // Set global ACL for permissions_api.php to use
    $GLOBALS['acl'] = $acl;
    
    // Include the permissions API file which will handle the specific operation
    require_once(__DIR__ . '/acl/permissions_api.php');
}

/**
 * Handle component operations with enhanced compatibility support
 */
function handleComponentOperations($module, $operation, $user) {
    global $pdo;
    
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    $table = $tableMap[$module];
    
    // Enhanced operations with compatibility checking
    if ($operation === 'get-compatible') {
        // Handle getting compatible components for a specific component
        if (!hasPermission($pdo, "$module.view", $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions");
        }
        
        if (!serverSystemInitialized($pdo)) {
            send_json_response(0, 1, 503, "Compatibility system not available");
        }
        
        require_once(__DIR__ . '/../includes/models/CompatibilityEngine.php');
        
        $componentUuid = $_GET['component_uuid'] ?? $_POST['component_uuid'] ?? '';
        $targetType = $_GET['target_type'] ?? $_POST['target_type'] ?? '';
        $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
        
        if (empty($componentUuid)) {
            send_json_response(0, 1, 400, "Component UUID is required");
        }
        
        try {
            $compatibilityEngine = new CompatibilityEngine($pdo);
            $baseComponent = ['type' => $module, 'uuid' => $componentUuid];
            
            if ($targetType) {
                $compatibleComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $targetType, $availableOnly);
                
                send_json_response(1, 1, 200, "Compatible components retrieved", [
                    'base_component' => $baseComponent,
                    'target_type' => $targetType,
                    'compatible_components' => $compatibleComponents,
                    'total_count' => count($compatibleComponents)
                ]);
            } else {
                // Get compatible components for all types
                $allCompatible = [];
                $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
                
                foreach ($componentTypes as $type) {
                    if ($type !== $module) {
                        $allCompatible[$type] = $compatibilityEngine->getCompatibleComponents($baseComponent, $type, $availableOnly);
                    }
                }
                
                send_json_response(1, 1, 200, "All compatible components retrieved", [
                    'base_component' => $baseComponent,
                    'compatible_components' => $allCompatible,
                    'summary' => array_map(function($components) {
                        return ['count' => count($components)];
                    }, $allCompatible)
                ]);
            }
        } catch (Exception $e) {
            error_log("Error getting compatible components: " . $e->getMessage());
            send_json_response(0, 1, 500, "Error retrieving compatible components");
        }
        
        return;
    }
    
    // Delegate to existing component API for standard operations
    require_once(__DIR__ . '/components/components_api.php');
}

/**
 * Handle dashboard operations with server statistics
 */
function handleDashboardOperations($operation, $user) {
    global $pdo;
    
    switch ($operation) {
        case 'get_data':
        case 'stats':
            requirePermission($pdo, 'dashboard.view', $user['id']);
            
            try {
                $stats = getSystemStats($pdo);
                
                // Add server configuration statistics if user has permission and system is available
                if (hasPermission($pdo, 'server.view_statistics', $user['id']) && serverSystemInitialized($pdo)) {
                    $serverStats = getServerConfigurationStats($pdo);
                    $stats['server_configurations'] = $serverStats;
                }
                
                // Add compatibility statistics if available
                if (hasPermission($pdo, 'compatibility.view_statistics', $user['id']) && serverSystemInitialized($pdo)) {
                    try {
                        require_once(__DIR__ . '/../includes/models/CompatibilityEngine.php');
                        $compatibilityEngine = new CompatibilityEngine($pdo);
                        $compatibilityStats = $compatibilityEngine->getCompatibilityStatistics();
                        $stats['compatibility'] = $compatibilityStats;
                    } catch (Exception $e) {
                        error_log("Error getting compatibility stats: " . $e->getMessage());
                        $stats['compatibility'] = null;
                    }
                }
                
                send_json_response(1, 1, 200, "Dashboard stats retrieved successfully", ['stats' => $stats]);
            } catch (Exception $e) {
                error_log("Error getting dashboard stats: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve dashboard stats");
            }
            break;
            
        case 'server-summary':
            requirePermission($pdo, 'server.view_statistics', $user['id']);
            
            if (!serverSystemInitialized($pdo)) {
                send_json_response(0, 1, 503, "Server system not available");
            }
            
            try {
                $serverStats = getServerConfigurationStats($pdo);
                send_json_response(1, 1, 200, "Server statistics retrieved", $serverStats);
            } catch (Exception $e) {
                error_log("Error getting server stats: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve server stats");
            }
            break;
            
        case 'compatibility-summary':
            requirePermission($pdo, 'compatibility.view_statistics', $user['id']);
            
            if (!serverSystemInitialized($pdo)) {
                send_json_response(0, 1, 503, "Compatibility system not available");
            }
            
            try {
                require_once(__DIR__ . '/../includes/models/CompatibilityEngine.php');
                $compatibilityEngine = new CompatibilityEngine($pdo);
                $compatibilityStats = $compatibilityEngine->getCompatibilityStatistics();
                
                send_json_response(1, 1, 200, "Compatibility statistics retrieved", $compatibilityStats);
            } catch (Exception $e) {
                error_log("Error getting compatibility stats: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve compatibility stats");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid dashboard operation: $operation");
    }
}

/**
 * Handle search operations
 */
function handleSearchOperations($operation, $user) {
    global $pdo;
    
    switch ($operation) {
        case 'components':
            requirePermission($pdo, 'search.global', $user['id']);
            
            try {
                $query = $_GET['q'] ?? $_POST['q'] ?? '';
                $componentType = $_GET['type'] ?? $_POST['type'] ?? 'all';
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
                $includeCompatibility = filter_var($_GET['include_compatibility'] ?? $_POST['include_compatibility'] ?? false, FILTER_VALIDATE_BOOLEAN);
                
                if (empty($query)) {
                    send_json_response(0, 1, 400, "Search query is required");
                }
                
                $results = performGlobalSearch($pdo, $query, $componentType, $limit);
                
                // Add compatibility information if requested and available
                if ($includeCompatibility && serverSystemInitialized($pdo) && hasPermission($pdo, 'compatibility.check', $user['id'])) {
                    foreach ($results as &$result) {
                        if (!empty($result['UUID'])) {
                            $result['compatibility_available'] = true;
                        }
                    }
                }
                
                send_json_response(1, 1, 200, "Search completed successfully", [
                    'results' => $results,
                    'query' => $query,
                    'type' => $componentType,
                    'total' => count($results),
                    'features' => [
                        'compatibility_search' => serverSystemInitialized($pdo) && hasPermission($pdo, 'compatibility.check', $user['id'])
                    ]
                ]);
            } catch (Exception $e) {
                error_log("Error in search: " . $e->getMessage());
                send_json_response(0, 1, 500, "Search failed");
            }
            break;
            
        case 'compatible-components':
            requirePermission($pdo, 'compatibility.check', $user['id']);
            
            if (!serverSystemInitialized($pdo)) {
                send_json_response(0, 1, 503, "Compatibility system not available");
            }
            
            try {
                $baseComponentType = $_GET['base_type'] ?? $_POST['base_type'] ?? '';
                $baseComponentUuid = $_GET['base_uuid'] ?? $_POST['base_uuid'] ?? '';
                $query = $_GET['q'] ?? $_POST['q'] ?? '';
                $targetType = $_GET['target_type'] ?? $_POST['target_type'] ?? '';
                
                if (empty($baseComponentType) || empty($baseComponentUuid)) {
                    send_json_response(0, 1, 400, "Base component type and UUID are required");
                }
                
                require_once(__DIR__ . '/../includes/models/CompatibilityEngine.php');
                $compatibilityEngine = new CompatibilityEngine($pdo);
                
                $baseComponent = ['type' => $baseComponentType, 'uuid' => $baseComponentUuid];
                $compatibleComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $targetType, true);
                
                // Filter by search query if provided
                if (!empty($query)) {
                    $compatibleComponents = array_filter($compatibleComponents, function($component) use ($query) {
                        return stripos($component['SerialNumber'], $query) !== false ||
                               stripos($component['Notes'] ?? '', $query) !== false ||
                               stripos($component['Location'] ?? '', $query) !== false;
                    });
                }
                
                send_json_response(1, 1, 200, "Compatible components search completed", [
                    'base_component' => $baseComponent,
                    'query' => $query,
                    'target_type' => $targetType,
                    'results' => array_values($compatibleComponents),
                    'total' => count($compatibleComponents)
                ]);
                
            } catch (Exception $e) {
                error_log("Error in compatibility search: " . $e->getMessage());
                send_json_response(0, 1, 500, "Compatibility search failed");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid search operation: $operation");
    }
}

/**
 * Handle user operations
 */
function handleUserOperations($operation, $user) {
    global $pdo;
    
    switch ($operation) {
        case 'list':
            requirePermission($pdo, 'users.view', $user['id']);
            
            try {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.username, u.email, u.firstname, u.lastname, u.created_at,
                           GROUP_CONCAT(r.display_name) as roles
                    FROM users u
                    LEFT JOIN user_roles ur ON u.id = ur.user_id
                    LEFT JOIN roles r ON ur.role_id = r.id
                    GROUP BY u.id
                    ORDER BY u.id
                ");
                $stmt->execute();
                $users = $stmt->fetchAll();
                
                send_json_response(1, 1, 200, "Users retrieved successfully", ['users' => $users]);
            } catch (Exception $e) {
                error_log("Error getting users: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve users");
            }
            break;
            
        case 'create':
            requirePermission($pdo, 'users.create', $user['id']);
            
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            $roleId = $_POST['role_id'] ?? null;
            
            if (empty($username) || empty($email) || empty($password)) {
                send_json_response(0, 1, 400, "Username, email, and password are required");
            }
            
            try {
                $newUserId = createUser($pdo, $username, $email, $password, $firstname, $lastname, $roleId);
                if ($newUserId) {
                    logActivity($pdo, $user['id'], "User created", 'user_management', $newUserId, "Created new user: $username");
                    send_json_response(1, 1, 200, "User created successfully", ['id' => $newUserId]);
                } else {
                    send_json_response(0, 1, 500, "Failed to create user");
                }
            } catch (Exception $e) {
                error_log("Error creating user: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to create user");
            }
            break;
            
        case 'profile':
            // Get current user profile
            $requestedUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? $user['id'];
            
            // Check permissions
            if ($requestedUserId != $user['id'] && !hasPermission($pdo, 'users.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions to view user profile");
            }
            
            try {
                $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, created_at FROM users WHERE id = ?");
                $stmt->execute([$requestedUserId]);
                $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$userProfile) {
                    send_json_response(0, 1, 404, "User not found");
                }
                
                // Get user roles
                $acl = new ACL($pdo);
                $userProfile['roles'] = $acl->getUserRoles($requestedUserId);
                
                send_json_response(1, 1, 200, "User profile retrieved", ['user' => $userProfile]);
                
            } catch (Exception $e) {
                error_log("Error getting user profile: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve user profile");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid user operation: $operation");
    }
}

/**
 * Handle authentication operations
 */
function handleAuthOperations($operation) {
    global $pdo;
    
    error_log("Auth operation: $operation");
    
    switch ($operation) {
        case 'login':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            error_log("Login attempt for username: $username");
            
            if (empty($username) || empty($password)) {
                error_log("Login failed: Missing username or password");
                send_json_response(0, 0, 400, "Username and password are required");
            }
            
            $authResult = loginUser($pdo, $username, $password);
            
            if ($authResult) {
                error_log("Login successful for user: " . $authResult['user']['username'] . " (ID: " . $authResult['user']['id'] . ")");
                send_json_response(1, 1, 200, "Login successful", $authResult);
            } else {
                error_log("Login failed for username: $username");
                send_json_response(0, 0, 401, "Invalid username or password");
            }
            break;
            
        case 'refresh':
            $refreshToken = $_POST['refresh_token'] ?? '';
            
            if (empty($refreshToken)) {
                send_json_response(0, 0, 400, "Refresh token is required");
            }
            
            $refreshResult = refreshJWTToken($pdo, $refreshToken);
            
            if ($refreshResult) {
                error_log("Token refresh successful for user: " . $refreshResult['user']['username']);
                send_json_response(1, 1, 200, "Token refreshed successfully", $refreshResult);
            } else {
                error_log("Token refresh failed for token: " . substr($refreshToken, 0, 10) . "...");
                send_json_response(0, 0, 401, "Invalid refresh token");
            }
            break;
            
        case 'logout':
            $refreshToken = $_POST['refresh_token'] ?? '';
            
            if (logoutUser($pdo, $refreshToken)) {
                error_log("User logged out successfully");
                send_json_response(1, 1, 200, "Logout successful");
            } else {
                error_log("Logout failed");
                send_json_response(0, 0, 500, "Logout failed");
            }
            break;
            
        case 'verify_token':
            // Verify JWT token endpoint
            $user = authenticateWithJWT($pdo);
            
            if ($user) {
                send_json_response(1, 1, 200, "Token is valid", ['user' => $user]);
            } else {
                send_json_response(0, 0, 401, "Invalid token");
            }
            break;
            
        default:
            error_log("Invalid auth operation: $operation");
            send_json_response(0, 0, 400, "Invalid auth operation");
    }
}

/**
 * Get server configuration statistics
 */
function getServerConfigurationStats($pdo) {
    try {
        $stats = [];
        
        // Check if server_configurations table exists
        $tableExists = false;
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'server_configurations'");
            $tableExists = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Error checking server_configurations table: " . $e->getMessage());
            return [];
        }
        
        if (!$tableExists) {
            return [
                'configurations' => [
                    'total' => 0,
                    'draft' => 0,
                    'validated' => 0,
                    'built' => 0,
                    'deployed' => 0
                ],
                'recent_activity' => ['recent_count' => 0],
                'averages' => [
                    'avg_score' => 0,
                    'total_power' => 0,
                    'total_cost' => 0
                ],
                'popular_cpus' => []
            ];
        }
        
        // Configuration counts by status
        $stmt = $pdo->prepare("
            SELECT configuration_status, COUNT(*) as count 
            FROM server_configurations 
            GROUP BY configuration_status
        ");
        $stmt->execute();
        $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stats['configurations'] = [
            'total' => array_sum($statusCounts),
            'draft' => $statusCounts[0] ?? 0,
            'validated' => $statusCounts[1] ?? 0,
            'built' => $statusCounts[2] ?? 0,
            'deployed' => $statusCounts[3] ?? 0
        ];
        
        // Recent configurations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as recent_count 
            FROM server_configurations 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $stats['recent_activity'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Average compatibility score
        $stmt = $pdo->prepare("
            SELECT AVG(compatibility_score) as avg_score,
                   SUM(power_consumption) as total_power,
                   SUM(total_cost) as total_cost
            FROM server_configurations 
            WHERE configuration_status > 0
        ");
        $stmt->execute();
        $averages = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['averages'] = $averages;
        
        // Most popular components
        $stmt = $pdo->prepare("
            SELECT cpu_uuid, COUNT(*) as usage_count
            FROM server_configurations 
            WHERE cpu_uuid IS NOT NULL
            GROUP BY cpu_uuid
            ORDER BY usage_count DESC
            LIMIT 5
        ");
        $stmt->execute();
        $stats['popular_cpus'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error getting server configuration stats: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if server system is initialized
 */
function serverSystemInitialized($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'server_configurations'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error checking server system initialization: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced require permission function
 */
function requirePermission($pdo, $permission, $userId = null) {
    if (!hasPermission($pdo, $permission, $userId)) {
        send_json_response(0, 1, 403, "Insufficient permissions: $permission required");
    }
}

/**
 * Check if user has specific permission function
 */
function checkUserPermission($permission) {
    global $pdo;
    $user = authenticateWithJWT($pdo);
    return $user ? hasPermission($pdo, $permission, $user['id']) : false;
}

/**
 * Get current authenticated user
 */
function getCurrentUser() {
    global $pdo;
    return authenticateWithJWT($pdo);
}

/**
 * Enhanced global search with compatibility information
 */
function performGlobalSearch($pdo, $query, $componentType = 'all', $limit = 20) {
    $results = [];
    $searchTerm = "%$query%";
    
    $tables = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    $searchFields = ['SerialNumber', 'UUID', 'Location', 'Notes'];
    
    foreach ($tables as $type => $table) {
        if ($componentType !== 'all' && $componentType !== $type) {
            continue;
        }
        
        try {
            $whereConditions = array_map(function($field) {
                return "$field LIKE :search";
            }, $searchFields);
            
            $sql = "SELECT *, '$type' as component_type FROM $table WHERE " . implode(' OR ', $whereConditions) . " LIMIT :limit";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':search', $searchTerm, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $typeResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results = array_merge($results, $typeResults);
            
        } catch (PDOException $e) {
            error_log("Search error for table $table: " . $e->getMessage());
        }
    }
    
    // Sort by relevance (exact matches first)
    usort($results, function($a, $b) use ($query) {
        $aExact = (stripos($a['SerialNumber'], $query) === 0) ? 1 : 0;
        $bExact = (stripos($b['SerialNumber'], $query) === 0) ? 1 : 0;
        return $bExact - $aExact;
    });
    
    return array_slice($results, 0, $limit);
}

/**
 * Enhanced system stats with server and compatibility information
 */
function getSystemStats($pdo) {
    $stats = [];
    
    $tables = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    $totalComponents = 0;
    $totalAvailable = 0;
    $totalInUse = 0;
    $totalFailed = 0;
    
    foreach ($tables as $type => $table) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) as available,
                    SUM(CASE WHEN Status = 2 THEN 1 ELSE 0 END) as in_use,
                    SUM(CASE WHEN Status = 0 THEN 1 ELSE 0 END) as failed
                FROM $table
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats['components'][$type] = $result;
            $totalComponents += $result['total'];
            $totalAvailable += $result['available'];
            $totalInUse += $result['in_use'];
            $totalFailed += $result['failed'];
            
        } catch (PDOException $e) {
            error_log("Error getting stats for $type: " . $e->getMessage());
            $stats['components'][$type] = [
                'total' => 0,
                'available' => 0,
                'in_use' => 0,
                'failed' => 0
            ];
        }
    }
    
    $stats['summary'] = [
        'total_components' => $totalComponents,
        'total_available' => $totalAvailable,
        'total_in_use' => $totalInUse,
        'total_failed' => $totalFailed,
        'utilization_rate' => $totalComponents > 0 ? round(($totalInUse / $totalComponents) * 100, 2) : 0,
        'availability_rate' => $totalComponents > 0 ? round(($totalAvailable / $totalComponents) * 100, 2) : 0,
        'failure_rate' => $totalComponents > 0 ? round(($totalFailed / $totalComponents) * 100, 2) : 0
    ];
    
    // Recent activity
    try {
        $recentActivity = [];
        foreach ($tables as $type => $table) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as recent_count 
                FROM $table 
                WHERE CreatedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $recentActivity[$type] = $result['recent_count'];
        }
        $stats['recent_activity'] = $recentActivity;
        $stats['summary']['recent_additions'] = array_sum($recentActivity);
        
    } catch (PDOException $e) {
        error_log("Error getting recent activity: " . $e->getMessage());
        $stats['recent_activity'] = [];
        $stats['summary']['recent_additions'] = 0;
    }
    
    // System features availability
    $stats['system_features'] = [
        'server_management' => serverSystemInitialized($pdo),
        'compatibility_checking' => serverSystemInitialized($pdo),
        'json_specifications' => true, // Always available based on file system
        'audit_logging' => true,
        'role_based_access' => true
    ];
    
    return $stats;
}

/**
 * Create a new user with optional role assignment
 */
function createUser($pdo, $username, $email, $password, $firstname = '', $lastname = '', $roleId = null) {
    try {
        $pdo->beginTransaction();
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, firstname, lastname, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname])) {
            $pdo->rollBack();
            return false;
        }
        
        $userId = $pdo->lastInsertId();
        
        // Assign role if provided
        if ($roleId) {
            $acl = new ACL($pdo);
            if (!$acl->assignRole($userId, $roleId)) {
                $pdo->rollBack();
                return false;
            }
        }
        
        $pdo->commit();
        return $userId;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error creating user: " . $e->getMessage());
        return false;
    }
}

/**
 * Log user activity
 */
function logActivity($pdo, $userId, $action, $category, $targetId = null, $description = '', $oldData = null, $newData = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (user_id, action, category, target_id, description, old_data, new_data, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $category,
            $targetId,
            $description,
            $oldData ? json_encode($oldData) : null,
            $newData ? json_encode($newData) : null
        ]);
        
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
        // Don't fail the main operation if logging fails
    }
}
?>
