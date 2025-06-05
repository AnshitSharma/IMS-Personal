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
    // Note: ACL is already included in BaseFunctions.php
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

// Validate CSRF token for state-changing operations
$stateChangingActions = ['add', 'update', 'delete', 'create', 'edit', 'remove'];
$actionParts = explode('-', $action);
if (in_array(end($actionParts), $stateChangingActions)) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        send_json_response(0, 0, 403, "Invalid CSRF token");
    }
}

// Log the action for debugging
error_log("API called with action: " . $action);

try {
    // Route to appropriate handler based on action
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
            
        case 'acl':
            handleACLOperations($operation);
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
// AUTHENTICATION OPERATIONS
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
                        
                        // Get user ACL information
                        $acl = new ACL($pdo, $user["id"]);
                        $userRoles = $acl->getUserRoles();
                        $userPermissions = $acl->getUserPermissions();
                        
                        error_log("Login successful for user: $username");
                        logAction($pdo, "User login", "auth", $user["id"]);
                        
                        send_json_response(1, 1, 200, "Login successful", [
                            'user' => [
                                'id' => $user["id"],
                                'username' => $username,
                                'email' => $user["email"],
                                'firstname' => $user["firstname"],
                                'lastname' => $user["lastname"],
                                'roles' => array_column($userRoles, 'name'),
                                'is_super_admin' => $acl->isSuperAdmin()
                            ],
                            'session_id' => session_id(),
                            'csrf_token' => generateCSRFToken(),
                            'permissions_summary' => getUserPermissionsSummary($pdo, $user["id"])
                        ]);
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
                send_json_response(0, 0, 500, "Database error occurred");
            }
            break;
            
        case 'logout':
            session_start();
            if (isset($_SESSION['id'])) {
                logAction($pdo, "User logout", "auth", $_SESSION['id']);
            }
            
            $_SESSION = array();
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            session_destroy();
            send_json_response(1, 1, 200, "Logout successful");
            break;
            
        case 'check_session':
            session_start();
            $user = isUserLoggedInWithACL($pdo);
            if ($user) {
                send_json_response(1, 1, 200, "Session valid", [
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'firstname' => $user['firstname'],
                        'lastname' => $user['lastname'],
                        'roles' => array_column($user['roles'], 'name'),
                        'is_super_admin' => $user['is_super_admin']
                    ],
                    'session_id' => session_id(),
                    'csrf_token' => generateCSRFToken()
                ]);
            } else {
                send_json_response(0, 0, 401, "Session invalid or expired");
            }
            break;
            
        case 'register':
            // Check if user can create other users (admin only)
            session_start();
            if (!isset($_SESSION['id'])) {
                send_json_response(0, 0, 401, "Authentication required");
                return;
            }
            
            $acl = new ACL($pdo, $_SESSION['id']);
            if (!$acl->hasPermission('users', 'create')) {
                send_json_response(1, 0, 403, "Insufficient permissions to create users");
                return;
            }
            
            $userData = [
                'username' => trim($_POST['username'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'password' => password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT),
                'firstname' => trim($_POST['firstname'] ?? ''),
                'lastname' => trim($_POST['lastname'] ?? '')
            ];
            
            $defaultRoles = $_POST['roles'] ?? ['viewer'];
            
            if (empty($userData['username']) || empty($userData['email']) || empty($_POST['password'])) {
                send_json_response(1, 0, 400, "Required fields missing");
                return;
            }
            
            $userId = createUserWithACL($pdo, $userData, $defaultRoles);
            if ($userId) {
                logAction($pdo, "User created", "users", $userId);
                send_json_response(1, 1, 200, "User created successfully", [
                    'user_id' => $userId
                ]);
            } else {
                send_json_response(1, 0, 500, "Failed to create user");
            }
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid auth operation: " . $operation);
    }
}

