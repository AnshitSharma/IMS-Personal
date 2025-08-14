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
 * Handle authentication operations (no login required)
 */
function handleAuthOperations($operation) {
    error_log("Auth operation: $operation");
    
    global $pdo;
    
    switch ($operation) {
        case 'login':
            handleLogin();
            break;
            
        case 'logout':
            handleLogout();
            break;
            
        case 'refresh':
            handleTokenRefresh();
            break;
            
        case 'verify_token':
            handleTokenVerification();
            break;
            
        case 'register':
            handleRegistration();
            break;
            
        case 'forgot_password':
            handleForgotPassword();
            break;
            
        case 'reset_password':
            handleResetPassword();
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid authentication operation: $operation");
    }
}

/**
 * Handle login request with improved error handling
 */
function handleLogin() {
    global $pdo;
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = filter_var($_POST['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    error_log("Login attempt - Username: '$username'");
    
    if (empty($username) || empty($password)) {
        send_json_response(0, 0, 400, "Username and password are required");
    }
    
    try {
        // Get user from database with detailed error handling
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, password, status FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            error_log("User not found: $username");
            send_json_response(0, 0, 401, "Invalid credentials");
        }
        
        // Check if user is active
        if (isset($user['status']) && $user['status'] !== 'active') {
            error_log("User account is inactive: $username");
            send_json_response(0, 0, 401, "Account is inactive");
        }
        
        error_log("User found: " . $user['username'] . " (ID: " . $user['id'] . ")");
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            error_log("Password verification failed for user: $username");
            send_json_response(0, 0, 401, "Invalid credentials");
        }
        
        error_log("Authentication successful for user: $username");
        
        // Generate JWT tokens
        $accessTokenExpiry = $rememberMe ? 86400 : 3600; // 24h or 1h
        $refreshTokenExpiry = $rememberMe ? 2592000 : 604800; // 30 days or 7 days
        
        $accessToken = JWTHelper::generateToken([
            'user_id' => $user['id'],
            'username' => $user['username']
        ], $accessTokenExpiry);
        
        $refreshToken = JWTHelper::generateRefreshToken();
        
        // Store refresh token
        JWTHelper::storeRefreshToken($pdo, $user['id'], $refreshToken, $refreshTokenExpiry);
        
        // Get user permissions
        $permissions = getUserPermissions($pdo, $user['id']);
        $roles = getUserRoles($pdo, $user['id']);
        
        send_json_response(1, 1, 200, "Login successful", [
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
            ],
            'tokens' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $accessTokenExpiry,
                'token_type' => 'Bearer'
            ],
            'permissions' => $permissions,
            'roles' => $roles
        ]);
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        send_json_response(0, 0, 500, "Login failed");
    }
}

/**
 * Handle logout request
 */
function handleLogout() {
    global $pdo;
    
    try {
        $token = JWTHelper::getTokenFromHeader();
        
        if ($token) {
            $payload = JWTHelper::verifyToken($token);
            $userId = $payload['user_id'];
            
            // Revoke all refresh tokens for this user
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        
        send_json_response(1, 1, 200, "Logged out successfully");
        
    } catch (Exception $e) {
        // Even if token verification fails, we consider logout successful
        send_json_response(1, 1, 200, "Logged out successfully");
    }
}

/**
 * Handle token refresh
 */
function handleTokenRefresh() {
    global $pdo;
    
    $refreshToken = $_POST['refresh_token'] ?? '';
    
    if (empty($refreshToken)) {
        send_json_response(0, 0, 400, "Refresh token is required");
    }
    
    try {
        // Verify refresh token
        $stmt = $pdo->prepare("
            SELECT user_id, expires_at 
            FROM auth_tokens 
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$refreshToken]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            send_json_response(0, 0, 401, "Invalid or expired refresh token");
        }
        
        // Get user data
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$tokenData['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            send_json_response(0, 0, 401, "User not found or inactive");
        }
        
        // Generate new access token
        $accessToken = JWTHelper::generateToken([
            'user_id' => $user['id'],
            'username' => $user['username']
        ], 3600);
        
        send_json_response(1, 1, 200, "Token refreshed successfully", [
            'access_token' => $accessToken,
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Token refresh error: " . $e->getMessage());
        send_json_response(0, 0, 401, "Token refresh failed");
    }
}

/**
 * Handle token verification
 */
function handleTokenVerification() {
    global $pdo;
    
    try {
        $user = authenticateWithJWT($pdo);
        
        if (!$user) {
            send_json_response(0, 0, 401, "Invalid token");
        }
        
        $permissions = getUserPermissions($pdo, $user['id']);
        $roles = getUserRoles($pdo, $user['id']);
        
        send_json_response(1, 1, 200, "Token is valid", [
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
            ],
            'permissions' => $permissions,
            'roles' => $roles
        ]);
        
    } catch (Exception $e) {
        error_log("Token verification error: " . $e->getMessage());
        send_json_response(0, 0, 401, "Token verification failed");
    }
}

/**
 * Handle user registration (if enabled)
 */
function handleRegistration() {
    global $pdo;
    
    // Check if registration is enabled
    $registrationEnabled = getSystemSetting($pdo, 'registration_enabled', false);
    if (!$registrationEnabled) {
        send_json_response(0, 0, 403, "Registration is disabled");
    }
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    
    if (empty($username) || empty($email) || empty($password)) {
        send_json_response(0, 0, 400, "Username, email, and password are required");
    }
    
    try {
        // Check if username/email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            send_json_response(0, 0, 409, "Username or email already exists");
        }
        
        // Create user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, firstname, lastname, status, created_at) 
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname]);
        
        $userId = $pdo->lastInsertId();
        
        // Assign default role
        $defaultRoleId = getSystemSetting($pdo, 'default_user_role', 2); // Assume role ID 2 is default
        assignRoleToUser($pdo, $userId, $defaultRoleId);
        
        send_json_response(1, 1, 201, "Registration successful", [
            'user_id' => (int)$userId,
            'username' => $username,
            'message' => "Please login with your credentials"
        ]);
        
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        send_json_response(0, 0, 500, "Registration failed");
    }
}

