<?php
// ===========================================================================
// FILE: api/api.php 
// ===========================================================================

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include required files
try {
    require_once(__DIR__ . '/../includes/db_config.php');
    require_once(__DIR__ . '/../includes/BaseFunctions.php');
    require_once(__DIR__ . '/../includes/SimpleACL.php');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'is_logged_in' => 0,
        'status_code' => 500,
        'success' => 0,
        'message' => 'Configuration error: ' . $e->getMessage()
    ]);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(0, 0, 405, "Method Not Allowed");
}

// Rate limiting
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit($clientIP, 200, 3600)) {
    send_json_response(0, 0, 429, "Rate limit exceeded. Please try again later.");
}

// Get action from form data
$action = $_POST['action'] ?? '';

if (empty($action)) {
    send_json_response(0, 0, 400, "Action parameter is required");
}

// Log the action for debugging
error_log("API called with action: " . $action);

try {
    // Route to appropriate handler based on action
    $actionParts = explode('-', $action);
    $module = $actionParts[0] ?? '';
    $operation = $actionParts[1] ?? '';
    
    error_log("Module: $module, Operation: $operation");
    
    switch($module) {
        case 'auth':
            handleAuthOperations($operation);
            break;
            
        case 'dashboard':
            handleDashboardOperations($operation);
            break;
            
        case 'search':
            handleSearchOperations($operation);
            break;
            
        case 'user':
        case 'users':
            handleUserOperations($operation);
            break;
            
        case 'cpu':
        case 'ram':
        case 'storage':
        case 'motherboard':
        case 'nic':
        case 'caddy':
            handleComponentOperations($module, $operation);
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid module: " . $module);
    }
} catch (Exception $e) {
    error_log("API error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    send_json_response(0, 0, 500, "Internal server error");
}

// ===========================================================================
// AUTHENTICATION OPERATIONS - UPDATED
// ===========================================================================
function handleAuthOperations($operation) {
    global $pdo;
    
    error_log("Auth operation: $operation");
    
    switch($operation) {
        case 'login':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            error_log("Login attempt for username: $username");
            
            if (empty($username) || empty($password)) {
                send_json_response(0, 0, 400, "Please enter both username and password");
                return;
            }
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->execute();
                
                if ($stmt->rowCount() == 1) {
                    $user = $stmt->fetch();
                    
                    if (password_verify($password, $user['password'])) {
                        session_start();
                        $_SESSION["loggedin"] = true;
                        $_SESSION["id"] = $user["id"];
                        $_SESSION["username"] = $username;
                        $_SESSION["email"] = $user["email"];
                        
                        // Get user role information with updated SimpleACL
                        $acl = new SimpleACL($pdo, $user["id"]);
                        $userRole = $acl->getUserRole();
                        $permissionsSummary = $acl->getPermissionsSummary();
                        
                        error_log("Login successful for user: $username with role: $userRole");
                        
                        // Log the action
                        $acl->logAction("User login", "auth", $user["id"]);
                        
                        $responseData = [
                            'user_context' => [
                                'user_id' => $user["id"],
                                'username' => $username,
                                'role' => $userRole,
                                'is_admin' => $acl->isAdmin(),
                                'is_manager' => $acl->isManagerOrAdmin()
                            ],
                            'user' => [
                                'id' => $user["id"],
                                'username' => $username,
                                'email' => $user["email"],
                                'firstname' => $user["firstname"],
                                'lastname' => $user["lastname"],
                                'role' => $userRole,
                                'is_admin' => $acl->isAdmin(),
                                'is_manager' => $acl->isManagerOrAdmin()
                            ],
                            'session_id' => session_id(),
                            'csrf_token' => generateCSRFToken(),
                            'permissions' => $permissionsSummary
                        ];
                        
                        send_json_response(1, 1, 200, "Login successful", $responseData);
                    } else {
                        error_log("Invalid password for user: $username");
                        send_json_response(0, 0, 401, "Invalid username or password");
                    }
                } else {
                    error_log("User not found: $username");
                    send_json_response(0, 0, 401, "User not found");
                }
            } catch (PDOException $e) {
                error_log("Login database error: " . $e->getMessage());
                send_json_response(0, 0, 500, "Database error");
            }
            break;
            
        case 'logout':
            session_start();
            
            if (isset($_SESSION['id']) && class_exists('SimpleACL')) {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $acl->logAction("User logout", "auth", $_SESSION['id']);
            }
            
            $_SESSION = array();
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            session_destroy();
            send_json_response(0, 1, 200, "Logged out successfully");
            break;
            
        case 'check':
        case 'check_session':
            session_start();
            if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $userRole = $acl->getUserRole();
                $permissionsSummary = $acl->getPermissionsSummary();
                
                $responseData = [
                    'user_context' => [
                        'user_id' => $_SESSION['id'],
                        'username' => $_SESSION['username'],
                        'role' => $userRole,
                        'is_admin' => $acl->isAdmin(),
                        'is_manager' => $acl->isManagerOrAdmin()
                    ],
                    'user' => [
                        'id' => $_SESSION['id'],
                        'username' => $_SESSION['username'],
                        'email' => $_SESSION['email'],
                        'role' => $userRole,
                        'is_admin' => $acl->isAdmin(),
                        'is_manager' => $acl->isManagerOrAdmin()
                    ],
                    'permissions' => $permissionsSummary
                ];
                
                send_json_response(1, 1, 200, "User is logged in", $responseData);
            } else {
                send_json_response(0, 0, 401, "User is not logged in");
            }
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid operation");
    }
}

