<?php
/**
 * Complete JWT-Based API with ACL Integration and Server Management
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
        'get-statistics' => 'server.view_statistics'
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? 'server.view';
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    // Include server API handler
    require_once(__DIR__ . '/server/server_api.php');
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
                    'compatible_components' => $allCompatible
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
                
                // Add server configuration statistics if user has permission
                if (hasPermission($pdo, 'server.view_statistics', $user['id'])) {
                    $serverStats = getServerConfigurationStats($pdo);
                    $stats['server_configurations'] = $serverStats;
                }
                
                send_json_response(1, 1, 200, "Dashboard stats retrieved successfully", ['stats' => $stats]);
            } catch (Exception $e) {
                error_log("Error getting dashboard stats: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve dashboard stats");
            }
            break;
            
        case 'server-summary':
            requirePermission($pdo, 'server.view_statistics', $user['id']);
            
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
                
                if (empty($query)) {
                    send_json_response(0, 1, 400, "Search query is required");
                }
                
                $results = performGlobalSearch($pdo, $query, $componentType, $limit);
                
                send_json_response(1, 1, 200, "Search completed successfully", [
                    'results' => $results,
                    'query' => $query,
                    'type' => $componentType,
                    'total' => count($results)
                ]);
            } catch (Exception $e) {
                error_log("Error in search: " . $e->getMessage());
                send_json_response(0, 1, 500, "Search failed");
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
            
            error_log("Login attempt - Username: '$username'");
            
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
?>