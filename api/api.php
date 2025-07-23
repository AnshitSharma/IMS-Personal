<?php
/**
 * Complete API with JWT Support
 * Replace your existing api.php with this version
 */

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once(__DIR__ . '/../includes/db_config.php');
require_once(__DIR__ . '/../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (ob_get_level()) {
    ob_clean();
}

// Clean up expired tokens periodically
if (rand(1, 100) === 1) {
    JWTHelper::cleanupExpiredTokens($pdo);
}

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
    switch ($operation) {
        case 'login':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            error_log("Login attempt - Username: '$username'");
            
            if (empty($username) || empty($password)) {
                error_log("Login failed: Missing username or password");
                send_json_response(0, 0, 400, "Username and password are required");
            }
            
            $authResult = authenticateUser($pdo, $username, $password);
            
            if ($authResult) {
                // Also create session for backward compatibility
                safeSessionStart();
                $_SESSION['id'] = $authResult['user']['id'];
                $_SESSION['username'] = $authResult['user']['username'];
                $_SESSION['email'] = $authResult['user']['email'];
                
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
            // Try to get user from JWT first
            $user = authenticateWithJWT($pdo);
            
            if ($user) {
                // Invalidate refresh token if provided
                $refreshToken = $_POST['refresh_token'] ?? '';
                if ($refreshToken) {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE token = ? AND user_id = ?");
                        $stmt->execute([$refreshToken, $user['id']]);
                    } catch (Exception $e) {
                        error_log("Error invalidating refresh token: " . $e->getMessage());
                    }
                }
                
                error_log("JWT logout for user: " . $user['username']);
            }
            
            // Also destroy session for backward compatibility
            safeSessionStart();
            session_destroy();
            
            error_log("User logged out");
            send_json_response(1, 1, 200, "Logout successful");
            break;
            
        case 'check_session':
            // Try JWT authentication first, then session
            $user = authenticateWithJWT($pdo);
            
            if (!$user) {
                $user = isUserLoggedIn($pdo);
            }
            
            if ($user) {
                error_log("Session/token check successful for user: " . $user['username']);
                send_json_response(1, 1, 200, "Authentication valid", ['user' => $user]);
            } else {
                error_log("Session/token check failed - no valid authentication");
                send_json_response(0, 0, 401, "No valid authentication");
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
    exit();
}

// All other operations require authentication (JWT or session)
$user = requireLogin($pdo);

// Component operations
$componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
if (in_array($module, $componentTypes)) {
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
                
                send_json_response(1, 1, 200, "Components retrieved", [
                    'components' => $components,
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset
                ]);
            } catch (Exception $e) {
                error_log("Error getting $module components: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve components");
            }
            break;
            
        case 'add':
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
                
                // Basic fields that exist in all component tables
                $fields = ['UUID', 'SerialNumber', 'Status', 'Notes', 'Location', 'RackPosition', 'Flag'];
                $values = [
                    $uuid, 
                    $_POST['SerialNumber'], 
                    $_POST['Status'], 
                    $_POST['Notes'] ?? '', 
                    $_POST['Location'] ?? '',
                    $_POST['RackPosition'] ?? '',
                    $_POST['Flag'] ?? ''
                ];
                
                $placeholders = str_repeat('?,', count($fields) - 1) . '?';
                $query = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES ($placeholders)";
                
                $stmt = $pdo->prepare($query);
                if ($stmt->execute($values)) {
                    $newId = $pdo->lastInsertId();
                    logActivity($pdo, $user['id'], 'create', $module, $newId, "Added new $module component");
                    send_json_response(1, 1, 200, "Component added successfully", ['id' => $newId, 'uuid' => $uuid]);
                } else {
                    send_json_response(0, 1, 500, "Failed to add component");
                }
            } catch (Exception $e) {
                error_log("Error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to add component: " . $e->getMessage());
            }
            break;
            
        case 'update':
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }
            
            try {
                // Check if component exists
                $stmt = $pdo->prepare("SELECT ID FROM $table WHERE ID = ?");
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 0) {
                    send_json_response(0, 1, 404, "Component not found");
                }
                
                $updateFields = [];
                $params = [];
                
                $allowedFields = ['SerialNumber', 'Status', 'Notes', 'Location', 'RackPosition', 'Flag'];
                
                foreach ($allowedFields as $field) {
                    if (isset($_POST[$field])) {
                        $updateFields[] = "$field = ?";
                        $params[] = $_POST[$field];
                    }
                }
                
                if (empty($updateFields)) {
                    send_json_response(0, 1, 400, "No fields to update");
                }
                
                $params[] = $id; // Add ID for WHERE clause
                $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = ?";
                
                $stmt = $pdo->prepare($query);
                if ($stmt->execute($params)) {
                    logActivity($pdo, $user['id'], 'update', $module, $id, "Updated $module component");
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
            $id = $_POST['id'] ?? $_GET['id'] ?? '';
            if (empty($id)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }
            
            try {
                $stmt = $pdo->prepare("DELETE FROM $table WHERE ID = ?");
                if ($stmt->execute([$id])) {
                    logActivity($pdo, $user['id'], 'delete', $module, $id, "Deleted $module component");
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
    exit();
}

// Dashboard operations
if ($module === 'dashboard') {
    switch ($operation) {
        case 'get_data':
        case 'stats':
            try {
                $stats = getSystemStats($pdo);
                send_json_response(1, 1, 200, "Dashboard stats retrieved", ['stats' => $stats]);
            } catch (Exception $e) {
                error_log("Error getting dashboard stats: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve dashboard stats");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid dashboard operation: $operation");
    }
    exit();
}

// User management (simplified)
if ($module === 'users') {
    switch ($operation) {
        case 'list':
            try {
                $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, created_at FROM users ORDER BY id");
                $stmt->execute();
                $users = $stmt->fetchAll();
                send_json_response(1, 1, 200, "Users retrieved", ['users' => $users]);
            } catch (Exception $e) {
                error_log("Error getting users: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve users");
            }
            break;
            
        case 'add':
            // Only allow admin to add users
            if (!isAdmin($pdo, $user['id'])) {
                send_json_response(0, 1, 403, "Admin access required");
            }
            
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            
            if (empty($username) || empty($email) || empty($password)) {
                send_json_response(0, 1, 400, "Username, email, and password are required");
            }
            
            try {
                $newId = createUser($pdo, $username, $email, $password, $firstname, $lastname);
                if ($newId) {
                    send_json_response(1, 1, 200, "User created successfully", ['id' => $newId]);
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
    exit();
}

// Invalid module
error_log("Invalid module requested: $module");
send_json_response(0, 1, 400, "Invalid module: $module");
?>