<?php
/**
 * Updated api.php with Extended Component Fields
 * Main API endpoint for BDC IMS with complete component field support
 */

// Prevent any output before JSON response
ob_start();

// Error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production
ini_set('log_errors', 1);

// Include required files
require_once(__DIR__ . '/../includes/db_config.php');
require_once(__DIR__ . '/../includes/BaseFunctions.php');

// Initialize JWT with secret key
if (class_exists('JWTHelper')) {
    JWTHelper::init(getenv('JWT_SECRET') ?: 'bdc-ims-default-secret-change-in-production-environment');
}

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Clean any previous output
if (ob_get_level()) {
    ob_clean();
}

// Get the action parameter
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($action)) {
    send_json_response(0, 0, 400, "Action parameter is required");
}

// Parse action
$parts = explode('-', $action, 2);
$module = $parts[0] ?? '';
$operation = $parts[1] ?? '';

error_log("API called with action: $action");
error_log("Module: $module, Operation: $operation");

// Authentication operations (no JWT required)
if ($module === 'auth') {
    error_log("Auth operation: $operation");
    
    switch ($operation) {
        case 'login':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                send_json_response(0, 0, 400, "Username and password are required");
            }
            
            try {
                error_log("Login attempt for username: $username");
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->execute();
                
                if ($stmt->rowCount() == 1) {
                    $user = $stmt->fetch();
                    
                    if (password_verify($password, $user['password'])) {
                        // Generate JWT token if JWT is available
                        if (function_exists('generateUserJWT')) {
                            $token = generateUserJWT($user, $pdo);
                            
                            // Get user permissions
                            if (class_exists('SimpleACL')) {
                                $acl = new SimpleACL($pdo, $user['id']);
                                $userRole = $acl->getUserRole();
                                $permissionsSummary = $acl->getPermissionsSummary();
                                
                                // Log successful login
                                $acl->logAction("User login", "auth", $user['id']);
                            } else {
                                $userRole = 'viewer';
                                $permissionsSummary = [];
                            }
                            
                            error_log("Login successful for user: $username with role: $userRole");
                            
                            $responseData = [
                                'token' => $token,
                                'token_type' => 'Bearer',
                                'expires_in' => 24 * 60 * 60, // 24 hours in seconds
                                'user' => [
                                    'id' => $user['id'],
                                    'username' => $user['username'],
                                    'email' => $user['email'],
                                    'firstname' => $user['firstname'] ?? '',
                                    'lastname' => $user['lastname'] ?? '',
                                    'role' => $userRole,
                                    'is_admin' => isset($acl) ? $acl->isAdmin() : false,
                                    'is_manager' => isset($acl) ? $acl->isManagerOrAdmin() : false
                                ],
                                'permissions' => $permissionsSummary
                            ];
                            
                            send_json_response(1, 1, 200, "Login successful", $responseData);
                        } else {
                            // Fallback to session-based login
                            session_start();
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $user["id"];
                            $_SESSION["username"] = $username;
                            $_SESSION["email"] = $user["email"];
                            
                            send_json_response(1, 1, 200, "Login successful (session)", [
                                'user' => [
                                    'id' => $user['id'],
                                    'username' => $username,
                                    'email' => $user['email']
                                ],
                                'session_id' => session_id()
                            ]);
                        }
                    } else {
                        error_log("Invalid password for user: $username");
                        send_json_response(0, 0, 401, "Invalid credentials");
                    }
                } else {
                    error_log("User not found: $username");
                    send_json_response(0, 0, 401, "Invalid credentials");
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                send_json_response(0, 0, 500, "Login failed");
            }
            break;
            
        case 'logout':
            // For JWT, logout is handled client-side, but we can log the action
            if (function_exists('logoutJWT')) {
                logoutJWT();
            } else {
                // Session-based logout
                session_start();
                session_destroy();
            }
            send_json_response(1, 1, 200, "Logout successful");
            break;
            
        case 'verify':
        case 'check_token':
            if (function_exists('authenticateWithJWTAndACL')) {
                $user = authenticateWithJWTAndACL($pdo);
                if ($user) {
                    $responseData = [
                        'user' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'email' => $user['email'],
                            'firstname' => $user['firstname'] ?? '',
                            'lastname' => $user['lastname'] ?? '',
                            'role' => $user['role'] ?? 'viewer',
                            'is_admin' => $user['is_admin'] ?? false,
                            'is_manager' => $user['is_manager'] ?? false
                        ],
                        'permissions' => $user['permissions'] ?? []
                    ];
                    
                    send_json_response(1, 1, 200, "Token valid", $responseData);
                } else {
                    send_json_response(0, 0, 401, "Invalid or expired token");
                }
            } else {
                // Fallback to session check
                session_start();
                if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
                    send_json_response(1, 1, 200, "Session valid", [
                        'user' => [
                            'id' => $_SESSION['id'],
                            'username' => $_SESSION['username'],
                            'email' => $_SESSION['email']
                        ]
                    ]);
                } else {
                    send_json_response(0, 0, 401, "Session invalid");
                }
            }
            break;
            
        case 'refresh':
            if (function_exists('refreshJWTToken')) {
                $newToken = refreshJWTToken();
                if ($newToken) {
                    $responseData = [
                        'token' => $newToken,
                        'token_type' => 'Bearer',
                        'expires_in' => 24 * 60 * 60
                    ];
                    send_json_response(1, 1, 200, "Token refreshed", $responseData);
                } else {
                    send_json_response(0, 0, 401, "Unable to refresh token");
                }
            } else {
                send_json_response(0, 0, 400, "Token refresh not available");
            }
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid auth operation");
    }
    exit();
}