/**
 * Handle forgot password
 */
function handleForgotPassword() {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        send_json_response(0, 0, 400, "Email is required");
    }
    
    // For security, always return success even if email doesn't exist
    send_json_response(1, 1, 200, "If an account with that email exists, a password reset link has been sent");
}

/**
 * Handle password reset
 */
function handleResetPassword() {
    send_json_response(0, 0, 501, "Password reset functionality not implemented");
}

/**
 * Handle server creation and management operations
 */
function handleServerModule($operation, $user) {
    global $pdo;
    
    // Map operations to their required permissions and internal actions
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
        'export-config' => 'server.view',
        'initialize' => 'server.create',
        'get_next_options' => 'server.view',
        'validate_current' => 'server.view',
        'finalize' => 'server.create',
        'save_draft' => 'server.create',
        'load_draft' => 'server.view',
        'get_server_progress' => 'server.view',
        'reset_configuration' => 'server.edit'
    ];
    
    // Map external operations to internal actions
    $actionMap = [
        'add-component' => 'step_add_component',
        'remove-component' => 'step_remove_component'
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? 'server.view';
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    // Convert external action to internal action if needed
    $internalAction = $actionMap[$operation] ?? $operation;
    $_POST['action'] = $internalAction;
    $_GET['action'] = $internalAction;
    
    // Include server creation handler
    require_once(__DIR__ . '/server/create_server.php');
}

/**
 * Handle ACL operations
 */
