<?php
// api_inventory.php - API for inventory management

// Include the inventory functions
require_once __DIR__ . '/Inventory/listInventoryFunction.php';

// Include authentication functions
require_once __DIR__ . "/src/Auth/auth_functions.php";


// Set response header to JSON
header('Content-Type: application/json');

// Allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check authentication
$authenticated = false;
$user_id = null;

// Get token from Authorization header
$token = null;
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

// Verify token if present
if (!empty($token)) {
    $user_id = verifyAuthToken($token);
    if ($user_id) {
        $authenticated = true;
    }
}

// If not authenticated, return error
if (!$authenticated) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Authentication required'
    ]);
    exit;
}

// Process the request based on the method
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        handleGetRequest();
        break;
        
    case 'POST':
        handlePostRequest($user_id);
        break;
        
    case 'PUT':
        handlePutRequest($user_id);
        break;
        
    case 'DELETE':
        handleDeleteRequest($user_id);
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => 'Method not allowed'
        ]);
}

/**
 * Handle GET requests (fetch inventory items)
 */
function handleGetRequest() {
    // Parse query parameters
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';
    
    switch ($action) {
        case 'list':
            // Get parameters
            $status = isset($_GET['status']) ? $_GET['status'] : 'Available';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            
            // Get inventory items
            $result = listInventory($status, $page, $limit);
            echo json_encode($result);
            break;
            
        case 'status':
            // Get component info
            $componentType = isset($_GET['type']) ? $_GET['type'] : '';
            $serialNumber = isset($_GET['serial']) ? $_GET['serial'] : '';
            
            if (empty($componentType) || empty($serialNumber)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Component type and serial number are required'
                ]);
                return;
            }
            
            // Get status (would need to implement a getComponentStatus function)
            $result = getComponentStatus($componentType, $serialNumber);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action'
            ]);
    }
}

/**
 * Handle POST requests (add new items)
 */
function handlePostRequest($userId) {
    // Get JSON body
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid JSON data'
        ]);
        return;
    }
    
    // Check required fields
    if (!isset($data['component_type']) || !isset($data['data'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Component type and data are required'
        ]);
        return;
    }
    
    $componentType = $data['component_type'];
    $componentData = $data['data'];
    
    // Add the item
    $result = addInventoryItem($componentType, $componentData);
    echo json_encode($result);
}

/**
 * Handle PUT requests (update existing items)
 */
function handlePutRequest($userId) {
    // Get JSON body
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid JSON data'
        ]);
        return;
    }
    
    // Check if action is specified
    $action = isset($data['action']) ? $data['action'] : 'update';
    
    switch ($action) {
        case 'update':
            // Check required fields
            if (!isset($data['component_type']) || !isset($data['serial_number']) || !isset($data['data'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Component type, serial number, and data are required'
                ]);
                return;
            }
            
            $componentType = $data['component_type'];
            $serialNumber = $data['serial_number'];
            $fieldsToUpdate = $data['data'];
            
            // Update the item
            $result = updateInventoryItem($componentType, $serialNumber, $fieldsToUpdate, $userId);
            echo json_encode($result);
            break;
            
        case 'status':
            // Check required fields
            if (!isset($data['component_type']) || !isset($data['serial_number']) || !isset($data['status'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Component type, serial number, and status are required'
                ]);
                return;
            }
            
            $componentType = $data['component_type'];
            $serialNumber = $data['serial_number'];
            $newStatus = $data['status'];
            $notes = isset($data['notes']) ? $data['notes'] : '';
            
            // Update the status
            $result = updateInventoryStatus($componentType, $serialNumber, $newStatus, $userId, $notes);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action'
            ]);
    }
}

/**
 * Handle DELETE requests (remove items)
 */
function handleDeleteRequest($userId) {
    // Check if component type and serial number are provided in query string
    $componentType = isset($_GET['type']) ? $_GET['type'] : '';
    $serialNumber = isset($_GET['serial']) ? $_GET['serial'] : '';
    
    // If not in query string, try to get from JSON body
    if (empty($componentType) || empty($serialNumber)) {
        $jsonData = file_get_contents('php://input');
        $data = json_decode($jsonData, true);
        
        if ($data) {
            $componentType = isset($data['component_type']) ? $data['component_type'] : '';
            $serialNumber = isset($data['serial_number']) ? $data['serial_number'] : '';
        }
    }
    
    if (empty($componentType) || empty($serialNumber)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Component type and serial number are required'
        ]);
        return;
    }
    
    // Delete the item (would need to implement a deleteInventoryItem function)
    $result = deleteInventoryItem($componentType, $serialNumber, $userId);
    echo json_encode($result);
}