// ===========================================================================
// COMPONENT OPERATIONS - UPDATED WITH PERMISSIONS
// ===========================================================================
function handleComponentOperations($componentType, $operation) {
    global $pdo;
    
    // Check authentication first
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        send_json_response(1, 0, 400, "Invalid component type: " . $componentType);
        return;
    }
    
    $table = $tableMap[$componentType];
    
    switch($operation) {
        case 'list':
        case 'get':
            // Check read permission
            validateComponentAccess($pdo, 'read', $componentType);
            
            try {
                $statusFilter = $_POST['status'] ?? 'all';
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
                $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
                
                $sql = "SELECT * FROM {$table}";
                $params = [];
                
                if ($statusFilter !== 'all') {
                    $sql .= " WHERE Status = ?";
                    $params[] = $statusFilter;
                }
                
                $sql .= " ORDER BY ID DESC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $components = $stmt->fetchAll();
                
                // Get total count
                $countSql = "SELECT COUNT(*) as total FROM {$table}";
                if ($statusFilter !== 'all') {
                    $countSql .= " WHERE Status = '{$statusFilter}'";
                }
                $countStmt = $pdo->query($countSql);
                $total = $countStmt->fetch()['total'];
                
                send_json_response(1, 1, 200, "Components retrieved successfully", [
                    'components' => $components,
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset
                ]);
            } catch (PDOException $e) {
                error_log("Component list error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve components");
            }
            break;
            
        case 'add':
            // Check create permission
            validateComponentAccess($pdo, 'create', $componentType);
            
            try {
                $requiredFields = getRequiredFields($componentType);
                $missingFields = [];
                
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        $missingFields[] = $field;
                    }
                }
                
                if (!empty($missingFields)) {
                    send_json_response(1, 0, 400, "Missing required fields: " . implode(', ', $missingFields));
                    return;
                }
                
                $pdo->beginTransaction();
                
                // Generate UUID
                $uuid = generateUUID();
                
                // Prepare insert statement
                $columns = array_keys($_POST);
                $columns[] = 'UUID';
                $placeholders = ':' . implode(', :', $columns);
                
                $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                $stmt = $pdo->prepare($sql);
                
                // Bind parameters
                foreach ($_POST as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->bindValue(':UUID', $uuid);
                
                $result = $stmt->execute();
                
                if ($result) {
                    $componentId = $pdo->lastInsertId();
                    
                    // Log the action
                    $acl = new SimpleACL($pdo, $_SESSION['id']);
                    $acl->logAction("Component added", $componentType, $uuid, null, $_POST);
                    
                    $pdo->commit();
                    
                    send_json_response(1, 1, 201, ucfirst($componentType) . " added successfully", [
                        'component_id' => $componentId,
                        'uuid' => $uuid
                    ]);
                } else {
                    $pdo->rollBack();
                    send_json_response(1, 0, 500, "Failed to add " . $componentType);
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Component add error: " . $e->getMessage());
                
                if ($e->getCode() == 23000) { // Duplicate entry
                    send_json_response(1, 0, 409, "Component with this serial number already exists");
                } else {
                    send_json_response(1, 0, 500, "Database error occurred");
                }
            }
            break;
            
        case 'update':
            // Check update permission
            validateComponentAccess($pdo, 'update', $componentType);
            
            $uuid = $_POST['uuid'] ?? '';
            if (empty($uuid)) {
                send_json_response(1, 0, 400, "UUID is required for update");
                return;
            }
            
            try {
                // Get current values for audit
                $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE UUID = ?");
                $stmt->execute([$uuid]);
                $oldValues = $stmt->fetch();
                
                if (!$oldValues) {
                    send_json_response(1, 0, 404, "Component not found");
                    return;
                }
                
                $pdo->beginTransaction();
                
                // Build update query
                $updateFields = [];
                $params = [];
                
                foreach ($_POST as $key => $value) {
                    if ($key !== 'uuid' && $key !== 'action') {
                        $updateFields[] = "$key = ?";
                        $params[] = $value;
                    }
                }
                
                if (empty($updateFields)) {
                    send_json_response(1, 0, 400, "No fields to update");
                    return;
                }
                
                $params[] = $uuid;
                $sql = "UPDATE {$table} SET " . implode(', ', $updateFields) . " WHERE UUID = ?";
                
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute($params);
                
                if ($result) {
                    // Log the action
                    $acl = new SimpleACL($pdo, $_SESSION['id']);
                    $acl->logAction("Component updated", $componentType, $uuid, $oldValues, $_POST);
                    
                    $pdo->commit();
                    send_json_response(1, 1, 200, ucfirst($componentType) . " updated successfully");
                } else {
                    $pdo->rollBack();
                    send_json_response(1, 0, 500, "Failed to update " . $componentType);
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Component update error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error occurred");
            }
            break;
            
        case 'delete':
            // Check delete permission
            validateComponentAccess($pdo, 'delete', $componentType);
            
            $uuid = $_POST['uuid'] ?? '';
            if (empty($uuid)) {
                send_json_response(1, 0, 400, "UUID is required for delete");
                return;
            }
            
            try {
                // Get current values for audit
                $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE UUID = ?");
                $stmt->execute([$uuid]);
                $oldValues = $stmt->fetch();
                
                if (!$oldValues) {
                    send_json_response(1, 0, 404, "Component not found");
                    return;
                }
                
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE UUID = ?");
                $result = $stmt->execute([$uuid]);
                
                if ($result && $stmt->rowCount() > 0) {
                    // Log the action
                    $acl = new SimpleACL($pdo, $_SESSION['id']);
                    $acl->logAction("Component deleted", $componentType, $uuid, $oldValues, null);
                    
                    $pdo->commit();
                    send_json_response(1, 1, 200, ucfirst($componentType) . " deleted successfully");
                } else {
                    $pdo->rollBack();
                    send_json_response(1, 0, 404, "Component not found or already deleted");
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Component delete error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error occurred");
            }
            break;
            
        case 'export':
            // Check export permission
            validateComponentAccess($pdo, 'export', $componentType);
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM {$table} ORDER BY ID");
                $stmt->execute();
                $components = $stmt->fetchAll();
                
                // Log the action
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $acl->logAction("Component export", $componentType, null, null, ['count' => count($components)]);
                
                send_json_response(1, 1, 200, "Export data retrieved successfully", [
                    'components' => $components,
                    'total' => count($components),
                    'export_time' => date('Y-m-d H:i:s')
                ]);
            } catch (PDOException $e) {
                error_log("Component export error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to export components");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid component operation: " . $operation);
    }
}

// ===========================================================================
// USER OPERATIONS - UPDATED
// ===========================================================================
function handleUserOperations($operation) {
    global $pdo;
    
    // Check authentication first
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    switch($operation) {
        case 'list':
            // Only admins can list users
            validateComponentAccess($pdo, 'manage_users');
            
            try {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $users = $acl->getUsersWithRoles();
                
                send_json_response(1, 1, 200, "Users retrieved successfully", [
                    'users' => $users
                ]);
            } catch (Exception $e) {
                error_log("Error getting users: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve users");
            }
            break;
            
        case 'roles':
            // Only admins can view roles
            validateComponentAccess($pdo, 'manage_users');
            
            try {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $roles = $acl->getAllRoles();
                $hierarchy = $acl->getRoleHierarchy();
                
                send_json_response(1, 1, 200, "Roles retrieved successfully", [
                    'roles' => $roles,
                    'hierarchy' => $hierarchy
                ]);
            } catch (Exception $e) {
                error_log("Error getting roles: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve roles");
            }
            break;
            
        case 'assign_role':
            // Only admins can assign roles
            validateComponentAccess($pdo, 'manage_users');
            
            $userId = $_POST['user_id'] ?? '';
            $roleName = $_POST['role'] ?? '';
            
            if (empty($userId) || empty($roleName)) {
                send_json_response(1, 0, 400, "User ID and role are required");
                return;
            }
            
            try {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $result = $acl->assignRole($userId, $roleName, $_SESSION['id']);
                
                if ($result) {
                    $acl->logAction("Role assigned", "user", $userId, null, ['role' => $roleName]);
                    send_json_response(1, 1, 200, "Role assigned successfully");
                } else {
                    send_json_response(1, 0, 500, "Failed to assign role");
                }
            } catch (Exception $e) {
                error_log("Error assigning role: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to assign role");
            }
            break;
            
        case 'audit':
            // Only admins can view audit log
            validateComponentAccess($pdo, 'manage_users');
            
            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
            $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
            $filters = [
                'user_id' => $_POST['filter_user_id'] ?? null,
                'component_type' => $_POST['filter_component_type'] ?? null,
                'action' => $_POST['filter_action'] ?? null
            ];
            
            try {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $auditLog = $acl->getAuditLog($limit, $offset, $filters);
                
                send_json_response(1, 1, 200, "Audit log retrieved successfully", [
                    'audit_log' => $auditLog,
                    'limit' => $limit,
                    'offset' => $offset
                ]);
            } catch (Exception $e) {
                error_log("Error getting audit log: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve audit log");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid user operation: " . $operation);
    }
}

// ===========================================================================
// DASHBOARD OPERATIONS
// ===========================================================================
function handleDashboardOperations($operation) {
    global $pdo;
    
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    switch($operation) {
        case 'data':
            try {
                $dashboardData = getDashboardDataWithACL($pdo);
                send_json_response(1, 1, 200, "Dashboard data retrieved successfully", $dashboardData);
            } catch (Exception $e) {
                error_log("Dashboard error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve dashboard data");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid dashboard operation: " . $operation);
    }
}

// ===========================================================================
// SEARCH OPERATIONS
// ===========================================================================
function handleSearchOperations($operation) {
    global $pdo;
    
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    // Check read permission for search
    validateComponentAccess($pdo, 'read');
    
    switch($operation) {
        case 'global':
            $query = $_POST['query'] ?? '';
            $componentTypes = $_POST['types'] ?? ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
            
            if (empty($query)) {
                send_json_response(1, 0, 400, "Search query is required");
                return;
            }
            
            try {
                $results = performGlobalSearch($pdo, $query, $componentTypes);
                send_json_response(1, 1, 200, "Search completed successfully", [
                    'results' => $results,
                    'query' => $query
                ]);
            } catch (Exception $e) {
                error_log("Search error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Search failed");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid search operation: " . $operation);
    }
}

// ===========================================================================
// HELPER FUNCTIONS
// ===========================================================================

/**
 * Validate component access for API operations
 */
function validateComponentAccess($pdo, $action, $componentType = null, $componentId = null) {
    session_start();
    if (!isset($_SESSION['id'])) {
        send_json_response(0, 0, 401, "Authentication required");
        exit;
    }
    
    if (!class_exists('SimpleACL')) {
        // If ACL is not available, only allow read operations
        if ($action !== 'read') {
            send_json_response(1, 0, 403, "Access denied. ACL system not available.");
            exit;
        }
        return true;
    }
    
    $acl = new SimpleACL($pdo, $_SESSION['id']);
    
    if (!$acl->hasPermission($action, $componentType)) {
        $userRole = $acl->getUserRole();
        $permissions = $acl->getPermissionsSummary();
        
        send_json_response(1, 0, 403, "Access denied. Insufficient permissions.", [
            'required_permission' => $action,
            'component_type' => $componentType,
            'user_role' => $userRole,
            'user_permissions' => $permissions['permissions']
        ]);
        exit;
    }
    
    return true;
}

/**
 * Get required fields for component type
 */
function getRequiredFields($componentType) {
    $fields = [
        'cpu' => ['Brand', 'Model', 'SerialNumber', 'Status'],
        'ram' => ['Brand', 'Model', 'SerialNumber', 'Status'],
        'storage' => ['Brand', 'Model', 'SerialNumber', 'Status'],
        'motherboard' => ['Brand', 'Model', 'SerialNumber', 'Status'],
        'nic' => ['Brand', 'Model', 'SerialNumber', 'Status'],
        'caddy' => ['Brand', 'Model', 'SerialNumber', 'Status']
    ];
    
    return $fields[$componentType] ?? [];
}

/**
 * Generate UUID
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Perform global search across all component types
 */
function performGlobalSearch($pdo, $query, $componentTypes) {
    $results = [];
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    foreach ($componentTypes as $type) {
        if (isset($tableMap[$type])) {
            $table = $tableMap[$type];
            $sql = "SELECT *, '$type' as component_type FROM {$table} 
                    WHERE Brand LIKE ? OR Model LIKE ? OR SerialNumber LIKE ? 
                    LIMIT 20";
            
            $stmt = $pdo->prepare($sql);
            $searchTerm = "%$query%";
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            
            $results[$type] = $stmt->fetchAll();
        }
    }
    
    return $results;
}

?>