function handleACLOperations($operation, $user) {
    global $pdo;
    
    error_log("ACL operation: $operation");
    
    // Check if user has ACL management permissions
    if (!hasPermission($pdo, 'acl.manage', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for ACL operations");
    }
    
    switch ($operation) {
        case 'get_user_permissions':
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? '';
            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }
            
            $permissions = getUserPermissions($pdo, $targetUserId);
            $roles = getUserRoles($pdo, $targetUserId);
            
            send_json_response(1, 1, 200, "User permissions retrieved", [
                'user_id' => (int)$targetUserId,
                'permissions' => $permissions,
                'roles' => $roles
            ]);
            break;
            
        case 'assign_permission':
            $targetUserId = $_POST['user_id'] ?? '';
            $permission = $_POST['permission'] ?? '';
            
            if (empty($targetUserId) || empty($permission)) {
                send_json_response(0, 1, 400, "User ID and permission are required");
            }
            
            $success = assignPermissionToUser($pdo, $targetUserId, $permission);
            
            if ($success) {
                send_json_response(1, 1, 200, "Permission assigned successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to assign permission");
            }
            break;
            
        case 'revoke_permission':
            $targetUserId = $_POST['user_id'] ?? '';
            $permission = $_POST['permission'] ?? '';
            
            if (empty($targetUserId) || empty($permission)) {
                send_json_response(0, 1, 400, "User ID and permission are required");
            }
            
            $success = revokePermissionFromUser($pdo, $targetUserId, $permission);
            
            if ($success) {
                send_json_response(1, 1, 200, "Permission revoked successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to revoke permission");
            }
            break;
            
        case 'assign_role':
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and role ID are required");
            }
            
            $success = assignRoleToUser($pdo, $targetUserId, $roleId);
            
            if ($success) {
                send_json_response(1, 1, 200, "Role assigned successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to assign role");
            }
            break;
            
        case 'revoke_role':
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and role ID are required");
            }
            
            $success = revokeRoleFromUser($pdo, $targetUserId, $roleId);
            
            if ($success) {
                send_json_response(1, 1, 200, "Role revoked successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to revoke role");
            }
            break;
            
        case 'get_all_roles':
            $roles = getAllRoles($pdo);
            send_json_response(1, 1, 200, "Roles retrieved successfully", ['roles' => $roles]);
            break;
            
        case 'get_all_permissions':
            $permissions = getAllPermissions($pdo);
            send_json_response(1, 1, 200, "Permissions retrieved successfully", ['permissions' => $permissions]);
            break;
            
        case 'check_permission':
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? $user['id'];
            $permission = $_GET['permission'] ?? $_POST['permission'] ?? '';
            
            if (empty($permission)) {
                send_json_response(0, 1, 400, "Permission is required");
            }
            
            $hasPermissionResult = hasPermission($pdo, $permission, $targetUserId);
            
            send_json_response(1, 1, 200, "Permission check completed", [
                'user_id' => (int)$targetUserId,
                'permission' => $permission,
                'has_permission' => $hasPermissionResult
            ]);
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid ACL operation: $operation");
    }
}

/**
 * Handle dashboard operations
 */