// All other operations require authentication
$user = null;

// Try JWT authentication first
if (function_exists('validateJWTMiddleware')) {
    try {
        $user = validateJWTMiddleware($pdo);
    } catch (Exception $e) {
        // JWT validation failed, try session
        error_log("JWT validation failed: " . $e->getMessage());
    }
}

// Fallback to session authentication
if (!$user) {
    $user = isUserLoggedIn($pdo);
    if (!$user) {
        send_json_response(0, 0, 401, "Authentication required");
    }
}

error_log("Authenticated user: " . $user['username'] . " (ID: " . $user['id'] . ")");

// Component operations with extended fields
if (in_array($module, ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'])) {
    
    // Table mapping
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
            // Check read permission
            requireUserPermission($pdo, 'read', $module);
            
            try {
                $status = $_GET['status'] ?? 'all';
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
                $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
                $search = $_GET['search'] ?? '';
                
                $query = "SELECT * FROM $table";
                $params = [];
                $conditions = [];
                
                if ($status !== 'all') {
                    $conditions[] = "Status = :status";
                    $params[':status'] = $status;
                }
                
                // Add search functionality
                if (!empty($search)) {
                    $conditions[] = "(SerialNumber LIKE :search OR Notes LIKE :search OR Location LIKE :search OR RackPosition LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                
                if (!empty($conditions)) {
                    $query .= " WHERE " . implode(' AND ', $conditions);
                }
                
                $query .= " ORDER BY CreatedAt DESC LIMIT :limit OFFSET :offset";
                
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
                    $countQuery .= " WHERE " . implode(' AND ', array_filter($conditions, function($cond) {
                        return strpos($cond, ':search') === false || !empty($_GET['search']);
                    }));
                }
                $countStmt = $pdo->prepare($countQuery);
                foreach ($params as $key => $value) {
                    if ($key !== ':limit' && $key !== ':offset') {
                        $countStmt->bindValue($key, $value);
                    }
                }
                $countStmt->execute();
                $total = $countStmt->fetch()['total'];
                
                send_json_response(1, 1, 200, "Components retrieved successfully", [
                    'components' => $components,
                    'pagination' => [
                        'total' => (int)$total,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $total
                    ]
                ]);
            } catch (Exception $e) {
                error_log("Error retrieving $module components: " . $e->getMessage());
                send_json_response(0, 0, 500, "Failed to retrieve components");
            }
            break;
            
        case 'add':
        case 'create':
            // Check create permission
            requireUserPermission($pdo, 'create', $module);
            
            try {
                // Extended component fields
                $uuid = $_POST['uuid'] ?? '';
                $serialNumber = $_POST['serial_number'] ?? '';
                $status = $_POST['status'] ?? '1';
                $serverUuid = $_POST['server_uuid'] ?? null;
                $location = $_POST['location'] ?? '';
                $rackPosition = $_POST['rack_position'] ?? '';
                $purchaseDate = $_POST['purchase_date'] ?? null;
                $warrantyEndDate = $_POST['warranty_end_date'] ?? null;
                $flag = $_POST['flag'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                // Validate required fields
                if (empty($uuid)) {
                    send_json_response(0, 0, 400, "UUID is required");
                }
                
                // Validate status
                if (!in_array($status, ['0', '1', '2'])) {
                    send_json_response(0, 0, 400, "Invalid status. Must be 0 (Failed), 1 (Available), or 2 (In Use)");
                }
                
                // Validate dates
                if ($purchaseDate && !DateTime::createFromFormat('Y-m-d', $purchaseDate)) {
                    send_json_response(0, 0, 400, "Invalid purchase date format. Use YYYY-MM-DD");
                }
                
                if ($warrantyEndDate && !DateTime::createFromFormat('Y-m-d', $warrantyEndDate)) {
                    send_json_response(0, 0, 400, "Invalid warranty end date format. Use YYYY-MM-DD");
                }
                
                // Check for duplicate UUID
                $checkStmt = $pdo->prepare("SELECT ID FROM $table WHERE UUID = :uuid");
                $checkStmt->bindValue(':uuid', $uuid);
                $checkStmt->execute();
                
                if ($checkStmt->fetch()) {
                    send_json_response(0, 0, 409, "Component with this UUID already exists");
                }
                
                // Insert new component with extended fields
                $insertQuery = "INSERT INTO $table (
                    UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
                    PurchaseDate, WarrantyEndDate, Flag, Notes, CreatedAt, UpdatedAt
                ) VALUES (
                    :uuid, :serial, :status, :server_uuid, :location, :rack_position,
                    :purchase_date, :warranty_end_date, :flag, :notes, NOW(), NOW()
                )";
                
                $stmt = $pdo->prepare($insertQuery);
                $stmt->bindValue(':uuid', $uuid);
                $stmt->bindValue(':serial', $serialNumber);
                $stmt->bindValue(':status', $status, PDO::PARAM_INT);
                $stmt->bindValue(':server_uuid', $serverUuid);
                $stmt->bindValue(':location', $location);
                $stmt->bindValue(':rack_position', $rackPosition);
                $stmt->bindValue(':purchase_date', $purchaseDate);
                $stmt->bindValue(':warranty_end_date', $warrantyEndDate);
                $stmt->bindValue(':flag', $flag);
                $stmt->bindValue(':notes', $notes);
                
                if ($stmt->execute()) {
                    $newId = $pdo->lastInsertId();
                    
                    // Log the action
                    logAPIAction($pdo, "Component added", $module, $newId, null, [
                        'uuid' => $uuid,
                        'serial_number' => $serialNumber,
                        'status' => $status,
                        'location' => $location,
                        'rack_position' => $rackPosition
                    ]);
                    
                    send_json_response(1, 1, 201, "Component added successfully", [
                        'id' => (int)$newId,
                        'uuid' => $uuid
                    ]);
                } else {
                    send_json_response(0, 0, 500, "Failed to add component");
                }
            } catch (Exception $e) {
                error_log("Error adding $module component: " . $e->getMessage());
                send_json_response(0, 0, 500, "Failed to add component: " . $e->getMessage());
            }
            break;
            
        case 'update':
        case 'edit':
            // Check update permission
            requireUserPermission($pdo, 'update', $module);
            
            try {
                $id = $_POST['id'] ?? '';
                if (empty($id)) {
                    send_json_response(0, 0, 400, "Component ID is required");
                }
                
                // Get current component data for logging
                $currentStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $currentStmt->bindValue(':id', $id, PDO::PARAM_INT);
                $currentStmt->execute();
                $currentData = $currentStmt->fetch();
                
                if (!$currentData) {
                    send_json_response(0, 0, 404, "Component not found");
                }
                
                // Build update query dynamically for extended fields
                $updateFields = [];
                $params = [':id' => $id];
                $newValues = [];
                
                $allowedFields = [
                    'serial_number' => 'SerialNumber',
                    'status' => 'Status',
                    'server_uuid' => 'ServerUUID',
                    'location' => 'Location',
                    'rack_position' => 'RackPosition',
                    'purchase_date' => 'PurchaseDate',
                    'warranty_end_date' => 'WarrantyEndDate',
                    'flag' => 'Flag',
                    'notes' => 'Notes'
                ];
                
                foreach ($allowedFields as $postKey => $dbField) {
                    if (isset($_POST[$postKey])) {
                        $value = $_POST[$postKey];
                        
                        // Validate status if being updated
                        if ($postKey === 'status' && !in_array($value, ['0', '1', '2'])) {
                            send_json_response(0, 0, 400, "Invalid status. Must be 0, 1, or 2");
                        }
                        
                        // Validate dates
                        if (in_array($postKey, ['purchase_date', 'warranty_end_date']) && 
                            $value && !DateTime::createFromFormat('Y-m-d', $value)) {
                            send_json_response(0, 0, 400, "Invalid date format for $postKey. Use YYYY-MM-DD");
                        }
                        
                        $updateFields[] = "$dbField = :$postKey";
                        $params[":$postKey"] = $value ?: null;
                        $newValues[$postKey] = $value;
                    }
                }
                
                if (empty($updateFields)) {
                    send_json_response(0, 0, 400, "No fields to update");
                }
                
                // Add UpdatedAt
                $updateFields[] = "UpdatedAt = NOW()";
                
                $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = :id";
                $stmt = $pdo->prepare($query);
                
                if ($stmt->execute($params)) {
                    // Log the action
                    logAPIAction($pdo, "Component updated", $module, $id, 
                        array_intersect_key($currentData, $newValues), $newValues);
                    
                    send_json_response(1, 1, 200, "Component updated successfully");
                } else {
                    send_json_response(0, 0, 500, "Failed to update component");
                }
            } catch (Exception $e) {
                error_log("Error updating $module component: " . $e->getMessage());
                send_json_response(0, 0, 500, "Failed to update component");
            }
            break;
            
        case 'delete':
            // Check delete permission
            requireUserPermission($pdo, 'delete', $module);
            
            try {
                $id = $_POST['id'] ?? '';
                if (empty($id)) {
                    send_json_response(0, 0, 400, "Component ID is required");
                }
                
                // Get component data for logging before deletion
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                $componentData = $stmt->fetch();
                
                if (!$componentData) {
                    send_json_response(0, 0, 404, "Component not found");
                }
                
                // Delete the component
                $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
                $deleteStmt->bindValue(':id', $id, PDO::PARAM_INT);
                
                if ($deleteStmt->execute()) {
                    // Log the action
                    logAPIAction($pdo, "Component deleted", $module, $id, $componentData, null);
                    
                    send_json_response(1, 1, 200, "Component deleted successfully");
                } else {
                    send_json_response(0, 0, 500, "Failed to delete component");
                }
            } catch (Exception $e) {
                error_log("Error deleting $module component: " . $e->getMessage());
                send_json_response(0, 0, 500, "Failed to delete component");
            }
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid component operation");
    }
    exit();
}

