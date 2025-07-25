<?php
/**
 * Complete JWT-Based API with ACL Integration
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
    
    // ACL Management endpoints
    if ($module === 'acl') {
        handleACLOperations($operation, $user);
        exit();
    }
    
    // Component operations
    $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
    if (in_array($module, $componentTypes)) {
        handleComponentOperations($module, $operation, $user);
        exit();
    }
    
    // Dashboard operations
    if ($module === 'dashboard') {
        handleDashboardOperations($operation, $user);
        exit();
    }
    
    // Search operations
    if ($module === 'search') {
        handleSearchOperations($operation, $user);
        exit();
    }
    
    // User management operations
    if ($module === 'users') {
        handleUserOperations($operation, $user);
        exit();
    }
    
    // Invalid module
    error_log("Invalid module requested: $module");
    send_json_response(0, 1, 400, "Invalid module: $module");
    
} catch (Exception $e) {
    error_log("API error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Internal server error: " . $e->getMessage());
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
 * Handle component operations
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
    
    switch ($operation) {
        case 'get':
        case 'list':
            // Check permission
            requirePermission($pdo, "$module.view", $user['id']);
            
            try {
                $status = $_GET['status'] ?? 'all';
                $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 50;
                $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
                $search = $_GET['search'] ?? '';
                
                $query = "SELECT * FROM $table";
                $params = [];
                $conditions = [];
                
                if ($status !== 'all' && in_array($status, ['0', '1', '2'])) {
                    $conditions[] = "Status = :status";
                    $params[':status'] = $status;
                }
                
                if (!empty($search)) {
                    $conditions[] = "(SerialNumber LIKE :search OR Notes LIKE :search OR UUID LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                
                if (!empty($conditions)) {
                    $query .= " WHERE " . implode(" AND ", $conditions);
                }
                
                $query .= " ORDER BY ID DESC LIMIT :limit OFFSET :offset";
                
                $stmt = $pdo->prepare($query);
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                
                $components = $stmt->fetchAll();
                
                // Get total count
                $countQuery = "SELECT COUNT(*) as total FROM $table";
                if (!empty($conditions)) {
                    $countQuery .= " WHERE " . implode(" AND ", $conditions);
                }
                
                $countStmt = $pdo->prepare($countQuery);
                foreach ($params as $key => $value) {
                    if ($key !== ':limit' && $key !== ':offset') {
                        $countStmt->bindValue($key, $value);
                    }
                }
                $countStmt->execute();
                $totalCount = $countStmt->fetchColumn();
                
                // Permission info
                $permissions = [
                    'can_create' => hasPermission($pdo, "$module.create", $user['id']),
                    'can_edit' => hasPermission($pdo, "$module.edit", $user['id']),
                    'can_delete' => hasPermission($pdo, "$module.delete", $user['id'])
                ];
                
                send_json_response(1, 1, 200, "Components retrieved successfully", [
                    'components' => $components,
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'permissions' => $permissions
                ]);
            } catch (Exception $e) {
                error_log("Error getting $module components: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve components");
            }
            break;
            
        case 'add':
        case 'create':
            // Check permission
            requirePermission($pdo, "$module.create", $user['id']);
            
            try {
                $requiredFields = ['SerialNumber', 'Status'];
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        send_json_response(0, 1, 400, "Required field missing: $field");
                    }
                }
                
                // Check for duplicate serial number
                $stmt = $pdo->prepare("SELECT ID FROM $table WHERE SerialNumber = ?");
                $stmt->execute([$_POST['SerialNumber']]);
                if ($stmt->rowCount() > 0) {
                    send_json_response(0, 1, 400, "Component with this serial number already exists");
                }
                
                $uuid = generateUUID();
                
                // Prepare insert data
                $insertData = [
                    'UUID' => $uuid,
                    'SerialNumber' => $_POST['SerialNumber'],
                    'Status' => $_POST['Status'],
                    'ServerUUID' => $_POST['ServerUUID'] ?? '',
                    'Location' => $_POST['Location'] ?? '',
                    'RackPosition' => $_POST['RackPosition'] ?? '',
                    'PurchaseDate' => !empty($_POST['PurchaseDate']) ? $_POST['PurchaseDate'] : null,
                    'WarrantyEndDate' => !empty($_POST['WarrantyEndDate']) ? $_POST['WarrantyEndDate'] : null,
                    'Flag' => $_POST['Flag'] ?? '',
                    'Notes' => $_POST['Notes'] ?? ''
                ];
                
                // Add NIC-specific fields if applicable
                if ($module === 'nic') {
                    $insertData['MacAddress'] = $_POST['MacAddress'] ?? '';
                    $insertData['IPAddress'] = $_POST['IPAddress'] ?? '';
                    $insertData['NetworkName'] = $_POST['NetworkName'] ?? '';
                }
                
                error_log("Inserting $module data: " . json_encode($insertData));
                
                // Insert component
                $fields = array_keys($insertData);
                $values = array_values($insertData);
                
                $placeholders = str_repeat('?,', count($fields) - 1) . '?';
                $query = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES ($placeholders)";
                
                $stmt = $pdo->prepare($query);
                if ($stmt->execute($values)) {
                    $newId = $pdo->lastInsertId();
                    
                    // Log the activity
                    logActivity($pdo, $user['id'], "Component created", $module, $newId, "Created new $module component", null, $insertData);
                    
                    error_log("Successfully added $module component with ID: $newId");
                    send_json_response(1, 1, 200, "Component added successfully", ['id' => $newId, 'uuid' => $uuid]);
                } else {
                    error_log("Failed to execute insert for $module");
                    send_json_response(0, 1, 500, "Failed to add component");
                }
            } catch (Exception $e) {
                error_log("Error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to add component: " . $e->getMessage());
            }
            break;
            
        case 'update':
        case 'edit':
            // Check permission
            requirePermission($pdo, "$module.edit", $user['id']);
            
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }
            
            try {
                // Get current component data for logging
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = ?");
                $stmt->execute([$id]);
                $currentData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentData) {
                    send_json_response(0, 1, 404, "Component not found");
                }
                
                $updateFields = [];
                $params = [];
                $newData = [];
                
                $allowedFields = ['Status', 'Notes', 'Location', 'RackPosition', 'Flag', 'ServerUUID'];
                
                // Add NIC-specific fields if applicable
                if ($module === 'nic') {
                    $allowedFields = array_merge($allowedFields, ['MacAddress', 'IPAddress', 'NetworkName']);
                }
                
                foreach ($allowedFields as $field) {
                    if (isset($_POST[$field])) {
                        $updateFields[] = "$field = ?";
                        $params[] = $_POST[$field];
                        $newData[$field] = $_POST[$field];
                    }
                }
                
                if (empty($updateFields)) {
                    send_json_response(0, 1, 400, "No fields to update");
                }
                
                $params[] = $id; // Add ID for WHERE clause
                $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = ?";
                
                $stmt = $pdo->prepare($query);
                if ($stmt->execute($params)) {
                    // Log the activity
                    $oldData = array_intersect_key($currentData, $newData);
                    logActivity($pdo, $user['id'], "Component updated", $module, $id, "Updated $module component", $oldData, $newData);
                    
                    send_json_response(1, 1, 200, "Component updated successfully");
                } else {
                    send_json_response(0, 1, 500, "Failed to update component");
                }
            } catch (Exception $e) {
                error_log("Error updating $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to update component");
            }
            break;
            
        case 'delete':
        case 'remove':
            // Check permission
            requirePermission($pdo, "$module.delete", $user['id']);
            
            $id = $_POST['id'] ?? $_GET['id'] ?? '';
            if (empty($id)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }
            
            try {
                // Get component data for logging before deletion
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = ?");
                $stmt->execute([$id]);
                $componentData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$componentData) {
                    send_json_response(0, 1, 404, "Component not found");
                }
                
                // Delete the component
                $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = ?");
                if ($deleteStmt->execute([$id])) {
                    // Log the activity
                    logActivity($pdo, $user['id'], "Component deleted", $module, $id, "Deleted $module component", $componentData, null);
                    
                    send_json_response(1, 1, 200, "Component deleted successfully");
                } else {
                    send_json_response(0, 1, 500, "Failed to delete component");
                }
            } catch (Exception $e) {
                error_log("Error deleting $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to delete component");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid component operation: $operation");
    }
}

/**
 * Handle dashboard operations
 */
function handleDashboardOperations($operation, $user) {
    global $pdo;
    
    switch ($operation) {
        case 'get_data':
        case 'stats':
            requirePermission($pdo, 'dashboard.view', $user['id']);
            
            try {
                $stats = getSystemStats($pdo);
                send_json_response(1, 1, 200, "Dashboard stats retrieved successfully", ['stats' => $stats]);
            } catch (Exception $e) {
                error_log("Error getting dashboard stats: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve dashboard stats");
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
?>