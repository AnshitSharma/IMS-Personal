<?php
// ===========================================================================
// FILE: api/main_api.php (Complete Fixed Version)
// ===========================================================================

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to user
ini_set('log_errors', 1);

// Try to include required files with error checking
try {
    require_once(__DIR__ . '/../includes/db_config.php');
    require_once(__DIR__ . '/../includes/BaseFunctions.php');
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
                        
                        error_log("Login successful for user: $username");
                        
                        send_json_response(1, 1, 200, "Login successful", [
                            'user' => [
                                'id' => $user["id"],
                                'username' => $username,
                                'email' => $user["email"],
                                'firstname' => $user["firstname"],
                                'lastname' => $user["lastname"]
                            ],
                            'session_id' => session_id()
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
            $user = isUserLoggedIn($pdo);
            if ($user) {
                send_json_response(1, 1, 200, "Session valid", [
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'firstname' => $user['firstname'],
                        'lastname' => $user['lastname']
                    ],
                    'session_id' => session_id()
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
// DASHBOARD OPERATIONS
// ===========================================================================
function handleDashboardOperations($operation) {
    global $pdo;
    
    // Check authentication
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
                
                // Get component counts
                $componentCounts = getComponentCounts($pdo, $statusFilter);
                
                // Get counts by status
                $statusCounts = [
                    'available' => getComponentCounts($pdo, '1')['total'],
                    'in_use' => getComponentCounts($pdo, '2')['total'],
                    'failed' => getComponentCounts($pdo, '0')['total'],
                    'total' => getComponentCounts($pdo, 'all')['total']
                ];
                
                // Get recent activity
                $recentActivity = getRecentActivity($pdo, 10);
                
                // Get warranty alerts
                $warrantyAlerts = getWarrantyAlerts($pdo, 90);
                
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
                    ]
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
    
    // Check authentication
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
                $results = searchComponents($pdo, $query, $componentType, $limit);
                
                send_json_response(1, 1, 200, "Search completed successfully", [
                    'results' => $results,
                    'total_found' => count($results),
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
// COMPONENT OPERATIONS
// ===========================================================================
function handleComponentOperations($componentType, $operation) {
    global $pdo;
    
    // Check authentication
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
                    'has_more' => ($offset + $limit) < $totalCount
                ]);
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
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                
                $component = $stmt->fetch();
                if (!$component) {
                    send_json_response(1, 0, 404, "Component not found");
                    return;
                }
                
                send_json_response(1, 1, 200, "Component retrieved successfully", [
                    'component' => $component
                ]);
            } catch (PDOException $e) {
                error_log("Component get error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'add':
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
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(1, 0, 400, "Component ID is required");
                return;
            }
            
            try {
                // Check if component exists
                $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() == 0) {
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
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " updated successfully");
                
            } catch (PDOException $e) {
                error_log("Component update error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'delete':
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(1, 0, 400, "Component ID is required");
                return;
            }
            
            try {
                // Check if component exists
                $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() == 0) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                    return;
                }
                
                // Delete the component
                $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
                $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $deleteStmt->execute();
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " deleted successfully");
                
            } catch (PDOException $e) {
                error_log("Component delete error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'options':
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
// HELPER FUNCTIONS
// ===========================================================================

function getComponentCounts($pdo, $statusFilter = null) {
    $counts = [
        'cpu' => 0, 'ram' => 0, 'storage' => 0,
        'motherboard' => 0, 'nic' => 0, 'caddy' => 0, 'total' => 0
    ];
    
    $tables = [
        'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
    ];
    
    foreach ($tables as $key => $table) {
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

function getRecentActivity($pdo, $limit = 10) {
    try {
        $activities = [];
        $tables = [
            'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
        ];
        
        foreach ($tables as $type => $table) {
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

function getWarrantyAlerts($pdo, $days = 90) {
    try {
        $alerts = [];
        $tables = [
            'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
        ];
        
        $alertDate = date('Y-m-d', strtotime("+$days days"));
        
        foreach ($tables as $type => $table) {
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

function searchComponents($pdo, $query, $componentType = 'all', $limit = 20) {
    $results = [];
    
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
    
    foreach ($searchTables as $type => $table) {
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
            $results = array_merge($results, $componentResults);
        } catch (PDOException $e) {
            error_log("Search error for $type: " . $e->getMessage());
        }
    }
    
    // Sort results by relevance and limit
    usort($results, function($a, $b) {
        return strtotime($b['UpdatedAt']) - strtotime($a['UpdatedAt']);
    });
    
    return array_slice($results, 0, $limit);
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
            // For NIC, return empty array since there's no specific JSON file
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
                    if (isset($brand['models']) && is_array($brand['models'])) {
                        foreach ($brand['models'] as $model) {
                            $components[] = [
                                'uuid' => $model['UUID'] ?? '',
                                'name' => $model['model'] ?? 'Unknown Model',
                                'brand' => $brand['brand'] ?? 'Unknown Brand',
                                'series' => $brand['series'] ?? 'Unknown Series',
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
                    if (isset($brand['models']) && is_array($brand['models'])) {
                        foreach ($brand['models'] as $model) {
                            $components[] = [
                                'uuid' => $model['inventory']['UUID'] ?? generateUUID(),
                                'name' => $model['model'] ?? 'Unknown Model',
                                'brand' => $brand['brand'] ?? 'Unknown Brand',
                                'series' => $brand['series'] ?? 'Unknown Series',
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
                            'uuid' => $ram['UUID'] ?? generateUUID(),
                            'name' => ($ram['manufacturer'] ?? 'Unknown') . ' ' . ($ram['part_number'] ?? 'Unknown'),
                            'brand' => $ram['manufacturer'] ?? 'Unknown',
                            'series' => ($ram['type'] ?? 'Unknown') . ' ' . ($ram['subtype'] ?? ''),
                            'details' => [
                                'Type' => $ram['type'] ?? 'N/A',
                                'Size' => ($ram['size'] ?? 'N/A') . 'GB',
                                'Frequency' => ($ram['frequency_MHz'] ?? 'N/A') . ' MHz',
                                'Latency' => $ram['Latency'] ?? 'N/A',
                                'Form Factor' => $ram['Form_Factor'] ?? 'N/A',
                                'Voltage' => $ram['voltage'] ?? 'N/A',
                                'ECC' => $ram['ECC'] ?? 'N/A'
                            ]
                        ];
                    }
                }
            } elseif ($type == 'storage') {
                if (isset($data['storage_specifications'])) {
                    foreach ($data['storage_specifications'] as $storage) {
                        $uuid = generateUUID();
                        $components[] = [
                            'uuid' => $uuid,
                            'name' => $storage['name'] ?? 'Unknown Storage',
                            'brand' => 'Generic',
                            'series' => $storage['interface'] ?? 'Unknown Interface',
                            'details' => [
                                'Interface' => $storage['interface'] ?? 'N/A',
                                'Capacities' => isset($storage['capacity_GB']) ? 
                                    implode(', ', array_map(function($cap) { return $cap . 'GB'; }, $storage['capacity_GB'])) : 'N/A',
                                'Read Speed' => ($storage['read_speed_MBps'] ?? 'N/A') . ' MB/s',
                                'Write Speed' => ($storage['write_speed_MBps'] ?? 'N/A') . ' MB/s',
                                'Power (Idle)' => isset($storage['power_consumption_W']['idle']) ? 
                                    $storage['power_consumption_W']['idle'] . 'W' : 'N/A',
                                'Power (Active)' => isset($storage['power_consumption_W']['active']) ? 
                                    $storage['power_consumption_W']['active'] . 'W' : 'N/A'
                            ]
                        ];
                    }
                }
            } elseif ($type == 'caddy') {
                if (isset($data['caddies'])) {
                    foreach ($data['caddies'] as $caddy) {
                        $uuid = generateUUID();
                        $components[] = [
                            'uuid' => $uuid,
                            'name' => $caddy['model'] ?? 'Unknown Caddy',
                            'brand' => 'Generic',
                            'series' => isset($caddy['compatibility']['drive_type'][0]) ? 
                                $caddy['compatibility']['drive_type'][0] : 'Unknown',
                            'details' => [
                                'Drive Type' => isset($caddy['compatibility']['drive_type']) ? 
                                    implode(', ', $caddy['compatibility']['drive_type']) : 'N/A',
                                'Size' => $caddy['compatibility']['size'] ?? 'N/A',
                                'Interface' => $caddy['compatibility']['interface'] ?? 'N/A',
                                'Material' => $caddy['material'] ?? 'N/A',
                                'Weight' => $caddy['weight'] ?? 'N/A',
                                'Connector' => $caddy['connector'] ?? 'N/A'
                            ]
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error processing $type JSON: " . $e->getMessage());
        }
    } else {
        error_log("JSON file not found for $type at: $jsonFile");
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

// ===========================================================================
// ADDITIONAL UTILITY FUNCTIONS
// ===========================================================================

function validateSessionId($providedSessionId) {
    session_start();
    return $providedSessionId === session_id();
}

function logApiCall($action, $success, $message = '') {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'success' => $success,
        'message' => $message,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    error_log("API Call: " . json_encode($logData));
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validateComponentType($type) {
    $validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
    return in_array($type, $validTypes);
}

function validateStatus($status) {
    return in_array($status, ['0', '1', '2', 'all']);
}

function formatDate($date) {
    if (empty($date)) return null;
    
    $timestamp = strtotime($date);
    if ($timestamp === false) return null;
    
    return date('Y-m-d', $timestamp);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateMacAddress($mac) {
    return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac);
}

function validateIPAddress($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

// ===========================================================================
// ERROR HANDLING FUNCTIONS
// ===========================================================================

function handleDatabaseError($e, $operation = 'database operation') {
    error_log("Database error during $operation: " . $e->getMessage());
    
    // Don't expose detailed database errors to users
    if ($e->getCode() == 23000) {
        return "Duplicate entry - item already exists";
    } elseif ($e->getCode() == 23505) {
        return "Unique constraint violation";
    } else {
        return "Database error occurred";
    }
}

function validateRequiredFields($fields, $data) {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $missing[] = $field;
        }
    }
    return $missing;
}

// ===========================================================================
// RESPONSE HELPER FUNCTIONS
// ===========================================================================

function sendSuccessResponse($message, $data = []) {
    send_json_response(1, 1, 200, $message, $data);
}

function sendErrorResponse($code, $message, $data = []) {
    send_json_response(1, 0, $code, $message, $data);
}

function sendAuthErrorResponse($message = "Unauthorized") {
    send_json_response(0, 0, 401, $message);
}

// ===========================================================================
// PERFORMANCE MONITORING
// ===========================================================================

function startTimer() {
    return microtime(true);
}

function endTimer($start, $operation = 'operation') {
    $duration = microtime(true) - $start;
    error_log("Performance: $operation took " . number_format($duration * 1000, 2) . "ms");
    return $duration;
}

// ===========================================================================
// SECURITY FUNCTIONS
// ===========================================================================

function rateLimitCheck($identifier, $maxRequests = 100, $timeWindow = 3600) {
    // Simple rate limiting - in production, use Redis or database
    $cacheFile = sys_get_temp_dir() . '/api_rate_limit_' . md5($identifier);
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data && $data['reset_time'] > time()) {
            if ($data['requests'] >= $maxRequests) {
                return false; // Rate limit exceeded
            }
            $data['requests']++;
        } else {
            $data = ['requests' => 1, 'reset_time' => time() + $timeWindow];
        }
    } else {
        $data = ['requests' => 1, 'reset_time' => time() + $timeWindow];
    }
    
    file_put_contents($cacheFile, json_encode($data));
    return true;
}

function validateCSRFToken($token) {
    session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ===========================================================================
// MAINTENANCE FUNCTIONS
// ===========================================================================

function cleanupExpiredSessions() {
    // Clean up expired session files (if using file-based sessions)
    $sessionPath = session_save_path();
    if ($sessionPath && is_dir($sessionPath)) {
        $files = glob($sessionPath . '/sess_*');
        $now = time();
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) + ini_get('session.gc_maxlifetime') < $now) {
                unlink($file);
            }
        }
    }
}

function getDatabaseStats() {
    global $pdo;
    
    $stats = [];
    $tables = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory',
        'users' => 'users'
    ];
    
    foreach ($tables as $name => $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $result = $stmt->fetch();
            $stats[$name] = (int)$result['count'];
        } catch (PDOException $e) {
            $stats[$name] = 0;
        }
    }
    
    return $stats;
}

// Log API initialization
error_log("Main API initialized successfully at " . date('Y-m-d H:i:s'));

?>