// Dashboard operations
if ($module === 'dashboard') {
    requireUserPermission($pdo, 'read');
    
    switch ($operation) {
        case 'stats':
        case 'get':
            try {
                $stats = [];
                $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
                
                foreach ($componentTypes as $type) {
                    $table = $tableMap[$type] ?? $type . 'inventory';
                    
                    // Get counts by status
                    $query = "SELECT Status, COUNT(*) as count FROM $table GROUP BY Status";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute();
                    $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    
                    $stats[$type] = [
                        'total' => array_sum($statusCounts),
                        'available' => $statusCounts[1] ?? 0,
                        'in_use' => $statusCounts[2] ?? 0,
                        'failed' => $statusCounts[0] ?? 0
                    ];
                }
                
                send_json_response(1, 1, 200, "Dashboard stats retrieved", ['stats' => $stats]);
            } catch (Exception $e) {
                error_log("Error getting dashboard stats: " . $e->getMessage());
                send_json_response(0, 0, 500, "Failed to retrieve dashboard stats");
            }
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid dashboard operation");
    }
    exit();
}

// ACL operations (admin only)
if ($module === 'acl') {
    if (class_exists('SimpleACL')) {
        $acl = new SimpleACL($pdo, $user['id']);
        
        if (!$acl->isAdmin()) {
            send_json_response(0, 0, 403, "Admin access required");
        }
        
        // Handle ACL operations
        switch ($operation) {
            case 'get_user_permissions':
                $userId = $_POST['user_id'] ?? $_GET['user_id'] ?? '';
                
                if (empty($userId)) {
                    send_json_response(0, 0, 400, "User ID is required");
                }
                
                try {
                    $userAcl = new SimpleACL($pdo, $userId);
                    $permissions = $userAcl->getPermissionsSummary();
                    $role = $userAcl->getUserRole();
                    
                    send_json_response(1, 1, 200, "User permissions retrieved", [
                        'user_id' => $userId,
                        'role' => $role,
                        'permissions' => $permissions
                    ]);
                } catch (Exception $e) {
                    error_log("Error getting user permissions: " . $e->getMessage());
                    send_json_response(0, 0, 500, "Failed to retrieve user permissions");
                }
                break;
                
            case 'get_all_roles':
                try {
                    $query = "SELECT * FROM roles ORDER BY id";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute();
                    $roles = $stmt->fetchAll();
                    
                    send_json_response(1, 1, 200, "Roles retrieved", ['roles' => $roles]);
                } catch (Exception $e) {
                    error_log("Error getting roles: " . $e->getMessage());
                    send_json_response(0, 0, 500, "Failed to retrieve roles");
                }
                break;
                
            default:
                send_json_response(0, 0, 400, "Invalid ACL operation");
        }
    } else {
        send_json_response(0, 0, 500, "ACL system not available");
    }
    exit();
}

// Invalid module
send_json_response(0, 0, 400, "Invalid module: $module");