// ===========================================================================
// ACL OPERATIONS
// ===========================================================================
function handleACLOperations($operation) {
    global $pdo;
    
    switch($operation) {
        case 'get_user_permissions':
            // Users can check their own permissions
            session_start();
            if (!isset($_SESSION['id'])) {
                send_json_response(0, 0, 401, "Authentication required");
                return;
            }
            
            $requestedUserId = $_POST['user_id'] ?? $_SESSION['id'];
            
            // Users can only check their own permissions unless they have admin rights
            if ($requestedUserId != $_SESSION['id']) {
                $acl = new ACL($pdo, $_SESSION['id']);
                if (!$acl->hasPermission('users', 'read')) {
                    send_json_response(1, 0, 403, "Can only view your own permissions");
                    return;
                }
            }
            
            $summary = getUserPermissionsSummary($pdo, $requestedUserId);
            
            if ($summary) {
                send_json_response(1, 1, 200, "Permissions retrieved successfully", $summary);
            } else {
                send_json_response(1, 0, 404, "User not found or error retrieving permissions");
            }
            break;
            
        case 'assign_role':
            // Check if user can manage roles
            session_start();
            if (!isset($_SESSION['id'])) {
                send_json_response(0, 0, 401, "Authentication required");
                return;
            }
            
            $acl = new ACL($pdo, $_SESSION['id']);
            if (!$acl->hasPermission('users', 'manage_roles')) {
                send_json_response(1, 0, 403, "Insufficient permissions to manage roles");
                return;
            }
            
            $userId = $_POST['user_id'] ?? '';
            $roleName = $_POST['role_name'] ?? '';
            $expiresAt = $_POST['expires_at'] ?? null;
            
            if (empty($userId) || empty($roleName)) {
                send_json_response(1, 0, 400, "User ID and role name required");
                return;
            }
            
            if ($acl->assignRole($userId, $roleName, $_SESSION['id'], $expiresAt)) {
                logAction($pdo, "Role assigned: $roleName", "users", $userId);
                send_json_response(1, 1, 200, "Role assigned successfully");
            } else {
                send_json_response(1, 0, 500, "Failed to assign role");
            }
            break;
            
        case 'remove_role':
            // Check if user can manage roles
            session_start();
            if (!isset($_SESSION['id'])) {
                send_json_response(0, 0, 401, "Authentication required");
                return;
            }
            
            $acl = new ACL($pdo, $_SESSION['id']);
            if (!$acl->hasPermission('users', 'manage_roles')) {
                send_json_response(1, 0, 403, "Insufficient permissions to manage roles");
                return;
            }
            
            $userId = $_POST['user_id'] ?? '';
            $roleName = $_POST['role_name'] ?? '';
            
            if (empty($userId) || empty($roleName)) {
                send_json_response(1, 0, 400, "User ID and role name required");
                return;
            }
            
            if ($acl->removeRole($userId, $roleName)) {
                logAction($pdo, "Role removed: $roleName", "users", $userId);
                send_json_response(1, 1, 200, "Role removed successfully");
            } else {
                send_json_response(1, 0, 500, "Failed to remove role");
            }
            break;
            
        case 'get_all_roles':
            session_start();
            if (!isset($_SESSION['id'])) {
                send_json_response(0, 0, 401, "Authentication required");
                return;
            }
            
            $acl = new ACL($pdo, $_SESSION['id']);
            if (!$acl->hasPermission('roles', 'read')) {
                send_json_response(1, 0, 403, "Insufficient permissions to view roles");
                return;
            }
            
            $roles = $acl->getAllRoles();
            send_json_response(1, 1, 200, "Roles retrieved successfully", [
                'roles' => $roles
            ]);
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid ACL operation: " . $operation);
    }
}

