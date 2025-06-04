<?php
// ===========================================================================
// FILE: api/api.php - Complete Final Version
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
            
        case 'cpu':
        case 'ram':
        case 'storage':
        case 'motherboard':
        case 'nic':
        case 'caddy':
            handleComponentOperations($module, $operation);
            break;
            
        case 'search':
            handleSearchOperations($operation);
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
                        logAction($pdo, "User login", "auth", $user["id"]);
                        
                        send_json_response(1, 1, 200, "Login successful", [
                            'user' => [
                                'id' => $user["id"],
                                'username' => $username,
                                'email' => $user["email"],
                                'firstname' => $user["firstname"],
                                'lastname' => $user["lastname"]
                            ],
                            'session_id' => session_id(),
                            'csrf_token' => generateCSRFToken()
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
// COMPONENT OPERATIONS
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
                
                logAction($pdo, "Viewed $componentType component", $componentType, $component['UUID']);
                
            } catch (PDOException $e) {
                error_log("Component get error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Database error");
            }
            break;
            
        case 'add':
        case 'add_' . $componentType:
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
// SEARCH OPERATIONS
// ===========================================================================
function handleSearchOperations($operation) {
    global $pdo;
    
    // Check authentication first
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
                
                logAction($pdo, "Searched components: $query", "search");
                
                send_json_response(1, 1, 200, "Search completed successfully", [
                    'results' => $results['components'],
                    'total_found' => $results['total_found'],
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
            
            // Process data based on component type
            if ($type == 'cpu') {
                foreach ($data as $brand) {
                    if (isset($brand['models'])) {
                        foreach ($brand['models'] as $model) {
                            $components[] = [
                                'uuid' => $model['UUID'] ?? generateUUID(),
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
            }
            
        } catch (Exception $e) {
            error_log("Error processing $type JSON: " . $e->getMessage());
        }
    }
    
    return $components;
}

function searchComponents($pdo, $query, $componentType = 'all', $limit = 20) {
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
            $totalFound += count($componentResults);
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
        'total_found' => $totalFound
    ];
}

?>