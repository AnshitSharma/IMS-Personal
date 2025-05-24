<?php

require_once(__DIR__ . '/../includes/db_config.php');
require_once(__DIR__ . '/../includes/QueryModel.php');
require_once(__DIR__ . '/../includes/config.php');
require_once(__DIR__ . '/../includes/BaseFunctions.php');

define('AJAX_ENTRY', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header("Access-Control-Allow-Headers: *");

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

// Table mapping for each component type
$tableMap = [
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Load component JSON data
function loadComponentFromJSON($type, $uuid) {
    $component = null;
    
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
        default:
            return null;
    }
    
    if (file_exists($jsonFile)) {
        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);
        
        // Find component by UUID
        if ($type == 'cpu') {
            foreach ($data as $brand) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['UUID']) && $model['UUID'] == $uuid) {
                        return $model;
                    }
                }
            }
        } elseif ($type == 'motherboard') {
            foreach ($data as $brand) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['inventory']['UUID']) && $model['inventory']['UUID'] == $uuid) {
                        return $model;
                    }
                }
            }
        } elseif ($type == 'ram') {
            if (isset($data['name']) && is_array($data['name'])) {
                foreach ($data['name'] as $ram) {
                    if (isset($ram['UUID']) && $ram['UUID'] == $uuid) {
                        return $ram;
                    }
                }
            }
        }
    }
    
    return $component;
}

// Generate UUID if not provided
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Usage
if (isUserLoggedIn($pdo)) {
    if ($_SERVER['REQUEST_METHOD'] == "GET") {
        send_json_response(1, 0, 405, "Method Not Allowed");
    } elseif ($_SERVER['REQUEST_METHOD'] == "POST") {
        
        if (!isset($_POST['action'])) {
            send_json_response(1, 0, 400, "Parameter Missing");
        } else {
            $action = $_POST['action'];
            
            // Handle ADD operations
            if (strpos($action, '-add_') !== false) {
                $parts = explode('-', $action);
                $componentType = $parts[0];
                
                if (!isset($tableMap[$componentType])) {
                    send_json_response(1, 0, 400, "Invalid component type");
                }
                
                try {
                    $table = $tableMap[$componentType];
                    
                    // Get component UUID from JSON selection or generate new one
                    $componentUuid = $_POST['component_uuid'] ?? '';
                    $uuid = !empty($componentUuid) ? $componentUuid : generateUUID();
                    
                    // Common fields for all components
                    $serialNumber = $_POST['serial_number'] ?? '';
                    $status = $_POST['status'] ?? 1;
                    $serverUuid = $_POST['server_uuid'] ?? null;
                    $location = $_POST['location'] ?? null;
                    $rackPosition = $_POST['rack_position'] ?? null;
                    $purchaseDate = $_POST['purchase_date'] ?? null;
                    $warrantyEndDate = $_POST['warranty_end_date'] ?? null;
                    $flag = $_POST['flag'] ?? null;
                    $notes = $_POST['notes'] ?? null;
                    
                    // Basic validation
                    if (empty($serialNumber)) {
                        send_json_response(1, 0, 400, "Serial Number is required");
                    }
                    
                    // Base query
                    $query = "INSERT INTO $table (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
                              PurchaseDate, WarrantyEndDate, Flag, Notes";
                    
                    $values = ":uuid, :serial_number, :status, :server_uuid, :location, :rack_position, 
                               :purchase_date, :warranty_end_date, :flag, :notes";
                    
                    $params = [
                        ':uuid' => $uuid,
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
                        'id' => $insertId,
                        'uuid' => $uuid
                    ]);
                    
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) { // Duplicate entry
                        send_json_response(1, 0, 400, "Serial number already exists");
                    } else {
                        send_json_response(1, 0, 500, "Database error: " . $e->getMessage());
                    }
                }
                
            }
            // Handle EDIT operations
            elseif (strpos($action, '-edit_') !== false) {
                $parts = explode('-', $action);
                $componentType = $parts[0];
                
                if (!isset($tableMap[$componentType])) {
                    send_json_response(1, 0, 400, "Invalid component type");
                }
                
                $id = $_POST['id'] ?? '';
                
                if (empty($id)) {
                    send_json_response(1, 0, 400, "Component ID is required");
                }
                
                try {
                    $table = $tableMap[$componentType];
                    
                    // Check if component exists
                    $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                    $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $checkStmt->execute();
                    
                    if ($checkStmt->rowCount() == 0) {
                        send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                    }
                    
                    // Build update query
                    $updateFields = [];
                    $params = [':id' => $id];
                    
                    // Editable fields
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
                    }
                    
                    $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = :id";
                    
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    
                    send_json_response(1, 1, 200, ucfirst($componentType) . " updated successfully");
                    
                } catch (PDOException $e) {
                    send_json_response(1, 0, 500, "Database error: " . $e->getMessage());
                }
            }
            // Handle REMOVE operations
            elseif (strpos($action, '-remove_') !== false) {
                $parts = explode('-', $action);
                $componentType = $parts[0];
                
                if (!isset($tableMap[$componentType])) {
                    send_json_response(1, 0, 400, "Invalid component type");
                }
                
                $id = $_POST['id'] ?? '';
                
                if (empty($id)) {
                    send_json_response(1, 0, 400, "Component ID is required");
                }
                
                try {
                    $table = $tableMap[$componentType];
                    
                    // Check if component exists
                    $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                    $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $checkStmt->execute();
                    
                    if ($checkStmt->rowCount() == 0) {
                        send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                    }
                    
                    // Delete the component
                    $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
                    $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $deleteStmt->execute();
                    
                    send_json_response(1, 1, 200, ucfirst($componentType) . " deleted successfully");
                    
                } catch (PDOException $e) {
                    send_json_response(1, 0, 500, "Database error: " . $e->getMessage());
                }
            }
            // Handle LIST operations
            elseif (strpos($action, '-list_') !== false) {
                $parts = explode('-', $action);
                $componentType = $parts[0];
                
                if (!isset($tableMap[$componentType])) {
                    send_json_response(1, 0, 400, "Invalid component type");
                }
                
                try {
                    $table = $tableMap[$componentType];
                    $stmt = $pdo->prepare("SELECT * FROM $table ORDER BY CreatedAt DESC");
                    $stmt->execute();
                    
                    $data = $stmt->fetchAll();
                    
                    send_json_response(1, 1, 200, ucfirst($componentType) . " inventory retrieved successfully", [
                        'data' => $data,
                        'total_records' => count($data)
                    ]);
                    
                } catch (PDOException $e) {
                    send_json_response(1, 0, 500, "Database query failed: " . $e->getMessage());
                }
            } else {
                send_json_response(1, 0, 400, "Invalid Action");
            }
        }
    }
} else {
    send_json_response(0, 0, 401, "Unauthenticated");
}

?>