// ===========================================================================
// COMPONENT OPERATIONS (Enhanced with ACL)
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
        case 'list_' . $componentType:
            // Check read permission
            $acl = new ACL($pdo, $_SESSION['id']);
            if (!$acl->hasPermission($componentType, 'read')) {
                send_json_response(1, 0, 403, "Insufficient permissions to view $componentType components");
                return;
            }
            
            try {
                $statusFilter = $_POST['status'] ?? 'all';
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
                $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
                
                $query = "SELECT * FROM $table";
                $params = [];
                
                if ($statusFilter !== 'all') {
                    $query .= " WHERE Status = :status";
                    $params[':status'] = $statusFilter;
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
                
                // Add user permissions for each component
                foreach ($components as &$component) {
                    $component['user_permissions'] = [
                        'can_update' => $acl->hasPermission($componentType, 'update', $component['UUID']),
                        'can_delete' => $acl->hasPermission($componentType, 'delete', $component['UUID']),
                        'can_export' => $acl->hasPermission($componentType, 'export', $component['UUID'])
                    ];
                }
                
                // Get total count
                $countQuery = "SELECT COUNT(*) as total FROM $table";
                if ($statusFilter !== 'all') {
                    $countQuery .= " WHERE Status = :status";
                }
                $countStmt = $pdo->prepare($countQuery);
                if ($statusFilter !== 'all') {
                    $countStmt->bindParam(':status', $statusFilter);
                }
                $countStmt->execute();
                $totalCount = $countStmt->fetch()['total'];
                
                send_json_response(1, 1, 200, "Components retrieved successfully", [
                    'components' => $components,
                    'total_count' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $totalCount,
                    'user_permissions' => [
                        'can_create' => $acl->hasPermission($componentType, 'create'),
                        'can_export_all' => $acl->hasPermission($componentType, 'export')
                    ]
                ]);
                
                logAction($pdo, "Listed $componentType components", $componentType);
                
            } catch (PDOException $e) {
                error_log("Component list error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'get':
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(1, 0, 400, "Component ID required");
                return;
            }
            
            // Check read permission for specific component
            validateComponentAccess($pdo, $componentType, 'read', $id);
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                
                $component = $stmt->fetch();
                if (!$component) {
                    send_json_response(1, 0, 404, "Component not found");
                    return;
                }
                
                // Add user permissions for this component
                $acl = new ACL($pdo, $_SESSION['id']);
                $component['user_permissions'] = [
                    'can_update' => $acl->hasPermission($componentType, 'update', $component['UUID']),
                    'can_delete' => $acl->hasPermission($componentType, 'delete', $component['UUID']),
                    'can_export' => $acl->hasPermission($componentType, 'export', $component['UUID'])
                ];
                
                send_json_response(1, 1, 200, "Component retrieved successfully", [
                    'component' => $component
                ]);
                
                logAction($pdo, "Viewed $componentType component", $componentType, $component['UUID']);
                
            } catch (PDOException $e) {
                error_log("Component get error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'add':
        case 'add_' . $componentType:
            // Check create permission
            $acl = new ACL($pdo, $_SESSION['id']);
            if (!$acl->hasPermission($componentType, 'create')) {
                send_json_response(1, 0, 403, "Insufficient permissions to create $componentType components");
                return;
            }
            
            try {
                $componentUuid = $_POST['component_uuid'] ?? generateComponentUUID();
                $serialNumber = $_POST['serial_number'] ?? '';
                $status = $_POST['status'] ?? 1;
                $serverUuid = $_POST['server_uuid'] ?? null;
                $location = $_POST['location'] ?? null;
                $rackPosition = $_POST['rack_position'] ?? null;
                $purchaseDate = $_POST['purchase_date'] ?? null;
                $warrantyEndDate = $_POST['warranty_end_date'] ?? null;
                $flag = $_POST['flag'] ?? null;
                $notes = $_POST['notes'] ?? null;
                
                if (empty($serialNumber)) {
                    send_json_response(1, 0, 400, "Serial Number is required");
                    return;
                }
                
                $query = "INSERT INTO $table (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
                          PurchaseDate, WarrantyEndDate, Flag, Notes";
                
                $values = ":uuid, :serial_number, :status, :server_uuid, :location, :rack_position, 
                           :purchase_date, :warranty_end_date, :flag, :notes";
                
                $params = [
                    ':uuid' => $componentUuid,
                    ':serial_number' => $serialNumber,
                    ':status' => $status,
                    ':server_uuid' => $serverUuid,
                    ':location' => $location,
                    ':rack_position' => $rackPosition,
                    ':purchase_date' => $purchaseDate,
                    ':warranty_end_date' => $warrantyEndDate,
                    ':flag' => $flag,
                    ':notes' => $notes
                ];
                
                // Handle NIC-specific fields
                if ($componentType == 'nic') {
                    $macAddress = $_POST['mac_address'] ?? null;
                    $ipAddress = $_POST['ip_address'] ?? null;
                    $networkName = $_POST['network_name'] ?? null;
                    
                    $query .= ", MacAddress, IPAddress, NetworkName";
                    $values .= ", :mac_address, :ip_address, :network_name";
                    
                    $params[':mac_address'] = $macAddress;
                    $params[':ip_address'] = $ipAddress;
                    $params[':network_name'] = $networkName;
                }
                
                $query .= ") VALUES ($values)";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                
                $insertId = $pdo->lastInsertId();
                
                logAction($pdo, "Created $componentType component", $componentType, $componentUuid);
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " added successfully", [
                    'id' => (int)$insertId,
                    'uuid' => $componentUuid
                ]);
                
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    send_json_response(1, 0, 400, "Serial number already exists");
                } else {
                    error_log("Component add error: " . $e->getMessage());
                    send_json_response(1, 0, 500, "Database error");
                }
            }
            break;
            
        case 'update':
        case 'edit':
        case 'edit_' . $componentType:
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(1, 0, 400, "Component ID is required");
                return;
            }
            
            // Check update permission for specific component
            validateComponentAccess($pdo, $componentType, 'update', $id);
            
            try {
                // Check if component exists and get its UUID
                $checkStmt = $pdo->prepare("SELECT UUID FROM $table WHERE ID = :id");
                $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                $component = $checkStmt->fetch();
                
                if (!$component) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                    return;
                }
                
                // Build update query
                $updateFields = [];
                $params = [':id' => $id];
                
                $editableFields = [
                    'status' => 'Status',
                    'server_uuid' => 'ServerUUID',
                    'location' => 'Location',
                    'rack_position' => 'RackPosition',
                    'purchase_date' => 'PurchaseDate',
                    'warranty_end_date' => 'WarrantyEndDate',
                    'flag' => 'Flag',
                    'notes' => 'Notes'
                ];
                
                foreach ($editableFields as $postField => $dbField) {
                    if (isset($_POST[$postField])) {
                        $updateFields[] = "$dbField = :$postField";
                        $params[":$postField"] = $_POST[$postField] ?: null;
                    }
                }
                
                // Handle NIC-specific fields
                if ($componentType == 'nic') {
                    $nicFields = [
                        'mac_address' => 'MacAddress',
                        'ip_address' => 'IPAddress',
                        'network_name' => 'NetworkName'
                    ];
                    
                    foreach ($nicFields as $postField => $dbField) {
                        if (isset($_POST[$postField])) {
                            $updateFields[] = "$dbField = :$postField";
                            $params[":$postField"] = $_POST[$postField] ?: null;
                        }
                    }
                }
                
                if (empty($updateFields)) {
                    send_json_response(1, 0, 400, "No fields to update");
                    return;
                }
                
                $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = :id";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                
                logAction($pdo, "Updated $componentType component", $componentType, $component['UUID']);
                send_json_response(1, 1, 200, ucfirst($componentType) . " updated successfully");
                
            } catch (PDOException $e) {
                error_log("Component update error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'delete':
        case 'remove':
        case 'remove_' . $componentType:
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(1, 0, 400, "Component ID is required");
                return;
            }
            
            // Check delete permission for specific component
            validateComponentAccess($pdo, $componentType, 'delete', $id);
            
            try {
                // Check if component exists and get its UUID
                $checkStmt = $pdo->prepare("SELECT UUID FROM $table WHERE ID = :id");
                $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                $component = $checkStmt->fetch();
                
                if (!$component) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                    return;
                }
                
                // Delete the component
                $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
                $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $deleteStmt->execute();
                
                logAction($pdo, "Deleted $componentType component", $componentType, $component['UUID']);
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " deleted successfully");
                
            } catch (PDOException $e) {
                error_log("Component delete error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'options':
            // Check read permission to get component options
            $acl = new ACL($pdo, $_SESSION['id']);
            if (!$acl->hasPermission($componentType, 'read')) {
                send_json_response(1, 0, 403, "Insufficient permissions to view $componentType options");
                return;
            }
            
            try {
                $options = loadComponentOptions($componentType);
                send_json_response(1, 1, 200, "Component options retrieved successfully", [
                    'options' => $options
                ]);
            } catch (Exception $e) {
                error_log("Component options error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to load options");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid component operation: " . $operation);
    }
}

// ===========================================================================
// DASHBOARD OPERATIONS
// ===========================================================================
function handleDashboardOperations($operation) {
    global $pdo;
    
    // Check authentication and dashboard access
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    switch($operation) {
        case 'get_data':
            try {
                $statusFilter = $_POST['status'] ?? 'all';
                $componentType = $_POST['component'] ?? 'all';
                
                // Get component counts with ACL filtering
                $componentCounts = getComponentCountsWithACL($pdo, $statusFilter);
                
                // Get counts by status
              $statusCounts = [
                    'available' => getComponentCountsWithACL($pdo, '1')['total'],
                    'in_use' => getComponentCountsWithACL($pdo, '2')['total'],
                    'failed' => getComponentCountsWithACL($pdo, '0')['total'],
                    'total' => getComponentCountsWithACL($pdo, 'all')['total']
                ];
                
                // Get recent activity (filtered by permissions)
                $recentActivity = getRecentActivityWithACL($pdo, 10);
                
                // Get warranty alerts (filtered by permissions)
                $warrantyAlerts = getWarrantyAlertsWithACL($pdo, 90);
                
                // Get user dashboard data
                $dashboardData = getDashboardDataWithACL($pdo);
                
                $responseData = [
                    'user_info' => [
                        'id' => $_SESSION['id'],
                        'username' => $_SESSION['username'],
                        'email' => $_SESSION['email']
                    ],
                    'status_counts' => $statusCounts,
                    'component_counts' => $componentCounts,
                    'recent_activity' => $recentActivity,
                    'warranty_alerts' => $warrantyAlerts,
                    'filters' => [
                        'current_status' => $statusFilter,
                        'current_component' => $componentType
                    ],
                    'permissions' => $dashboardData
                ];
                
                send_json_response(1, 1, 200, "Dashboard data retrieved successfully", $responseData);
            } catch (Exception $e) {
                error_log("Dashboard error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to get dashboard data");
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
    
    // Check authentication and search permission
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    switch($operation) {
        case 'components':
            $query = $_POST['query'] ?? '';
            $componentType = $_POST['type'] ?? 'all';
            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
            
            if (empty($query)) {
                send_json_response(1, 0, 400, "Search query is required");
                return;
            }
            
            try {
                $results = searchComponentsWithACL($pdo, $query, $componentType, $limit);
                
                logAction($pdo, "Searched components: $query", "search");
                
                send_json_response(1, 1, 200, "Search completed successfully", [
                    'results' => $results['components'],
                    'total_found' => $results['total_found'],
                    'accessible_count' => $results['accessible_count'],
                    'query' => $query,
                    'component_type' => $componentType
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
// HELPER FUNCTIONS WITH ACL
// ===========================================================================

function getComponentCountsWithACL($pdo, $statusFilter = null) {
    $counts = [
        'cpu' => 0, 'ram' => 0, 'storage' => 0,
        'motherboard' => 0, 'nic' => 0, 'caddy' => 0, 'total' => 0
    ];
    
    $tables = [
        'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
    ];
    
    $acl = getCurrentUserACL($pdo);
    
    foreach ($tables as $key => $table) {
        // Only count components user can read
        if (!$acl->hasPermission($key, 'read')) {
            continue;
        }
        
        try {
            $query = "SELECT COUNT(*) as count FROM $table";
            if ($statusFilter !== null && $statusFilter !== 'all') {
                $query .= " WHERE Status = :status";
            }
            
            $stmt = $pdo->prepare($query);
            if ($statusFilter !== null && $statusFilter !== 'all') {
                $stmt->bindParam(':status', $statusFilter, PDO::PARAM_INT);
            }
            $stmt->execute();
            $result = $stmt->fetch();
            $counts[$key] = (int)$result['count'];
            $counts['total'] += (int)$result['count'];
        } catch (PDOException $e) {
            error_log("Error counting $key: " . $e->getMessage());
        }
    }
    
    return $counts;
}

function getRecentActivityWithACL($pdo, $limit = 10) {
    try {
        $activities = [];
        $tables = [
            'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
        ];
        
        $acl = getCurrentUserACL($pdo);
        
        foreach ($tables as $type => $table) {
            // Only get activity for components user can read
            if (!$acl->hasPermission($type, 'read')) {
                continue;
            }
            
            $stmt = $pdo->prepare("
                SELECT ID, SerialNumber, Status, UpdatedAt, '$type' as component_type
                FROM $table ORDER BY UpdatedAt DESC LIMIT $limit
            ");
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            foreach ($results as $result) {
                $activities[] = [
                    'id' => $result['ID'],
                    'component_type' => $result['component_type'],
                    'serial_number' => $result['SerialNumber'],
                    'status' => $result['Status'],
                    'updated_at' => $result['UpdatedAt'],
                    'action' => 'Updated'
                ];
            }
        }
        
        usort($activities, function($a, $b) {
            return strtotime($b['updated_at']) - strtotime($a['updated_at']);
        });
        
        return array_slice($activities, 0, $limit);
        
    } catch (PDOException $e) {
        error_log("Error getting recent activity: " . $e->getMessage());
        return [];
    }
}

function getWarrantyAlertsWithACL($pdo, $days = 90) {
    try {
        $alerts = [];
        $tables = [
            'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
        ];
        
        $alertDate = date('Y-m-d', strtotime("+$days days"));
        $acl = getCurrentUserACL($pdo);
        
        foreach ($tables as $type => $table) {
            // Only get alerts for components user can read
            if (!$acl->hasPermission($type, 'read')) {
                continue;
            }
            
            $stmt = $pdo->prepare("
                SELECT ID, SerialNumber, WarrantyEndDate, '$type' as component_type
                FROM $table 
                WHERE WarrantyEndDate IS NOT NULL 
                AND WarrantyEndDate <= :alert_date
                AND Status != 0
                ORDER BY WarrantyEndDate ASC
            ");
            $stmt->bindParam(':alert_date', $alertDate);
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            foreach ($results as $result) {
                $daysUntilExpiry = floor((strtotime($result['WarrantyEndDate']) - time()) / (60 * 60 * 24));
                $alerts[] = [
                    'id' => $result['ID'],
                    'component_type' => $result['component_type'],
                    'serial_number' => $result['SerialNumber'],
                    'warranty_end_date' => $result['WarrantyEndDate'],
                    'days_until_expiry' => $daysUntilExpiry,
                    'severity' => $daysUntilExpiry <= 30 ? 'high' : ($daysUntilExpiry <= 60 ? 'medium' : 'low')
                ];
            }
        }
        
        return $alerts;
        
    } catch (PDOException $e) {
        error_log("Error getting warranty alerts: " . $e->getMessage());
        return [];
    }
}

function searchComponentsWithACL($pdo, $query, $componentType = 'all', $limit = 20) {
    $results = [];
    $totalFound = 0;
    $accessibleCount = 0;
    
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory', 
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    $searchTables = [];
    if ($componentType === 'all') {
        $searchTables = $tableMap;
    } elseif (isset($tableMap[$componentType])) {
        $searchTables = [$componentType => $tableMap[$componentType]];
    }
    
    $acl = getCurrentUserACL($pdo);
    
    foreach ($searchTables as $type => $table) {
        // Only search in components user can read
        if (!$acl->hasPermission($type, 'read')) {
            continue;
        }
        
        try {
            $searchQuery = "
                SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition,
                       PurchaseDate, WarrantyEndDate, Flag, Notes, CreatedAt, UpdatedAt,
                       '$type' as component_type
                FROM $table 
                WHERE SerialNumber LIKE :query 
                   OR UUID LIKE :query 
                   OR Location LIKE :query 
                   OR RackPosition LIKE :query 
                   OR Flag LIKE :query 
                   OR Notes LIKE :query
            ";
            
            // Add NIC-specific search fields
            if ($type === 'nic') {
                $searchQuery = "
                    SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition,
                           MacAddress, IPAddress, NetworkName, PurchaseDate, WarrantyEndDate, 
                           Flag, Notes, CreatedAt, UpdatedAt, '$type' as component_type
                    FROM $table 
                    WHERE SerialNumber LIKE :query 
                       OR UUID LIKE :query 
                       OR Location LIKE :query 
                       OR RackPosition LIKE :query 
                       OR Flag LIKE :query 
                       OR Notes LIKE :query
                       OR MacAddress LIKE :query 
                       OR IPAddress LIKE :query 
                       OR NetworkName LIKE :query
                ";
            }
            
            $searchQuery .= " ORDER BY CreatedAt DESC LIMIT :limit";
            
            $stmt = $pdo->prepare($searchQuery);
            $searchTerm = '%' . $query . '%';
            $stmt->bindParam(':query', $searchTerm);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $componentResults = $stmt->fetchAll();
            $totalFound += count($componentResults);
            $accessibleCount += count($componentResults);
            $results = array_merge($results, $componentResults);
            
        } catch (PDOException $e) {
            error_log("Search error for $type: " . $e->getMessage());
        }
    }
    
    // Sort results by relevance and limit
    usort($results, function($a, $b) use ($query) {
        $aExact = (stripos($a['SerialNumber'], $query) !== false) ? 1 : 0;
        $bExact = (stripos($b['SerialNumber'], $query) !== false) ? 1 : 0;
        
        if ($aExact !== $bExact) {
            return $bExact - $aExact;
        }
        
        return strtotime($b['UpdatedAt']) - strtotime($a['UpdatedAt']);
    });
    
    return [
        'components' => array_slice($results, 0, $limit),
        'total_found' => $totalFound,
        'accessible_count' => $accessibleCount
    ];
}

function loadComponentOptions($type) {
    $components = [];
    
    switch($type) {
        case 'cpu':
            $jsonFile = __DIR__ . '/../All JSON/cpu jsons/Cpu details level 3.json';
            break;
        case 'motherboard':
            $jsonFile = __DIR__ . '/../All JSON/motherboad jsons/motherboard level 3.json';
            break;
        case 'ram':
            $jsonFile = __DIR__ . '/../All JSON/Ram JSON/ram_detail.json';
            break;
        case 'storage':
            $jsonFile = __DIR__ . '/../All JSON/storage jsons/storagedetail.json';
            break;
        case 'caddy':
            $jsonFile = __DIR__ . '/../All JSON/caddy json/caddy_details.json';
            break;
        case 'nic':
            return [];
        default:
            return [];
    }
    
    if (file_exists($jsonFile)) {
        try {
            $jsonContent = file_get_contents($jsonFile);
            $data = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error for $type: " . json_last_error_msg());
                return [];
            }
            
            // Process data based on component type
            if ($type == 'cpu') {
                foreach ($data as $brand) {
                    if (isset($brand['models'])) {
                        foreach ($brand['models'] as $model) {
                            $components[] = [
                                'uuid' => $model['UUID'] ?? '',
                                'name' => $model['model'],
                                'brand' => $brand['brand'],
                                'series' => $brand['series'],
                                'details' => [
                                    'Architecture' => $model['architecture'] ?? 'N/A',
                                    'Cores' => $model['cores'] ?? 'N/A',
                                    'Threads' => $model['threads'] ?? 'N/A',
                                    'Base Frequency' => ($model['base_frequency_GHz'] ?? 'N/A') . ' GHz',
                                    'Max Frequency' => ($model['max_frequency_GHz'] ?? 'N/A') . ' GHz',
                                    'TDP' => ($model['tdp_W'] ?? 'N/A') . 'W',
                                    'Socket' => $model['socket'] ?? 'N/A'
                                ]
                            ];
                        }
                    }
                }
            } elseif ($type == 'motherboard') {
                foreach ($data as $brand) {
                    if (isset($brand['models'])) {
                        foreach ($brand['models'] as $model) {
                            $components[] = [
                                'uuid' => $model['inventory']['UUID'] ?? '',
                                'name' => $model['model'],
                                'brand' => $brand['brand'],
                                'series' => $brand['series'],
                                'details' => [
                                    'Form Factor' => $model['form_factor'] ?? 'N/A',
                                    'Socket' => $model['socket']['type'] ?? 'N/A',
                                    'Chipset' => $model['chipset'] ?? 'N/A',
                                    'Memory Type' => $model['memory']['type'] ?? 'N/A',
                                    'Max Memory' => ($model['memory']['max_capacity_TB'] ?? 'N/A') . ' TB',
                                    'Memory Slots' => $model['memory']['slots'] ?? 'N/A'
                                ]
                            ];
                        }
                    }
                }
            } elseif ($type == 'ram') {
                if (isset($data['name']) && is_array($data['name'])) {
                    foreach ($data['name'] as $ram) {
                        $components[] = [
                            'uuid' => $ram['UUID'] ?? '',
                            'name' => $ram['manufacturer'] . ' ' . $ram['part_number'],
                            'brand' => $ram['manufacturer'],
                            'series' => $ram['type'] . ' ' . $ram['subtype'],
                            'details' => [
                                'Type' => $ram['type'] ?? 'N/A',
                                'Size' => ($ram['size'] ?? 'N/A') . 'GB',
                                'Frequency' => ($ram['frequency_MHz'] ?? 'N/A') . ' MHz',
                                'Latency' => $ram['Latency'] ?? 'N/A',
                                'Form Factor' => $ram['Form_Factor'] ?? 'N/A',
                                'ECC' => $ram['ECC'] ?? 'N/A'
                            ]
                        ];
                    }
                }
            } elseif ($type == 'storage') {
                if (isset($data['storage_specifications'])) {
                    foreach ($data['storage_specifications'] as $storage) {
                        $uuid = md5($storage['name'] . time() . rand());
                        $components[] = [
                            'uuid' => $uuid,
                            'name' => $storage['name'],
                            'brand' => 'Generic',
                            'series' => $storage['interface'] ?? '',
                            'details' => [
                                'Interface' => $storage['interface'] ?? 'N/A',
                                'Capacities' => implode(', ', array_map(function($cap) { return $cap . 'GB'; }, $storage['capacity_GB'] ?? [])),
                                'Read Speed' => ($storage['read_speed_MBps'] ?? 'N/A') . ' MB/s',
                                'Write Speed' => ($storage['write_speed_MBps'] ?? 'N/A') . ' MB/s'
                            ]
                        ];
                    }
                }
            } elseif ($type == 'caddy') {
                if (isset($data['caddies'])) {
                    foreach ($data['caddies'] as $caddy) {
                        $uuid = md5($caddy['model'] . time() . rand());
                        $components[] = [
                            'uuid' => $uuid,
                            'name' => $caddy['model'],
                            'brand' => 'Generic',
                            'series' => $caddy['compatibility']['drive_type'][0] ?? '',
                            'details' => [
                                'Drive Type' => implode(', ', $caddy['compatibility']['drive_type'] ?? []),
                                'Size' => $caddy['compatibility']['size'] ?? 'N/A',
                                'Interface' => $caddy['compatibility']['interface'] ?? 'N/A',
                                'Material' => $caddy['material'] ?? 'N/A',
                                'Weight' => $caddy['weight'] ?? 'N/A'
                            ]
                        ];
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Error processing $type JSON: " . $e->getMessage());
        }
    }
    
    return $components;
}

// ===========================================================================
// UTILITY FUNCTIONS
// ===========================================================================

/**
 * Health check endpoint for monitoring
 */
function handleHealthCheck($pdo) {
    $startTime = microtime(true);
    $health = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'version' => '2.0.0',
        'checks' => []
    ];
    
    // Database connectivity check
    try {
        $stmt = $pdo->query("SELECT 1");
        $health['checks']['database'] = [
            'status' => 'healthy',
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];
    } catch (Exception $e) {
        $health['status'] = 'unhealthy';
        $health['checks']['database'] = [
            'status' => 'unhealthy',
            'error' => 'Database connection failed'
        ];
    }
    
    // File system check
    $uploadsDir = __DIR__ . '/../uploads/';
    $health['checks']['filesystem'] = [
        'status' => is_writable($uploadsDir) ? 'healthy' : 'unhealthy',
        'uploads_writable' => is_writable($uploadsDir)
    ];
    
    // Memory usage check
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $health['checks']['memory'] = [
        'status' => 'healthy',
        'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
        'limit' => $memoryLimit
    ];
    
    $health['total_response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
    
    http_response_code($health['status'] === 'healthy' ? 200 : 503);
    echo json_encode($health, JSON_PRETTY_PRINT);
    exit;
}

// ===========================================================================
// SPECIAL ENDPOINTS
// ===========================================================================

// Handle special endpoints
if (isset($_GET['endpoint'])) {
    switch ($_GET['endpoint']) {
        case 'health':
            handleHealthCheck($pdo);
            break;
            
        case 'csrf':
            session_start();
            echo json_encode([
                'csrf_token' => generateCSRFToken()
            ]);
            exit;
            
        default:
            send_json_response(0, 0, 404, "Endpoint not found");
    }
}

// ===========================================================================
// ERROR HANDLING AND CLEANUP
// ===========================================================================

/**
 * Global error handler for uncaught exceptions
 */
function handleUncaughtException($exception) {
    error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'is_logged_in' => isset($_SESSION['id']) ? 1 : 0,
            'status_code' => 500,
            'success' => false,
            'message' => 'Internal server error occurred',
            'timestamp' => date('c')
        ]);
    }
    exit;
}

set_exception_handler('handleUncaughtException');

/**
 * Cleanup function called at script end
 */
function cleanup() {
    global $pdo;
    
    // Close database connection
    $pdo = null;
    
    // Clean up temporary files if any
    $tempDir = sys_get_temp_dir() . '/bdc_ims_*';
    foreach (glob($tempDir) as $tempFile) {
        if (is_file($tempFile) && filemtime($tempFile) < (time() - 3600)) { // 1 hour old
            unlink($tempFile);
        }
    }
}

register_shutdown_function('cleanup');

// Log successful API initialization
error_log("BDC IMS API with ACL initialized successfully at " . date('Y-m-d H:i:s'));

?>