/**
 * Get status of a component
 * 
 * @param string $componentType - Type of component
 * @param string $serialNumber - Serial number of component
 * @return array - Result of the operation
 */
function getComponentStatus($componentType, $serialNumber) {
    global $conn;
    
    try {
        // Validate component type
        $validTypes = ['cpu', 'motherboard', 'storage', 'ram', 'nic'];
        if (!in_array($componentType, $validTypes)) {
            throw new Exception("Invalid component type: $componentType");
        }
        
        // Determine table name
        $tableName = $componentType . '_inventory';
        
        // Escape serial number
        $serialNumber = $conn->real_escape_string($serialNumber);
        
        // Get component info
        $sql = "SELECT * FROM $tableName WHERE serial_number = '$serialNumber'";
        $result = $conn->query($sql);
        
        if (!$result || $result->num_rows === 0) {
            throw new Exception("Component with serial number $serialNumber not found");
        }
        
        $component = $result->fetch_assoc();
        
        // Get recent status changes from log
        $componentId = $component['id'];
        $sql = "SELECT * FROM inventory_log 
                WHERE component_type = '$componentType' AND component_id = $componentId 
                ORDER BY created_at DESC LIMIT 5";
        
        $logResult = $conn->query($sql);
        $statusLog = [];
        
        if ($logResult) {
            while ($row = $logResult->fetch_assoc()) {
                $statusLog[] = [
                    'previous_status' => $row['previous_status'],
                    'new_status' => $row['new_status'],
                    'changed_by' => $row['changed_by'],
                    'notes' => $row['notes'],
                    'date' => $row['created_at']
                ];
            }
        }
        
        return [
            'status' => 'success',
            'data' => [
                'component' => $component,
                'status_log' => $statusLog
            ]
        ];
    } catch (Exception $e) {
        error_log("Error in getComponentStatus: " . $e->getMessage());
        
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Delete an inventory item
 * 
 * @param string $componentType - Type of component
 * @param string $serialNumber - Serial number of component
 * @param int $userId - ID of user making the change
 * @return array - Result of the operation
 */
function deleteInventoryItem($componentType, $serialNumber, $userId) {
    global $conn;
    
    try {
        // Validate component type
        $validTypes = ['cpu', 'motherboard', 'storage', 'ram', 'nic'];
        if (!in_array($componentType, $validTypes)) {
            throw new Exception("Invalid component type: $componentType");
        }
        
        // Determine table name
        $tableName = $componentType . '_inventory';
        
        // Escape serial number
        $serialNumber = $conn->real_escape_string($serialNumber);
        
        // Check if component exists
        $sql = "SELECT id FROM $tableName WHERE serial_number = '$serialNumber'";
        $result = $conn->query($sql);
        
        if (!$result || $result->num_rows === 0) {
            throw new Exception("Component with serial number $serialNumber not found");
        }
        
        $row = $result->fetch_assoc();
        $componentId = $row['id'];
        
        // Delete the component
        $sql = "DELETE FROM $tableName WHERE serial_number = '$serialNumber'";
        
        if (!$conn->query($sql)) {
            throw new Exception("Failed to delete component: " . $conn->error);
        }
        
        // Log the deletion
        $userId = (int)$userId;
        $notes = "Component deleted from inventory";
        $notes = $conn->real_escape_string($notes);
        
        $sql = "INSERT INTO inventory_log (component_type, component_id, previous_status, new_status, changed_by, notes) 
                VALUES ('$componentType', $componentId, NULL, 'Deleted', $userId, '$notes')";
        
        if (!$conn->query($sql)) {
            error_log("Failed to log inventory deletion: " . $conn->error);
        }
        
        return [
            'status' => 'success',
            'message' => "$componentType with serial number $serialNumber deleted successfully"
        ];
    } catch (Exception $e) {
        error_log("Error in deleteInventoryItem: " . $e->getMessage());
        
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}
?>