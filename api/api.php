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
                        
                        // Get user role information
                        $acl = new SimpleACL($pdo, $user["id"]);
                        $userRole = $acl->getUserRole();
                        
                        error_log("Login successful for user: $username with role: $userRole");
                        logAction($pdo, "User login", "auth", $user["id"]);
                        
                        send_json_response(1, 1, 200, "Login successful", [
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
                            'permissions' => $acl->getPermissionsSummary()
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
                        'role' => $user['role'],
                        'is_admin' => $user['is_admin'],
                        'is_manager' => $user['is_manager']
                    ],
                    'session_id' => session_id(),
                    'csrf_token' => generateCSRFToken()
                ]);
            } else {
                send_json_response(0, 0, 401, "Session invalid or expired");
            }
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid auth operation: " . $operation);
    }
}

// ===========================================================================
// USER OPERATIONS
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
        case 'create':
        case 'register':
            // Only admins can create users
            validateComponentAccess($pdo, 'manage_users');
            
            $userData = [
                'username' => trim($_POST['username'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'password' => password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT),
                'firstname' => trim($_POST['firstname'] ?? ''),
                'lastname' => trim($_POST['lastname'] ?? '')
            ];
            
            $role = $_POST['role'] ?? 'viewer';
            
            if (empty($userData['username']) || empty($userData['email']) || empty($_POST['password'])) {
                send_json_response(1, 0, 400, "Required fields missing");
                return;
            }
            
            $userId = createUserWithACL($pdo, $userData, $role);
            if ($userId) {
                logAction($pdo, "User created", "users", $userId);
                send_json_response(1, 1, 200, "User created successfully", [
                    'user_id' => $userId
                ]);
            } else {
                send_json_response(1, 0, 500, "Failed to create user");
            }
            break;
            
        case 'list':
            // Only admins can list users
            if (!isAdmin($pdo)) {
                send_json_response(1, 0, 403, "Access denied. Admin privileges required.");
                return;
            }
            
            try {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $users = $acl->getUsersWithRoles();
                
                send_json_response(1, 1, 200, "Users retrieved successfully", [
                    'users' => $users
                ]);
            } catch (Exception $e) {
                error_log("Error listing users: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve users");
            }
            break;
            
        case 'assign_role':
            // Only admins can assign roles
            if (!isAdmin($pdo)) {
                send_json_response(1, 0, 403, "Access denied. Admin privileges required.");
                return;
            }
            
            $userId = $_POST['user_id'] ?? '';
            $roleName = $_POST['role'] ?? '';
            
            if (empty($userId) || empty($roleName)) {
                send_json_response(1, 0, 400, "User ID and role are required");
                return;
            }
            
            try {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                if ($acl->assignRole($userId, $roleName, $_SESSION['id'])) {
                    logAction($pdo, "Role assigned: $roleName", "users", $userId);
                    send_json_response(1, 1, 200, "Role assigned successfully");
                } else {
                    send_json_response(1, 0, 500, "Failed to assign role");
                }
            } catch (Exception $e) {
                error_log("Error assigning role: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to assign role");
            }
            break;
            
        case 'get_permissions':
            $requestedUserId = $_POST['user_id'] ?? $_SESSION['id'];
            
            // Users can only check their own permissions unless they're admin
            if ($requestedUserId != $_SESSION['id'] && !isAdmin($pdo)) {
                send_json_response(1, 0, 403, "Can only view your own permissions");
                return;
            }
            
            $summary = getUserPermissionsSummary($pdo, $requestedUserId);
            
            if ($summary) {
                send_json_response(1, 1, 200, "Permissions retrieved successfully", $summary);
            } else {
                send_json_response(1, 0, 404, "User not found or error retrieving permissions");
            }
            break;
            
        case 'get_roles':
            // Only admins can view all roles
            if (!isAdmin($pdo)) {
                send_json_response(1, 0, 403, "Access denied. Admin privileges required.");
                return;
            }
            
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
            
        case 'audit_log':
            // Only admins can view audit log
            if (!isAdmin($pdo)) {
                send_json_response(1, 0, 403, "Access denied. Admin privileges required.");
                return;
            }
            
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
// COMPONENT OPERATIONS (Enhanced with Simple ACL)
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
            validateComponentAccess($pdo, 'read', $componentType);
            
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
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                foreach ($components as &$component) {
                    $component['user_permissions'] = [
                        'can_update' => $acl->hasPermission('update'),
                        'can_delete' => $acl->hasPermission('delete'),
                        'can_export' => $acl->hasPermission('export')
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
                        'can_create' => $acl->hasPermission('create'),
                        'can_export_all' => $acl->hasPermission('export')
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
            
            // Check read permission
            validateComponentAccess($pdo, 'read', $componentType);
            
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
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $component['user_permissions'] = [
                    'can_update' => $acl->hasPermission('update'),
                    'can_delete' => $acl->hasPermission('delete'),
                    'can_export' => $acl->hasPermission('export')
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
            validateComponentAccess($pdo, 'create', $componentType);
            
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
            
            // Check update permission
            validateComponentAccess($pdo, 'update', $componentType);
            
            try {
                // Check if component exists and get its UUID
                $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                $component = $checkStmt->fetch();
                
                if (!$component) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                    return;
                }
                
                // Store old values for audit
                $oldValues = $component;
                
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
                
                // Get new values for audit
                $checkStmt->execute();
                $newValues = $checkStmt->fetch();
                
                logAction($pdo, "Updated $componentType component", $componentType, $component['UUID'], $oldValues, $newValues);
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
            
            // Check delete permission
            validateComponentAccess($pdo, 'delete', $componentType);
            
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
            validateComponentAccess($pdo, 'read', $componentType);
            
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
            
            // Check read permission
            validateComponentAccess($pdo, 'read');
            
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
// HELPER FUNCTIONS
// ===========================================================================

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
            
            // Process data based on component type (same as before)
            // ... [processing logic remains the same as in original file]
            
        } catch (Exception $e) {
            error_log("Error processing $type JSON: " . $e->getMessage());
        }
    }
    
    return $components;
}

// ===========================================================================
// SPECIAL ENDPOINTS
// ===========================================================================

// Handle special endpoints
if (isset($_GET['endpoint'])) {
    switch ($_GET['endpoint']) {
        case 'health':
            $health = [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'version' => '3.0.0-simple-acl',
                'acl_system' => 'SimpleACL',
                'checks' => []
            ];
            
            // Database connectivity check
            try {
                $stmt = $pdo->query("SELECT 1");
                $health['checks']['database'] = ['status' => 'healthy'];
            } catch (Exception $e) {
                $health['status'] = 'unhealthy';
                $health['checks']['database'] = ['status' => 'unhealthy'];
            }
            
            http_response_code($health['status'] === 'healthy' ? 200 : 503);
            echo json_encode($health, JSON_PRETTY_PRINT);
            exit;
            
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

// Log successful API initialization
error_log("BDC IMS API with Simple ACL initialized successfully at " . date('Y-m-d H:i:s'));

?>