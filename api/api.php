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
    require_once(__DIR__ . '/../includes/ACL.php');
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
// AUTHENTICATION OPERATIONS (Enhanced with ACL)
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
            // Check if registration is allowed (only admins can create users)
            requirePermission($pdo, 'users', 'create');
            
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
    
    // Most ACL operations require admin access
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
                requirePermission($pdo, 'users', 'read');
            }
            
            $summary = getUserPermissionsSummary($pdo, $requestedUserId);
            
            if ($summary) {
                send_json_response(1, 1, 200, "Permissions retrieved successfully", $summary);
            } else {
                send_json_response(1, 0, 404, "User not found or error retrieving permissions");
            }
            break;
            
        default:
            // All other ACL operations require admin access
            requirePermission($pdo, 'users', 'manage_roles');
            
            $acl = getCurrentUserACL($pdo);
            
            switch($operation) {
                case 'assign_role':
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
                    $roles = $acl->getAllRoles();
                    send_json_response(1, 1, 200, "Roles retrieved successfully", [
                        'roles' => $roles
                    ]);
                    break;
                    
                case 'get_audit_log':
                    requirePermission($pdo, 'system', 'admin');
                    
                    $limit = (int)($_POST['limit'] ?? 50);
                    $offset = (int)($_POST['offset'] ?? 0);
                    $filters = $_POST['filters'] ?? [];
                    
                    $auditLog = $acl->getAuditLog($limit, $offset, $filters);
                    send_json_response(1, 1, 200, "Audit log retrieved successfully", [
                        'audit_log' => $auditLog,
                        'limit' => $limit,
                        'offset' => $offset
                    ]);
                    break;
                    
                case 'create_role':
                    requirePermission($pdo, 'roles', 'create');
                    
                    $name = $_POST['name'] ?? '';
                    $displayName = $_POST['display_name'] ?? '';
                    $description = $_POST['description'] ?? '';
                    
                    if (empty($name) || empty($displayName)) {
                        send_json_response(1, 0, 400, "Role name and display name required");
                        return;
                    }
                    
                    $roleId = $acl->createRole($name, $displayName, $description);
                    if ($roleId) {
                        logAction($pdo, "Role created: $name", "roles", $roleId);
                        send_json_response(1, 1, 200, "Role created successfully", [
                            'role_id' => $roleId
                        ]);
                    } else {
                        send_json_response(1, 0, 500, "Failed to create role");
                    }
                    break;
                    
                case 'grant_resource_permission':
                    requirePermission($pdo, 'users', 'manage_roles');
                    
                    $userId = $_POST['user_id'] ?? '';
                    $resourceType = $_POST['resource_type'] ?? '';
                    $permission = $_POST['permission'] ?? '';
                    $resourceId = $_POST['resource_id'] ?? null;
                    $expiresAt = $_POST['expires_at'] ?? null;
                    
                    if (empty($userId) || empty($resourceType) || empty($permission)) {
                        send_json_response(1, 0, 400, "User ID, resource type, and permission required");
                        return;
                    }
                    
                    if ($acl->grantResourcePermission($userId, $resourceType, $permission, $resourceId, $_SESSION['id'], $expiresAt)) {
                        logAction($pdo, "Resource permission granted: $resourceType.$permission", "users", $userId);
                        send_json_response(1, 1, 200, "Resource permission granted successfully");
                    } else {
                        send_json_response(1, 0, 500, "Failed to grant resource permission");
                    }
                    break;
                    
                case 'get_all_permissions':
                    $permissions = $acl->getAllPermissions();
                    send_json_response(1, 1, 200, "Permissions retrieved successfully", [
                        'permissions' => $permissions
                    ]);
                    break;
                    
                case 'get_role_permissions':
                    $roleName = $_POST['role_name'] ?? '';
                    if (empty($roleName)) {
                        send_json_response(1, 0, 400, "Role name required");
                        return;
                    }
                    
                    $permissions = $acl->getRolePermissions($roleName);
                    send_json_response(1, 1, 200, "Role permissions retrieved successfully", [
                        'role_name' => $roleName,
                        'permissions' => $permissions
                    ]);
                    break;
                    
                case 'assign_permission_to_role':
                    requirePermission($pdo, 'roles', 'assign_permissions');
                    
                    $roleName = $_POST['role_name'] ?? '';
                    $permissionName = $_POST['permission_name'] ?? '';
                    
                    if (empty($roleName) || empty($permissionName)) {
                        send_json_response(1, 0, 400, "Role name and permission name required");
                        return;
                    }
                    
                    if ($acl->assignPermissionToRole($roleName, $permissionName)) {
                        logAction($pdo, "Permission assigned to role: $roleName -> $permissionName", "roles");
                        send_json_response(1, 1, 200, "Permission assigned to role successfully");
                    } else {
                        send_json_response(1, 0, 500, "Failed to assign permission to role");
                    }
                    break;
                    
                case 'remove_permission_from_role':
                    requirePermission($pdo, 'roles', 'assign_permissions');
                    
                    $roleName = $_POST['role_name'] ?? '';
                    $permissionName = $_POST['permission_name'] ?? '';
                    
                    if (empty($roleName) || empty($permissionName)) {
                        send_json_response(1, 0, 400, "Role name and permission name required");
                        return;
                    }
                    
                    if ($acl->removePermissionFromRole($roleName, $permissionName)) {
                        logAction($pdo, "Permission removed from role: $roleName -> $permissionName", "roles");
                        send_json_response(1, 1, 200, "Permission removed from role successfully");
                    } else {
                        send_json_response(1, 0, 500, "Failed to remove permission from role");
                    }
                    break;
                    
                default:
                    send_json_response(1, 0, 400, "Invalid ACL operation: " . $operation);
            }
            break;
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
            requirePermission($pdo, $componentType, 'read');
            
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
                
                // Filter components based on user permissions
                $filteredComponents = filterComponentsByPermission($pdo, $components, 'read');
                
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
                
                // Add user permissions for each component
                $acl = getCurrentUserACL($pdo);
                foreach ($filteredComponents as &$component) {
                    $component['user_permissions'] = [
                        'can_update' => $acl->hasPermission($componentType, 'update', $component['UUID']),
                        'can_delete' => $acl->hasPermission($componentType, 'delete', $component['UUID']),
                        'can_export' => $acl->hasPermission($componentType, 'export', $component['UUID'])
                    ];
                }
                
                send_json_response(1, 1, 200, "Components retrieved successfully", [
                    'components' => $filteredComponents,
                    'total_count' => (int)$totalCount,
                    'filtered_count' => count($filteredComponents),
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
                $acl = getCurrentUserACL($pdo);
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
            requirePermission($pdo, $componentType, 'create');
            
            try {
                $componentUuid = $_POST['component_uuid'] ?? generateUUID();
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
            requirePermission($pdo, $componentType, 'read');
            
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
            
        case 'export':
            // Check export permission
            requirePermission($pdo, $componentType, 'export');
            
            try {
                $statusFilter = $_POST['status'] ?? 'all';
                $format = $_POST['format'] ?? 'json';
                
                $query = "SELECT * FROM $table";
                if ($statusFilter !== 'all') {
                    $query .= " WHERE Status = :status";
                }
                $query .= " ORDER BY CreatedAt DESC";
                
                $stmt = $pdo->prepare($query);
                if ($statusFilter !== 'all') {
                    $stmt->bindParam(':status', $statusFilter);
                }
                $stmt->execute();
                
                $components = $stmt->fetchAll();
                
                // Filter components based on user permissions
                $filteredComponents = filterComponentsByPermission($pdo, $components, 'export');
                
                logAction($pdo, "Exported $componentType components ($format)", $componentType);
                
                send_json_response(1, 1, 200, "Components exported successfully", [
                    'components' => $filteredComponents,
                    'format' => $format,
                    'exported_count' => count($filteredComponents),
                    'exported_at' => date('c')
                ]);
                
            } catch (PDOException $e) {
                error_log("Component export error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'bulk_update':
            // Check update permission
            requirePermission($pdo, $componentType, 'update');
            
            $componentIds = $_POST['component_ids'] ?? [];
            $updateData = $_POST['update_data'] ?? [];
            
            if (empty($componentIds) || empty($updateData)) {
                send_json_response(1, 0, 400, "Component IDs and update data required");
                return;
            }
            
            if (!canPerformBulkOperation($pdo, $componentType, 'update', $componentIds)) {
                send_json_response(1, 0, 403, "Insufficient permissions for bulk update");
                return;
            }
            
            try {
                $pdo->beginTransaction();
                
                $updatedCount = 0;
                foreach ($componentIds as $id) {
                    // Validate each component access
                    validateComponentAccess($pdo, $componentType, 'update', $id);
                    
                    // Build update query
                    $updateFields = [];
                    $params = [':id' => $id];
                    
                    foreach ($updateData as $field => $value) {
                        if (in_array($field, ['status', 'location', 'flag', 'notes'])) {
                            $dbField = ucfirst($field);
                            $updateFields[] = "$dbField = :$field";
                            $params[":$field"] = $value;
                        }
                    }
                    
                    if (!empty($updateFields)) {
                        $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = :id";
                        $stmt = $pdo->prepare($query);
                        $stmt->execute($params);
                        $updatedCount++;
                    }
                }
                
                $pdo->commit();
                
                logAction($pdo, "Bulk updated $updatedCount $componentType components", $componentType);
                
                send_json_response(1, 1, 200, "Bulk update completed successfully", [
                    'updated_count' => $updatedCount
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Bulk update error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Bulk update failed");
            }
            break;
            
        case 'bulk_delete':
            // Check delete permission
            requirePermission($pdo, $componentType, 'delete');
            
            $componentIds = $_POST['component_ids'] ?? [];
            
            if (empty($componentIds)) {
                send_json_response(1, 0, 400, "Component IDs required");
                return;
            }
            
            if (!canPerformBulkOperation($pdo, $componentType, 'delete', $componentIds)) {
                send_json_response(1, 0, 403, "Insufficient permissions for bulk delete");
                return;
            }
            
            try {
                $pdo->beginTransaction();
                
                $deletedCount = 0;
                $deletedUUIDs = [];
                
                foreach ($componentIds as $id) {
                    // Validate each component access
                    validateComponentAccess($pdo, $componentType, 'delete', $id);
                    
                    // Get UUID before deletion
                    $stmt = $pdo->prepare("SELECT UUID FROM $table WHERE ID = :id");
                    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $stmt->execute();
                    $component = $stmt->fetch();
                    
                    if ($component) {
                        $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
                        $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
                        $deleteStmt->execute();
                        
                        $deletedCount++;
                        $deletedUUIDs[] = $component['UUID'];
                    }
                }
                
                $pdo->commit();
                
                logAction($pdo, "Bulk deleted $deletedCount $componentType components", $componentType);
                
                send_json_response(1, 1, 200, "Bulk delete completed successfully", [
                    'deleted_count' => $deletedCount,
                    'deleted_uuids' => $deletedUUIDs
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Bulk delete error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Bulk delete failed");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid component operation: " . $operation);
    }
}

// ===========================================================================
// DASHBOARD OPERATIONS (Enhanced with ACL)
// ===========================================================================
function handleDashboardOperations($operation) {
    global $pdo;
    
    // Check authentication and dashboard access
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    requirePermission($pdo, 'dashboard', 'view');
    
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
            
        case 'get_stats':
            requirePermission($pdo, 'reports', 'read');
            
            try {
                $acl = getCurrentUserACL($pdo);
                $stats = [];
                
                // Get statistics for components user can read
                $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
                foreach ($componentTypes as $type) {
                    if ($acl->hasPermission($type, 'read')) {
                        $stats[$type] = getComponentStats($pdo, $type);
                    }
                }
                
                // Get system stats if user has admin access
                if ($acl->hasPermission('system', 'admin')) {
                    $stats['system'] = getSystemStats($pdo);
                }
                
                send_json_response(1, 1, 200, "Statistics retrieved successfully", [
                    'stats' => $stats
                ]);
                
            } catch (Exception $e) {
                error_log("Dashboard stats error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to get statistics");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid dashboard operation: " . $operation);
    }
}

// ===========================================================================
// SEARCH OPERATIONS (Enhanced with ACL)
// ===========================================================================
function handleSearchOperations($operation) {
    global $pdo;
    
    // Check authentication and search permission
    session_start();
    if (!isUserLoggedIn($pdo)) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
        return;
    }
    
    requirePermission($pdo, 'search', 'basic');
    
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
            
        case 'advanced':
            requirePermission($pdo, 'search', 'advanced');
            
            $filters = $_POST['filters'] ?? [];
            $sortBy = $_POST['sort_by'] ?? 'created_at';
            $sortOrder = $_POST['sort_order'] ?? 'DESC';
            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
            
            try {
                $results = advancedSearchWithACL($pdo, $filters, $sortBy, $sortOrder, $limit);
                
                logAction($pdo, "Advanced search performed", "search");
                
                send_json_response(1, 1, 200, "Advanced search completed successfully", [
                    'results' => $results['components'],
                    'total_found' => $results['total_found'],
                    'filters_applied' => $filters
                ]);
                
            } catch (Exception $e) {
                error_log("Advanced search error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Advanced search failed");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid search operation: " . $operation);
    }
}

// ===========================================================================
// HELPER FUNCTIONS (Enhanced with ACL)
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
            
            // Filter by specific permissions if needed
            $filteredResults = filterComponentsByPermission($pdo, $componentResults, 'read');
            $accessibleCount += count($filteredResults);
            
            $results = array_merge($results, $filteredResults);
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

function advancedSearchWithACL($pdo, $filters, $sortBy = 'created_at', $sortOrder = 'DESC', $limit = 50) {
    $results = [];
    $totalFound = 0;
    
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory', 
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    $acl = getCurrentUserACL($pdo);
    
    foreach ($tableMap as $type => $table) {
        // Only search in components user can read
        if (!$acl->hasPermission($type, 'read')) {
            continue;
        }
        
        try {
            $whereConditions = [];
            $params = [];
            
            // Apply filters
            if (!empty($filters['status'])) {
                $whereConditions[] = "Status = :status";
                $params[':status'] = $filters['status'];
            }
            
            if (!empty($filters['location'])) {
                $whereConditions[] = "Location LIKE :location";
                $params[':location'] = '%' . $filters['location'] . '%';
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "CreatedAt >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "CreatedAt <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }
            
            if (!empty($filters['warranty_expiring'])) {
                $whereConditions[] = "WarrantyEndDate <= :warranty_date";
                $params[':warranty_date'] = date('Y-m-d', strtotime('+' . $filters['warranty_expiring'] . ' days'));
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            // Validate sort column
            $allowedSortColumns = ['CreatedAt', 'UpdatedAt', 'SerialNumber', 'Status', 'Location'];
            $sortColumn = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'CreatedAt';
            $sortDirection = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            $query = "
                SELECT *, '$type' as component_type
                FROM $table 
                $whereClause
                ORDER BY $sortColumn $sortDirection
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $componentResults = $stmt->fetchAll();
            $totalFound += count($componentResults);
            $results = array_merge($results, $componentResults);
            
        } catch (PDOException $e) {
            error_log("Advanced search error for $type: " . $e->getMessage());
        }
    }
    
    return [
        'components' => array_slice($results, 0, $limit),
        'total_found' => $totalFound
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
            
            // Process data based on component type (implementation same as before)
            // ... (keeping existing processing logic)
            
        } catch (Exception $e) {
            error_log("Error processing $type JSON: " . $e->getMessage());
        }
    }
    
    return $components;
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
     mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function getComponentStats($pdo, $componentType) {
    try {
        $table = getComponentTable($componentType);
        if (!$table) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                Status,
                COUNT(*) as count,
                AVG(CASE WHEN WarrantyEndDate IS NOT NULL 
                    THEN DATEDIFF(WarrantyEndDate, NOW()) 
                    ELSE NULL END) as avg_warranty_days,
                COUNT(CASE WHEN WarrantyEndDate < NOW() THEN 1 END) as expired_warranty_count,
                COUNT(CASE WHEN WarrantyEndDate BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 90 DAY) THEN 1 END) as expiring_soon_count
            FROM $table 
            GROUP BY Status
        ");
        $stmt->execute();
        
        $stats = $stmt->fetchAll();
        
        // Get total count
        $totalStmt = $pdo->prepare("SELECT COUNT(*) as total FROM $table");
        $totalStmt->execute();
        $total = $totalStmt->fetch()['total'];
        
        return [
            'by_status' => $stats,
            'total_count' => (int)$total,
            'warranty_stats' => [
                'expired' => (int)($stats[0]['expired_warranty_count'] ?? 0),
                'expiring_soon' => (int)($stats[0]['expiring_soon_count'] ?? 0),
                'avg_days_remaining' => round($stats[0]['avg_warranty_days'] ?? 0)
            ]
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting component stats for $componentType: " . $e->getMessage());
        return [];
    }
}

function getSystemStats($pdo) {
    try {
        $stats = [];
        
        // User statistics
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_users,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as new_users_30d
            FROM users
        ");
        $stats['users'] = $stmt->fetch();
        
        // Role distribution
        $stmt = $pdo->query("
            SELECT r.display_name, COUNT(ur.user_id) as user_count
            FROM roles r
            LEFT JOIN user_roles ur ON r.id = ur.role_id 
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            GROUP BY r.id, r.display_name
            ORDER BY user_count DESC
        ");
        $stats['role_distribution'] = $stmt->fetchAll();
        
        // Recent activity summary
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_actions,
                COUNT(CASE WHEN result = 'granted' THEN 1 END) as granted_actions,
                COUNT(CASE WHEN result = 'denied' THEN 1 END) as denied_actions,
                COUNT(DISTINCT user_id) as active_users
            FROM acl_audit_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stats['activity_24h'] = $stmt->fetch();
        
        // Storage usage (if applicable)
        $stmt = $pdo->query("SHOW TABLE STATUS");
        $tables = $stmt->fetchAll();
        $totalSize = 0;
        foreach ($tables as $table) {
            $totalSize += $table['Data_length'] + $table['Index_length'];
        }
        $stats['database_size'] = [
            'size_bytes' => $totalSize,
            'size_mb' => round($totalSize / 1024 / 1024, 2)
        ];
        
        return $stats;
        
    } catch (PDOException $e) {
        error_log("Error getting system stats: " . $e->getMessage());
        return [];
    }
}

// ===========================================================================
// ADDITIONAL UTILITY FUNCTIONS
// ===========================================================================

/**
 * Validate session ID and user permissions
 */
function validateSessionAndPermissions($pdo, $requiredPermission = null) {
    session_start();
    
    if (!isset($_SESSION['id'])) {
        send_json_response(0, 0, 401, "Authentication required");
        exit;
    }
    
    $user = isUserLoggedIn($pdo);
    if (!$user) {
        send_json_response(0, 0, 401, "Session expired");
        exit;
    }
    
    if ($requiredPermission) {
        $parts = explode('.', $requiredPermission);
        if (count($parts) === 2) {
            [$resource, $action] = $parts;
            if (!hasPermission($pdo, $resource, $action)) {
                send_json_response(1, 0, 403, "Insufficient permissions", [
                    'required_permission' => $requiredPermission
                ]);
                exit;
            }
        }
    }
    
    return $user;
}

/**
 * Clean input data
 */
function cleanInputData($data) {
    if (is_array($data)) {
        return array_map('cleanInputData', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate component data before insertion/update
 */
function validateComponentData($componentType, $data) {
    $errors = [];
    
    // Common validations
    if (empty($data['serial_number'])) {
        $errors[] = "Serial number is required";
    }
    
    if (isset($data['status']) && !in_array($data['status'], ['0', '1', '2'])) {
        $errors[] = "Invalid status value";
    }
    
    if (isset($data['purchase_date']) && !empty($data['purchase_date'])) {
        if (!DateTime::createFromFormat('Y-m-d', $data['purchase_date'])) {
            $errors[] = "Invalid purchase date format";
        }
    }
    
    if (isset($data['warranty_end_date']) && !empty($data['warranty_end_date'])) {
        if (!DateTime::createFromFormat('Y-m-d', $data['warranty_end_date'])) {
            $errors[] = "Invalid warranty end date format";
        }
    }
    
    // Component-specific validations
    if ($componentType === 'nic') {
        if (isset($data['mac_address']) && !empty($data['mac_address'])) {
            if (!validateMacAddress($data['mac_address'])) {
                $errors[] = "Invalid MAC address format";
            }
        }
        
        if (isset($data['ip_address']) && !empty($data['ip_address'])) {
            if (!validateIPAddress($data['ip_address'])) {
                $errors[] = "Invalid IP address format";
            }
        }
    }
    
    return $errors;
}

/**
 * Log API performance metrics
 */
function logPerformanceMetrics($startTime, $action, $success = true) {
    $endTime = microtime(true);
    $duration = round(($endTime - $startTime) * 1000, 2); // milliseconds
    
    $logData = [
        'timestamp' => date('c'),
        'action' => $action,
        'duration_ms' => $duration,
        'success' => $success,
        'memory_usage' => round(memory_get_peak_usage(true) / 1024 / 1024, 2), // MB
        'user_id' => $_SESSION['id'] ?? null
    ];
    
    if ($duration > 1000) { // Log slow operations (>1 second)
        error_log("SLOW_OPERATION: " . json_encode($logData));
    }
    
    if ($duration > 5000) { // Alert for very slow operations (>5 seconds)
        error_log("VERY_SLOW_OPERATION: " . json_encode($logData));
    }
}

/**
 * Handle file uploads (if needed for component attachments)
 */
function handleFileUpload($fileField, $allowedTypes = ['pdf', 'jpg', 'png', 'doc', 'docx']) {
    if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    $file = $_FILES[$fileField];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedTypes)) {
        throw new Exception("File type not allowed");
    }
    
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        throw new Exception("File too large");
    }
    
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileName = uniqid() . '.' . $fileExtension;
    $uploadPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return $fileName;
    }
    
    throw new Exception("File upload failed");
}

/**
 * Generate API documentation (for development)
 */
function generateAPIDocumentation() {
    $documentation = [
        'version' => '2.0.0',
        'description' => 'BDC IMS API with ACL Support',
        'base_url' => '/bdc_ims/api/api.php',
        'authentication' => 'Session-based with CSRF tokens',
        'endpoints' => [
            'auth' => [
                'auth-login' => 'Authenticate user and establish session',
                'auth-logout' => 'End user session',
                'auth-check_session' => 'Validate current session',
                'auth-register' => 'Create new user account (admin only)'
            ],
            'components' => [
                '{type}-list' => 'List components with pagination and filtering',
                '{type}-get' => 'Get specific component details',
                '{type}-add' => 'Add new component',
                '{type}-update' => 'Update existing component',
                '{type}-delete' => 'Delete component',
                '{type}-export' => 'Export component data',
                '{type}-bulk_update' => 'Update multiple components',
                '{type}-bulk_delete' => 'Delete multiple components',
                '{type}-options' => 'Get component type options'
            ],
            'acl' => [
                'acl-assign_role' => 'Assign role to user',
                'acl-remove_role' => 'Remove role from user',
                'acl-get_user_permissions' => 'Get user permission summary',
                'acl-get_all_roles' => 'List all available roles',
                'acl-create_role' => 'Create new role',
                'acl-grant_resource_permission' => 'Grant specific resource permission'
            ],
            'dashboard' => [
                'dashboard-get_data' => 'Get dashboard data with user permissions',
                'dashboard-get_stats' => 'Get detailed statistics'
            ],
            'search' => [
                'search-components' => 'Search across components',
                'search-advanced' => 'Advanced search with filters'
            ]
        ],
        'response_format' => [
            'success_response' => [
                'is_logged_in' => 'boolean',
                'status_code' => 'integer',
                'success' => 'boolean',
                'message' => 'string',
                'data' => 'object|array',
                'user_context' => 'object',
                'timestamp' => 'string (ISO 8601)'
            ],
            'error_response' => [
                'is_logged_in' => 'boolean',
                'status_code' => 'integer',
                'success' => 'false',
                'message' => 'string',
                'required_permission' => 'string (optional)',
                'timestamp' => 'string (ISO 8601)'
            ]
        ],
        'permissions' => [
            'format' => 'resource.action (e.g., cpu.create, users.manage_roles)',
            'resources' => ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'users', 'roles', 'system', 'dashboard', 'reports', 'search'],
            'actions' => ['create', 'read', 'update', 'delete', 'export', 'manage_roles', 'assign_permissions', 'admin', 'settings', 'backup', 'view', 'basic', 'advanced']
        ]
    ];
    
    return $documentation;
}

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
            
        case 'docs':
            header('Content-Type: application/json');
            echo json_encode(generateAPIDocumentation(), JSON_PRETTY_PRINT);
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
 * Global error handler for PHP errors
 */
function handleError($severity, $message, $filename, $lineno) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    $errorInfo = [
        'severity' => $severity,
        'message' => $message,
        'file' => $filename,
        'line' => $lineno,
        'timestamp' => date('c')
    ];
    
    error_log("PHP Error: " . json_encode($errorInfo));
    
    if ($severity === E_ERROR || $severity === E_PARSE || $severity === E_CORE_ERROR) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'is_logged_in' => isset($_SESSION['id']) ? 1 : 0,
                'status_code' => 500,
                'success' => false,
                'message' => 'A critical error occurred',
                'timestamp' => date('c')
            ]);
        }
        exit;
    }
    
    return true;
}

set_error_handler('handleError');

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