function handleDashboardOperations($operation, $user) {
    global $pdo;
    
    if (!hasPermission($pdo, 'dashboard.view', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for dashboard access");
    }
    
    switch ($operation) {
        case 'get_data':
            $dashboardData = getDashboardData($pdo, $user);
            send_json_response(1, 1, 200, "Dashboard data retrieved", $dashboardData);
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
    
    if (!hasPermission($pdo, 'search.use', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for search operations");
    }
    
    switch ($operation) {
        case 'global':
            $query = $_GET['q'] ?? $_POST['q'] ?? '';
            $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 20);
            
            if (empty($query)) {
                send_json_response(0, 1, 400, "Search query is required");
            }
            
            $results = performGlobalSearch($pdo, $query, $limit, $user);
            send_json_response(1, 1, 200, "Search completed", $results);
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
            if (!hasPermission($pdo, 'user.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user listing");
            }
            
            $users = getAllUsers($pdo);
            send_json_response(1, 1, 200, "Users retrieved successfully", ['users' => $users]);
            break;
            
        case 'create':
            if (!hasPermission($pdo, 'user.create', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user creation");
            }
            
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            
            if (empty($username) || empty($email) || empty($password)) {
                send_json_response(0, 1, 400, "Username, email, and password are required");
            }
            
            $userId = createUser($pdo, $username, $email, $password, $firstname, $lastname);
            
            if ($userId) {
                send_json_response(1, 1, 201, "User created successfully", ['user_id' => $userId]);
            } else {
                send_json_response(0, 1, 400, "Failed to create user");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid user operation: $operation");
    }
}

/**
 * Handle component operations (CPU, RAM, Storage, etc.)
 */
function handleComponentOperations($module, $operation, $user) {
    global $pdo;
    
    // Map operations to permissions
    $permissionMap = [
        'list' => "$module.view",
        'get' => "$module.view",
        'add' => "$module.create",
        'update' => "$module.edit",
        'delete' => "$module.delete",
        'bulk_update' => "$module.edit",
        'bulk_delete' => "$module.delete"
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? "$module.view";
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    switch ($operation) {
        case 'list':
            $components = getComponentsByType($pdo, $module);
            send_json_response(1, 1, 200, ucfirst($module) . " components retrieved", [
                'components' => $components,
                'total_count' => count($components)
            ]);
            break;
            
        case 'get':
            $componentId = $_GET['id'] ?? $_POST['id'] ?? '';
            
            if (empty($componentId)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }
            
            $component = getComponentById($pdo, $module, $componentId);
            
            if ($component) {
                send_json_response(1, 1, 200, "Component retrieved successfully", ['component' => $component]);
            } else {
                send_json_response(0, 1, 404, "Component not found");
            }
            break;
            
        case 'add':
            $componentData = [];
            
            // Extract component data from POST
            foreach ($_POST as $key => $value) {
                if ($key !== 'action') {
                    $componentData[$key] = $value;
                }
            }
            
            if (empty($componentData)) {
                send_json_response(0, 1, 400, "Component data is required");
            }
            
            try {
                $componentId = addComponent($pdo, $module, $componentData, $user['id']);
                
                if ($componentId) {
                    error_log("Successfully added $module component with ID: $componentId");
                    send_json_response(1, 1, 201, ucfirst($module) . " component added successfully", [
                        'component_id' => $componentId,
                        'uuid' => $componentData['UUID'] ?? null
                    ]);
                } else {
                    send_json_response(0, 1, 400, "Failed to add " . $module . " component");
                }
            } catch (Exception $e) {
                error_log("Error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to add component: " . $e->getMessage());
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid $module operation: $operation");
    }
}

/**
 * Handle roles operations
 */
function handleRolesOperations($operation, $user) {
    global $pdo;
    
    if (!hasPermission($pdo, 'role.manage', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for role operations");
    }
    
    switch ($operation) {
        case 'list':
            $roles = getAllRoles($pdo);
            send_json_response(1, 1, 200, "Roles retrieved successfully", ['roles' => $roles]);
            break;
            
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($name)) {
                send_json_response(0, 1, 400, "Role name is required");
            }
            
            $roleId = createRole($pdo, $name, $description);
            
            if ($roleId) {
                send_json_response(1, 1, 201, "Role created successfully", ['role_id' => $roleId]);
            } else {
                send_json_response(0, 1, 400, "Failed to create role");
            }
            break;
            
        case 'update':
            $roleId = $_POST['role_id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($roleId) || empty($name)) {
                send_json_response(0, 1, 400, "Role ID and name are required");
            }
            
            $success = updateRole($pdo, $roleId, $name, $description);
            
            if ($success) {
                send_json_response(1, 1, 200, "Role updated successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to update role");
            }
            break;
            
        case 'delete':
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($roleId)) {
                send_json_response(0, 1, 400, "Role ID is required");
            }
            
            $success = deleteRole($pdo, $roleId);
            
            if ($success) {
                send_json_response(1, 1, 200, "Role deleted successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to delete role");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid role operation: $operation");
    }
}

/**
 * Handle permissions operations
 */
function handlePermissionsOperations($operation, $user) {
    global $pdo;
    
    if (!hasPermission($pdo, 'permission.manage', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for permission operations");
    }
    
    switch ($operation) {
        case 'list':
            $permissions = getAllPermissions($pdo);
            send_json_response(1, 1, 200, "Permissions retrieved successfully", ['permissions' => $permissions]);
            break;
            
        case 'create':
            $name = trim($_POST['permission_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = trim($_POST['category'] ?? '');
            
            if (empty($name)) {
                send_json_response(0, 1, 400, "Permission name is required");
            }
            
            try {
                $stmt = $pdo->prepare("INSERT INTO acl_permissions (permission_name, description, category, created_at) VALUES (?, ?, ?, NOW())");
                $result = $stmt->execute([$name, $description, $category]);
                
                if ($result) {
                    $permissionId = $pdo->lastInsertId();
                    send_json_response(1, 1, 201, "Permission created successfully", ['permission_id' => $permissionId]);
                } else {
                    send_json_response(0, 1, 400, "Failed to create permission");
                }
            } catch (Exception $e) {
                error_log("Error creating permission: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to create permission");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid permission operation: $operation");
    }
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
    
    // Basic compatibility operations
    switch ($operation) {
        case 'check':
            $component1Type = $_POST['component1_type'] ?? '';
            $component1Uuid = $_POST['component1_uuid'] ?? '';
            $component2Type = $_POST['component2_type'] ?? '';
            $component2Uuid = $_POST['component2_uuid'] ?? '';
            
            if (empty($component1Type) || empty($component1Uuid) || empty($component2Type) || empty($component2Uuid)) {
                send_json_response(0, 1, 400, "All component information is required");
            }
            
            // Get component details
            $component1 = getComponentDetails($pdo, $component1Type, $component1Uuid);
            $component2 = getComponentDetails($pdo, $component2Type, $component2Uuid);
            
            if (!$component1 || !$component2) {
                send_json_response(0, 1, 404, "One or more components not found");
            }
            
            // Perform compatibility check
            $compatibility = checkComponentPairCompatibility($component1Type, $component1, $component2Type, $component2);
            
            send_json_response(1, 1, 200, "Compatibility check completed", [
                'component1' => ['type' => $component1Type, 'uuid' => $component1Uuid],
                'component2' => ['type' => $component2Type, 'uuid' => $component2Uuid],
                'compatibility' => $compatibility
            ]);
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid compatibility operation: $operation");
    }
}

/**
 * Helper function to check compatibility between two components
 */
function checkComponentPairCompatibility($type1, $component1, $type2, $component2) {
    $issues = [];
    $compatible = true;
    
    // CPU-Motherboard compatibility
    if (($type1 === 'cpu' && $type2 === 'motherboard') || ($type1 === 'motherboard' && $type2 === 'cpu')) {
        $cpu = $type1 === 'cpu' ? $component1 : $component2;
        $motherboard = $type1 === 'motherboard' ? $component1 : $component2;
        
        $cpuSocket = extractSocketFromNotes($cpu['Notes'] ?? '');
        $mbSocket = extractSocketFromNotes($motherboard['Notes'] ?? '');
        
        if ($cpuSocket && $mbSocket && strtolower($cpuSocket) !== strtolower($mbSocket)) {
            $compatible = false;
            $issues[] = [
                'type' => 'socket_mismatch',
                'severity' => 'error',
                'message' => "CPU socket ($cpuSocket) does not match motherboard socket ($mbSocket)"
            ];
        }
    }
    
    // RAM-Motherboard compatibility
    if (($type1 === 'ram' && $type2 === 'motherboard') || ($type1 === 'motherboard' && $type2 === 'ram')) {
        $ram = $type1 === 'ram' ? $component1 : $component2;
        $motherboard = $type1 === 'motherboard' ? $component1 : $component2;
        
        $ramType = extractRAMTypeFromNotes($ram['Notes'] ?? '');
        $mbRAMType = extractRAMTypeFromNotes($motherboard['Notes'] ?? '');
        
        if ($ramType && $mbRAMType && strtolower($ramType) !== strtolower($mbRAMType)) {
            $compatible = false;
            $issues[] = [
                'type' => 'ram_type_mismatch',
                'severity' => 'warning',
                'message' => "RAM type ($ramType) may not be compatible with motherboard ($mbRAMType)"
            ];
        }
    }
    
    return [
        'compatible' => $compatible,
        'issues' => $issues,
        'confidence' => count($issues) === 0 ? 'high' : 'medium'
    ];
}

/**
 * Helper function to get component details
 */
function getComponentDetails($pdo, $type, $uuid) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM $type WHERE UUID = ?");
        $stmt->execute([$uuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting $type component details: " . $e->getMessage());
        return null;
    }
}

/**
 * Extract socket information from notes
 */
function extractSocketFromNotes($notes) {
    // Look for patterns like "Socket: LGA1151", "Socket LGA1151", "LGA1151 socket"
    if (preg_match('/socket[:\s]*([A-Z0-9]+)/i', $notes, $matches)) {
        return $matches[1];
    }
    
    // Look for common socket patterns
    if (preg_match('/(LGA\d+|AM\d+|FM\d+|TR4)/i', $notes, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Extract RAM type from notes
 */
function extractRAMTypeFromNotes($notes) {
    // Look for DDR patterns
    if (preg_match('/(DDR\d+)/i', $notes, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Create new role
 */
function createRole($pdo, $name, $description = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO acl_roles (role_name, description, created_at) VALUES (?, ?, NOW())");
        $result = $stmt->execute([$name, $description]);
        
        if ($result) {
            return $pdo->lastInsertId();
        }
        return false;
    } catch (Exception $e) {
        error_log("Create role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update role
 */
function updateRole($pdo, $roleId, $name, $description = '') {
    try {
        $stmt = $pdo->prepare("UPDATE acl_roles SET role_name = ?, description = ? WHERE id = ?");
        return $stmt->execute([$name, $description, $roleId]);
    } catch (Exception $e) {
        error_log("Update role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete role
 */
function deleteRole($pdo, $roleId) {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Remove role permissions
        $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        
        // Remove user roles
        $stmt = $pdo->prepare("DELETE FROM user_roles WHERE role_id = ?");
        $stmt->execute([$roleId]);
        
        // Delete role
        $stmt = $pdo->prepare("DELETE FROM acl_roles WHERE id = ?");
        $result = $stmt->execute([$roleId]);
        
        $pdo->commit();
        return $result;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Delete role error: " . $e->getMessage());
        return false;
    }
}

?>
